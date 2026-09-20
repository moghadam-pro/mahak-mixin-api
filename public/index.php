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

    if (in_array($path, ['/login', '/dashboard', '/dashboard/refresh-status', '/logout'], true)) {
        dashboardSession();
        if ($method === 'GET' && $path === '/login') {
            dashboardLoginPage();
        }
        if ($method === 'POST' && $path === '/login') {
            dashboardLogin();
        }
        if ($method === 'POST' && $path === '/logout') {
            dashboardRequireLogin();
            verifyCsrf();
            $_SESSION = [];
            session_destroy();
            redirect('/login');
        }
        if ($method === 'GET' && $path === '/dashboard') {
            dashboardRequireLogin();
            dashboardPage();
        }
        if ($method === 'POST' && $path === '/dashboard/refresh-status') {
            dashboardRequireLogin();
            dashboardRefreshStatus();
        }
        htmlResponse(404, '<h1>یافت نشد</h1>');
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

function dashboardSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('mahak_mixin_dashboard');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Strict',
        'path' => '/',
    ]);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
}

function dashboardLoginPage(?string $error = null): never
{
    if (!empty($_SESSION['dashboard_authenticated'])) {
        redirect('/dashboard');
    }
    $message = $error === null ? '' : '<div class="alert">' . escapeHtml($error) . '</div>';
    $csrf = escapeHtml((string) $_SESSION['csrf']);
    htmlResponse(200, dashboardLayout('ورود', <<<HTML
        <main class="login-card">
          <h1>داشبورد همگام‌سازی</h1>
          <p>برای مشاهده وضعیت انتقال‌ها وارد شوید.</p>
          {$message}
          <form method="post" action="/login">
            <input type="hidden" name="csrf" value="{$csrf}">
            <label>نام کاربری<input name="username" autocomplete="username" required></label>
            <label>رمز عبور<input type="password" name="password" autocomplete="current-password" required></label>
            <button type="submit">ورود</button>
          </form>
        </main>
        HTML));
}

function dashboardLogin(): never
{
    verifyCsrf();
    $expectedUser = Config::string('DASHBOARD_USERNAME', '');
    $passwordHash = Config::string('DASHBOARD_PASSWORD_HASH', '');
    if ($expectedUser === '' || $passwordHash === '') {
        htmlResponse(503, dashboardLayout('تنظیم نشده', '<main class="login-card"><h1>داشبورد تنظیم نشده است</h1></main>'));
    }
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (!hash_equals($expectedUser, $username) || !password_verify($password, $passwordHash)) {
        usleep(500000);
        dashboardLoginPage('نام کاربری یا رمز عبور صحیح نیست.');
    }
    session_regenerate_id(true);
    $_SESSION['dashboard_authenticated'] = true;
    $_SESSION['dashboard_user'] = $expectedUser;
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    redirect('/dashboard');
}

function dashboardRequireLogin(): void
{
    if (empty($_SESSION['dashboard_authenticated'])) {
        redirect('/login');
    }
}

function dashboardRefreshStatus(): never
{
    verifyCsrf();
    $status = [
        'checked_at' => (new DateTimeImmutable('now', new DateTimeZone(Config::string('APP_TIMEZONE', 'Asia/Tehran'))))->format('Y-m-d H:i:s'),
        'mahak' => false,
        'mixin' => false,
    ];

    try {
        AppFactory::mahak()->login();
        $status['mahak'] = true;
    } catch (Throwable) {
        $status['mahak'] = false;
    }

    try {
        $response = AppFactory::mixin()->health();
        $health = strtolower((string) ($response['status'] ?? ''));
        $status['mixin'] = in_array($health, ['healthy', 'ok', 'success'], true);
    } catch (Throwable) {
        $status['mixin'] = false;
    }

    $_SESSION['dashboard_api_status'] = $status;
    redirect('/dashboard');
}

function dashboardConnectionBadge(?bool $connected): string
{
    if ($connected === true) {
        return '<span class="connection-badge connected"><i></i>متصل</span>';
    }
    if ($connected === false) {
        return '<span class="connection-badge disconnected"><i></i>قطع</span>';
    }
    return '<span class="connection-badge unchecked"><i></i>بررسی نشده</span>';
}

