<?php

declare(strict_types=1);

namespace MahakMixin\Sync;

use MahakMixin\Client\MahakClient;
use MahakMixin\Client\MixinClient;
use MahakMixin\Mapper\OrderMapper;
use MahakMixin\Persistence\StateStore;
use RuntimeException;

final class SingleOrderSyncService
{
    public function __construct(
        private readonly MixinClient $mixin,
        private readonly MahakClient $mahak,
        private readonly StateStore $state,
        private readonly OrderMapper $mapper,
    ) {
    }

    /** @return array<string,mixed> */
    public function preview(int $orderId): array
    {
        return $this->build($orderId, true);
    }

    /** @return array<string,mixed> */
    public function apply(int $orderId): array
    {
        return $this->build($orderId, false);
    }

    /** @return array<string,mixed> */
    private function build(int $orderId, bool $dryRun): array
    {
        if ($orderId < 1) {
            throw new RuntimeException('Mixin order id must be a positive integer');
        }
        $existing = $this->state->mapping('order', (string) $orderId);
        if ($existing !== null) {
            return [
                'ok' => true,
                'dry_run' => $dryRun,
                'action' => 'skip_existing',
                'source_order_id' => $orderId,
                'mahak_order_id' => $existing,
            ];
        }

        $response = $this->mixin->order($orderId);
        $order = $this->unwrapData($response);
        $payload = $this->mapper->toMahak($order, $this->state->reverseMappings('product'));
        $financialCheck = $this->mapper->financialCheck($order);
        $result = [
            'ok' => true,
            'dry_run' => $dryRun,
            'action' => 'create',
            'source_order_id' => $orderId,
            'source_status' => $order['status'] ?? null,
            'source_payment_status' => $order['payment_status'] ?? null,
            'financial_check' => $financialCheck,
            'payload' => $payload,
        ];
        if ($dryRun) {
            return $result;
        }
        if (($financialCheck['balanced'] ?? false) !== true) {
            throw new RuntimeException('Order totals do not balance after Mixin toman to Mahak rial mapping; apply was blocked');
        }

        $saved = $this->mahak->saveAllData($payload);
        $targetId = $this->findSavedOrderId($saved, $this->mapper->orderClientId($orderId));
        $this->state->saveMapping('order', (string) $orderId, $targetId === null
            ? 'client:' . $this->mapper->orderClientId($orderId)
            : (string) $targetId);
        $result['response'] = $saved;
        $result['mahak_order_id'] = $targetId;
        return $result;
    }

    /** @return array<string,mixed> */
    private function unwrapData(array $response): array
    {
        $data = $response['data'] ?? $response['Data'] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException('Mixin order response does not contain an object in data');
        }
        return $data;
    }

    private function findSavedOrderId(array $response, int $clientId): int|string|null
    {
        $data = $response['Data'] ?? $response['data'] ?? [];
        $objects = is_array($data) ? ($data['Objects'] ?? $data['objects'] ?? $data) : [];
        $orders = is_array($objects) ? ($objects['Orders'] ?? $objects['orders'] ?? []) : [];
        if (!is_array($orders)) {
            return null;
        }
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $candidateClientId = $order['OrderClientId'] ?? $order['orderClientId'] ?? null;
            if ((string) $candidateClientId !== (string) $clientId) {
                continue;
            }
            $id = $order['OrderId'] ?? $order['orderId'] ?? null;
            if (is_int($id) || is_string($id)) {
                return $id;
            }
        }
        return null;
    }
}
