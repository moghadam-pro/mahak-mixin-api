<?php

declare(strict_types=1);

namespace MahakMixin\Mapper;

final class ProductMapper
{
    public function __construct(private readonly int $priceDivisor = 10)
    {
        if ($priceDivisor < 1) {
            throw new \InvalidArgumentException('Price divisor must be at least 1');
        }
    }

    /** @param array<string,mixed> $product @param array<string,mixed> $detail @param array<string,mixed>|null $visitorProduct */
    public function toMixin(array $product, array $detail, ?array $visitorProduct = null): array
    {
        $visitorPrice = (float) $this->read($visitorProduct ?? [], 'price', 0);
        $detailPrice = (float) $this->read($detail, 'price1', 0);
        $price = $visitorPrice > 0 ? $visitorPrice : $detailPrice;
        $stock = max(0, (int) floor((float) $this->read($visitorProduct ?? [], 'count1', 0)));
        $deleted = (bool) $this->read($product, 'deleted', false)
            || (bool) $this->read($detail, 'deleted', false)
            || (bool) $this->read($visitorProduct ?? [], 'deleted', false);

        $productId = $this->read($product, 'productId');
        $productDetailId = $this->read($detail, 'productDetailId');

        return array_filter([
            'name' => $this->normalizeName($this->read($product, 'name', '')),
            'description' => $this->nullableString($this->read($product, 'description')),
            'price' => max(0, (int) round($price / $this->priceDivisor)),
            'barcode' => $this->nullableString($this->read($detail, 'barcode')),
            'available' => !$deleted && $stock > 0,
            'stock' => $stock,
            'stock_type' => $stock > 0 ? 'limited' : 'out_of_stock',
            'product_identifier' => $productDetailId !== null ? (string) $productDetailId : null,
            'weight' => $this->nonNegativeIntOrNull($this->read($product, 'weight')),
            'length' => $this->nonNegativeIntOrNull($this->read($product, 'length')),
            'width' => $this->nonNegativeIntOrNull($this->read($product, 'width')),
            'height' => $this->nonNegativeIntOrNull($this->read($product, 'height')),
            'external_ids' => [
                'source' => 'mahak-mixin-bridge',
                'mahak_product_id' => $productId,
                'mahak_product_detail_id' => $productDetailId,
            ],
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function normalizeName(mixed $value): string
    {
        $normalized = strtr(trim((string) $value), [
            'ي' => 'ی',
            'ى' => 'ی',
            'ك' => 'ک',
        ]);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\(\s+/u', '(', $normalized) ?? $normalized;
        return preg_replace('/\s+\)/u', ')', $normalized) ?? $normalized;
    }

    private function nonNegativeIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return max(0, (int) round((float) $value));
    }

    private function read(array $data, string $key, mixed $default = null): mixed
    {
        foreach ($data as $candidate => $value) {
            if (strcasecmp((string) $candidate, $key) === 0) {
                return $value;
            }
        }
        return $default;
    }
}
