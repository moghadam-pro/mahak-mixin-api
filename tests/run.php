<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use MahakMixin\Mapper\ProductMapper;
use MahakMixin\Persistence\StateStore;
use MahakMixin\Sync\ProductSyncService;
use MahakMixin\Support\TestProductFactory;
use MahakMixin\Version;

final class SkippedTest extends RuntimeException {}

$tests = [];
$tests['reads a valid semantic application version'] = static function (): void {
    assertSame('0.2.3', Version::current());
};
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
    assertSame('mahak-mixin-bridge', $payload['external_ids']['source']);
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
$tests['catalog mode ignores unreliable child deleted flags'] = static function (): void {
    $payload = (new ProductMapper(10))->toMixinCatalog(
        ['ProductId' => 1, 'Name' => 'کالای فعال', 'Deleted' => false],
        ['ProductDetailId' => 2, 'ProductId' => 1, 'Price1' => 12000, 'Count1' => 8, 'Deleted' => true],
        ['ProductDetailId' => 2, 'Count1' => 5, 'Deleted' => true],
    );
    assertSame(true, $payload['available']);
    assertSame(5, $payload['stock']);
};
$tests['catalog mode falls back to detail stock without visitor row'] = static function (): void {
    $payload = (new ProductMapper(10))->toMixinCatalog(
        ['ProductId' => 1, 'Name' => 'کالای فعال', 'Deleted' => false],
        ['ProductDetailId' => 2, 'ProductId' => 1, 'Price1' => 12000, 'Count1' => 8, 'Deleted' => true],
    );
    assertSame(true, $payload['available']);
    assertSame(8, $payload['stock']);
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
$tests['normalizes Persian product names and repeated whitespace'] = static function (): void {
    $payload = (new ProductMapper(10))->toMixin(
        ['ProductId' => 12, 'Name' => "  سيم  سيم كالا\nتست ( 126 عددي )  "],
        ['ProductDetailId' => 34, 'ProductId' => 12, 'Price1' => 10000],
        ['Count1' => 1],
    );
    assertSame('سیم سیم کالا تست (126 عددی)', $payload['name']);
};
$tests['detects supported image signatures'] = static function (): void {
    $service = (new ReflectionClass(ProductSyncService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ProductSyncService::class, 'detectImageMime');
    $method->setAccessible(true);
    assertSame('image/jpeg', $method->invoke($service, "\xFF\xD8\xFFexample"));
    assertSame('image/png', $method->invoke($service, "\x89PNG\r\n\x1A\nexample"));
    assertSame(null, $method->invoke($service, 'not-an-image'));
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
        $store->deleteMapping('product', '10');
        assertSame(null, $store->mapping('product', '10'));
        $runId = $store->startRun('mahak_to_mixin', 'products');
        assertSame(true, $runId > 0);
        $store->finishRun($runId, 'success', ['created' => 1]);
        assertSame('Test', $store->snapshots('mahak.products')[0]['Name']);
        $store->resetProductSyncState();
        assertSame(0, $store->checkpoint('mahak.products'));
        assertSame(0, $store->mappingCount('product'));
        assertSame([], $store->snapshots('mahak.products'));
    } finally {
        @unlink($path);
    }
};
$tests['creates a safe and recognizable Mixin test product'] = static function (): void {
    $payload = TestProductFactory::make('unit-test');
    assertSame(false, $payload['available']);
    assertSame(0, $payload['stock']);
    assertSame('out_of_stock', $payload['stock_type']);
    assertSame(true, TestProductFactory::isMarked($payload));
};
$tests['does not mark ordinary products as Bridge tests'] = static function (): void {
    assertSame(false, TestProductFactory::isMarked([
        'name' => 'محصول واقعی',
        'product_identifier' => '123',
    ]));
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
