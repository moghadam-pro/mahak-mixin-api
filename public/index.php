<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use MahakMixin\AppFactory;
use MahakMixin\Config;
header('Content-Type: application/json; charset=utf-8');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($method === 'GET' && $path === '/health') {
        respond(200, ['status' => 'ok', 'service' => 'mahak-mixin-bridge']);
    }

    authorize();
    if ($method === 'GET' && $path === '/connections') {
        $mahak = AppFactory::mahak()->login();
        $mixin = AppFactory::mixin()->health();
        respond(200, ['status' => 'ok', 'mahak' => redact($mahak), 'mixin' => $mixin]);
    }
    if ($method === 'POST' && $path === '/sync/products') {
        respond(200, AppFactory::productSync()->run(Config::bool('SYNC_DRY_RUN', true)));
    }
    respond(404, ['status' => 'error', 'message' => 'Not found']);
} catch (Throwable $exception) {
    $debug = Config::bool('APP_DEBUG', false);
    respond(500, ['status' => 'error', 'message' => $debug ? $exception->getMessage() : 'Internal server error']);
}

function authorize(): void
{
    $expected = Config::string('BRIDGE_API_KEY');
    $provided = $_SERVER['HTTP_X_BRIDGE_KEY'] ?? '';
    if (!is_string($provided) || !hash_equals($expected, $provided)) {
        respond(401, ['status' => 'error', 'message' => 'Unauthorized']);
    }
}

function redact(array $value): array
{
    foreach ($value as $key => &$item) {
        if (in_array(strtolower((string) $key), ['usertoken', 'token', 'password', 'api_key'], true)) {
            $item = '[REDACTED]';
        } elseif (is_array($item)) {
            $item = redact($item);
        }
    }
    return $value;
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