function dashboardPage(): never
{
    $state = AppFactory::state();
    $runs = $state->recentRuns(25);
    $checkpoints = $state->checkpoints();
    $mappingCount = $state->mappingCount('product');
    $latest = $runs[0] ?? null;
    $lastSuccess = null;
    foreach ($runs as $run) {
        if (($run['status'] ?? '') === 'success') {
            $lastSuccess = $run;
            break;
        }
    }

    $runRows = '';
    foreach ($runs as $run) {
        $stats = is_array($run['stats'] ?? null) ? $run['stats'] : [];
        $status = (string) ($run['status'] ?? 'unknown');
        $runRows .= '<tr><td>' . escapeHtml((string) $run['id']) . '</td>'
            . '<td><span class="badge ' . escapeHtml($status) . '">' . escapeHtml($status) . '</span></td>'
            . '<td>' . escapeHtml((string) ($stats['received'] ?? '—')) . '</td>'
            . '<td>' . escapeHtml((string) ($stats['created'] ?? '—')) . '</td>'
            . '<td>' . escapeHtml((string) ($stats['updated'] ?? '—')) . '</td>'
            . '<td>' . escapeHtml((string) ($stats['skipped'] ?? '—')) . '</td>'
            . '<td>' . escapeHtml((string) ($run['started_at'] ?? '')) . '</td>'
            . '<td class="error-cell">' . escapeHtml((string) ($run['error'] ?? '')) . '</td></tr>';
    }
    if ($runRows === '') {
        $runRows = '<tr><td colspan="8">هنوز اجرایی ثبت نشده است.</td></tr>';
    }

    $checkpointRows = '';
    foreach ($checkpoints as $checkpoint) {
        $checkpointRows .= '<tr><td>' . escapeHtml((string) $checkpoint['entity']) . '</td><td>'
            . escapeHtml((string) $checkpoint['row_version']) . '</td><td>'
            . escapeHtml((string) $checkpoint['updated_at']) . '</td></tr>';
    }
    if ($checkpointRows === '') {
        $checkpointRows = '<tr><td colspan="3">checkpoint ثبت نشده است.</td></tr>';
    }

    $csrf = escapeHtml((string) $_SESSION['csrf']);
    $apiStatus = is_array($_SESSION['dashboard_api_status'] ?? null) ? $_SESSION['dashboard_api_status'] : [];
    $mahakConnected = isset($apiStatus['mahak']) && is_bool($apiStatus['mahak']) ? $apiStatus['mahak'] : null;
    $mixinConnected = isset($apiStatus['mixin']) && is_bool($apiStatus['mixin']) ? $apiStatus['mixin'] : null;
    $mahakBadge = dashboardConnectionBadge($mahakConnected);
    $mixinBadge = dashboardConnectionBadge($mixinConnected);
    $checkedAt = escapeHtml((string) ($apiStatus['checked_at'] ?? 'هنوز بررسی نشده'));
    $latestStatus = escapeHtml((string) ($latest['status'] ?? '—'));
    $lastSuccessAt = escapeHtml((string) ($lastSuccess['finished_at'] ?? '—'));
    htmlResponse(200, dashboardLayout('داشبورد', <<<HTML
      <header class="topbar"><div><strong>Mahak ↔ Mixin Bridge</strong><small>داشبورد محلی وضعیت انتقال</small></div><form method="post" action="/logout"><input type="hidden" name="csrf" value="{$csrf}"><button class="secondary">خروج</button></form></header>
      <main class="container">
        <section class="panel api-status-card">
          <div class="panel-heading"><div><h2>وضعیت ارتباط API</h2><small>آخرین بررسی: {$checkedAt}</small></div><form method="post" action="/dashboard/refresh-status"><input type="hidden" name="csrf" value="{$csrf}"><button class="refresh-button" type="submit" title="بروزرسانی وضعیت‌ها" aria-label="بروزرسانی وضعیت اتصال APIها"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6v5h-5M4 18v-5h5M6.1 9a7 7 0 0 1 11.5-2.6L20 9M4 15l2.4 2.6A7 7 0 0 0 17.9 15"/></svg></button></form></div>
          <div class="api-services"><div class="api-service"><span>API محک</span>{$mahakBadge}</div><div class="api-service"><span>API میکسین</span>{$mixinBadge}</div></div>
        </section>
        <section class="cards">
          <article><span>حالت سرویس</span><strong class="success">فعال (فقط پل)</strong></article>
          <article><span>محصولات نگاشت‌شده</span><strong>{$mappingCount}</strong></article>
          <article><span>آخرین وضعیت</span><strong>{$latestStatus}</strong></article>
          <article><span>آخرین موفقیت</span><strong>{$lastSuccessAt}</strong></article>
        </section>
        <section class="panel"><h2>تاریخچه اجراها</h2><div class="table-wrap"><table><thead><tr><th>شناسه</th><th>وضعیت</th><th>دریافت</th><th>ایجاد</th><th>بروزرسانی</th><th>ردشده</th><th>شروع</th><th>خطا</th></tr></thead><tbody>{$runRows}</tbody></table></div></section>
        <section class="panel"><h2>Checkpointها</h2><div class="table-wrap"><table><thead><tr><th>موجودیت</th><th>نسخه</th><th>آخرین بروزرسانی</th></tr></thead><tbody>{$checkpointRows}</tbody></table></div></section>
      </main>
      HTML));
}

