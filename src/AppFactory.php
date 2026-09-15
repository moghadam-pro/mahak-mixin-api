<?php

declare(strict_types=1);

namespace MahakMixin;

use MahakMixin\Client\MahakClient;
use MahakMixin\Client\MixinClient;
use MahakMixin\Http\HttpClient;
use MahakMixin\Mapper\ProductMapper;
use MahakMixin\Persistence\StateStore;
use MahakMixin\Sync\ProductSyncService;

final class AppFactory
{
    public static function http(): HttpClient
    {
        return new HttpClient(Config::int('SYNC_TIMEOUT_SECONDS', 30), Config::bool('SYNC_VERIFY_TLS', true));
    }

    public static function mahak(): MahakClient
    {
        $package = getenv('MAHAK_PACKAGE_NO');
        return new MahakClient(
            self::http(),
            Config::string('MAHAK_BASE_URL', 'https://mahakacc.mahaksoft.com/API/v3'),
            Config::string('MAHAK_USERNAME'),
            Config::string('MAHAK_PASSWORD'),
            Config::int('MAHAK_DATABASE_ID'),
            $package === false ? null : trim($package),
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
            new StateStore(Config::path('STATE_DB', 'var/bridge.sqlite')),
            new ProductMapper(Config::int('SYNC_PRICE_DIVISOR', 10)),
            Config::int('MAHAK_VISITOR_ID'),
            Config::int('SYNC_PAGE_SIZE', 100),
        );
    }
}
