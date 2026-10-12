<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use MahakMixin\AppFactory;
use MahakMixin\Config;
use MahakMixin\Version;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($method === 'GET' && $path === '/health') {
        jsonResponse(200, ['status' => 'ok', 'service' => 'mahak-mixin-bridge', 'version' => Version::current()]);
    }
    if ($method === 'GET' && str_starts_with($path, '/assets/')) {
        serveAsset($path);
    }
    if (in_array($path, ['/dashboard', '/dashboard/refresh-status'], true)) {
        dashboardSession();
    }
    if (in_array($path, ['/login', '/logout'], true)) {
        jsonResponse(404, ['status' => 'error', 'message' => 'Not found']);
    }
    if ($method === 'GET' && $path === '/dashboard') {
        dashboardPage();
    }
    if ($method === 'POST' && $path === '/dashboard/refresh-status') {
        dashboardRefreshStatus();
    }

    authorizeBridgeApi();
    if ($method === 'GET' && $path === '/connections') {
        jsonResponse(200, ['status' => 'ok', 'mahak' => redact(AppFactory::mahak()->login()), 'mixin' => AppFactory::mixin()->health()]);
    }
    if ($method === 'POST' && $path === '/sync/products') {
        jsonResponse(200, AppFactory::productSync()->run(Config::bool('SYNC_DRY_RUN', true)));
    }
    jsonResponse(404, ['status' => 'error', 'message' => 'Not found']);
} catch (Throwable $exception) {
    jsonResponse(500, ['status' => 'error', 'message' => Config::bool('APP_DEBUG', false) ? $exception->getMessage() : 'Internal server error']);
}

