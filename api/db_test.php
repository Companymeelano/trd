<?php
/**
 * POST /api/db_test.php — تست سلامت اتصال به پایگاه‌داده.
 * ورودی: {host,port,name,user,pass,driver,socket} یا خالی (استفاده از تنظیمات ذخیره‌شده)
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Config;
use Meelano\Db;
use Meelano\Security;

m_guard(true, 'db_test');
$in = m_input();

// اگر کاربر مقادیر را در فرم وارد کرده، روی تنظیمات ذخیره‌شده اولویت دارند
$override = [];
foreach (['host', 'port', 'name', 'user', 'pass', 'driver', 'socket'] as $field) {
    if (isset($in[$field]) && $in[$field] !== '') {
        $override[$field] = $field === 'port' ? (int)$in[$field] : (string)$in[$field];
    }
}

$db = Db::make($override);
$result = $db->test(true);

m_json([
    'ok' => $result['ok'],
    'result' => [
        'ok' => $result['ok'],
        'message' => $result['message'],
        'latency_ms' => $result['latency_ms'],
        'server' => $result['server'],
        'driver' => $result['driver'],
        'checks' => $result['checks'],
        'dsn_hint' => $result['ok'] ? null : 'در هاست اشتراکی cPanel مقدار host باید localhost باشد.',
    ],
    'echo' => $override ? array_intersect_key($override, array_flip(['host', 'port', 'name', 'user', 'driver'])) : null,
]);
