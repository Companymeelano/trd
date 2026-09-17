<?php
/**
 * GET /api/db_status.php — بررسی خودکار سلامت دیتابیس هنگام ورود.
 * بدون CSRF (فقط خواندنی) اما با احراز هویت.
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Config;
use Meelano\Db;
use Meelano\Installer;
use Meelano\Schema;
use Meelano\Security;

if ((bool)Config::get('app.require_admin', true) && !Security::isLoggedIn()) {
    m_json(['ok' => false, 'error' => 'ورود لازم است.'], 401);
}

$started = m_microtime();
$db = Db::make();
$connected = $db->isConnected();

$payload = [
    'ok' => $connected,
    'connected' => $connected,
    'configured' => (string)Config::get('db.name', '') !== '',
    'driver' => $db->driver(),
    'latency_ms' => 0.0,
    'install' => null,
    'stats' => null,
    'schema_version' => Schema::VERSION,
    'checked_at' => m_jalali() . ' — ' . date('H:i:s'),
];

if ($connected) {
    $installer = new Installer($db);
    $payload['install'] = $installer->status();
    try {
        if ($db->tableExists('signals')) {
            $row = $db->selectOne("SELECT COUNT(*) AS total, COALESCE(AVG(combined_score),0) AS avg_score FROM " . $db->table('signals'));
            $payload['stats'] = ['signals' => (int)($row['total'] ?? 0), 'avg_score' => round((float)($row['avg_score'] ?? 0),1)];
        }
    } catch (Throwable $e) { $payload['stats'] = null; }

    // ثبت وضعیت در app_state تا «آخرین بررسی موفق» قابل نمایش باشد
    try {
        if ($db->tableExists('app_state')) {
            $state = json_encode([
                'last_check' => date('Y-m-d H:i:s'),
                'complete' => $payload['install']['complete'],
                'signals' => is_array($payload['stats']) ? ($payload['stats']['signals'] ?? 0) : 0,
            ], JSON_UNESCAPED_UNICODE);
            if ($db->selectOne('SELECT k FROM ' . $db->table('app_state') . ' WHERE k = ?', ['health'])) {
                $db->update('app_state', ['v' => $state, 'updated_at' => date('Y-m-d H:i:s')], 'k = ?', ['health']);
            } else {
                $db->insert('app_state', ['k' => 'health', 'v' => $state, 'updated_at' => date('Y-m-d H:i:s')]);
            }
        }
    } catch (Throwable $e) {
        // اختیاری
    }
} else {
    $payload['hint'] = 'از بخش تنظیمات اتصال را تست و جدول‌ها را بسازید.';
}

$payload['latency_ms'] = round((m_microtime() - $started) * 1000, 1);
m_json($payload);
