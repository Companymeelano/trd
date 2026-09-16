<?php
/**
 * POST /api/tracker.php — ردیاب سیگنال: داوری سیگنال‌های گذشته با کندل واقعی.
 *
 * ورودی: {action?: "run|stats", limit?: 60}
 * خروجی run:  تعداد بررسی/به‌روزرسانی/بسته‌شده
 * خروجی stats: عملکرد واقعی به تفکیک (درجه × رژیم) + جمع کل
 *
 * کران (Cron): برای اجرای زمان‌بندی‌شده بدون نشست، کلید امنیتی تنظیم شده
 * (security.cron_key) را بفرستید: GET ?action=run&key=CRON_KEY
 * راهنمای تنظیم: هر ۱ ساعت یک‌بار از cron-job.org یا cPanel.
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Config;
use Meelano\Crypto\MarketData;
use Meelano\Crypto\SignalTracker;
use Meelano\Security;

$in = m_input();
$action = m_clean_string($in['action'] ?? 'stats', 12);

// اجرای کران با کلید امنیتی (بدون نشست) — فقط برای action=run
$cronKey = (string)Config::get('security.cron_key', '');
$keyProvided = (string)($_GET['key'] ?? ($in['key'] ?? ''));
$cronOk = $cronKey !== '' && $keyProvided !== '' && hash_equals($cronKey, $keyProvided);

if (!$cronOk) {
    m_guard(false, 'tracker');
    Security::requireRateLimit('tracker', 10);
}

$db = Db::make();
if (!$db->isConnected()) {
    m_json(['ok' => false, 'error' => 'پایگاه‌داده در دسترس نیست.'], 503);
}

$tracker = new SignalTracker($db, new MarketData(null, (array)Config::get('market', [])),
    array_merge((array)Config::get('trading', []), (array)Config::get('market', [])));

if ($action === 'run') {
    $limit = max(1, min(200, (int)($in['limit'] ?? 60)));
    m_json($tracker->run($limit) + ['stats' => $tracker->stats()]);
}

m_json(['ok' => true] + $tracker->stats());
