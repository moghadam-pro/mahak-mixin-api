<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use MahakMixin\Mapper\ProductMapper;
use MahakMixin\Mapper\OrderMapper;
use MahakMixin\Persistence\StateStore;
use MahakMixin\Sync\ProductSyncService;
use MahakMixin\Sync\MappedProductReconcileService;
use MahakMixin\Sync\SingleOrderSyncService;
use MahakMixin\Sync\OrderSyncService;
use MahakMixin\Support\TestProductFactory;
use MahakMixin\Version;

final class SkippedTest extends RuntimeException {}

$tests = [];
$tests['reads a valid semantic application version'] = static function (): void {
    assertSame('0.8.0', Version::current());
};
$tests['normalizes numeric SQLite mapping keys before reconciliation'] = static function (): void {
    assertSame('11667880', MappedProductReconcileService::normalizeSourceId(11667880));
    assertSame('11667880', MappedProductReconcileService::normalizeSourceId('11667880'));
};
$tests['extracts Mixin order rows for the incremental order worker'] = static function (): void {
    $service = (new ReflectionClass(OrderSyncService::class))->newInstanceWithoutConstructor();
    assertSame([['id' => 174]], $service->orderRows(['status' => 'success', 'data' => [['id' => 174]]]));
};
$tests['maps a Mixin cash order to a Mahak sales invoice in rials'] = static function (): void {
    $payload = (new OrderMapper(48824, 100, 1, 201, 1, 10, 2, 50, false))->toMahak([
        'id' => 123,
        'status' => 'paid',
        'status_display' => 'پرداخت‌شده',
        'payment_status' => 'paid',
        'payment_status_display' => 'موفق',
        'creation_date' => '2026-09-28T12:30:00+03:30',
        'shipping_price' => 15_000,
        'discount_amount' => 2_000,
        'final_price' => 53_000,
        'shipping_province' => 'تهران',
        'shipping_city' => 'تهران',
        'shipping_address' => 'خیابان نمونه',
        'shipping_zip_code' => '1234567890',
        'shipping_phone_number' => '09120000000',
        'items' => [[
            'id' => 456,
            'product_id' => 971,
            'product_name' => 'کالای نمونه',
            'quantity' => 2,
            'price' => 20_000,
        ]],
    ], ['971' => '11667880']);
    $order = $payload['orders'][0];
    $detail = $payload['orderDetails'][0];
    assertSame(201, $order['orderType']);
    assertSame(1, $order['settlementType']);
    assertSame(2, $order['carrierType']);
    assertSame(50, $order['carrierID']);
    assertSame(false, $order['carryingAsExpense']);
    assertSame(150_000, $order['sendCost']);
    assertSame(20_000, $order['discount']);
    assertSame(1, $detail['storeId']);
    assertSame(200_000, $detail['price']);
    assertSame(2.0, $detail['count1']);
    assertSame(11667880, $detail['productDetailId']);
    $check = (new OrderMapper(48824, 100, 1, 201, 1, 10, 2, 50, false))->financialCheck([
        'final_price' => 53_000,
        'shipping_price' => 15_000,
        'discount_amount' => 2_000,
        'items' => [['quantity' => 2, 'price' => 20_000]],
    ]);
    assertSame(true, $check['balanced']);
    assertSame(530_000, $check['source_final_rial']);
};
$tests['blocks an order item without a product mapping'] = static function (): void {
    $mapper = new OrderMapper(48824, 100, 1, 201, 1, 10, 2, 50, false);
    assertThrows(
        static fn (): array => $mapper->toMahak([
            'id' => 123,
            'creation_date' => '2026-09-28T12:30:00+03:30',
            'items' => [['id' => 456, 'product_id' => 999, 'quantity' => 1, 'price' => 100]],
        ], []),
        RuntimeException::class,
    );
};
$tests['extracts the Mahak order id from SaveAllData results'] = static function (): void {
    $id = SingleOrderSyncService::savedOrderId([
        'Result' => true,
        'Data' => ['Objects' => ['Orders' => ['Results' => [[
            'Result' => true,
            'EntityId' => 301831562,
            'EntityClientId' => 8000000000000173,
        ]]]]],
    ], 8000000000000173);
    assertSame(301831562, $id);
};
$tests['uses Mahak second sell price and converts rial to toman'] = static function (): void {
    $payload = (new ProductMapper(10))->toMixin(
        ['productId' => 12, 'name' => 'کالای تست', 'description' => 'توضیح', 'weight' => 500],
        ['productDetailId' => 34, 'productId' => 12, 'price1' => 125000, 'price2' => 135000, 'barcode' => 'ABC'],
        ['productDetailId' => 34, 'price' => 130000, 'count1' => 7],
    );
    assertSame(13500, $payload['price']);
    assertSame(7, $payload['stock']);
    assertSame('limited', $payload['stock_type']);
    assertSame(34, $payload['external_ids']['mahak_product_detail_id']);
    assertSame('mahak-mixin-bridge', $payload['external_ids']['source']);
};
$tests['keeps product 1681 on Mahak Price2 instead of Price1'] = static function (): void {
    $payload = (new ProductMapper(10))->toMixinCatalog(
        ['ProductId' => 10267346, 'ProductCode' => 1681, 'Name' => 'کاپیتانو خمیر دندان سفید کننده'],
        ['ProductDetailId' => 11667880, 'ProductId' => 10267346, 'Price1' => 5_379_000, 'Price2' => 200],
        ['ProductDetailId' => 11667880, 'Count1' => 26],
    );
    assertSame(20, $payload['price']);
    assertSame(26, $payload['stock']);
};
$tests['reconciliation detects a stale Mixin price even when local state may be current'] = static function (): void {
    $payload = [
        'name' => 'کالای تست', 'price' => 20, 'available' => true, 'stock' => 26,
        'stock_type' => 'limited', 'product_identifier' => '11667880',
        'weight' => 0, 'length' => 0, 'width' => 0, 'height' => 0,
        'external_ids' => ['source' => 'mahak-mixin-bridge', 'mahak_product_detail_id' => 11667880],
        'main_category_id' => 28,
    ];
    $target = $payload;
    $target['stock_number'] = $target['stock'];
    unset($target['stock']);
    $target['stock_type'] = ['value' => 'limited', 'label' => 'محدود'];
    $target['main_category'] = ['id' => 28, 'name' => 'بدون دسته‌بندی'];
    unset($target['main_category_id']);
    assertSame(true, MappedProductReconcileService::targetMatchesPayload($target, $payload));
    $target['price'] = 537900;
    assertSame(false, MappedProductReconcileService::targetMatchesPayload($target, $payload));
};
$tests['changes the image fingerprint when Mahak replaces a picture'] = static function (): void {
    $first = ProductSyncService::imageSourceHash([
        'picture_id' => 10, 'gallery_id' => 20, 'url' => '/old.jpg', 'file_size' => 100,
    ]);
    $second = ProductSyncService::imageSourceHash([
        'picture_id' => 11, 'gallery_id' => 21, 'url' => '/new.jpg', 'file_size' => 120,
    ]);
    assertSame(false, hash_equals($first, $second));
};
$tests['marks a product affected when its Mahak picture changes'] = static function (): void {
    $service = (new ReflectionClass(ProductSyncService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ProductSyncService::class, 'imageAffectedProductIds');
    $method->setAccessible(true);
    $ids = $method->invoke(
        $service,
        [['PictureId' => 55]],
        [],
        [['PhotoGalleryId' => 70, 'PictureId' => 55, 'ItemCode' => 12]],
    );
    assertSame(['12' => true], $ids);
};
$tests['falls back to first sell price when second sell price is zero and trims name'] = static function (): void {
    $payload = (new ProductMapper(10))->toMixin(
        ['ProductId' => 12, 'Name' => '  کالای تست  '],
        ['ProductDetailId' => 34, 'ProductId' => 12, 'Price1' => 461100, 'Price2' => 0],
        ['ProductDetailId' => 34, 'Price' => 999999, 'Count1' => 5],
    );
    assertSame('کالای تست', $payload['name']);
    assertSame(46110, $payload['price']);
};
$tests['keeps zero price when both Mahak sell prices are zero'] = static function (): void {
    $payload = (new ProductMapper(10))->toMixin(
        ['ProductId' => 12, 'Name' => 'کالای بدون قیمت'],
        ['ProductDetailId' => 34, 'ProductId' => 12, 'Price1' => 0, 'Price2' => 0],
        ['ProductDetailId' => 34, 'Price' => 750000, 'Count1' => 5],
    );
    assertSame(0, $payload['price']);
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
        ['ProductDetailId' => 10, 'ProductId' => 9, 'Price1' => 20000, 'Price2' => 22000],
        ['Price' => 25000, 'Count1' => 2],
    );
    assertSame('Pascal', $payload['name']);
    assertSame(2200, $payload['price']);
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
$tests['matches a cached Mahak image to its product'] = static function (): void {
    $service = (new ReflectionClass(ProductSyncService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ProductSyncService::class, 'imageSourceForProduct');
    $method->setAccessible(true);
    $source = $method->invoke(
        $service,
        ['ProductId' => 12, 'Name' => '  كالای تست  '],
        [['PhotoGalleryId' => 1, 'ItemCode' => 12, 'PictureId' => 99, 'Deleted' => false]],
        ['99' => ['PictureId' => 99, 'Url' => '/image.jpg', 'Deleted' => false]],
    );
    assertSame('/image.jpg', $source['url']);
    assertSame('کالای تست', $source['title']);
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
        $store->saveMapping('product', '11', '21');
        $store->saveSnapshot('mahak.products', '10', ['ProductId' => 10, 'Name' => 'Test']);
        $store->saveMetadata(MappedProductReconcileService::LAST_RUN_META_KEY, '123');
        $payloadMetaKey = MappedProductReconcileService::payloadMetaKey('10');
        $store->saveMetadata($payloadMetaKey, 'written-payload-hash');
        assertSame(42, $store->checkpoint('mahak.products'));
        assertSame('20', $store->mapping('product', '10'));
        assertSame(['10' => '20', '11' => '21'], $store->mappings('product'));
        assertSame([20 => '10', 21 => '11'], $store->reverseMappings('product'));
        assertSame('123', $store->metadata(MappedProductReconcileService::LAST_RUN_META_KEY));
        assertSame('written-payload-hash', $store->metadata($payloadMetaKey));
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
        assertSame(null, $store->metadata(MappedProductReconcileService::LAST_RUN_META_KEY));
        assertSame(null, $store->metadata($payloadMetaKey));
    } finally {
        @unlink($path);
    }
};
$tests['mapped reconciliation compares normalized payloads'] = static function (): void {
    $first = MappedProductReconcileService::payloadHash(['price' => 100, 'stock' => 2]);
    $same = MappedProductReconcileService::payloadHash(['price' => 100, 'stock' => 2]);
    $changed = MappedProductReconcileService::payloadHash(['price' => 110, 'stock' => 2]);
    assertSame($first, $same);
    assertSame(false, $first === $changed);
    assertSame('mahak.product.payload_hash.10', MappedProductReconcileService::payloadMetaKey('10'));
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

$tests['dashboard excludes dry runs and marks missing price history'] = static function (): void {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new SkippedTest('pdo_sqlite is not installed');
    }
    $store = new StateStore(':memory:');
    $id = $store->startRun('mahak_to_mixin', 'products');
    $store->finishRun($id, 'success', ['dry_run' => false, 'created' => 2, 'updated' => 3, 'images_uploaded' => 1]);
    $id = $store->startRun('mahak_to_mixin', 'products');
    $store->finishRun($id, 'success', ['dry_run' => true, 'created' => 999, 'price_changed' => 999]);
    $id = $store->startRun('mahak_to_mixin', 'mapped_product_reconcile');
    $store->finishRun($id, 'partial', ['dry_run' => false, 'updated' => 1, 'price_changed' => 1]);
    $metrics = $store->dashboardMetrics();
    assertSame(6, $metrics['product_writes']['day']['value']);
    assertSame(1, $metrics['price_changes']['day']['value']);
    assertSame('partial', $metrics['price_changes']['day']['coverage']);
    assertSame('none', $metrics['invoices']['day']['coverage']);
    assertSame(7, $store->lifetimeWriteTotals()['mahak_to_mixin']);
};
$tests['field-level reconciliation isolates price and stock differences'] = static function (): void {
    $payload = ['name' => 'test', 'price' => 20, 'stock' => 4, 'available' => true, 'stock_type' => 'limited', 'product_identifier' => '10', 'main_category_id' => 28];
    $target = $payload;
    $target['price'] = 100;
    $target['stock'] = 3;
    assertSame(['price', 'stock'], MappedProductReconcileService::changedFields($target, $payload));
};

$tests['customer mapping normalizes phones and preserves contact and address fields'] = static function (): void {
    $a = \MahakMixin\Sync\OrderCustomerResolver::person(['customer_id'=>12,'customer_name'=>'Test Buyer','customer_phone'=>'+98 912 000 0000','customer_email'=>'test@example.com','shipping_address'=>'test address','shipping_zip_code'=>'1234567890']);
    $b = \MahakMixin\Sync\OrderCustomerResolver::person(['customer_id'=>12,'customer_name'=>'Test Buyer','customer_phone'=>'09120000000']);
    assertSame($a['key'], $b['key']);
    assertSame('09120000000', $a['person']['mobile']);
    assertSame('test@example.com', $a['person']['email']);
    assertSame('1234567890', $a['person']['postalCode']);
    assertSame('Buyer', $a['person']['lastName']);
    assertSame(false, isset($a['person']['password']));
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

/** @param class-string<Throwable> $expectedClass */
function assertThrows(callable $callback, string $expectedClass): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $expectedClass) {
            return;
        }
        throw new RuntimeException('Expected ' . $expectedClass . ', got ' . $exception::class);
    }
    throw new RuntimeException("Expected {$expectedClass} to be thrown");
}
