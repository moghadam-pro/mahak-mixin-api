<?php

declare(strict_types=1);

namespace MahakMixin\Support;

final class TestProductFactory
{
    public const NAME_PREFIX = '[Bridge Test]';
    public const IDENTIFIER_PREFIX = 'bridge-test-';

    /** @return array<string,mixed> */
    public static function make(?string $runId = null, ?int $mainCategoryId = null): array
    {
        $runId ??= gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));

        $payload = [
            'name' => self::NAME_PREFIX . ' اتصال Mixin - قابل حذف',
            'description' => 'رکورد کنترل‌شده برای آزمایش API؛ قابل فروش نیست.',
            'price' => 1000,
            'available' => false,
            'stock' => 0,
            'stock_type' => 'out_of_stock',
            'product_identifier' => self::IDENTIFIER_PREFIX . $runId,
            'external_ids' => [
                'source' => 'mahak-mixin-bridge',
                'test_record' => true,
                'run_id' => $runId,
            ],
        ];

        if ($mainCategoryId !== null && $mainCategoryId > 0) {
            $payload['main_category_id'] = $mainCategoryId;
        }

        return $payload;
    }

    /** @param array<string,mixed> $product */
    public static function isMarked(array $product): bool
    {
        $name = (string) self::read($product, 'name', '');
        $identifier = (string) self::read($product, 'product_identifier', '');
        $externalIds = self::read($product, 'external_ids', []);
        $source = is_array($externalIds) ? self::read($externalIds, 'source') : null;
        $testRecord = is_array($externalIds) ? self::read($externalIds, 'test_record', false) : false;

        return str_starts_with($name, self::NAME_PREFIX)
            && str_starts_with($identifier, self::IDENTIFIER_PREFIX)
            && $source === 'mahak-mixin-bridge'
            && $testRecord === true;
    }

    private static function read(array $data, string $key, mixed $default = null): mixed
    {
        foreach ($data as $candidate => $value) {
            if (strcasecmp((string) $candidate, $key) === 0) {
                return $value;
            }
        }
        return $default;
    }
}
