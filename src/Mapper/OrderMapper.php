<?php

declare(strict_types=1);

namespace MahakMixin\Mapper;

use InvalidArgumentException;
use RuntimeException;

final class OrderMapper
{
    public function __construct(
        private readonly int $visitorId,
        private readonly int $personId,
        private readonly int $storeId = 1,
        private readonly int $orderType = 201,
        private readonly int $settlementType = 1,
        private readonly int $moneyMultiplier = 10,
        private readonly int $carrierType = 2,
        private readonly int $carrierId = 0,
        private readonly bool $carryingAsExpense = false,
    ) {
        foreach (['visitorId' => $visitorId, 'personId' => $personId, 'storeId' => $storeId, 'carrierId' => $carrierId] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("{$name} must be a positive integer");
            }
        }
        if ($carrierType < 1) {
            throw new InvalidArgumentException('carrierType must be a positive integer');
        }
        if ($moneyMultiplier < 1) {
            throw new InvalidArgumentException('moneyMultiplier must be at least 1');
        }
    }

    /**
     * @param array<string,mixed> $order
     * @param array<int|string,string> $productMappings Mixin product id => Mahak ProductDetail id
     * @return array{orders:list<array<string,mixed>>,orderDetails:list<array<string,mixed>>}
     */
    public function toMahak(array $order, array $productMappings): array
    {
        $orderId = $this->positiveInt($this->value($order, 'id'), 'Mixin order id');
        $orderClientId = $this->clientId('order', $orderId);
        $items = $this->value($order, 'items', []);
        if (!is_array($items) || $items === []) {
            throw new RuntimeException("Mixin order {$orderId} has no items");
        }

        $details = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException("Mixin order {$orderId} contains an invalid item");
            }
            $mixinProductId = $this->positiveInt($this->value($item, 'product_id'), 'Mixin product id');
            $mahakDetailId = $productMappings[(string) $mixinProductId] ?? null;
            if ($mahakDetailId === null || filter_var($mahakDetailId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new RuntimeException("Mixin product {$mixinProductId} is not mapped to a Mahak ProductDetail");
            }
            $quantity = (float) $this->value($item, 'quantity', 0);
            if ($quantity <= 0) {
                throw new RuntimeException("Mixin order {$orderId} has a non-positive quantity at row " . ($index + 1));
            }
            $price = max(0, (float) $this->value($item, 'price', 0));
            $details[] = [
                'orderDetailClientId' => $this->clientId('order-detail', $this->positiveInt($this->value($item, 'id'), 'Mixin order item id')),
                'orderClientId' => $orderClientId,
                'itemType' => 1,
                'productDetailId' => (int) $mahakDetailId,
                'price' => $this->money($price),
                'count1' => $quantity,
                'count2' => 0,
                'discount' => 0,
                'discountType' => 0,
                'taxPercent' => 0,
                'chargePercent' => 0,
                'storeId' => $this->storeId,
                'rowId' => $index + 1,
                'description' => $this->lineDescription($item),
                'deleted' => false,
            ];
        }

        $shippingPrice = max(0, (float) $this->value($order, 'shipping_price', 0));
        $discount = max(0, (float) $this->value($order, 'discount_amount', 0));
        $creationDate = trim((string) $this->value($order, 'creation_date', ''));
        if ($creationDate === '') {
            throw new RuntimeException("Mixin order {$orderId} has no creation_date");
        }

        return [
            'orders' => [[
                'orderClientId' => $orderClientId,
                'personId' => $this->personId,
                'visitorId' => $this->visitorId,
                'orderType' => $this->orderType,
                'orderDate' => $creationDate,
                'deliveryDate' => $creationDate,
                'discount' => $this->money($discount),
                'discountType' => 0,
                'sendCost' => $this->money($shippingPrice),
                'otherCost' => 0,
                'settlementType' => $this->settlementType,
                'immediate' => false,
                'description' => $this->orderDescription($order),
                'shippingAddress' => $this->shippingAddress($order),
                'carrierType' => $this->carrierType,
                'carrierID' => $this->carrierId,
                'carryingAsExpense' => $this->carryingAsExpense,
                'deleted' => false,
            ]],
            'orderDetails' => $details,
        ];
    }

    public function orderClientId(int $mixinOrderId): int
    {
        return $this->clientId('order', $mixinOrderId);
    }

    /** @param array<string,mixed> $order @return array<string,int|bool> */
    public function financialCheck(array $order): array
    {
        $subtotal = 0.0;
        $items = $this->value($order, 'items', []);
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $subtotal += max(0, (float) $this->value($item, 'price', 0))
                        * max(0, (float) $this->value($item, 'quantity', 0));
                }
            }
        }
        $discount = max(0, (float) $this->value($order, 'discount_amount', 0));
        $shipping = max(0, (float) $this->value($order, 'shipping_price', 0));
        $sourceFinal = max(0, (float) $this->value($order, 'final_price', 0));
        $mappedFinal = max(0, $subtotal - $discount + $shipping);
        $difference = $mappedFinal - $sourceFinal;
        return [
            'source_final_rial' => $this->money($sourceFinal),
            'mapped_final_rial' => $this->money($mappedFinal),
            'difference_rial' => (int) round($difference * $this->moneyMultiplier),
            'balanced' => abs($difference) < 0.00001,
        ];
    }

    private function money(float $amount): int
    {
        return max(0, (int) round($amount * $this->moneyMultiplier));
    }

    private function clientId(string $entity, int $id): int
    {
        $prefix = match ($entity) {
            'order' => 8_000_000_000_000_000,
            'order-detail' => 8_100_000_000_000_000,
            default => throw new InvalidArgumentException("Unsupported client id entity: {$entity}"),
        };
        if ($id > 99_999_999_999_999) {
            throw new RuntimeException("{$entity} id is too large for Mahak client id namespace");
        }
        return $prefix + $id;
    }

    /** @param array<string,mixed> $order */
    private function orderDescription(array $order): string
    {
        $parts = [
            'سفارش میکسین #' . $this->value($order, 'id', ''),
            'وضعیت: ' . $this->value($order, 'status_display', $this->value($order, 'status', 'نامشخص')),
            'پرداخت: ' . $this->value($order, 'payment_status_display', $this->value($order, 'payment_status', 'نامشخص')),
        ];
        $note = trim((string) $this->value($order, 'customer_note', ''));
        if ($note !== '') {
            $parts[] = 'یادداشت مشتری: ' . $note;
        }
        return implode(' | ', $parts);
    }

    /** @param array<string,mixed> $item */
    private function lineDescription(array $item): string
    {
        $name = trim((string) $this->value($item, 'product_name', ''));
        $variant = trim((string) $this->value($item, 'variant_name', ''));
        return $variant === '' ? $name : "{$name} - {$variant}";
    }

    /** @param array<string,mixed> $order */
    private function shippingAddress(array $order): string
    {
        $address = [
            'title' => 'آدرس سفارش میکسین',
            'description' => trim(implode('، ', array_filter([
                (string) $this->value($order, 'shipping_province', ''),
                (string) $this->value($order, 'shipping_city', ''),
            ], static fn (string $value): bool => trim($value) !== ''))),
            'address' => (string) $this->value($order, 'shipping_address', ''),
            'postalcode' => (string) $this->value($order, 'shipping_zip_code', ''),
            'tel' => (string) $this->value($order, 'shipping_phone_number', ''),
            'mobile' => (string) $this->value($order, 'shipping_phone_number', ''),
            'cityid' => 0,
            'latitude' => 0,
            'longitude' => 0,
        ];
        return json_encode($address, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function positiveInt(mixed $value, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new RuntimeException("{$label} must be a positive integer");
        }
        return (int) $value;
    }

    /** @param array<string,mixed> $data */
    private function value(array $data, string $key, mixed $default = null): mixed
    {
        foreach ($data as $candidate => $value) {
            if (strcasecmp((string) $candidate, $key) === 0) {
                return $value;
            }
        }
        return $default;
    }
}
