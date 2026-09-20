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
    }
}
