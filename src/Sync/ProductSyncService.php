<?php

declare(strict_types=1);

namespace MahakMixin\Sync;

use MahakMixin\Client\MahakClient;
use MahakMixin\Client\MixinClient;
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
    ) {
    }

    /** @return array<string,mixed> */
    public function run(bool $dryRun = true): array
    {
        $request = [
            'currentVisitorId' => $this->visitorId,
            'fromProductVersion' => $this->state->checkpoint('mahak.products'),
            'fromProductDetailVersion' => $this->state->checkpoint('mahak.product_details'),
            'fromVisitorProductVersion' => $this->state->checkpoint('mahak.visitor_products'),
            'pageSize' => $this->pageSize,
        ];
        $response = $this->mahak->getAllData($request);
        $objects = $this->objects($response);
        $changedProducts = $this->list($objects, 'products');
        $changedDetails = $this->list($objects, 'productDetails');
        $changedVisitorProducts = $this->list($objects, 'visitorProducts');

        $products = $this->index(array_merge($this->state->snapshots('mahak.products'), $changedProducts), 'productId');
        $details = $this->index(array_merge($this->state->snapshots('mahak.product_details'), $changedDetails), 'productDetailId');
        $visitorProducts = $this->index(array_merge($this->state->snapshots('mahak.visitor_products'), $changedVisitorProducts), 'productDetailId');
        $affectedDetailIds = $this->affectedDetailIds($changedProducts, $changedDetails, $changedVisitorProducts, $details);

        $stats = ['dry_run' => $dryRun, 'received' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'preview' => []];
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
            $payload = $this->mapper->toMixin($product, $detail, $visitorProducts[$sourceId] ?? null);
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

            $saved = $targetId === null ? $this->mixin->createProduct($payload) : $this->mixin->updateProduct((int) $targetId, $payload);
            $savedId = $this->findId($saved) ?? $targetId;
            if ($savedId === null) {
                throw new RuntimeException("Mixin product response has no id for Mahak detail {$sourceId}");
            }
            $this->state->saveMapping('product', $sourceId, (string) $savedId);
            $stats[$targetId === null ? 'created' : 'updated']++;
        }

        if (!$dryRun) {
            $this->saveSnapshots('mahak.products', 'productId', $changedProducts);
            $this->saveSnapshots('mahak.product_details', 'productDetailId', $changedDetails);
            $this->saveSnapshots('mahak.visitor_products', 'productDetailId', $changedVisitorProducts);
            $this->advanceCheckpoints($objects);
        }

        return $stats;
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

    private function advanceCheckpoints(array $objects): void
    {
        foreach (['products' => 'mahak.products', 'productDetails' => 'mahak.product_details', 'visitorProducts' => 'mahak.visitor_products'] as $key => $entity) {
            $max = $this->state->checkpoint($entity);
            foreach ($this->list($objects, $key) as $item) {
                $max = max($max, (int) $this->value($item, 'rowVersion', 0));
            }
            $this->state->saveCheckpoint($entity, $max);
        }
    }
}
