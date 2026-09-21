<?php

declare(strict_types=1);

namespace MahakMixin\Sync;

use MahakMixin\Client\MahakClient;
use MahakMixin\Client\MixinClient;
use MahakMixin\Http\HttpException;
use MahakMixin\Mapper\ProductMapper;
use MahakMixin\Persistence\StateStore;
use RuntimeException;

/**
 * One-off, resumable full catalogue import.
 *
 * It is intentionally separate from the incremental worker: every active
 * Mahak ProductDetail is read from row-version zero, assigned to one temporary
 * Mixin category, and its first valid image is copied when available.
 */
final class FullCatalogSyncService
{
    public function __construct(
        private readonly MahakClient $mahak,
        private readonly MixinClient $mixin,
        private readonly StateStore $state,
        private readonly ProductMapper $mapper,
        private readonly int $visitorId,
        private readonly int $fallbackCategoryId,
        private readonly int $pageSize = 100,
        private readonly int $maxPages = 100,
        private readonly int $writeDelayMs = 150,
        private readonly string $lockPath = 'var/product-sync.lock',
    ) {
    }

    /** @return array<string,mixed> */
    public function run(bool $dryRun = true): array
    {
        if ($this->fallbackCategoryId < 1) {
            throw new RuntimeException('SYNC_FALLBACK_CATEGORY_ID must contain the temporary Mixin category id');
        }
        $runId = $this->state->startRun('mahak_to_mixin', 'full_catalog');
        try {
            $stats = $this->withLock(fn (): array => $this->runInternal($dryRun));
            $stats['run_id'] = $runId;
            $status = ($stats['failed'] ?? 0) > 0 ? 'partial' : 'success';
            $this->state->finishRun($runId, $status, $stats);
            return $stats;
        } catch (\Throwable $exception) {
            $this->state->finishRun($runId, 'failed', null, $exception->getMessage());
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function runInternal(bool $dryRun): array
    {
        $catalog = $this->fetchCatalog();
        $products = $this->index($catalog['products'], 'productId');
        $visitorProducts = $this->index($catalog['visitorProducts'], 'productDetailId');
        $pictures = $this->index($catalog['pictures'], 'pictureId');
        $imagesByProduct = $this->imageSources($catalog['photoGalleries'], $pictures, $products);

        $stats = [
            'dry_run' => $dryRun,
            'scope' => 'full_catalog',
            'pages_fetched' => $catalog['pages'],
            'source' => [
                'products' => count($catalog['products']),
                'product_details' => count($catalog['productDetails']),
                'visitor_products' => count($catalog['visitorProducts']),
                'pictures' => count($catalog['pictures']),
                'photo_galleries' => count($catalog['photoGalleries']),
            ],
            'received' => 0,
            'created' => 0,
            'updated' => 0,
            'recreated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'images_found' => 0,
            'images_uploaded' => 0,
            'images_existing' => 0,
            'images_missing' => 0,
            'images_failed' => 0,
            'preview' => [],
            'errors' => [],
        ];

        foreach ($catalog['productDetails'] as $detail) {
            $sourceId = (string) $this->value($detail, 'productDetailId', '');
            $productId = (string) $this->value($detail, 'productId', '');
            $product = $products[$productId] ?? null;
            if ($sourceId === '' || !is_array($product)) {
                $stats['skipped']++;
                continue;
            }
            if ((bool) $this->value($product, 'deleted', false)) {
                $stats['skipped']++;
                continue;
            }

            $stats['received']++;
            $payload = $this->mapper->toMixinCatalog($product, $detail, $visitorProducts[$sourceId] ?? null);
            $payload['main_category_id'] = $this->fallbackCategoryId;
            if (($payload['name'] ?? '') === '') {
                $stats['skipped']++;
                $this->addError($stats, $sourceId, 'Product has no usable name');
                continue;
            }

            $image = $imagesByProduct[$productId] ?? null;
            $image === null ? $stats['images_missing']++ : $stats['images_found']++;
            $targetId = $this->state->mapping('product', $sourceId);
            $action = $targetId === null ? 'create' : 'update';

            if ($dryRun) {
                if ($targetId !== null && !$this->targetExists((int) $targetId)) {
                    $action = 'recreate';
                }
                $stats[$action === 'recreate' ? 'recreated' : $action . 'd']++;
                if (count($stats['preview']) < 20) {
                    $stats['preview'][] = [
                        'action' => $action,
                        'source_id' => $sourceId,
                        'target_id' => $targetId,
                        'has_image' => $image !== null,
                        'payload' => $payload,
                    ];
                }
                continue;
            }

            try {
                [$savedId, $savedAction] = $this->saveProduct($sourceId, $targetId, $payload);
                $stats[$savedAction . 'd']++;
                if ($image === null) {
                    $this->delay();
                    continue;
                }
                try {
                    $imageAction = $this->saveImage($savedId, $image);
                    $stats[$imageAction === 'uploaded' ? 'images_uploaded' : 'images_existing']++;
                } catch (\Throwable $imageException) {
                    $stats['images_failed']++;
                    $this->addError($stats, $sourceId, 'Image: ' . $imageException->getMessage());
                }
            } catch (\Throwable $exception) {
                $stats['failed']++;
                $this->addError($stats, $sourceId, $exception->getMessage());
            }
            $this->delay();
        }

        if (!$dryRun && $stats['failed'] === 0 && $stats['images_failed'] === 0) {
            $this->persistCatalog($catalog);
            $stats['checkpoints_advanced'] = true;
        } else {
            $stats['checkpoints_advanced'] = false;
        }
        return $stats;
    }

    /** @return array{0:int,1:string} */
    private function saveProduct(string $sourceId, ?string $targetId, array $payload): array
    {
        $action = $targetId === null ? 'create' : 'update';
        try {
            $saved = $targetId === null
                ? $this->mixin->createProduct($payload)
                : $this->mixin->updateProduct((int) $targetId, $payload);
        } catch (HttpException $exception) {
            if ($targetId === null || $exception->statusCode !== 404) {
                throw $exception;
            }
            $this->state->deleteMapping('product', $sourceId);
            $saved = $this->mixin->createProduct($payload);
            $action = 'recreate';
            $targetId = null;
        }
        $savedId = $this->findId($saved) ?? $targetId;
        if ($savedId === null || filter_var($savedId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new RuntimeException("Mixin product response has no valid id for Mahak detail {$sourceId}");
        }
        $this->state->saveMapping('product', $sourceId, (string) $savedId);
        return [(int) $savedId, $action];
    }

    /** @param array<string,mixed> $source */
    private function saveImage(int $targetId, array $source): string
    {
        $existing = $this->mixin->productImages(['product_id' => $targetId, 'page_size' => 1]);
        if (count($this->responseItems($existing)) > 0) {
            return 'existing';
        }
        $binary = $this->mahak->downloadContent((string) $source['url']);
        $length = strlen($binary);
        if ($length < 1 || $length > 5 * 1024 * 1024) {
            throw new RuntimeException("image size {$length} is outside the allowed range");
        }
        $mime = $this->detectImageMime($binary);
        if ($mime === null) {
            throw new RuntimeException('image format is not JPEG, PNG, GIF, or WebP');
        }
        $this->mixin->createProductImage([
            'product_id' => $targetId,
            'image_base64' => 'data:' . $mime . ';base64,' . base64_encode($binary),
            'image_alt' => (string) $source['title'],
            'default' => true,
            'order' => 0,
        ]);
        return 'uploaded';
    }

    private function targetExists(int $targetId): bool
    {
        try {
            $this->mixin->product($targetId);
            return true;
        } catch (HttpException $exception) {
            if ($exception->statusCode === 404) {
                return false;
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function fetchCatalog(): array
    {
        $entities = [
            'products' => ['request' => 'fromProductVersion', 'id' => 'productId'],
            'productDetails' => ['request' => 'fromProductDetailVersion', 'id' => 'productDetailId'],
            'visitorProducts' => ['request' => 'fromVisitorProductVersion', 'id' => 'productDetailId'],
            'pictures' => ['request' => 'fromPictureVersion', 'id' => 'pictureId'],
            'photoGalleries' => ['request' => 'fromPhotoGalleryVersion', 'id' => 'photoGalleryId'],
        ];
        $cursors = array_fill_keys(array_keys($entities), 0);
        $collected = array_fill_keys(array_keys($entities), []);
        $pages = 0;

        for ($page = 1; $page <= $this->maxPages; $page++) {
            $request = ['currentVisitorId' => $this->visitorId, 'pageSize' => $this->pageSize];
            foreach ($entities as $key => $meta) {
                $request[$meta['request']] = $cursors[$key];
            }
            $objects = $this->objects($this->mahak->getAllData($request));
            $progress = false;
            foreach ($entities as $key => $meta) {
                $rows = $this->list($objects, $key);
                foreach ($rows as $row) {
                    $id = $this->value($row, $meta['id']);
                    if ($id !== null) {
                        $collected[$key][(string) $id] = $row;
                    }
                    $rowVersion = (int) $this->value($row, 'rowVersion', 0);
                    if ($rowVersion > $cursors[$key]) {
                        $cursors[$key] = $rowVersion;
                        $progress = true;
                    }
                }
            }
            $pages = $page;
            if (!$progress) {
                break;
            }
            if ($page === $this->maxPages) {
                throw new RuntimeException("Full catalogue exceeded SYNC_FULL_MAX_PAGES={$this->maxPages}");
            }
        }
        foreach ($collected as $key => $rows) {
            $collected[$key] = array_values($rows);
        }
        $collected['cursors'] = $cursors;
        $collected['pages'] = $pages;
        return $collected;
    }

    /** @return array<string,array<string,mixed>> */
    private function imageSources(array $galleries, array $pictures, array $products): array
    {
        usort($galleries, fn (array $a, array $b): int => (int) $this->value($a, 'photoGalleryId', 0) <=> (int) $this->value($b, 'photoGalleryId', 0));
        $result = [];
        foreach ($galleries as $gallery) {
            $productId = (string) $this->value($gallery, 'itemCode', '');
            $pictureId = (string) $this->value($gallery, 'pictureId', '');
            $picture = $pictures[$pictureId] ?? null;
            if ($productId === '' || isset($result[$productId]) || (bool) $this->value($gallery, 'deleted', false)
                || !is_array($picture) || (bool) $this->value($picture, 'deleted', false)) {
                continue;
            }
            $url = $this->value($picture, 'url');
            if (!is_string($url) || $url === '') {
                continue;
            }
            $result[$productId] = [
                'picture_id' => $pictureId,
                'url' => $url,
                'title' => $this->normalizeText((string) $this->value($products[$productId] ?? [], 'name', '')),
            ];
        }
        return $result;
    }

    private function persistCatalog(array $catalog): void
    {
        $definitions = [
            'products' => ['entity' => 'mahak.products', 'id' => 'productId'],
            'productDetails' => ['entity' => 'mahak.product_details', 'id' => 'productDetailId'],
            'visitorProducts' => ['entity' => 'mahak.visitor_products', 'id' => 'productDetailId'],
            'pictures' => ['entity' => 'mahak.pictures', 'id' => 'pictureId'],
            'photoGalleries' => ['entity' => 'mahak.photo_galleries', 'id' => 'photoGalleryId'],
        ];
        foreach ($definitions as $key => $meta) {
            foreach ($catalog[$key] as $row) {
                $id = $this->value($row, $meta['id']);
                if ($id !== null) {
                    $this->state->saveSnapshot($meta['entity'], (string) $id, $row);
                }
            }
            $this->state->saveCheckpoint($meta['entity'], (int) ($catalog['cursors'][$key] ?? 0));
        }
    }

    private function withLock(callable $callback): mixed
    {
        $directory = dirname($this->lockPath);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create sync lock directory: {$directory}");
        }
        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Another product sync is already running');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function objects(array $response): array
    {
        $data = $this->value($response, 'data', []);
        $objects = is_array($data) ? $this->value($data, 'objects', $data) : [];
        return is_array($objects) ? $objects : [];
    }

    private function list(array $data, string $key): array
    {
        $value = $this->value($data, $key, []);
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /** @return array<string,array<string,mixed>> */
    private function index(array $items, string $key): array
    {
        $result = [];
        foreach ($items as $item) {
            $id = $this->value($item, $key);
            if ($id !== null) {
                $result[(string) $id] = $item;
            }
        }
        return $result;
    }

    private function value(array $data, string $key, mixed $default = null): mixed
    {
        foreach ($data as $candidate => $value) {
            if (strcasecmp((string) $candidate, $key) === 0) {
                return $value;
            }
        }
        return $default;
    }

    private function findId(array $response): int|string|null
    {
        foreach ([$response, is_array($response['data'] ?? null) ? $response['data'] : []] as $candidate) {
            foreach ($candidate as $key => $value) {
                if (strcasecmp((string) $key, 'id') === 0 && (is_int($value) || is_string($value))) {
                    return $value;
                }
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    private function responseItems(array $response): array
    {
        $data = $this->value($response, 'data', []);
        if (!is_array($data)) {
            return [];
        }
        $items = $this->value($data, 'items', $data);
        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    private function detectImageMime(string $binary): ?string
    {
        return match (true) {
            str_starts_with($binary, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($binary, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($binary, 'GIF87a'), str_starts_with($binary, 'GIF89a') => 'image/gif',
            strlen($binary) >= 12 && substr($binary, 0, 4) === 'RIFF' && substr($binary, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    private function normalizeText(string $value): string
    {
        $value = strtr(trim($value), ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک']);
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function addError(array &$stats, string $sourceId, string $message): void
    {
        if (count($stats['errors']) < 50) {
            $stats['errors'][] = ['source_id' => $sourceId, 'message' => $message];
        }
    }

    private function delay(): void
    {
        if ($this->writeDelayMs > 0) {
            usleep($this->writeDelayMs * 1000);
        }
    }
}
