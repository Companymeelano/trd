<?php
/**
 * GET /api/health.php — بررسی سلامت کلی سامانه (عمومی، بدون اطلاعات حساس).
 * برای مانیتورینگ و چک خودکار هنگام ورود به برنامه.
 */

require __DIR__ . '/bootstrap.php';

/* CORS (v5.8.1): سلامت، دادهٔ عمومی و بدون احراز است — اپ اندروید میلانو
   و پایشگرهای بیرونی می‌توانند آن را از مبدأ متفاوت بخوانند. */
if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
}

use Meelano\Ai\Health;
use Meelano\Ai\Registry;
use Meelano\Ai\Router;
use Meelano\Config;
use Meelano\Db;
use Meelano\Installer;
use Meelano\Schema;
use Meelano\Security;

$started = m_microtime();
$checks = [];

/* ── PHP ─────────────────────────────────────────────────────────────── */
$checks['php'] = [
    'ok' => PHP_VERSION_ID >= 70400,
    'value' => PHP_VERSION,
    'label' => 'نسخه PHP',
];
foreach (['curl' => 'cURL', 'pdo_mysql' => 'PDO MySQL', 'pdo_sqlite' => 'PDO SQLite', 'mbstring' => 'mbstring', 'openssl' => 'OpenSSL'] as $ext => $label) {
    $checks['ext_' . $ext] = [
        'ok' => extension_loaded($ext),
        'value' => extension_loaded($ext) ? 'فعال' : 'غیرفعال',
        'label' => 'افزونه ' . $label,
    ];
}

/* ── دیتابیس ─────────────────────────────────────────────────────────── */
$dbOk = false;
$installStatus = null;
$stats = null;
try {
    $db = Db::make();
    $dbOk = $db->isConnected();
    $checks['db'] = [
        'ok' => $dbOk,
        'value' => $dbOk ? $db->driver() . ' متصل' : 'قطع',
        'label' => 'پایگاه‌داده',
    ];
    if ($dbOk) {
        $installStatus = (new Installer($db))->status();
        $stats = null;
        try {
            if ($db->tableExists('signals')) {
                $row = $db->selectOne("SELECT COUNT(*) AS total, COALESCE(AVG(combined_score),0) AS avg_score, SUM(CASE WHEN side='BUY' THEN 1 ELSE 0 END) AS buys FROM " . $db->table('signals'));
                $stats = ['signals' => (int)($row['total'] ?? 0), 'avg_score' => round((float)($row['avg_score'] ?? 0),1), 'buys' => (int)($row['buys'] ?? 0)];
            }
        } catch (Throwable $e) { $stats = null; }
        $checks['schema'] = [
            'ok' => (bool)$installStatus['complete'],
            'value' => $installStatus['percent'] . '% از جدول‌ها',
            'label' => 'ساختار جداول',
        ];
    }
} catch (Throwable $e) {
    $checks['db'] = ['ok' => false, 'value' => $e->getMessage(), 'label' => 'پایگاه‌داده'];
}

/* ── پوشه‌های نوشتنی ─────────────────────────────────────────────────── */
foreach ([MEELANO_CONFIG => 'config', MEELANO_UPLOADS => 'storage/uploads', MEELANO_LOGS => 'storage/logs'] as $dir => $label) {
    $checks['writable_' . $label] = [
        'ok' => is_writable($dir),
        'value' => is_writable($dir) ? 'قابل نوشتن' : 'فقط‌خواندنی',
        'label' => 'مجوز ' . $label,
    ];
}

/* ── ارائه‌دهندگان AI (از کش دیتابیس، بدون مصرف سهمیه) ─────────────── */
$providers = [];
$healthyCount = 0;
$configuredCount = 0;
$healthCache = [];
if ($dbOk) {
    $healthCache = Health::cached(Db::make());
}
foreach (Registry::providers() as $id => $meta) {
    $cfg = (array)Config::get('ai.providers.' . $id, []);
    $keyField = $meta['key_field'] ?? 'api_key';
    $configured = !empty($cfg[$keyField]);
    $row = $healthCache[$id] ?? null;
    $ok = $row !== null && (int)$row['ok'] === 1;
    if ($configured) {
        $configuredCount++;
    }
    if ($ok) {
        $healthyCount++;
    }
    $providers[$id] = [
        'label' => $meta['label'],
        'configured' => $configured,
        'ok' => $ok,
        'latency_ms' => $row !== null ? (int)$row['latency_ms'] : null,
        'message' => $row['message'] ?? ($configured ? 'هنوز تست نشده' : 'کلید وارد نشده'),
        'tested_at' => $row['tested_at'] ?? null,
    ];
}
$checks['ai'] = [
    'ok' => $healthyCount > 0,
    'value' => sprintf('%d سالم از %d پیکربندی‌شده', $healthyCount, $configuredCount),
    'label' => 'هوش مصنوعی',
];

/* ── مسیریابی ────────────────────────────────────────────────────────── */
$router = new Router(Config::all(), $healthCache);
$assigned = 0;
$taskMap = [];
foreach (array_keys(Registry::tasks()) as $task) {
    $picked = $router->pick($task);
    $taskMap[$task] = $picked;
    if ($picked !== null) {
        $assigned++;
    }
}
$checks['routing'] = [
    'ok' => $assigned > 0,
    'value' => sprintf('%d از %d وظیفه مسیریابی شده', $assigned, count($taskMap)),
    'label' => 'مسیریابی وظایف',
];

$allOk = true;
foreach ($checks as $c) {
    if (empty($c['ok'])) {
        $allOk = false;
        break;
    }
}

m_json([
    'ok' => true,
    'healthy' => $allOk,
    'version' => MEELANO_VERSION,
    'codename' => MEELANO_CODENAME,
    'schema_version' => Schema::VERSION,
    'checks' => $checks,
    'providers' => $providers,
    'routing' => $taskMap,
    'mode' => (string)Config::get('routing.mode', 'auto'),
    'stats' => $stats,
    'install' => $installStatus,
    'logged_in' => Security::isLoggedIn(),
    'jalali_date' => m_jalali(),
    'latency_ms' => round((m_microtime() - $started) * 1000, 1),
]);
