<?php
/**
 * POST /api/db_install.php — ساخت جداول با گزارش پیشرفت لحظه‌ای (SSE).
 *
 * خروجی به‌صورت text/event-stream ارسال می‌شود تا نوار درصد و نام جدول
 * در حال ساخت به‌صورت زنده در UI دیده شود.
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Db;
use Meelano\Installer;
use Meelano\Security;

if ((bool)\Meelano\Config::get('app.require_admin', true) && !Security::isLoggedIn()) {
    m_json(['ok' => false, 'error' => 'ورود لازم است.'], 401);
}
Security::requireRateLimit('db_install', 10);
Security::requireCsrf();

// ── آماده‌سازی SSE ────────────────────────────────────────────────────
if (!headers_sent()) {
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
}
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    @ob_end_flush();
}
@set_time_limit(300);
@ignore_user_abort(false);

function sse(array $event): void
{
    echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @flush();
}

$db = Db::make();

if (!$db->isConnected()) {
    sse(['phase' => 'fatal', 'percent' => 0, 'ok' => false, 'message' => 'اتصال به پایگاه‌داده برقرار نیست. ابتدا اتصال را تست کنید.']);
    exit;
}

sse(['phase' => 'connect', 'percent' => 0, 'ok' => true, 'message' => 'اتصال برقرار شد — درایور ' . $db->driver()]);

$installer = new Installer($db);
$result = $installer->run(static function (array $event): void {
    sse($event);
});

Security::audit('database', 'install', $result['summary']);

sse([
    'phase' => 'complete',
    'percent' => 100,
    'ok' => $result['ok'],
    'result' => $result,
    'status' => $installer->status(),
]);
exit;
