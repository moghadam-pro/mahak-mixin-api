<?php

declare(strict_types=1);

namespace MahakMixin\Support;

use MahakMixin\AppFactory;
use MahakMixin\Config;
use MahakMixin\Version;

final class Diagnostics
{
    /** Local evidence only; never logs credentials, payloads, customer data or calls a write endpoint. */
    public static function report(): array
    {
        $state = AppFactory::state();
        $runs = $state->recentRuns(100);
        $groups = [];
        foreach ($runs as &$run) {
            $stats = is_array($run['stats'] ?? null) ? $run['stats'] : [];
            $run['stats'] = array_intersect_key($stats, array_flip([
                'dry_run', 'scope', 'received', 'created', 'updated', 'recreated', 'changed',
                'unchanged', 'compared', 'mapped', 'failed', 'skipped', 'deferred',
                'images_uploaded', 'images_replaced', 'images_missing', 'price_changed',
                'stock_changed', 'pages_fetched', 'target_pages_fetched',
            ]));
            $messages = [];
            if (!empty($run['error'])) {
                $messages[] = self::safeMessage((string) $run['error']);
            }
            foreach ((array) ($stats['errors'] ?? []) as $error) {
                if (is_array($error) && isset($error['message'])) {
                    $messages[] = self::safeMessage((string) $error['message']);
                }
            }
            $run['error'] = $messages[0] ?? null;
            $run['item_errors'] = array_values(array_unique($messages));
            foreach (array_unique($messages) as $message) {
                $groups[$message] = ($groups[$message] ?? 0) + 1;
            }
        }
        unset($run);
        arsort($groups);
        $flags = [];
        foreach (['SYNC_DRY_RUN' => true, 'SYNC_RECONCILE_ENABLED' => true, 'SYNC_ORDER_SYNC_ENABLED' => false] as $name => $default) {
            $flags[$name] = Config::bool($name, $default);
        }
        return [
            'generated_at_utc' => gmdate('c'),
            'version' => Version::current(),
            'php_version' => PHP_VERSION,
            'extensions' => array_values(array_intersect(['curl', 'json', 'PDO', 'pdo_sqlite', 'mbstring'], get_loaded_extensions())),
            'flags' => $flags,
            'product_mappings' => $state->mappingCount('product'),
            'order_mappings' => $state->mappingCount('order'),
            'checkpoints' => $state->checkpoints(),
            'last_reconcile_epoch' => $state->metadata('mahak.products.last_reconcile_at'),
            'order_baseline_set' => $state->metadata('mixin.orders.last_seen_id') !== null,
            'error_groups_last_100' => $groups,
            'runs' => $runs,
        ];
    }

    public static function safeMessage(string $message): string
    {
        foreach (['MAHAK_PASSWORD', 'MIXIN_API_KEY', 'BRIDGE_API_KEY', 'DASHBOARD_PASSWORD_HASH'] as $name) {
            $secret = Config::string($name, '');
            if ($secret !== '') {
                $message = str_replace($secret, '[REDACTED]', $message);
            }
        }
        $message = preg_replace('~https?://\S+~i', '[URL]', $message) ?? $message;
        $message = preg_replace('/(password|token|api[_-]?key)\s*[=:]\s*\S+/i', '$1=[REDACTED]', $message) ?? $message;
        $message = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);
        return function_exists('mb_substr') ? mb_substr($message, 0, 800) : substr($message, 0, 800);
    }
}
