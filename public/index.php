<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use MahakMixin\AppFactory;
use MahakMixin\Config;
use MahakMixin\Version;
header('Content-Type: application/json; charset=utf-8');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($method === 'GET' && $path === '/health') {
        respond(200, ['status' => 'ok', 'service' => 'mahak-mixin-bridge', 'version' => Version::current()]);
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

function dashboardIcon(string $name, string $class = ''): string
{
    $paths = [
        'activity' => '<path d="M3 12h4l2-6 4 12 2-6h6"/>',
        'arrow-left-right' => '<path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/>',
        'check-circle' => '<path d="M22 11.1V12a10 10 0 1 1-5.9-9.1"/><path d="m9 11 3 3L22 4"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h6"/>',
        'gauge' => '<path d="M20.4 15a9 9 0 1 0-16.8 0"/><path d="m12 12 4-4"/><path d="M6.7 17h10.6"/>',
        'layers' => '<path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
        'log-out' => '<path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>',
        'package' => '<path d="m21 8-9-5-9 5 9 5 9-5Z"/><path d="m3 8 9 5 9-5v8l-9 5-9-5V8Z"/><path d="M12 13v8"/>',
        'refresh' => '<path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M6.1 9a7 7 0 0 1 11.5-2.6L20 9"/><path d="m4 15 2.4 2.6A7 7 0 0 0 17.9 15"/>',
        'terminal' => '<path d="m4 17 6-5-6-5"/><path d="M12 19h8"/>',
        'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
    ];
    $body = $paths[$name] ?? $paths['activity'];
    return '<svg class="icon ' . escapeHtml($class) . '" viewBox="0 0 24 24" aria-hidden="true">' . $body . '</svg>';
}

function dashboardFormatDate(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '—';
    }
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone(Config::string('APP_TIMEZONE', 'Asia/Tehran')))->format('Y/m/d · H:i:s');
    } catch (Throwable) {
        return $value;
    }
}

function dashboardRunDuration(?string $startedAt, ?string $finishedAt): string
{
    if ($startedAt === null || $finishedAt === null || $startedAt === '' || $finishedAt === '') {
        return '—';
    }
    try {
        $seconds = max(0, (new DateTimeImmutable($finishedAt))->getTimestamp() - (new DateTimeImmutable($startedAt))->getTimestamp());
        if ($seconds < 60) {
            return $seconds . ' ثانیه';
        }
        return intdiv($seconds, 60) . ' دقیقه و ' . ($seconds % 60) . ' ثانیه';
    } catch (Throwable) {
        return '—';
    }
}

function dashboardRunStatus(string $status): array
{
    return match (strtolower($status)) {
        'success' => ['موفق', 'success', dashboardIcon('check-circle')],
        'failed', 'error' => ['ناموفق', 'failed', dashboardIcon('x-circle')],
        'running' => ['در حال اجرا', 'running', dashboardIcon('activity')],
        default => [$status === '' ? 'نامشخص' : $status, 'unknown', dashboardIcon('clock')],
    };
}

