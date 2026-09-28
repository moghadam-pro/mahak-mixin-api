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
 * Reconciles only products already mapped to Mixin.
 *
 * Mahak/Bazara does not consistently expose edits to existing products through
 * a newer RowVersion. This service reads the current catalogue, compares the
 * mapped payload with the last local snapshot, and PATCHes only real changes.
 */
final class MappedProductReconcileService
{
    public const LAST_RUN_META_KEY = 'mahak.products.last_reconcile_at';

    public function __construct(
        private readonly MahakClient $mahak,
        private readonly MixinClient $mixin,
        private readonly StateStore $state,
        private readonly ProductMapper $mapper,
        private readonly int $visitorId,
        private readonly int $pageSize = 5000,
        private readonly int $maxPages = 100,
        private readonly int $maxWrites = 50,
        private readonly int $writeDelayMs = 150,
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
        $runId = $this->state->startRun('mahak_to_mixin', 'mapped_product_reconcile');
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
        $mappings = $this->state->mappings('product');
        $stats = [
            'dry_run' => $dryRun,
            'scope' => 'mapped_product_reconcile',
            'mapped' => count($mappings),
            'pages_fetched' => 0,
            'compared' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'updated' => 0,
            'recreated' => 0,
            'deferred' => 0,
            'missing_source' => 0,
            'skipped' => 0,
            'failed' => 0,
            'preview' => [],
            'errors' => [],
        ];
        if ($mappings === []) {
            if (!$dryRun) {
                $this->state->saveMetadata(self::LAST_RUN_META_KEY, (string) time());
            }
            return $stats;
        }

        $catalog = $this->fetchCatalog();
        $stats['pages_fetched'] = $catalog['pages'];
        $products = $this->index($catalog['products'], 'productId');
        $details = $this->index($catalog['productDetails'], 'productDetailId');
        $visitorProducts = $this->index($catalog['visitorProducts'], 'productDetailId');
        $previousProducts = $this->index($this->state->snapshots('mahak.products'), 'productId');
        $previousDetails = $this->index($this->state->snapshots('mahak.product_details'), 'productDetailId');
        $previousVisitorProducts = $this->index($this->state->snapshots('mahak.visitor_products'), 'productDetailId');
        $writes = 0;

        foreach ($mappings as $sourceId => $targetId) {
            $detail = $details[$sourceId] ?? null;
            if (!is_array($detail)) {
                $stats['missing_source']++;
                $this->addError($stats, $sourceId, 'Mapped ProductDetail was not returned by Mahak');
                continue;
            }
            $productId = (string) $this->value($detail, 'productId', '');
            $product = $products[$productId] ?? null;
            if (!is_array($product)) {
                $stats['missing_source']++;
                $this->addError($stats, $sourceId, 'Mapped Product was not returned by Mahak');
                continue;
            }
            $categoryId = $this->resolveCategoryId($sourceId, $product);
            if ($categoryId === null) {
                $stats['skipped']++;
                $this->addError($stats, $sourceId, 'No Mixin category mapping is available');
                continue;
            }
            if (filter_var($targetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                $stats['failed']++;
                $this->addError($stats, $sourceId, 'Mapped Mixin product id is not a positive integer');
                continue;
            }
            try {
                $payload = $this->payload($sourceId, $product, $detail, $visitorProducts[$sourceId] ?? null, $categoryId);
                $previousPayload = $this->previousPayload(
                    $sourceId,
                    $previousProducts,
                    $previousDetails,
                    $previousVisitorProducts,
                    $categoryId,
                );
            } catch (\Throwable $exception) {
                $stats['failed']++;
                $this->addError($stats, $sourceId, $exception->getMessage());
                continue;
            }
            $stats['compared']++;
            if ($previousPayload !== null && $this->payloadHash($previousPayload) === $this->payloadHash($payload)) {
                $stats['unchanged']++;
                if (!$dryRun) {
                    $this->persistCurrentSnapshots($sourceId, $productId, $product, $detail, $visitorProducts[$sourceId] ?? null);
                }
                continue;
            }

            $stats['changed']++;
            if ($writes >= $this->maxWrites) {
                $stats['deferred']++;
                continue;
            }
            if (count($stats['preview']) < 20) {
                $stats['preview'][] = [
                    'action' => 'update',
                    'source_id' => $sourceId,
                    'target_id' => $targetId,
                    'payload' => $payload,
                ];
            }
            if ($dryRun) {
                continue;
            }

            try {
                $action = 'updated';
                try {
                    $this->mixin->updateProduct((int) $targetId, $payload);
                } catch (HttpException $exception) {
                    if ($exception->statusCode !== 404) {
                        throw $exception;
                    }
                    $this->state->deleteMapping('product', $sourceId);
                    $created = $this->mixin->createProduct($payload);
                    $savedId = $this->findId($created);
                    if ($savedId === null) {
                        throw new RuntimeException("Mixin product response has no id for Mahak detail {$sourceId}");
                    }
                    $this->state->saveMapping('product', $sourceId, (string) $savedId);
                    $action = 'recreated';
                }
                $stats[$action]++;
                $writes++;
                $this->persistCurrentSnapshots($sourceId, $productId, $product, $detail, $visitorProducts[$sourceId] ?? null);
                $this->delay();
            } catch (\Throwable $exception) {
                $stats['failed']++;
                $this->addError($stats, $sourceId, $exception->getMessage());
            }
        }

        if (!$dryRun) {
            $this->state->saveMetadata(self::LAST_RUN_META_KEY, (string) time());
        }
        return $stats;
    }

    /** @return array<string,mixed> */
    private function fetchCatalog(): array
    {
        $entities = [
            'products' => ['request' => 'fromProductVersion', 'id' => 'productId'],
            'productDetails' => ['request' => 'fromProductDetailVersion', 'id' => 'productDetailId'],
            'visitorProducts' => ['request' => 'fromVisitorProductVersion', 'id' => 'productDetailId'],
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
                foreach ($this->list($objects, $key) as $row) {
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
                throw new RuntimeException("Mapped product reconciliation exceeded max pages {$this->maxPages}");
            }
        }
        foreach ($collected as $key => $rows) {
            $collected[$key] = array_values($rows);
        }
        $collected['pages'] = $pages;
        return $collected;
    }

    private function resolveCategoryId(string $sourceId, array $product): ?int
    {
        if (isset($this->productCategoryMap[$sourceId])) {
            return $this->productCategoryMap[$sourceId];
        }
        $sourceCategoryId = (string) $this->value($product, 'productCategoryId', '');
        return $this->categoryMap[$sourceCategoryId]
            ?? ($this->fallbackCategoryId > 0 ? $this->fallbackCategoryId : null);
    }

    /** @return array<string,mixed> */
    private function payload(string $sourceId, array $product, array $detail, ?array $visitorProduct, int $categoryId): array
    {
        $payload = $this->mapper->toMixinCatalog($product, $detail, $visitorProduct);
        $payload['main_category_id'] = $categoryId;
        if (($payload['name'] ?? '') === '') {
            throw new RuntimeException("Mahak ProductDetail {$sourceId} has no usable product name");
        }
        return $payload;
    }

    /**
     * @param array<string,array<string,mixed>> $products
     * @param array<string,array<string,mixed>> $details
     * @param array<string,array<string,mixed>> $visitorProducts
     * @return array<string,mixed>|null
     */
    private function previousPayload(string $sourceId, array $products, array $details, array $visitorProducts, int $categoryId): ?array
    {
        $detail = $details[$sourceId] ?? null;
        if (!is_array($detail)) {
            return null;
        }
        $product = $products[(string) $this->value($detail, 'productId', '')] ?? null;
        if (!is_array($product)) {
            return null;
        }
        return $this->payload($sourceId, $product, $detail, $visitorProducts[$sourceId] ?? null, $categoryId);
    }

    private function persistCurrentSnapshots(
        string $sourceId,
        string $productId,
        array $product,
        array $detail,
        ?array $visitorProduct,
    ): void {
        $this->state->saveSnapshot('mahak.products', $productId, $product);
        $this->state->saveSnapshot('mahak.product_details', $sourceId, $detail);
        if ($visitorProduct !== null) {
            $this->state->saveSnapshot('mahak.visitor_products', $sourceId, $visitorProduct);
        }
    }

    /** @param array<string,mixed> $payload */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function objects(array $response): array
    {
        $data = $this->value($response, 'data', []);
        $objects = is_array($data) ? $this->value($data, 'objects', $data) : [];
        return is_array($objects) ? $objects : [];
    }

    /** @return list<array<string,mixed>> */
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

    /** @template T @param callable():T $callback @return T */
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
}
