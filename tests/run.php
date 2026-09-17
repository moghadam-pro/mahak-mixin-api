<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use MahakMixin\Mapper\ProductMapper;
use MahakMixin\Persistence\StateStore;
use MahakMixin\Sync\ProductSyncService;

final class SkippedTest extends RuntimeException {}

$tests = [];
$tests['maps Mahak product to Mixin and converts rial to toman'] = static function (): void {
    $payload = (new ProductMapper(10))->toMixin(
        ['productId' => 12, 'name' => 'کالای تست', 'description' => 'توضیح', 'weight' => 500],
        ['productDetailId' => 34, 'productId' => 12, 'price1' => 125000, 'barcode' => 'ABC'],
        ['productDetailId' => 34, 'price' => 130000, 'count1' => 7],
    );
    assertSame(13000, $payload['price']);
    assertSame(7, $payload['stock']);
    assertSame('limited', $payload['stock_type']);
    assertSame(34, $payload['external_ids']['mahak_product_detail_id']);
};
$tests['falls back to detail price when visitor price is zero and trims name'] = static function (): void {
    $payload = (new ProductMapper(10))->toMixin(
        ['ProductId' => 12, 'Name' => '  کالای تست  '],
        ['ProductDetailId' => 34, 'ProductId' => 12, 'Price1' => 461100],
        ['ProductDetailId' => 34, 'Price' => 0, 'Count1' => 5],
    );
    assertSame('کالای تست', $payload['name']);
    assertSame(46110, $payload['price']);
};
$tests['marks unavailable inventory'] = static function (): void {
    $payload = (new ProductMapper())->toMixin(
        ['productId' => 1, 'name' => 'test'],
        ['productDetailId' => 2, 'productId' => 1, 'price1' => 1000],
        ['count1' => -2],
    );
    assertSame(false, $payload['available']);
    assertSame(0, $payload['stock']);
    assertSame('out_of_stock', $payload['stock_type']);
};
$tests['accepts PascalCase Mahak responses'] = static function (): void {
    $payload = (new ProductMapper(10))->toMixin(
        ['ProductId' => 9, 'Name' => 'Pascal', 'Deleted' => false],
        ['ProductDetailId' => 10, 'ProductId' => 9, 'Price1' => 20000],
        ['Price' => 25000, 'Count1' => 2],
    );
    assertSame('Pascal', $payload['name']);
    assertSame(2500, $payload['price']);
    assertSame('10', $payload['product_identifier']);
};
$tests['normalizes numeric Mahak detail IDs as strings'] = static function (): void {
    $service = (new ReflectionClass(ProductSyncService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ProductSyncService::class, 'affectedDetailIds');
    $method->setAccessible(true);
    $ids = $method->invoke(
        $service,
        [['ProductId' => 9]],
        [['ProductDetailId' => 10, 'ProductId' => 9]],
        [],
        ['10' => ['ProductDetailId' => 10, 'ProductId' => 9]],
    );
    assertSame(['10'], $ids);
};
$tests['persists checkpoints mappings and snapshots'] = static function (): void {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new SkippedTest('pdo_sqlite is not installed');
    }
    $path = tempnam(sys_get_temp_dir(), 'bridge-test-');
    if ($path === false) {
        throw new RuntimeException('Unable to create temporary database');
    }
    try {
        $store = new StateStore($path);
        $store->saveCheckpoint('mahak.products', 42);
        $store->saveMapping('product', '10', '20');
        $store->saveSnapshot('mahak.products', '10', ['ProductId' => 10, 'Name' => 'Test']);
        assertSame(42, $store->checkpoint('mahak.products'));
        assertSame('20', $store->mapping('product', '10'));
        assertSame('Test', $store->snapshots('mahak.products')[0]['Name']);
    } finally {
        @unlink($path);
    }
};

$failed = 0;
$skipped = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS {$name}\n";
    } catch (SkippedTest $exception) {
        $skipped++;
        echo "SKIP {$name}: {$exception->getMessage()}\n";
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
}
echo sprintf("%d test(s), %d skipped, %d failure(s)\n", count($tests), $skipped, $failed);
exit($failed === 0 ? 0 : 1);

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