function dashboardPage(): never
{
    $state = AppFactory::state();
    $runs = $state->recentRuns(100);
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
    $runCards = '';
    $consoleLines = '';
    $successCount = 0;
    $failedCount = 0;
    $runningCount = 0;
    $totalReceived = 0;
    $totalCreated = 0;
    $totalUpdated = 0;
    $totalSkipped = 0;
    $consoleIndex = 0;
    foreach ($runs as $run) {
        $stats = is_array($run['stats'] ?? null) ? $run['stats'] : [];
        $status = (string) ($run['status'] ?? 'unknown');
        [$statusLabel, $statusClass, $statusIcon] = dashboardRunStatus($status);
        $successCount += strtolower($status) === 'success' ? 1 : 0;
        $failedCount += in_array(strtolower($status), ['failed', 'error'], true) ? 1 : 0;
        $runningCount += strtolower($status) === 'running' ? 1 : 0;
        $totalReceived += (int) ($stats['received'] ?? 0);
        $totalCreated += (int) ($stats['created'] ?? 0);
        $totalUpdated += (int) ($stats['updated'] ?? 0);
        $totalSkipped += (int) ($stats['skipped'] ?? 0);

        $id = escapeHtml((string) ($run['id'] ?? '—'));
        $direction = escapeHtml((string) ($run['direction'] ?? '—'));
        $entity = escapeHtml((string) ($run['entity'] ?? '—'));
        $received = escapeHtml((string) ($stats['received'] ?? '—'));
        $created = escapeHtml((string) ($stats['created'] ?? '—'));
        $updated = escapeHtml((string) ($stats['updated'] ?? '—'));
        $skipped = escapeHtml((string) ($stats['skipped'] ?? '—'));
        $startedAt = escapeHtml(dashboardFormatDate(isset($run['started_at']) ? (string) $run['started_at'] : null));
        $finishedAt = escapeHtml(dashboardFormatDate(isset($run['finished_at']) ? (string) $run['finished_at'] : null));
        $duration = escapeHtml(dashboardRunDuration(isset($run['started_at']) ? (string) $run['started_at'] : null, isset($run['finished_at']) ? (string) $run['finished_at'] : null));
        $error = trim((string) ($run['error'] ?? ''));
        $errorHtml = $error === '' ? '<span class="muted">بدون خطا</span>' : '<span class="error-text">' . escapeHtml($error) . '</span>';
        $statusBadge = '<span class="status-badge ' . $statusClass . '">' . $statusIcon . escapeHtml($statusLabel) . '</span>';

        $runRows .= '<tr><td><span class="run-id">#' . $id . '</span></td><td>' . $statusBadge . '</td>'
            . '<td><span class="code-value">' . $direction . '</span><small>' . $entity . '</small></td>'
            . '<td>' . $received . '</td><td>' . $created . '</td><td>' . $updated . '</td><td>' . $skipped . '</td>'
            . '<td><span class="date-value">' . $startedAt . '</span><small>' . $duration . '</small></td>'
            . '<td class="error-cell">' . $errorHtml . '</td></tr>';

        $runCards .= '<article class="run-card"><div class="run-card-head"><div><span class="run-id">#' . $id . '</span>' . $statusBadge . '</div><span class="duration">' . $duration . '</span></div>'
            . '<div class="run-card-source"><span class="code-value">' . $direction . '</span><span>' . $entity . '</span></div>'
            . '<div class="run-card-stats"><div><b>' . $received . '</b><span>دریافت</span></div><div><b>' . $created . '</b><span>ایجاد</span></div><div><b>' . $updated . '</b><span>بروزرسانی</span></div><div><b>' . $skipped . '</b><span>ردشده</span></div></div>'
            . '<div class="run-card-time"><span>شروع: ' . $startedAt . '</span><span>پایان: ' . $finishedAt . '</span></div>'
            . ($error === '' ? '' : '<div class="run-card-error">' . escapeHtml($error) . '</div>') . '</article>';

        if ($consoleIndex < 6) {
            $consoleLines .= '<div class="console-line"><span class="console-prompt">$</span><span class="console-time">' . $startedAt . '</span><span class="console-command">sync.run --id=' . $id . ' --entity=' . $entity . '</span><span class="console-result ' . $statusClass . '">' . escapeHtml($statusLabel) . ' · دریافت ' . $received . ' · تغییر ' . escapeHtml((string) ((int) ($stats['created'] ?? 0) + (int) ($stats['updated'] ?? 0))) . '</span></div>';
            $consoleIndex++;
        }
    }
    if ($runRows === '') {
        $runRows = '<tr><td colspan="9"><div class="empty-state">' . dashboardIcon('terminal') . '<strong>هنوز اجرایی ثبت نشده است</strong><span>پس از نخستین اجرای همگام‌سازی، گزارش آن اینجا نمایش داده می‌شود.</span></div></td></tr>';
        $runCards = '<div class="empty-state">' . dashboardIcon('terminal') . '<strong>هنوز اجرایی ثبت نشده است</strong><span>گزارش اجرای بعدی در این بخش ظاهر می‌شود.</span></div>';
        $consoleLines = '<div class="console-empty">$ waiting_for_first_sync<span class="console-cursor"></span></div>';
    }

    $checkpointRows = '';
    foreach ($checkpoints as $checkpoint) {
        $checkpointRows .= '<article class="checkpoint-card"><div class="checkpoint-icon">' . dashboardIcon('database') . '</div><div><span>' . escapeHtml((string) $checkpoint['entity']) . '</span><strong>'
            . escapeHtml(number_format((int) $checkpoint['row_version'])) . '</strong><small>بروزرسانی: '
            . escapeHtml(dashboardFormatDate((string) $checkpoint['updated_at'])) . '</small></div></article>';
    }
    if ($checkpointRows === '') {
        $checkpointRows = '<div class="empty-state">' . dashboardIcon('database') . '<strong>checkpoint ثبت نشده است</strong></div>';
    }

    $csrf = escapeHtml((string) $_SESSION['csrf']);
    $apiStatus = is_array($_SESSION['dashboard_api_status'] ?? null) ? $_SESSION['dashboard_api_status'] : [];
    $mahakConnected = isset($apiStatus['mahak']) && is_bool($apiStatus['mahak']) ? $apiStatus['mahak'] : null;
    $mixinConnected = isset($apiStatus['mixin']) && is_bool($apiStatus['mixin']) ? $apiStatus['mixin'] : null;
    $mahakBadge = dashboardConnectionBadge($mahakConnected);
    $mixinBadge = dashboardConnectionBadge($mixinConnected);
    $checkedAt = escapeHtml((string) ($apiStatus['checked_at'] ?? 'هنوز بررسی نشده'));
    [$latestStatusLabel, $latestStatusClass] = dashboardRunStatus((string) ($latest['status'] ?? ''));
    $lastSuccessAt = escapeHtml(dashboardFormatDate(isset($lastSuccess['finished_at']) ? (string) $lastSuccess['finished_at'] : null));
    $runCount = count($runs);
    $completedCount = $successCount + $failedCount;
    $successRate = $completedCount > 0 ? (int) round(($successCount / $completedCount) * 100) : null;
    $successRateLabel = $successRate === null ? '—' : $successRate . '٪';
    $latestDuration = escapeHtml(dashboardRunDuration(isset($latest['started_at']) ? (string) $latest['started_at'] : null, isset($latest['finished_at']) ? (string) $latest['finished_at'] : null));
    $checkedAtLabel = $checkedAt === 'هنوز بررسی نشده' ? $checkedAt : $checkedAt;
    $refreshIcon = dashboardIcon('refresh');
    $activityIcon = dashboardIcon('activity');
    $packageIcon = dashboardIcon('package');
    $checkIcon = dashboardIcon('check-circle');
    $clockIcon = dashboardIcon('clock');
    $layersIcon = dashboardIcon('layers');
    $terminalIcon = dashboardIcon('terminal');
    $fileIcon = dashboardIcon('file-text');
    $databaseIcon = dashboardIcon('database');
    $logoutIcon = dashboardIcon('log-out');
    $appVersion = escapeHtml(Version::current());
    $checkpointCount = count($checkpoints);
    htmlResponse(200, dashboardLayout('داشبورد', <<<HTML
      <div class="app-shell">
        <header class="topbar"><div class="topbar-inner"><div class="brand"><strong>Mahak × Mixin Bridge</strong><small>v{$appVersion}</small></div><form method="post" action="/logout"><input type="hidden" name="csrf" value="{$csrf}"><button class="icon-button" type="submit" title="خروج" aria-label="خروج">{$logoutIcon}</button></form></div></header>

        <main class="main-content">
          <section class="page-heading" id="overview">
            <div><div class="heading-kicker"><span class="live-dot"></span>سرویس فعال · فقط پل</div><h1>مرکز گزارش همگام‌سازی</h1><p>سلامت اتصال‌ها، نتیجه اجراها و آخرین تغییرات ثبت‌شده در Bridge.</p></div>
          </section>

          <section class="connection-panel" aria-label="وضعیت اتصال APIها">
            <div class="connection-panel-head"><div><span>CONNECTION.STATUS</span><strong>وضعیت ارتباط سرویس‌ها</strong></div><small>آخرین بررسی: {$checkedAtLabel}</small></div>
            <div class="api-grid">
              <article class="api-card"><div class="api-card-icon mahak">M</div><div class="api-card-copy"><span>منبع داده</span><strong>API محک</strong><small>احراز هویت و دریافت اطلاعات</small></div>{$mahakBadge}</article>
              <article class="api-card"><div class="api-card-icon mixin">X</div><div class="api-card-copy"><span>مقصد داده</span><strong>API میکسین</strong><small>سلامت فروشگاه و دسترسی API</small></div>{$mixinBadge}</article>
              <form method="post" action="/dashboard/refresh-status" class="refresh-card"><input type="hidden" name="csrf" value="{$csrf}"><button type="submit" aria-label="بروزرسانی وضعیت اتصال APIها"><span class="refresh-icon">{$refreshIcon}</span><strong>بررسی دوباره</strong><small>اجرای تست اتصال هر دو API</small></button></form>
            </div>
          </section>

          <section class="metric-grid" aria-label="شاخص‌های اصلی">
            <article class="metric-card accent-blue"><div class="metric-icon">{$packageIcon}</div><span>محصولات نگاشت‌شده</span><strong>{$mappingCount}</strong><small>شناسه مبدأ و مقصد ثبت‌شده</small></article>
            <article class="metric-card accent-green"><div class="metric-icon">{$checkIcon}</div><span>نرخ موفقیت اجراهای اخیر</span><strong>{$successRateLabel}</strong><small>{$successCount} موفق از {$completedCount} اجرای پایان‌یافته</small></article>
            <article class="metric-card accent-violet"><div class="metric-icon">{$activityIcon}</div><span>اجراهای اخیر</span><strong>{$runCount}</strong><small>{$failedCount} ناموفق · {$runningCount} در حال اجرا</small></article>
            <article class="metric-card accent-amber"><div class="metric-icon">{$clockIcon}</div><span>آخرین اجرای موفق</span><strong class="metric-date">{$lastSuccessAt}</strong><small>مدت آخرین اجرا: {$latestDuration}</small></article>
          </section>

          <section class="workspace-grid" id="activity">
            <article class="panel console-panel"><div class="panel-header"><div><span class="section-icon dark">{$terminalIcon}</span><div><h2>جریان فعالیت اخیر</h2><p>خلاصه ۶ اجرای آخر به سبک کنسول</p></div></div><span class="panel-chip {$latestStatusClass}">آخرین وضعیت: {$latestStatusLabel}</span></div><div class="console-window"><div class="console-toolbar"><span></span><span></span><span></span><b>bridge@monitor: ~/sync/logs</b></div><div class="console-body">{$consoleLines}</div></div></article>
            <aside class="panel totals-panel"><div class="panel-header"><div><span class="section-icon">{$layersIcon}</span><div><h2>جمع عملیات</h2><p>بر مبنای {$runCount} اجرای اخیر</p></div></div></div><div class="total-list"><div><span>دریافت‌شده</span><strong>{$totalReceived}</strong></div><div><span>ایجادشده</span><strong>{$totalCreated}</strong></div><div><span>بروزرسانی‌شده</span><strong>{$totalUpdated}</strong></div><div><span>ردشده</span><strong>{$totalSkipped}</strong></div></div></aside>
          </section>

          <section class="panel runs-panel" id="runs"><div class="panel-header"><div><span class="section-icon">{$fileIcon}</span><div><h2>جزئیات لاگ اجراها</h2><p>وضعیت، مسیر انتقال، آمار رکوردها، مدت و خطای هر اجرا</p></div></div><span class="panel-chip neutral">حداکثر ۱۰۰ رکورد اخیر</span></div><div class="desktop-table"><div class="table-wrap"><table><thead><tr><th>اجرا</th><th>وضعیت</th><th>مسیر / موجودیت</th><th>دریافت</th><th>ایجاد</th><th>بروزرسانی</th><th>ردشده</th><th>زمان / مدت</th><th>نتیجه</th></tr></thead><tbody>{$runRows}</tbody></table></div></div><div class="mobile-runs">{$runCards}</div></section>

          <section class="panel checkpoints-panel" id="checkpoints"><div class="panel-header"><div><span class="section-icon">{$databaseIcon}</span><div><h2>نقاط ادامه همگام‌سازی</h2><p>آخرین RowVersion ثبت‌شده برای دریافت افزایشی</p></div></div><span class="panel-chip neutral">{$checkpointCount} موجودیت</span></div><div class="checkpoint-grid">{$checkpointRows}</div></section>

          <footer><span>Mahak × Mixin Bridge · v{$appVersion}</span><span>گزارش سلامت اتصال و تاریخچه همگام‌سازی</span></footer>
        </main>
      </div>
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
    $assetVersion = rawurlencode(Version::current());
    return <<<HTML
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$safeTitle} | Bridge</title><style>
@font-face{font-family:'Vazirmatn';src:url('/assets/fonts/Vazirmatn.woff2') format('woff2');font-style:normal;font-weight:100 900;font-display:swap}
:root{font-family:'Vazirmatn',Tahoma,Arial,sans-serif;color:#eef2f8;background:#080c14;font-synthesis:none;--ink:#eef2f8;--muted:#8e9aae;--line:#242d3d;--panel:#111824;--soft:#161e2c;--blue:#6f8cff;--green:#38d39f;--red:#ff7373;--amber:#f4ad4f;--violet:#9c82ff;--nav:#050914}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:radial-gradient(circle at 85% 0,#eef3ff 0,transparent 32%),#f5f7fb;color:var(--ink);line-height:1.55}button,input{font:inherit}button{cursor:pointer}.icon{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none}.app-shell{display:grid;grid-template-columns:minmax(0,1fr) 252px;min-height:100vh}.sidebar{grid-column:2;grid-row:1;position:sticky;top:0;height:100vh;padding:25px 18px 20px;background:rgba(255,255,255,.94);border-left:1px solid var(--line);display:flex;flex-direction:column;backdrop-filter:blur(16px);z-index:10}.brand{display:flex;align-items:center;gap:11px}.brand-mark{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:var(--nav);color:#fff;box-shadow:0 8px 20px rgba(17,24,39,.18)}.brand-mark .icon{width:22px;height:22px}.brand strong{display:block;font-size:15px;letter-spacing:-.2px}.brand small{display:block;color:#8a94a5;font-size:11px;margin-top:1px}.sidebar nav{display:flex;flex-direction:column;gap:6px;margin-top:38px}.sidebar nav a{display:flex;align-items:center;gap:11px;color:#667085;text-decoration:none;padding:11px 12px;border-radius:10px;font-size:13px;font-weight:600;transition:.2s}.sidebar nav a:hover{color:var(--ink);background:#f4f6fa}.sidebar nav a.active{color:var(--ink);background:#eef2ff;box-shadow:inset -3px 0 var(--blue)}.sidebar nav .icon{width:18px;height:18px}.sidebar-spacer{flex:1}.read-only-note{display:flex;align-items:flex-start;gap:10px;padding:13px;background:#f4f6fa;border:1px solid var(--line);border-radius:12px;color:#475467}.read-only-note .icon{width:18px;margin-top:2px}.read-only-note strong{display:block;font-size:12px;color:#344054}.read-only-note span{display:block;font-size:10px;margin-top:3px;line-height:1.7}.logout-form{margin-top:10px}.ghost-button{width:100%;display:flex;align-items:center;justify-content:center;gap:8px;border:0;background:transparent;color:#667085;padding:10px;border-radius:10px}.ghost-button:hover{background:#fff1f1;color:#b42318}.ghost-button .icon{width:18px}.main-content{grid-column:1;grid-row:1;width:100%;max-width:1540px;margin:0 auto;padding:32px 34px 40px}.mobile-header{display:none}.page-heading{display:flex;align-items:center;justify-content:space-between;gap:24px;margin-bottom:22px}.heading-kicker{display:flex;align-items:center;gap:7px;color:#0b7d59;font-size:12px;font-weight:700}.live-dot{width:8px;height:8px;border-radius:50%;background:#17b57d;box-shadow:0 0 0 5px rgba(23,181,125,.12)}.page-heading h1{font-size:26px;letter-spacing:-.7px;margin:8px 0 3px}.page-heading p{margin:0;color:var(--muted);font-size:13px}.read-only-pill{display:flex;align-items:center;gap:8px;padding:9px 13px;border:1px solid #dce3ed;background:rgba(255,255,255,.7);border-radius:99px;color:#475467;font-size:12px;font-weight:700}.read-only-pill .icon{width:17px}.api-grid{display:grid;grid-template-columns:1fr 1fr .82fr;gap:12px}.api-card,.refresh-card{min-width:0;background:var(--panel);border:1px solid var(--line);border-radius:15px;box-shadow:0 5px 22px rgba(15,23,42,.04)}.api-card{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:12px;padding:15px}.api-card-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;font-size:15px;font-weight:900}.api-card-icon.mahak{color:#3156d3;background:#eef2ff}.api-card-icon.mixin{color:#7759d8;background:#f2efff}.api-card-copy{min-width:0}.api-card-copy>span,.api-card-copy small{display:block;color:#8a94a5;font-size:10px}.api-card-copy strong{display:block;font-size:14px;margin:1px 0}.connection-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 9px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}.connection-badge i{width:7px;height:7px;border-radius:50%;background:currentColor}.connection-badge.connected{color:#087a55;background:#e6f7f0}.connection-badge.disconnected{color:#b42318;background:#feeceb}.connection-badge.unchecked{color:#667085;background:#eef1f5}.refresh-card{overflow:hidden}.refresh-card button{width:100%;height:100%;border:0;background:linear-gradient(135deg,#151c2c,#242f46);color:#fff;display:grid;grid-template-columns:auto 1fr;grid-template-rows:auto auto;text-align:right;column-gap:11px;align-items:center;padding:14px 16px}.refresh-card button:hover .refresh-icon{transform:rotate(45deg)}.refresh-icon{grid-row:1/3;width:39px;height:39px;border-radius:11px;display:grid;place-items:center;background:rgba(255,255,255,.09);transition:.25s}.refresh-icon .icon{width:19px}.refresh-card strong{font-size:12px}.refresh-card small{font-size:10px;color:#aab4c7;margin-top:2px}.metric-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:12px}.metric-card{position:relative;overflow:hidden;background:var(--panel);border:1px solid var(--line);border-radius:15px;padding:17px;box-shadow:0 5px 22px rgba(15,23,42,.04)}.metric-card:after{content:"";position:absolute;bottom:0;right:0;width:100%;height:3px;background:var(--accent)}.metric-card.accent-blue{--accent:#3156d3;--accent-soft:#eef2ff}.metric-card.accent-green{--accent:#0f9f6e;--accent-soft:#e8f8f1}.metric-card.accent-violet{--accent:#7759d8;--accent-soft:#f2efff}.metric-card.accent-amber{--accent:#d97706;--accent-soft:#fff4e6}.metric-icon{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;color:var(--accent);background:var(--accent-soft);margin-bottom:15px}.metric-icon .icon{width:18px}.metric-card>span{display:block;color:#7b8495;font-size:11px}.metric-card>strong{display:block;font-size:25px;margin:2px 0 4px;letter-spacing:-.5px}.metric-card>strong.metric-date{font-size:15px;margin:8px 0 7px;direction:ltr;text-align:right}.metric-card>small{display:block;color:#98a2b3;font-size:10px}.workspace-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(260px,.7fr);gap:12px;margin-top:12px}.panel{background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:0 5px 22px rgba(15,23,42,.04);padding:18px}.panel-header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px}.panel-header>div{display:flex;align-items:center;gap:10px;min-width:0}.panel-header h2{font-size:15px;margin:0;letter-spacing:-.2px}.panel-header p{font-size:10px;color:#8a94a5;margin:2px 0 0}.section-icon{width:36px;height:36px;border-radius:10px;background:#f2f4f7;color:#475467;display:grid;place-items:center}.section-icon.dark{background:#192132;color:#fff}.section-icon .icon{width:18px}.panel-chip{white-space:nowrap;font-size:10px;font-weight:700;border-radius:99px;padding:6px 9px}.panel-chip.success{color:#087a55;background:#e6f7f0}.panel-chip.failed{color:#b42318;background:#feeceb}.panel-chip.running{color:#975a00;background:#fff1d6}.panel-chip.unknown,.panel-chip.neutral{color:#667085;background:#f1f3f6}.console-panel{min-width:0}.console-window{overflow:hidden;border-radius:13px;background:#111827;color:#d7deea;box-shadow:inset 0 0 0 1px rgba(255,255,255,.06)}.console-toolbar{height:39px;display:flex;align-items:center;gap:6px;padding:0 13px;border-bottom:1px solid #293143;direction:ltr}.console-toolbar>span{width:8px;height:8px;border-radius:50%;background:#ef6461}.console-toolbar>span:nth-child(2){background:#eab64d}.console-toolbar>span:nth-child(3){background:#49bc7a}.console-toolbar b{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:10px;color:#7f8aa1;margin-left:8px;font-weight:500}.console-body{padding:13px 15px;direction:ltr;overflow:auto}.console-line{display:grid;grid-template-columns:12px 132px minmax(180px,1fr) auto;align-items:center;gap:8px;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:10px;min-width:600px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.05)}.console-line:last-child{border-bottom:0}.console-prompt{color:#6ee7b7;font-weight:800}.console-time{color:#748098}.console-command{color:#d6dce8}.console-result{font-weight:700}.console-result.success{color:#6ee7b7}.console-result.failed{color:#fca5a5}.console-result.running{color:#fcd34d}.console-result.unknown{color:#aab4c7}.console-empty{direction:ltr;font:11px ui-monospace,SFMono-Regular,Consolas,monospace;color:#8fa0b8;padding:12px}.console-cursor{display:inline-block;width:7px;height:13px;background:#6ee7b7;margin-left:6px;vertical-align:-2px;animation:blink 1.1s infinite}@keyframes blink{50%{opacity:0}}.total-list{display:grid;grid-template-columns:1fr 1fr;gap:8px}.total-list>div{padding:12px;border:1px solid #edf0f4;background:#fafbfc;border-radius:11px}.total-list span{display:block;color:#7b8495;font-size:10px}.total-list strong{font-size:20px}.read-only-banner{display:flex;align-items:flex-start;gap:9px;padding:11px;margin-top:12px;border-radius:11px;background:#eef2ff;color:#475467}.read-only-banner .icon{width:18px;color:#3156d3;margin-top:1px}.read-only-banner p{font-size:10px;line-height:1.8;margin:0}.read-only-banner strong{color:#253b80}.runs-panel,.checkpoints-panel{margin-top:12px}.table-wrap{overflow:auto;margin:0 -18px -18px}.desktop-table table{width:100%;border-collapse:collapse;white-space:nowrap}.desktop-table th,.desktop-table td{text-align:right;padding:12px 11px;border-bottom:1px solid #edf0f5;font-size:11px;vertical-align:middle}.desktop-table th{position:sticky;top:0;color:#7b8495;background:#fafbfc;font-weight:600}.desktop-table tbody tr:hover{background:#fafbff}.desktop-table td:first-child,.desktop-table th:first-child{padding-right:18px}.desktop-table td:last-child,.desktop-table th:last-child{padding-left:18px}.run-id{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;direction:ltr;color:#344054;font-weight:700}.status-badge{display:inline-flex;align-items:center;gap:5px;border-radius:99px;padding:5px 8px;font-size:10px;font-weight:700}.status-badge .icon{width:13px;height:13px}.status-badge.success{color:#087a55;background:#e6f7f0}.status-badge.failed{color:#b42318;background:#feeceb}.status-badge.running{color:#975a00;background:#fff1d6}.status-badge.unknown{color:#667085;background:#eef1f5}.code-value{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:10px;direction:ltr;display:block}.desktop-table td small{display:block;color:#98a2b3;font-size:9px;margin-top:2px}.date-value{direction:ltr;display:block;text-align:right}.muted{color:#98a2b3}.error-text{display:block;max-width:220px;color:#b42318;white-space:normal;line-height:1.6}.mobile-runs{display:none}.checkpoint-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.checkpoint-card{display:flex;align-items:center;gap:11px;padding:13px;border:1px solid #edf0f4;background:#fafbfc;border-radius:12px;min-width:0}.checkpoint-icon{width:37px;height:37px;display:grid;place-items:center;border-radius:10px;background:#eef2ff;color:#3156d3}.checkpoint-icon .icon{width:18px}.checkpoint-card>div:last-child{min-width:0}.checkpoint-card span,.checkpoint-card small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.checkpoint-card span{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:9px;color:#687386;direction:ltr;text-align:right}.checkpoint-card strong{display:block;font-size:16px;margin:2px 0}.checkpoint-card small{color:#98a2b3;font-size:9px}.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:30px;color:#98a2b3;text-align:center}.empty-state .icon{width:28px;height:28px}.empty-state strong{color:#667085;font-size:13px}.empty-state span{font-size:10px}footer{display:flex;align-items:center;justify-content:space-between;color:#98a2b3;font-size:10px;padding:24px 3px 0}.login-card{width:min(420px,92%);margin:10vh auto;background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 25px 70px rgba(15,23,42,.12);padding:30px}.login-card:before{content:"$ bridge.login";display:block;direction:ltr;text-align:left;font:11px ui-monospace,SFMono-Regular,Consolas,monospace;color:#6b7a90;background:#111827;margin:-30px -30px 25px;padding:12px 16px;border-radius:18px 18px 0 0}.login-card h1{font-size:22px;margin:0 0 5px}.login-card p{color:#7b8495;font-size:12px}.login-card label{display:block;margin:16px 0;color:#475467;font-size:12px;font-weight:600}.login-card input{display:block;width:100%;margin-top:7px;padding:12px;border:1px solid #d7dde6;border-radius:10px;outline:none;background:#fafbfc}.login-card input:focus{border-color:#7b91e8;box-shadow:0 0 0 3px #eef2ff}.login-card button{width:100%;border:0;border-radius:10px;background:#3156d3;color:#fff;padding:12px;font-weight:700}.alert{padding:10px;border-radius:9px;background:#feeceb;color:#b42318;font-size:11px}
@media(max-width:1100px){.app-shell{grid-template-columns:minmax(0,1fr) 220px}.main-content{padding:28px 24px}.api-grid{grid-template-columns:1fr 1fr}.refresh-card{grid-column:1/-1}.refresh-card button{min-height:68px}.metric-grid{grid-template-columns:1fr 1fr}.workspace-grid{grid-template-columns:1fr}.checkpoint-grid{grid-template-columns:1fr 1fr}}
@media(max-width:760px){body{background:#f5f7fb}.app-shell{display:block}.sidebar{display:none}.main-content{padding:0 14px 28px}.mobile-header{display:flex;align-items:center;justify-content:space-between;margin:0 -14px 22px;padding:12px 15px;background:rgba(255,255,255,.94);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:20;backdrop-filter:blur(14px)}.mobile-header .brand-mark{width:36px;height:36px;border-radius:10px}.mobile-header .brand strong{font-size:13px}.icon-button{width:38px;height:38px;display:grid;place-items:center;border:1px solid var(--line);border-radius:10px;background:#fff;color:#667085}.icon-button .icon{width:18px}.page-heading{align-items:flex-start;margin-bottom:18px}.page-heading h1{font-size:21px;margin-top:6px}.page-heading p{font-size:11px;line-height:1.8;max-width:290px}.read-only-pill{display:none}.api-grid{grid-template-columns:1fr}.refresh-card{grid-column:auto}.api-card{padding:13px}.metric-grid{grid-template-columns:1fr 1fr;gap:9px;margin-top:9px}.metric-card{padding:14px;min-height:139px}.metric-icon{margin-bottom:12px}.metric-card>strong{font-size:22px}.metric-card>strong.metric-date{font-size:12px;line-height:1.7}.workspace-grid{margin-top:9px;gap:9px}.panel{padding:14px;border-radius:14px}.panel-header{align-items:flex-start;margin-bottom:13px}.panel-header h2{font-size:14px}.panel-header p{font-size:9px}.panel-chip{font-size:9px;max-width:130px;overflow:hidden;text-overflow:ellipsis}.console-body{padding:10px 12px}.console-line{grid-template-columns:12px 118px minmax(165px,1fr) auto}.runs-panel,.checkpoints-panel{margin-top:9px}.desktop-table{display:none}.mobile-runs{display:grid;gap:9px}.run-card{border:1px solid #e9edf3;border-radius:12px;padding:12px;background:#fbfcfd}.run-card-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.run-card-head>div{display:flex;align-items:center;gap:7px}.duration{font-size:9px;color:#8a94a5}.run-card-source{display:flex;align-items:center;gap:7px;margin:10px 0;color:#687386;font-size:10px;direction:ltr;justify-content:flex-end}.run-card-source .code-value{background:#eef1f5;border-radius:6px;padding:3px 6px}.run-card-stats{display:grid;grid-template-columns:repeat(4,1fr);border:1px solid #edf0f4;border-radius:10px;overflow:hidden}.run-card-stats>div{text-align:center;padding:8px 3px;background:#fff;border-left:1px solid #edf0f4}.run-card-stats>div:last-child{border-left:0}.run-card-stats b,.run-card-stats span{display:block}.run-card-stats b{font-size:14px}.run-card-stats span{font-size:8px;color:#8a94a5}.run-card-time{display:flex;justify-content:space-between;gap:8px;margin-top:9px;color:#8a94a5;font-size:8px;direction:ltr}.run-card-error{margin-top:9px;padding:8px;background:#fff0f0;color:#b42318;border-radius:8px;font-size:9px;line-height:1.7}.checkpoint-grid{grid-template-columns:1fr}.checkpoint-card{padding:11px}.total-list{grid-template-columns:repeat(4,1fr)}.total-list>div{padding:9px 6px;text-align:center}.total-list strong{font-size:17px}.total-list span{font-size:8px}footer{display:block;text-align:center;line-height:1.9}footer span{display:block}}
@media(max-width:420px){.main-content{padding-left:10px;padding-right:10px}.mobile-header{margin-left:-10px;margin-right:-10px}.metric-grid{grid-template-columns:1fr}.metric-card{min-height:0;display:grid;grid-template-columns:auto 1fr;grid-template-rows:auto auto auto;column-gap:11px}.metric-icon{grid-row:1/4;margin:0}.metric-card>span,.metric-card>strong,.metric-card>small{grid-column:2}.metric-card>strong{margin:0}.api-card{grid-template-columns:auto 1fr}.api-card .connection-badge{grid-column:2;justify-self:start;margin-top:4px}.total-list{grid-template-columns:1fr 1fr}.panel-header{gap:8px}.panel-header .panel-chip{display:none}.run-card-time{flex-direction:column;gap:2px}.login-card{padding:24px}.login-card:before{margin:-24px -24px 22px}}

/* Dark reporting theme */
body{background:radial-gradient(circle at 88% -10%,#18264b 0,transparent 34%),radial-gradient(circle at 8% 55%,#101b31 0,transparent 27%),#080c14;color:var(--ink)}
.app-shell{display:block;min-height:100vh}.sidebar,.mobile-header,.read-only-pill,.read-only-banner{display:none!important}
.topbar{position:sticky;top:0;z-index:30;background:rgba(8,12,20,.9);border-bottom:1px solid var(--line);backdrop-filter:blur(18px)}.topbar-inner{width:min(1480px,calc(100% - 48px));height:70px;margin:0 auto;display:flex;align-items:center;gap:18px}.topbar .brand{display:flex;align-items:center;gap:11px;white-space:nowrap}.topbar .brand-mark{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:linear-gradient(135deg,#6f8cff,#7857dc);color:#fff;box-shadow:0 8px 24px rgba(90,103,235,.28)}.topbar .brand strong{color:#f7f9fd}.topbar .brand small{color:#718097}.topbar nav{display:flex;align-items:center;gap:5px;margin-inline-start:16px}.topbar nav a{display:flex;align-items:center;gap:7px;color:#8d99ad;text-decoration:none;padding:9px 11px;border-radius:9px;font-size:11px;font-weight:600;transition:.2s;white-space:nowrap}.topbar nav a:hover{color:#e8edf6;background:#151d2b}.topbar nav a.active{color:#fff;background:#1a2434;box-shadow:inset 0 -2px var(--blue)}.topbar nav .icon{width:16px;height:16px}.topbar form{margin-inline-start:auto}.icon-button{width:39px;height:39px;display:grid;place-items:center;border:1px solid var(--line);border-radius:10px;background:#121a27;color:#9ca8ba;transition:.2s}.icon-button:hover{color:#ff8b8b;border-color:#693a45;background:#24141b}.icon-button .icon{width:18px}.main-content{display:block;width:100%;max-width:1540px;margin:0 auto;padding:30px 34px 40px}.page-heading h1{color:#f7f9fd}.heading-kicker{color:#55d8a9}.live-dot{background:#38d39f;box-shadow:0 0 0 5px rgba(56,211,159,.12)}
.api-card,.refresh-card,.metric-card,.panel{background:var(--panel);border-color:var(--line);box-shadow:0 8px 28px rgba(0,0,0,.18)}.api-card-icon.mahak{color:#8ea2ff;background:#1c2743}.api-card-icon.mixin{color:#b19eff;background:#281f43}.api-card-copy>span,.api-card-copy small,.metric-card>span,.metric-card>small,.panel-header p{color:#7f8ba0}.connection-badge.connected{color:#63e4b5;background:#123c32}.connection-badge.disconnected{color:#ff8f8f;background:#412126}.connection-badge.unchecked{color:#a5b0c1;background:#202938}.refresh-card button{background:linear-gradient(135deg,#1b2740,#263653)}.metric-card.accent-blue{--accent:#6f8cff;--accent-soft:#1b2742}.metric-card.accent-green{--accent:#38d39f;--accent-soft:#15382f}.metric-card.accent-violet{--accent:#9c82ff;--accent-soft:#282142}.metric-card.accent-amber{--accent:#f4ad4f;--accent-soft:#3d2c17}.section-icon{background:#1b2433;color:#9aa7ba}.section-icon.dark{background:#050914;color:#e9eef7}.panel-chip.success{color:#63e4b5;background:#123c32}.panel-chip.failed{color:#ff8f8f;background:#412126}.panel-chip.running{color:#f5be69;background:#3f301a}.panel-chip.unknown,.panel-chip.neutral{color:#a5b0c1;background:#202938}.total-list>div{border-color:#273144;background:#0d1420}.total-list span{color:#7f8ba0}.console-window{background:#050914}.console-toolbar{border-color:#1e293b}.desktop-table th,.desktop-table td{border-color:#232d3d}.desktop-table th{color:#8995a8;background:#0d1420}.desktop-table tbody tr:hover{background:#151e2c}.run-id{color:#c3cede}.status-badge.success{color:#63e4b5;background:#123c32}.status-badge.failed{color:#ff8f8f;background:#412126}.status-badge.running{color:#f5be69;background:#3f301a}.status-badge.unknown{color:#a5b0c1;background:#202938}.muted{color:#748196}.error-text{color:#ff8585}.checkpoint-card{border-color:#273144;background:#0d1420}.checkpoint-icon{color:#8ea2ff;background:#1b2743}.checkpoint-card span{color:#8290a5}.checkpoint-card small,footer{color:#6f7b8f}.empty-state strong{color:#aab5c5}.login-card{background:#111824;border-color:#283246;box-shadow:0 25px 70px rgba(0,0,0,.4)}.login-card:before{background:#050914;color:#748197}.login-card p,.login-card label{color:#8d99ad}.login-card input{color:#eef2f8;border-color:#2a3548;background:#0c131e}.login-card input:focus{border-color:#6f8cff;box-shadow:0 0 0 3px rgba(111,140,255,.16)}.alert{color:#ff9494;background:#412126}
@media(max-width:760px){body{background:radial-gradient(circle at 80% -5%,#18264b 0,transparent 30%),#080c14}.topbar-inner{width:calc(100% - 24px);height:auto;display:grid;grid-template-columns:1fr auto;gap:9px;padding:10px 0 0}.topbar .brand{grid-column:1}.topbar form{grid-column:2;grid-row:1;margin:0}.topbar nav{grid-column:1/-1;grid-row:2;margin:0;overflow-x:auto;padding-bottom:9px;scrollbar-width:none}.topbar nav::-webkit-scrollbar{display:none}.topbar nav a{padding:8px 9px}.main-content{padding:20px 14px 28px}.page-heading{margin-bottom:18px}.run-card{border-color:#273144;background:#0d1420}.run-card-source .code-value{background:#1b2433}.run-card-stats{border-color:#273144}.run-card-stats>div{background:#111824;border-color:#273144}.run-card-error{color:#ff9494;background:#412126}}
@media(max-width:420px){.main-content{padding-left:10px;padding-right:10px}.topbar-inner{width:calc(100% - 20px)}.topbar .brand-mark{width:36px;height:36px}.topbar .brand strong{font-size:13px}.topbar nav{justify-content:space-between;gap:2px}.topbar nav a{gap:5px;font-size:10px;padding:8px 7px}}
</style><link rel="stylesheet" href="/assets/dashboard.css?v={$assetVersion}"></head><body>{$body}</body></html>
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