function verifyCsrf(): void
{
    $provided = (string) ($_POST['csrf'] ?? '');
    $expected = (string) ($_SESSION['csrf'] ?? '');
    if ($expected === '' || !hash_equals($expected, $provided)) {
        htmlResponse(419, dashboardLayout('درخواست نامعتبر', '<main class="login-card"><h1>درخواست نامعتبر است</h1></main>'));
    }
}

function dashboardLayout(string $title, string $body): string
{
    $safeTitle = escapeHtml($title);
    return <<<HTML
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$safeTitle} | Bridge</title><style>
@font-face{font-family:'Vazirmatn';src:url('/assets/fonts/Vazirmatn.woff2') format('woff2');font-style:normal;font-weight:100 900;font-display:swap}:root{font-family:'Vazirmatn',Tahoma,Arial,sans-serif;color:#172033;background:#f4f7fb}*{box-sizing:border-box}body{margin:0}.topbar{display:flex;justify-content:space-between;align-items:center;padding:18px 5%;background:#172033;color:#fff}.topbar small{display:block;color:#aebbd0;margin-top:5px}.container{width:min(1180px,92%);margin:28px auto}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:16px}.cards article,.panel,.login-card{background:#fff;border:1px solid #e3e9f2;border-radius:14px;box-shadow:0 5px 18px rgba(23,32,51,.05)}.cards article{padding:20px}.cards span{display:block;color:#69778f;font-size:13px;margin-bottom:10px}.cards strong{font-size:20px}.success{color:#138a55}.warning{color:#b26a00}.panel{padding:20px;margin-top:20px}.panel h2{margin:0 0 16px;font-size:18px}.api-status-card{margin-top:0}.panel-heading{display:flex;align-items:center;justify-content:space-between;gap:16px}.panel-heading h2{margin:0}.panel-heading small{display:block;color:#69778f;font-size:12px;margin-top:5px}.refresh-button{display:grid;place-items:center;width:42px;height:42px;padding:0;border:1px solid #d9e1ed;background:#f7f9fc;color:#3156d3}.refresh-button:hover{background:#edf2ff}.refresh-button svg{width:21px;height:21px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.api-services{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:18px}.api-service{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-radius:10px;background:#f7f9fc}.api-service>span{font-size:14px;font-weight:600}.connection-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 11px;border-radius:20px;font-size:13px;font-weight:600}.connection-badge i{width:8px;height:8px;border-radius:50%;background:currentColor}.connection-badge.connected{color:#138a55;background:#e5f7ee}.connection-badge.disconnected{color:#b42318;background:#fdeaea}.connection-badge.unchecked{color:#64748b;background:#e8eef7}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}th,td{text-align:right;padding:11px;border-bottom:1px solid #edf0f5;font-size:13px}.badge{padding:4px 9px;border-radius:20px;background:#e8eef7}.badge.success{background:#e5f7ee}.badge.failed{background:#fdeaea;color:#b42318}.error-cell{max-width:280px;white-space:normal;color:#b42318}.login-card{width:min(420px,92%);margin:10vh auto;padding:28px}.login-card h1{margin-top:0}.login-card label{display:block;margin:16px 0;color:#475569}.login-card input{display:block;width:100%;margin-top:7px;padding:12px;border:1px solid #cdd6e3;border-radius:9px}button{font:inherit;border:0;border-radius:9px;background:#3156d3;color:#fff;padding:11px 18px;cursor:pointer}.secondary{background:#334155}.alert{padding:10px;border-radius:8px;background:#fdeaea;color:#b42318}@media(max-width:800px){.cards{grid-template-columns:1fr 1fr}}@media(max-width:600px){.api-services{grid-template-columns:1fr}}@media(max-width:480px){.cards{grid-template-columns:1fr}}
</style></head><body>{$body}</body></html>
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