function authorizeBridgeApi(): void
{
    $expected = Config::string('BRIDGE_API_KEY');
    $provided = $_SERVER['HTTP_X_BRIDGE_KEY'] ?? '';
    if (!is_string($provided) || !hash_equals($expected, $provided)) {
        jsonResponse(401, ['status' => 'error', 'message' => 'Unauthorized']);
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

function jsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function serveAsset(string $path): never
{
    $relative = ltrim(rawurldecode($path), '/');
    $root = realpath(__DIR__ . '/assets');
    $file = realpath(__DIR__ . '/' . $relative);
    if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
        jsonResponse(404, ['status' => 'error', 'message' => 'Asset not found']);
    }
    $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'woff', 'woff2' => 'font/woff2',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}

function dashboardSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('mahak_mixin_dashboard');
    session_set_cookie_params(['httponly' => true, 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'samesite' => 'Strict', 'path' => '/']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
}

function dashboardRefreshStatus(): never
{
    verifyCsrf();
    $status = [
        'checked_at' => gmdate('Y-m-d H:i:s'),
        'mahak' => ['ok' => false, 'message' => 'unreachable'],
        'mixin' => ['ok' => false, 'message' => 'unreachable'],
    ];
    try {
        AppFactory::mahak()->login();
        $status['mahak'] = ['ok' => true, 'message' => 'login accepted'];
    } catch (Throwable $exception) {
        $status['mahak']['message'] = dashboardSafeError($exception->getMessage());
    }
    try {
        $response = AppFactory::mixin()->health();
        $health = strtolower((string) ($response['status'] ?? ''));
        $ok = in_array($health, ['healthy', 'ok', 'success'], true);
        $status['mixin'] = ['ok' => $ok, 'message' => $ok ? 'health endpoint accepted' : 'unexpected health response'];
    } catch (Throwable $exception) {
        $status['mixin']['message'] = dashboardSafeError($exception->getMessage());
    }
    $_SESSION['dashboard_api_status'] = $status;
    redirect('/dashboard');
}

function dashboardPage(): never
{
    $state = AppFactory::state();
    $runs = $state->recentRuns(100);
    $metrics = $state->dashboardMetrics();
    $totals = $state->lifetimeWriteTotals();
    $latest = $runs[0] ?? null;
    $api = is_array($_SESSION['dashboard_api_status'] ?? null) ? $_SESSION['dashboard_api_status'] : [];
    $productMappings = $state->mappingCount('product');
    $orderMappings = $state->mappingCount('order');
    $csrf = escapeHtml((string) $_SESSION['csrf']);
    $version = escapeHtml(Version::current());

    $latestStatus = strtolower((string) ($latest['status'] ?? 'unknown'));
    $latestAge = dashboardAgeSeconds(isset($latest['finished_at']) ? (string) $latest['finished_at'] : null);
    $overall = match (true) {
        in_array($latestStatus, ['failed', 'error'], true) => ['FAIL', 'red', 'latest worker run failed'],
        $latestStatus === 'partial' => ['WARN', 'amber', 'latest worker run completed with partial failures'],
        $latestStatus === 'running' => ['BUSY', 'amber', 'worker is running'],
        $latestAge === null => ['UNKNOWN', 'amber', 'no completed run recorded'],
        $latestAge > 600 => ['STALE', 'amber', 'no completed run in the last 10 minutes'],
        default => ['OK', 'green', 'latest worker run completed successfully'],
    };

    $metricRows = '';
    foreach (['product_writes' => 'product writes', 'price_changes' => 'price changes', 'image_changes' => 'image writes', 'invoices' => 'invoices to Mahak'] as $key => $label) {
        $metricRows .= '<tr><th>' . escapeHtml($label) . '</th>';
        foreach (['day', 'week', 'month'] as $window) {
            $entry = $metrics[$key][$window] ?? ['value' => 0, 'coverage' => 'none'];
            $coverage = (string) ($entry['coverage'] ?? 'none');
            $coverageClass = $coverage === 'full' ? 'green' : ($coverage === 'partial' ? 'amber' : 'muted');
            $metricValue = $coverage === 'none' ? '--' : number_format((int) ($entry['value'] ?? 0));
            $metricRows .= '<td><strong>' . $metricValue . '</strong><small class="' . $coverageClass . '">[' . strtoupper($coverage) . ']</small></td>';
        }
        $metricRows .= '</tr>';
    }

    $logText = '';
    foreach ($runs as $run) {
        $stats = is_array($run['stats'] ?? null) ? $run['stats'] : [];
        $status = strtoupper((string) ($run['status'] ?? 'unknown'));
        $statusClass = in_array(strtolower($status), ['failed', 'error'], true) ? 'red' : (strtolower($status) === 'partial' ? 'amber' : (strtolower($status) === 'success' ? 'green' : 'muted'));
        $pairs = [];
        foreach (['received', 'created', 'updated', 'recreated', 'price_changed', 'images_uploaded', 'images_replaced', 'failed', 'deferred'] as $key) {
            if (array_key_exists($key, $stats)) {
                $pairs[] = $key . '=' . (int) $stats[$key];
            }
        }
        $error = trim((string) ($run['error'] ?? ''));
        $errorPart = $error === '' ? '' : ' error=' . dashboardSafeError($error);
        foreach ((array) ($stats['errors'] ?? []) as $itemError) {
            if (is_array($itemError) && isset($itemError['message'])) {
                $errorPart .= ' item_error=' . dashboardSafeError((string) $itemError['message']);
            }
        }
        $pairs[] = 'dry_run=' . ((bool) ($stats['dry_run'] ?? false) ? 'true' : 'false');
        $plain = sprintf('%s [%s] #%s %s/%s %s%s', dashboardFormatDate(isset($run['started_at']) ? (string) $run['started_at'] : null), $status, (string) ($run['id'] ?? '?'), (string) ($run['direction'] ?? '?'), (string) ($run['entity'] ?? '?'), implode(' ', $pairs), $errorPart);
        $logText .= '<div class="log-line"><span class="' . $statusClass . '">' . escapeHtml($plain) . '</span></div>';
    }
    if ($logText === '') {
        $logText = '<div class="log-line muted">-- no runs recorded --</div>';
    }

    $mahak = dashboardApiLine('MAHAK_API', $api['mahak'] ?? null);
    $mixin = dashboardApiLine('MIXIN_API', $api['mixin'] ?? null);
    $checkedAt = dashboardFormatDate(isset($api['checked_at']) ? (string) $api['checked_at'] : null);
    $latestTime = dashboardFormatDate(isset($latest['finished_at']) ? (string) $latest['finished_at'] : null);
    $mode = Config::bool('SYNC_DRY_RUN', true) ? ['DRY-RUN', 'amber'] : ['WRITE', 'green'];
    $orders = Config::bool('SYNC_ORDER_SYNC_ENABLED', false) ? ['ENABLED', 'green'] : ['DISABLED', 'amber'];
    $failureCount = count(array_filter($runs, static fn (array $run): bool => in_array(strtolower((string) ($run['status'] ?? '')), ['failed', 'error'], true)));

    htmlResponse(200, dashboardLayout('Monitor', <<<HTML
      <header class="topbar"><div><strong>mahak-mixin-bridge</strong><span>v{$version}</span></div></header>
      <main class="dashboard">
        <section class="section overview">
          <div class="section-head"><div><span class="prompt">~/monitor</span><h1>OVERVIEW</h1></div><form method="post" action="/dashboard/refresh-status"><input type="hidden" name="csrf" value="{$csrf}"><button class="refresh" type="submit">[ recheck APIs ]</button></form></div>
          <div class="status-grid">
            <div><span>SERVICE</span><strong class="{$overall[1]}">[{$overall[0]}]</strong><small>{$overall[2]}</small></div>
            <div><span>MODE</span><strong class="{$mode[1]}">[{$mode[0]}]</strong><small>orders=<span class="{$orders[1]}">{$orders[0]}</span></small></div>
            <div><span>LATEST RUN</span><strong class="{$overall[1]}">{$latestTime}</strong><small>historical failures in last 100: {$failureCount}</small></div>
            <div><span>API CHECK</span><strong>{$checkedAt}</strong><small>manual probe timestamp</small></div>
          </div>
          <div class="api-lines">{$mahak}{$mixin}</div>
          <div class="metric-wrap">
            <table class="metrics"><thead><tr><th>COUNTER</th><th>24H</th><th>7D</th><th>30D</th></tr></thead><tbody>{$metricRows}</tbody></table>
            <aside class="totals">
              <div><span>MAHAK -&gt; MIXIN</span><strong>{$totals['mahak_to_mixin']}</strong><small>recorded write operations</small></div>
              <div><span>MIXIN -&gt; MAHAK</span><strong>{$totals['mixin_to_mahak']}</strong><small>recorded invoice writes</small></div>
              <div><span>ACTIVE PRODUCT MAPS</span><strong>{$productMappings}</strong><small>current source/target links</small></div>
              <div><span>INVOICE MAPS</span><strong>{$orderMappings}</strong><small>idempotency records</small></div>
            </aside>
          </div>
          <p class="footnote">[i] Counts use completed non-dry-run records only. PARTIAL means an older release did not record that counter; missing history is never treated as measured activity.</p>
        </section>
        <section class="section logs">
          <div class="section-head"><div><span class="prompt">~/sync/runs</span><h2>LOG STREAM <small>last 100</small></h2></div><button type="button" class="copy" id="copy-logs">[ copy output ]</button></div>
          <div class="terminal" id="log-output">{$logText}</div><div class="copy-state" id="copy-state" aria-live="polite"></div>
        </section>
      </main>
      HTML));
}

function dashboardApiLine(string $label, mixed $value): string
{
    if (!is_array($value) || !array_key_exists('ok', $value)) {
        return '<div><span>' . escapeHtml($label) . '</span><strong class="muted">[NOT CHECKED]</strong><small>press recheck APIs</small></div>';
    }
    $ok = (bool) $value['ok'];
    return '<div><span>' . escapeHtml($label) . '</span><strong class="' . ($ok ? 'green' : 'red') . '">[' . ($ok ? 'CONNECTED' : 'FAILED') . ']</strong><small>' . escapeHtml((string) ($value['message'] ?? '')) . '</small></div>';
}

function dashboardFormatDate(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '--';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(Config::string('APP_TIMEZONE', 'Asia/Tehran')))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $value;
    }
}

