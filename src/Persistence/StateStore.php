<?php

declare(strict_types=1);

namespace MahakMixin\Persistence;

use PDO;

final class StateStore
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create state directory: {$directory}");
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->migrate();
    }

    public function checkpoint(string $entity): int
    {
        $statement = $this->pdo->prepare('SELECT row_version FROM sync_checkpoints WHERE entity = :entity');
        $statement->execute(['entity' => $entity]);
        $value = $statement->fetchColumn();
        return $value === false ? 0 : (int) $value;
    }

    public function saveCheckpoint(string $entity, int $rowVersion): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sync_checkpoints(entity, row_version, updated_at) VALUES(:entity, :version, CURRENT_TIMESTAMP)
             ON CONFLICT(entity) DO UPDATE SET row_version = excluded.row_version, updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute(['entity' => $entity, 'version' => $rowVersion]);
    }

    public function mapping(string $entity, string $sourceId): ?string
    {
        $statement = $this->pdo->prepare('SELECT target_id FROM entity_mappings WHERE entity = :entity AND source_id = :source_id');
        $statement->execute(['entity' => $entity, 'source_id' => $sourceId]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function saveMapping(string $entity, string $sourceId, string $targetId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO entity_mappings(entity, source_id, target_id, updated_at) VALUES(:entity, :source_id, :target_id, CURRENT_TIMESTAMP)
             ON CONFLICT(entity, source_id) DO UPDATE SET target_id = excluded.target_id, updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute(['entity' => $entity, 'source_id' => $sourceId, 'target_id' => $targetId]);
    }

    public function deleteMapping(string $entity, string $sourceId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM entity_mappings WHERE entity = :entity AND source_id = :source_id');
        $statement->execute(['entity' => $entity, 'source_id' => $sourceId]);
    }

    /** @return array<string,string> source id => target id */
    public function mappings(string $entity): array
    {
        $statement = $this->pdo->prepare(
            'SELECT source_id, target_id FROM entity_mappings WHERE entity = :entity ORDER BY source_id'
        );
        $statement->execute(['entity' => $entity]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(string) $row['source_id']] = (string) $row['target_id'];
        }
        return $result;
    }

    /** @return array<int|string,string> target id => source id */
    public function reverseMappings(string $entity): array
    {
        $result = [];
        foreach ($this->mappings($entity) as $sourceId => $targetId) {
            $result[$targetId] = (string) $sourceId;
        }
        return $result;
    }

    public function metadata(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM sync_metadata WHERE key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function saveMetadata(string $key, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sync_metadata(key, value, updated_at) VALUES(:key, :value, CURRENT_TIMESTAMP)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute(['key' => $key, 'value' => $value]);
    }

    /** Clears local product sync state after a confirmed full target purge. */
    public function resetProductSyncState(): void
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('DELETE FROM entity_mappings WHERE entity = :entity');
            $statement->execute(['entity' => 'product']);
            $entities = [
                'mahak.products',
                'mahak.product_details',
                'mahak.visitor_products',
                'mahak.pictures',
                'mahak.photo_galleries',
            ];
            $placeholders = implode(',', array_fill(0, count($entities), '?'));
            $this->pdo->prepare("DELETE FROM entity_snapshots WHERE entity IN ({$placeholders})")->execute($entities);
            $this->pdo->prepare("DELETE FROM sync_checkpoints WHERE entity IN ({$placeholders})")->execute($entities);
            $this->pdo->prepare('DELETE FROM sync_metadata WHERE key = :key')->execute([
                'key' => 'mahak.products.last_reconcile_at',
            ]);
            $this->pdo->exec("DELETE FROM sync_metadata WHERE key LIKE 'mahak.product.payload_hash.%'");
            $this->pdo->exec("DELETE FROM sync_metadata WHERE key LIKE 'mahak.product.payload_json.%'");
            $this->pdo->exec("DELETE FROM sync_metadata WHERE key LIKE 'mahak.product.image_source_hash.%'");
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function startRun(string $direction, string $entity): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sync_runs(direction, entity, status) VALUES(:direction, :entity, :status)'
        );
        $statement->execute(['direction' => $direction, 'entity' => $entity, 'status' => 'running']);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed>|null $stats */
    public function finishRun(int $runId, string $status, ?array $stats = null, ?string $error = null): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE sync_runs SET status = :status, stats_json = :stats, error = :error, finished_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'id' => $runId,
            'status' => $status,
            'stats' => $stats === null ? null : json_encode($stats, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error' => $error,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function recentRuns(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->query(
            'SELECT id, direction, entity, status, stats_json, error, started_at, finished_at
             FROM sync_runs ORDER BY id DESC LIMIT ' . $limit
        );
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $decoded = isset($row['stats_json']) ? json_decode((string) $row['stats_json'], true) : null;
            $row['stats'] = is_array($decoded) ? $decoded : null;
            unset($row['stats_json']);
        }
        return $rows;
    }

    /**
     * Dashboard counters are derived only from completed, non-dry-run records.
     * A counter is marked partial when older rows in the requested window do
     * not contain that field (older Bridge releases did not record it).
     *
     * @return array<string,array<string,array{value:int,coverage:string}>>
     */
    public function dashboardMetrics(): array
    {
        $windows = ['day' => '-1 day', 'week' => '-7 days', 'month' => '-30 days'];
        $definitions = [
            'product_writes' => ['created', 'updated', 'recreated'],
            'price_changes' => ['price_changed'],
            'image_changes' => ['images_uploaded', 'images_replaced'],
            'invoices' => ['created'],
        ];
        $result = [];
        foreach ($windows as $window => $modifier) {
            $statement = $this->pdo->prepare(
                "SELECT entity, stats_json FROM sync_runs
                 WHERE finished_at IS NOT NULL
                   AND status IN ('success', 'partial')
                   AND started_at >= datetime('now', :modifier)"
            );
            $statement->execute(['modifier' => $modifier]);
            $rows = $statement->fetchAll();
            foreach ($definitions as $metric => $keys) {
                $value = 0;
                $eligible = 0;
                $covered = 0;
                foreach ($rows as $row) {
                    $stats = isset($row['stats_json']) ? json_decode((string) $row['stats_json'], true) : null;
                    if (!is_array($stats) || (bool) ($stats['dry_run'] ?? false)) {
                        continue;
                    }
                    $entity = (string) ($row['entity'] ?? '');
                    $entities = match ($metric) {
                        'invoices' => ['orders'],
                        'image_changes' => ['products', 'full_catalog'],
                        'price_changes' => ['products', 'full_catalog', 'mapped_product_reconcile'],
                        default => ['products', 'full_catalog', 'mapped_product_reconcile'],
                    };
                    if (!in_array($entity, $entities, true)) {
                        continue;
                    }
                    $eligible++;
                    $rowCovered = false;
                    foreach ($keys as $key) {
                        if (array_key_exists($key, $stats)) {
                            $rowCovered = true;
                            $value += max(0, (int) $stats[$key]);
                        }
                    }
                    if ($rowCovered) {
                        if ($metric !== 'price_changes' || (int) ($stats['price_tracking_unknown'] ?? 0) === 0) {
                            $covered++;
                        }
                    }
                }
                $result[$metric][$window] = [
                    'value' => $value,
                    'coverage' => $covered === 0 ? 'none' : ($covered === $eligible ? 'full' : 'partial'),
                ];
            }
        }
        return $result;
    }

    /** @return array<string,int> */
    public function lifetimeWriteTotals(): array
    {
        $totals = ['mahak_to_mixin' => 0, 'mixin_to_mahak' => 0];
        $statement = $this->pdo->query(
            "SELECT direction, entity, stats_json FROM sync_runs
             WHERE finished_at IS NOT NULL AND status IN ('success', 'partial')"
        );
        while ($row = $statement->fetch()) {
            $stats = isset($row['stats_json']) ? json_decode((string) $row['stats_json'], true) : null;
            if (!is_array($stats) || (bool) ($stats['dry_run'] ?? false)) {
                continue;
            }
            $direction = (string) ($row['direction'] ?? '');
            $entity = (string) ($row['entity'] ?? '');
            if ($direction === 'mahak_to_mixin') {
                $totals[$direction] += max(0, (int) ($stats['created'] ?? 0));
                $totals[$direction] += max(0, (int) ($stats['updated'] ?? 0));
                $totals[$direction] += max(0, (int) ($stats['recreated'] ?? 0));
                $totals[$direction] += max(0, (int) ($stats['images_uploaded'] ?? 0));
                $totals[$direction] += max(0, (int) ($stats['images_replaced'] ?? 0));
            } elseif ($direction === 'mixin_to_mahak' && $entity === 'orders') {
                $totals[$direction] += max(0, (int) ($stats['created'] ?? 0));
            }
        }
        return $totals;
    }

    /** @return list<array<string,mixed>> */
    public function checkpoints(): array
    {
        return $this->pdo->query(
            'SELECT entity, row_version, updated_at FROM sync_checkpoints ORDER BY entity'
        )->fetchAll();
    }

    public function mappingCount(string $entity): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM entity_mappings WHERE entity = :entity');
        $statement->execute(['entity' => $entity]);
        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function snapshots(string $entity): array
    {
        $statement = $this->pdo->prepare('SELECT payload_json FROM entity_snapshots WHERE entity = :entity');
        $statement->execute(['entity' => $entity]);
        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $decoded = json_decode((string) $row['payload_json'], true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }
        return $items;
    }

    /** @param array<string,mixed> $payload */
    public function saveSnapshot(string $entity, string $sourceId, array $payload): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO entity_snapshots(entity, source_id, payload_json, updated_at) VALUES(:entity, :source_id, :payload, CURRENT_TIMESTAMP)
             ON CONFLICT(entity, source_id) DO UPDATE SET payload_json = excluded.payload_json, updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'entity' => $entity,
            'source_id' => $sourceId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS sync_checkpoints (
            entity TEXT PRIMARY KEY,
            row_version INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS entity_mappings (
            entity TEXT NOT NULL,
            source_id TEXT NOT NULL,
            target_id TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(entity, source_id),
            UNIQUE(entity, target_id)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS entity_snapshots (
            entity TEXT NOT NULL,
            source_id TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(entity, source_id)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS sync_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            direction TEXT NOT NULL,
            entity TEXT NOT NULL,
            status TEXT NOT NULL,
            stats_json TEXT,
            error TEXT,
            started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at TEXT
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS sync_metadata (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS sync_runs_started_at ON sync_runs(started_at)');
    }
}
