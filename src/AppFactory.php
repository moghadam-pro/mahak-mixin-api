<?php

declare(strict_types=1);

namespace MahakMixin;

use MahakMixin\Client\MahakClient;
use MahakMixin\Client\MixinClient;
use MahakMixin\Http\HttpClient;
use MahakMixin\Mapper\ProductMapper;
use MahakMixin\Persistence\StateStore;
use MahakMixin\Sync\ProductSyncService;
use MahakMixin\Sync\FullCatalogSyncService;

final class AppFactory
{
    public static function http(): HttpClient
    {
        return new HttpClient(
            Config::int('SYNC_TIMEOUT_SECONDS', 30),
            Config::bool('SYNC_VERIFY_TLS', true),
            Config::int('SYNC_HTTP_MAX_RETRIES', 2),
        );
    }

    public static function mahak(): MahakClient
    {
        return new MahakClient(
            self::http(),
            Config::string('MAHAK_BASE_URL', 'https://mahakacc.mahaksoft.com/API/v3'),
            Config::string('MAHAK_USERNAME'),
            Config::string('MAHAK_PASSWORD'),
            Config::string('MAHAK_LOGIN_PATH', '/Sync/Login'),
            Config::string('MAHAK_GET_ALL_DATA_PATH', '/Sync/GetAllData'),
            Config::string('MAHAK_SAVE_ALL_DATA_PATH', '/Sync/SaveAllData'),
        );
    }

    public static function mixin(): MixinClient
    {
        return new MixinClient(self::http(), Config::string('MIXIN_BASE_URL'), Config::string('MIXIN_API_KEY'));
    }

    public static function productSync(): ProductSyncService
    {
        return new ProductSyncService(
            self::mahak(),
            self::mixin(),
            self::state(),
            new ProductMapper(Config::int('SYNC_PRICE_DIVISOR', 10)),
            Config::int('MAHAK_VISITOR_ID'),
            Config::int('SYNC_INCREMENTAL_PAGE_SIZE', 5000),
            Config::intMap('SYNC_CATEGORY_MAP_JSON'),
            Config::intMap('SYNC_PRODUCT_CATEGORY_MAP_JSON'),
            Config::int('SYNC_FALLBACK_CATEGORY_ID', 0),
            Config::path('SYNC_LOCK_FILE', 'var/product-sync.lock'),
        );
    }

    public static function fullCatalogSync(): FullCatalogSyncService
    {
        return new FullCatalogSyncService(
            self::mahak(),
            self::mixin(),
            self::state(),
            new ProductMapper(Config::int('SYNC_PRICE_DIVISOR', 10)),
            Config::int('MAHAK_VISITOR_ID'),
            Config::int('SYNC_FALLBACK_CATEGORY_ID', 0),
            Config::int('SYNC_FULL_PAGE_SIZE', 5000),
            Config::int('SYNC_FULL_MAX_PAGES', 100),
            Config::int('SYNC_WRITE_DELAY_MS', 150),
            Config::path('SYNC_LOCK_FILE', 'var/product-sync.lock'),
        );
    }

    public static function state(): StateStore
    {
        return new StateStore(Config::path('STATE_DB', 'var/bridge.sqlite'));
    }
}
