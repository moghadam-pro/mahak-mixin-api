<?php

declare(strict_types=1);

namespace MahakMixin\Sync;

use MahakMixin\Client\MahakClient;
use MahakMixin\Client\MixinClient;
use MahakMixin\Http\HttpException;
use MahakMixin\Mapper\ProductMapper;
use MahakMixin\Persistence\StateStore;
use RuntimeException;

final class ProductSyncService
{
    public function __construct(
        private readonly MahakClient $mahak,
        private readonly MixinClient $mixin,
        private readonly StateStore $state,
        private readonly ProductMapper $mapper,
        private readonly int $visitorId,
        private readonly int $pageSize = 100,
        /** @var array<string,int> */
        private readonly array $categoryMap = [],
        /** @var array<string,int> */
        private readonly array $productCategoryMap = [],
        private readonly int $fallbackCategoryId = 0,
        private readonly string $lockPath = 'var/product-sync.lock',
    ) {
    }

    /** @return array<string,mixed> */
    public function run(bool $dryRun = true): array
    {
        $runId = $this->state->startRun('mahak_to_mixin', 'products');
        try {
            $stats = $this->withLock(fn (): array => $this->runInternal($dryRun));
            $stats['run_id'] = $runId;
            $this->state->finishRun($runId, 'success', $stats);
            return $stats;
        } catch (\Throwable $exception) {
            $this->state->finishRun($runId, 'failed', null, $exception->getMessage());
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function runInternal(bool $dryRun): array
    {
        $request = [
            'currentVisitorId' => $this->visitorId,
            'fromProductVersion' => $this->state->checkpoint('mahak.products'),
            'fromProductDetailVersion' => $this->state->checkpoint('mahak.product_details'),
            'fromVisitorProductVersion' => $this->state->checkpoint('mahak.visitor_products'),
            'fromPictureVersion' => $this->state->checkpoint('mahak.pictures'),
            'fromPhotoGalleryVersion' => $this->state->checkpoint('mahak.photo_galleries'),
            'pageSize' => $this->pageSize,
        ];
        $response = $this->mahak->getAllData($request);
        $objects = $this->objects($response);
        $changedProducts = $this->list($objects, 'products');
        $changedDetails = $this->list($objects, 'productDetails');
        $changedVisitorProducts = $this->list($objects, 'visitorProducts');
        $changedPictures = $this->list($objects, 'pictures');
        $changedPhotoGalleries = $this->list($objects, 'photoGalleries');

        $products = $this->index(array_merge($this->state->snapshots('mahak.products'), $changedProducts), 'productId');
        $details = $this->index(array_merge($this->state->snapshots('mahak.product_details'), $changedDetails), 'productDetailId');
        $visitorProducts = $this->index(array_merge($this->state->snapshots('mahak.visitor_products'), $changedVisitorProducts), 'productDetailId');
        $pictures = $this->index(array_merge($this->state->snapshots('mahak.pictures'), $changedPictures), 'pictureId');
        $photoGalleries = $this->index(array_merge($this->state->snapshots('mahak.photo_galleries'), $changedPhotoGalleries), 'photoGalleryId');
        $affectedDetailIds = $this->affectedDetailIds($changedProducts, $changedDetails, $changedVisitorProducts, $details);

        $stats = [
            'dry_run' => $dryRun,
            'received' => 0,
            'created' => 0,
            'updated' => 0,
            'recreated' => 0,
            'skipped' => 0,
            'images_uploaded' => 0,
            'images_existing' => 0,
            'images_missing' => 0,
            'preview' => [],
        ];
        foreach ($affectedDetailIds as $sourceId) {
            $stats['received']++;
            $detail = $details[$sourceId] ?? null;
            if (!is_array($detail)) {
                $stats['skipped']++;
                continue;
            }
            $productId = $this->value($detail, 'productId');
            $product = $products[(string) ($productId ?? '')] ?? null;
            if (!is_array($product)) {
                $stats['skipped']++;
                continue;
            }
            $categoryId = $this->resolveCategoryId($sourceId, $product);
            if ($categoryId === null) {
                $stats['skipped']++;
                if (count($stats['preview']) < 10) {
                    $stats['preview'][] = [
                        'action' => 'skip',
                        'source_id' => $sourceId,
                        'reason' => 'missing_category_mapping',
                        'mahak_category_id' => $this->value($product, 'productCategoryId'),
                    ];
                }
                continue;
            }
            $payload = $this->mapper->toMixinCatalog($product, $detail, $visitorProducts[$sourceId] ?? null);
            $payload['main_category_id'] = $categoryId;
            if ($payload['name'] === '') {
                $stats['skipped']++;
                continue;
            }
            $targetId = $this->state->mapping('product', $sourceId);

            if ($dryRun) {
                if (count($stats['preview']) < 10) {
                    $stats['preview'][] = ['action' => $targetId === null ? 'create' : 'update', 'source_id' => $sourceId, 'target_id' => $targetId, 'payload' => $payload];
                }
                $stats[$targetId === null ? 'created' : 'updated']++;
                continue;
            }

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
                $targetId = null;
                $action = 'recreate';
            }
            $savedId = $this->findId($saved) ?? $targetId;
            if ($savedId === null) {
                throw new RuntimeException("Mixin product response has no id for Mahak detail {$sourceId}");
            }
            $this->state->saveMapping('product', $sourceId, (string) $savedId);
            $stats[$action . 'd']++;
            $imageAction = $this->syncImageFromData(
                (int) $savedId,
                $product,
                array_values($photoGalleries),
                $pictures,
            );
            $stats['images_' . $imageAction]++;
        }

        if (!$dryRun) {
            $this->saveSnapshots('mahak.products', 'productId', $changedProducts);
            $this->saveSnapshots('mahak.product_details', 'productDetailId', $changedDetails);
            $this->saveSnapshots('mahak.visitor_products', 'productDetailId', $changedVisitorProducts);
            $this->saveSnapshots('mahak.pictures', 'pictureId', $changedPictures);
            $this->saveSnapshots('mahak.photo_galleries', 'photoGalleryId', $changedPhotoGalleries);
            $this->advanceCheckpoints($objects);
        }

        return $stats;
    }

    private function resolveCategoryId(string $sourceDetailId, array $product): ?int
    {
        if (isset($this->productCategoryMap[$sourceDetailId])) {
            return $this->productCategoryMap[$sourceDetailId];
        }
        $sourceCategoryId = (string) $this->value($product, 'productCategoryId', '');
        return $this->categoryMap[$sourceCategoryId]
            ?? ($this->fallbackCategoryId > 0 ? $this->fallbackCategoryId : null);
    }

    /** @template T @param callable():T $callback @return T */
    private function withLock(callable $callback): mixed
    {
        $directory = dirname($this->lockPath);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create sync lock directory: {$directory}");
        }
        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Unable to open sync lock: {$this->lockPath}");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Another product sync is already running');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string,mixed> */
    public function runOne(string $sourceDetailId, int $mainCategoryId, bool $dryRun = true): array
    {
        if ($sourceDetailId === '' || $mainCategoryId < 1) {
            throw new \InvalidArgumentException('A source detail id and positive Mixin category id are required');
        }

        $response = $this->mahak->getAllData([
            'currentVisitorId' => $this->visitorId,
            'fromProductVersion' => 0,
            'fromProductDetailVersion' => 0,
            'fromVisitorProductVersion' => 0,
            'pageSize' => $this->pageSize,
        ]);
        $objects = $this->objects($response);
        $products = $this->index($this->list($objects, 'products'), 'productId');
        $details = $this->index($this->list($objects, 'productDetails'), 'productDetailId');
        $visitorProducts = $this->index($this->list($objects, 'visitorProducts'), 'productDetailId');

        $detail = $details[$sourceDetailId] ?? null;
        if (!is_array($detail)) {
            throw new RuntimeException("Mahak ProductDetail {$sourceDetailId} was not returned");
        }
        $productId = $this->value($detail, 'productId');
        $product = $products[(string) ($productId ?? '')] ?? null;
        if (!is_array($product)) {
            throw new RuntimeException("Mahak Product for detail {$sourceDetailId} was not returned");
        }

        $payload = $this->mapper->toMixinCatalog($product, $detail, $visitorProducts[$sourceDetailId] ?? null);
        if (($payload['name'] ?? '') === '') {
            throw new RuntimeException("Mahak ProductDetail {$sourceDetailId} has no usable product name");
        }
        $payload['main_category_id'] = $mainCategoryId;
        $targetId = $this->state->mapping('product', $sourceDetailId);
        $result = [
            'dry_run' => $dryRun,
            'scope' => 'single_product',
            'action' => $targetId === null ? 'create' : 'update',
            'source_id' => $sourceDetailId,
            'target_id' => $targetId,
            'payload' => $payload,
        ];
        if ($dryRun) {
            return $result;
        }

        $saved = $targetId === null
            ? $this->mixin->createProduct($payload)
            : $this->mixin->updateProduct((int) $targetId, $payload);
        $savedId = $this->findId($saved) ?? $targetId;
        if ($savedId === null) {
            throw new RuntimeException("Mixin product response has no id for Mahak detail {$sourceDetailId}");
        }
        $this->state->saveMapping('product', $sourceDetailId, (string) $savedId);
        $result['target_id'] = (string) $savedId;
        $result['response'] = $saved;
        return $result;
    }

    /** @return array<string,mixed> */
    public function rollbackOne(string $sourceDetailId): array
    {
        $targetId = $this->state->mapping('product', $sourceDetailId);
        if ($targetId === null || filter_var($targetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new RuntimeException("No valid Mixin mapping exists for Mahak detail {$sourceDetailId}");
        }

        $response = $this->mixin->product((int) $targetId);
        $product = $this->value($response, 'data', $response);
        if (!is_array($product) || !$this->isOwnedProduct($product, $sourceDetailId)) {
            throw new RuntimeException('Refusing rollback: Mixin product does not contain the expected Mahak Bridge markers');
        }

        $deleted = $this->mixin->deleteProduct((int) $targetId);
        $this->state->deleteMapping('product', $sourceDetailId);
        return ['ok' => true, 'source_id' => $sourceDetailId, 'target_id' => (int) $targetId, 'response' => $deleted];
    }

    /** @return array<string,mixed> */
    public function previewImage(string $sourceDetailId): array
    {
        $targetId = $this->state->mapping('product', $sourceDetailId);
        if ($targetId === null || filter_var($targetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new RuntimeException("No valid Mixin mapping exists for Mahak detail {$sourceDetailId}");
        }
        $source = $this->findImageSource($sourceDetailId);
        $existing = $this->mixin->productImages(['product_id' => (int) $targetId, 'page_size' => 20]);
        $existingItems = $this->value($existing, 'data', []);
        return [
            'dry_run' => true,
            'source_id' => $sourceDetailId,
            'target_id' => (int) $targetId,
            'source_image' => $source,
            'existing_image_count' => is_array($existingItems) ? count($existingItems) : 0,
            'action' => is_array($existingItems) && count($existingItems) > 0 ? 'skip_existing' : 'create',
        ];
    }

    /** @return array<string,mixed> */
    public function applyImage(string $sourceDetailId): array
    {
        $preview = $this->previewImage($sourceDetailId);
        if ($preview['action'] !== 'create') {
            throw new RuntimeException('Refusing image upload: target product already has an image');
        }
        $source = $preview['source_image'];
        if (!is_array($source) || !is_string($source['url'] ?? null)) {
            throw new RuntimeException('Mahak image source is invalid');
        }
        $binary = $this->mahak->downloadContent($source['url']);
        $length = strlen($binary);
        if ($length < 1 || $length > 5 * 1024 * 1024) {
            throw new RuntimeException("Mahak image size {$length} is outside the allowed range");
        }
        $mime = $this->detectImageMime($binary);
        if ($mime === null) {
            throw new RuntimeException('Mahak image format is not JPEG, PNG, GIF, or WebP');
        }
        $saved = $this->mixin->createProductImage([
            'product_id' => $preview['target_id'],
            'image_base64' => 'data:' . $mime . ';base64,' . base64_encode($binary),
            'image_alt' => $source['title'],
            'default' => true,
            'order' => 0,
        ]);
        return [
            'ok' => true,
            'source_id' => $sourceDetailId,
            'target_id' => $preview['target_id'],
            'source_picture_id' => $source['picture_id'],
            'downloaded_bytes' => $length,
            'mime' => $mime,
            'response' => $saved,
        ];
    }

    /** @return array<string,mixed> */
    public function rollbackImage(string $sourceDetailId, int $imageId): array
    {
        $targetId = $this->state->mapping('product', $sourceDetailId);
        if ($targetId === null) {
            throw new RuntimeException("No Mixin mapping exists for Mahak detail {$sourceDetailId}");
        }
        $response = $this->mixin->productImage($imageId);
        $image = $this->value($response, 'data', $response);
        if (!is_array($image) || (string) $this->value($image, 'product_id', '') !== (string) $targetId) {
            throw new RuntimeException('Refusing image rollback: image does not belong to the mapped Mixin product');
        }
        return [
            'ok' => true,
            'source_id' => $sourceDetailId,
            'target_id' => (int) $targetId,
            'image_id' => $imageId,
            'response' => $this->mixin->deleteProductImage($imageId),
        ];
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

    private function index(array $items, string $key): array
    {
        $result = [];
        foreach ($items as $item) {
            $value = $this->value($item, $key);
            if ($value !== null) {
                $result[(string) $value] = $item;
            }
        }
        return $result;
    }

    /**
     * @param list<array<string,mixed>> $changedProducts
     * @param list<array<string,mixed>> $changedDetails
     * @param list<array<string,mixed>> $changedVisitorProducts
     * @param array<string,array<string,mixed>> $allDetails
     * @return list<string>
     */
    private function affectedDetailIds(array $changedProducts, array $changedDetails, array $changedVisitorProducts, array $allDetails): array
    {
        $ids = [];
        $changedProductIds = [];
        foreach ($changedProducts as $product) {
            $id = $this->value($product, 'productId');
            if ($id !== null) {
                $changedProductIds[(string) $id] = true;
            }
        }
        foreach (array_merge($changedDetails, $changedVisitorProducts) as $item) {
            $id = $this->value($item, 'productDetailId');
            if ($id !== null) {
                $ids[(string) $id] = true;
            }
        }
        foreach ($allDetails as $detailId => $detail) {
            $productId = $this->value($detail, 'productId');
            if ($productId !== null && isset($changedProductIds[(string) $productId])) {
                $ids[(string) $detailId] = true;
            }
        }
        return array_map('strval', array_keys($ids));
    }

    /** @param list<array<string,mixed>> $items */
    private function saveSnapshots(string $entity, string $idKey, array $items): void
    {
        foreach ($items as $item) {
            $id = $this->value($item, $idKey);
            if ($id !== null) {
                $this->state->saveSnapshot($entity, (string) $id, $item);
            }
        }
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
        $candidates = [$response, is_array($response['data'] ?? null) ? $response['data'] : []];
        foreach ($candidates as $candidate) {
            foreach ($candidate as $key => $value) {
                if (strcasecmp((string) $key, 'id') === 0 && (is_int($value) || is_string($value))) {
                    return $value;
                }
            }
        }
        return null;
    }

    /** @param array<string,mixed> $product */
    private function isOwnedProduct(array $product, string $sourceDetailId): bool
    {
        $externalIds = $this->value($product, 'external_ids', []);
        if (!is_array($externalIds)) {
            return false;
        }
        return $this->value($externalIds, 'source') === 'mahak-mixin-bridge'
            && (string) $this->value($externalIds, 'mahak_product_detail_id', '') === $sourceDetailId
            && (string) $this->value($product, 'product_identifier', '') === $sourceDetailId;
    }

    /**
     * @param array<string,mixed> $product
     * @param list<array<string,mixed>> $galleryRows
     * @param array<string,array<string,mixed>> $pictures
     * @return 'uploaded'|'existing'|'missing'
     */
    private function syncImageFromData(int $targetId, array $product, array $galleryRows, array $pictures): string
    {
        $source = $this->imageSourceForProduct($product, $galleryRows, $pictures);
        if ($source === null) {
            return 'missing';
        }
        $existing = $this->mixin->productImages(['product_id' => $targetId, 'page_size' => 1]);
        $existingRows = $this->value($existing, 'data', []);
        if (is_array($existingRows) && count($existingRows) > 0) {
            return 'existing';
        }
        $binary = $this->mahak->downloadContent($source['url']);
        $length = strlen($binary);
        if ($length < 1 || $length > 5 * 1024 * 1024) {
            throw new RuntimeException("Mahak image size {$length} is outside the allowed range");
        }
        $mime = $this->detectImageMime($binary);
        if ($mime === null) {
            throw new RuntimeException('Mahak image format is not JPEG, PNG, GIF, or WebP');
        }
        $this->mixin->createProductImage([
            'product_id' => $targetId,
            'image_base64' => 'data:' . $mime . ';base64,' . base64_encode($binary),
            'image_alt' => $source['title'],
            'default' => true,
            'order' => 0,
        ]);
        return 'uploaded';
    }

    /**
     * @param array<string,mixed> $product
     * @param list<array<string,mixed>> $galleryRows
     * @param array<string,array<string,mixed>> $pictures
     * @return array{url:string,title:string}|null
     */
    private function imageSourceForProduct(array $product, array $galleryRows, array $pictures): ?array
    {
        $productId = (string) $this->value($product, 'productId', '');
        usort($galleryRows, fn (array $a, array $b): int => (int) $this->value($a, 'photoGalleryId', 0) <=> (int) $this->value($b, 'photoGalleryId', 0));
        foreach ($galleryRows as $gallery) {
            if ((string) $this->value($gallery, 'itemCode', '') !== $productId || (bool) $this->value($gallery, 'deleted', false)) {
                continue;
            }
            $picture = $pictures[(string) $this->value($gallery, 'pictureId', '')] ?? null;
            if (!is_array($picture) || (bool) $this->value($picture, 'deleted', false)) {
                continue;
            }
            $url = $this->value($picture, 'url');
            if (is_string($url) && $url !== '') {
                return [
                    'url' => $url,
                    'title' => $this->normalizeText((string) $this->value($product, 'name', '')),
                ];
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function findImageSource(string $sourceDetailId): array
    {
        $response = $this->mahak->getAllData([
            'currentVisitorId' => $this->visitorId,
            'fromProductVersion' => 0,
            'fromProductDetailVersion' => 0,
            'fromPictureVersion' => 0,
            'fromPhotoGalleryVersion' => 0,
            'pageSize' => $this->pageSize,
        ]);
        $objects = $this->objects($response);
        $products = $this->index($this->list($objects, 'products'), 'productId');
        $details = $this->index($this->list($objects, 'productDetails'), 'productDetailId');
        $pictures = $this->index($this->list($objects, 'pictures'), 'pictureId');
        $detail = $details[$sourceDetailId] ?? null;
        if (!is_array($detail)) {
            throw new RuntimeException("Mahak ProductDetail {$sourceDetailId} was not returned");
        }
        $productId = (string) $this->value($detail, 'productId', '');
        $product = $products[$productId] ?? [];
        $galleryRows = $this->list($objects, 'photoGalleries');
        usort($galleryRows, fn (array $a, array $b): int => (int) $this->value($a, 'photoGalleryId', 0) <=> (int) $this->value($b, 'photoGalleryId', 0));
        foreach ($galleryRows as $gallery) {
            if ((string) $this->value($gallery, 'itemCode', '') !== $productId || (bool) $this->value($gallery, 'deleted', false)) {
                continue;
            }
            $pictureId = (string) $this->value($gallery, 'pictureId', '');
            $picture = $pictures[$pictureId] ?? null;
            if (!is_array($picture) || (bool) $this->value($picture, 'deleted', false)) {
                continue;
            }
            $url = $this->value($picture, 'url');
            if (!is_string($url) || $url === '') {
                continue;
            }
            return [
                'picture_id' => (int) $pictureId,
                'gallery_id' => (int) $this->value($gallery, 'photoGalleryId', 0),
                'url' => $url,
                'title' => $this->normalizeText((string) $this->value(
                    is_array($product) ? $product : [],
                    'name',
                    $this->value($picture, 'title', '')
                )),
                'file_name' => $this->value($picture, 'fileName'),
                'file_size' => $this->value($picture, 'fileSize'),
                'width' => $this->value($picture, 'width'),
                'height' => $this->value($picture, 'height'),
                'format' => $this->value($picture, 'format'),
            ];
        }
        throw new RuntimeException("No Mahak picture was found for ProductDetail {$sourceDetailId}");
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
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\(\s+/u', '(', $value) ?? $value;
        return preg_replace('/\s+\)/u', ')', $value) ?? $value;
    }

    private function advanceCheckpoints(array $objects): void
    {
        foreach ([
            'products' => 'mahak.products',
            'productDetails' => 'mahak.product_details',
            'visitorProducts' => 'mahak.visitor_products',
            'pictures' => 'mahak.pictures',
            'photoGalleries' => 'mahak.photo_galleries',
        ] as $key => $entity) {
            $max = $this->state->checkpoint($entity);
            foreach ($this->list($objects, $key) as $item) {
                $max = max($max, (int) $this->value($item, 'rowVersion', 0));
            }
            $this->state->saveCheckpoint($entity, $max);
        }
    }
}