function dashboardAgeSeconds(?string $value): ?int
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    try {
        return max(0, time() - (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp());
    } catch (Throwable) {
        return null;
    }
}

function dashboardSafeError(string $message): string
{
    return \MahakMixin\Support\Diagnostics::safeMessage($message);
}

function verifyCsrf(): void
{
    $provided = (string) ($_POST['csrf'] ?? '');
    $expected = (string) ($_SESSION['csrf'] ?? '');
    if ($expected === '' || !hash_equals($expected, $provided)) {
        htmlResponse(419, dashboardLayout('Invalid request', '<main class="fatal">[FATAL] invalid CSRF token</main>'));
    }
}

function dashboardLayout(string $title, string $body): string
{
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    $safeTitle = escapeHtml($title);
    $version = rawurlencode(Version::current() . '.' . substr((string) hash_file('sha256', __DIR__ . '/assets/dashboard.css'), 0, 8));
    return <<<HTML
<!doctype html><html lang="en" dir="ltr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive,nosnippet"><meta name="theme-color" content="#050705"><title>{$safeTitle} | Mahak Mixin Bridge</title><link rel="stylesheet" href="/assets/dashboard.css?v={$version}"></head><body>{$body}
<script>(()=>{const b=document.getElementById('copy-logs');if(!b)return;b.addEventListener('click',async()=>{const o=document.getElementById('log-output'),s=document.getElementById('copy-state');try{await navigator.clipboard.writeText(o.innerText.trim());s.textContent='[OK] copied 100-run output to clipboard';s.className='copy-state green'}catch(_){s.textContent='[FAIL] clipboard permission denied';s.className='copy-state red'}})})();</script></body></html>
HTML;
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function htmlResponse(int $status, string $html): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}
