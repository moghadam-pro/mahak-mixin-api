<?php

declare(strict_types=1);

namespace MahakMixin\Sync;

use MahakMixin\Client\MixinClient;
use MahakMixin\Persistence\StateStore;
use RuntimeException;

/** Polls newly-created Mixin orders and writes each one to Mahak exactly once. */
final class OrderSyncService
{
    public const CHECKPOINT_META_KEY = 'mixin.orders.last_seen_id';

    public function __construct(
        private readonly MixinClient $mixin,
        private readonly SingleOrderSyncService $singleOrderSync,
        private readonly StateStore $state,
        private readonly int $pageSize = 100,
        private readonly int $maxPages = 20,
        private readonly int $maxWrites = 20,
        private readonly int $writeDelayMs = 150,
        private readonly string $lockPath = 'var/order-sync.lock',
    ) {
    }

    /** Records the newest current order without writing anything to Mahak. */
    public function baseline(): array
    {
        return $this->withLock(function (): array {
            $rows = $this->orderRows($this->mixin->orders([
                'page' => 1,
                'page_size' => 1,
                'ordering' => '-id',
            ]));
            $lastSeenId = 0;
            foreach ($rows as $row) {
                $lastSeenId = max($lastSeenId, $this->orderId($row));
            }
            $this->state->saveMetadata(self::CHECKPOINT_META_KEY, (string) $lastSeenId);
            return [
                'ok' => true,
                'scope' => 'order_baseline',
                'last_seen_order_id' => $lastSeenId,
                'mahak_writes' => 0,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function run(bool $dryRun = false): array
    {
        $runId = $this->state->startRun('mixin_to_mahak', 'orders');
        try {
            $stats = $this->withLock(fn (): array => $this->runInternal($dryRun));
            $stats['run_id'] = $runId;
            $this->state->finishRun($runId, ($stats['failed'] ?? 0) > 0 ? 'partial' : 'success', $stats);
            return $stats;
        } catch (\Throwable $exception) {
            $this->state->finishRun($runId, 'failed', null, $exception->getMessage());
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function runInternal(bool $dryRun): array
    {
        $checkpoint = $this->state->metadata(self::CHECKPOINT_META_KEY);
        if ($checkpoint === null) {
            throw new RuntimeException('Order baseline is missing. Run sync:orders:baseline once before enabling automatic order sync.');
        }
        $lastSeenId = max(0, (int) $checkpoint);
        $candidates = [];
        $pagesFetched = 0;
        for ($page = 1; $page <= $this->maxPages; $page++) {
            $rows = $this->orderRows($this->mixin->orders([
                'page' => $page,
                'page_size' => $this->pageSize,
                'ordering' => '-id',
            ]));
            $pagesFetched = $page;
            $reachedCheckpoint = false;
            foreach ($rows as $row) {
                $id = $this->orderId($row);
                if ($id <= $lastSeenId) {
                    $reachedCheckpoint = true;
                    continue;
                }
                $candidates[$id] = $row;
            }
            if ($reachedCheckpoint || count($rows) < $this->pageSize) {
                break;
            }
            if ($page === $this->maxPages) {
                throw new RuntimeException("Order polling exceeded max pages {$this->maxPages}; checkpoint was not advanced");
            }
        }
        ksort($candidates, SORT_NUMERIC);

        $stats = [
            'ok' => true,
            'dry_run' => $dryRun,
            'scope' => 'incremental_orders',
            'checkpoint_before' => $lastSeenId,
            'checkpoint_after' => $lastSeenId,
            'pages_fetched' => $pagesFetched,
            'received' => count($candidates),
            'created' => 0,
            'skipped_existing' => 0,
            'deferred' => 0,
            'failed' => 0,
            'preview' => [],
            'errors' => [],
        ];
        $writes = 0;
        foreach ($candidates as $orderId => $_row) {
            if ($writes >= $this->maxWrites) {
                $stats['deferred']++;
                continue;
            }
            try {
                $result = $dryRun
                    ? $this->singleOrderSync->preview((int) $orderId)
                    : $this->singleOrderSync->apply((int) $orderId);
                if (count($stats['preview']) < 20) {
                    $stats['preview'][] = [
                        'order_id' => (int) $orderId,
                        'action' => $result['action'] ?? 'unknown',
                        'source_status' => $result['source_status'] ?? null,
                        'source_payment_status' => $result['source_payment_status'] ?? null,
                    ];
                }
                if (($result['action'] ?? '') === 'skip_existing') {
                    $stats['skipped_existing']++;
                } else {
                    $stats['created']++;
                    $writes++;
                }
                if (!$dryRun) {
                    $lastSeenId = (int) $orderId;
                    $this->state->saveMetadata(self::CHECKPOINT_META_KEY, (string) $lastSeenId);
                    $this->delay();
                }
                $stats['checkpoint_after'] = $lastSeenId;
            } catch (\Throwable $exception) {
                $stats['ok'] = false;
                $stats['failed']++;
                $stats['errors'][] = ['order_id' => (int) $orderId, 'message' => $exception->getMessage()];
                // Do not pass a failed order: a later run must retry it before newer IDs.
                break;
            }
        }
        return $stats;
    }

    /** @return list<array<string,mixed>> */
    public function orderRows(array $response): array
    {
        $rows = $this->value($response, 'data', []);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @param array<string,mixed> $row */
    private function orderId(array $row): int
    {
        $id = $this->value($row, 'id');
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new RuntimeException('Mixin order list contains a row without a positive id');
        }
        return (int) $id;
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
            throw new RuntimeException("Unable to create order sync lock directory: {$directory}");
        }
        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Another order sync is already running');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
