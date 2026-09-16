<?php
/**
 * پنل هوشمند میلانو — هسته راه‌اندازی (Bootstrap)
 * Meelano Smart Panel — Core bootstrap
 *
 * مسئولیت‌ها: ثابت‌ها، بارگذار خودکار کلاس‌ها، مدیریت خطا، نشست (Session)،
 * منطقه زمانی و رمزگذاری چندبایتی.
 *
 * سازگاری: PHP 7.4 → 8.4 (بدون enum / readonly / match تا روی هاست اشتراکی هم کار کند)
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */

declare(strict_types=1);

define('MEELANO_VERSION', '5.6.0');
define('MEELANO_CODENAME', 'APEX TRADER · ADAPTIVE LEARNING');

if (!defined('MEELANO_ROOT')) {
    define('MEELANO_ROOT', dirname(__DIR__));
}
define('MEELANO_INCLUDES', MEELANO_ROOT . '/includes');
define('MEELANO_CONFIG', MEELANO_ROOT . '/config');
define('MEELANO_STORAGE', MEELANO_ROOT . '/storage');
define('MEELANO_UPLOADS', MEELANO_STORAGE . '/uploads');
define('MEELANO_CACHE', MEELANO_STORAGE . '/cache');
define('MEELANO_LOGS', MEELANO_STORAGE . '/logs');

date_default_timezone_set('Asia/Tehran');
mb_internal_encoding('UTF-8');

/**
 * مسیرهای ذخیره‌سازی را در صورت نبود می‌سازد.
 */
function meelano_ensure_dirs(): void
{
    foreach ([MEELANO_CONFIG, MEELANO_STORAGE, MEELANO_UPLOADS, MEELANO_CACHE, MEELANO_LOGS] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
    foreach ([
        MEELANO_UPLOADS . '/.gitkeep',
        MEELANO_CACHE   . '/.gitkeep',
        MEELANO_LOGS    . '/.gitkeep',
    ] as $keep) {
        if (!file_exists($keep)) {
            @file_put_contents($keep, '');
        }
    }
}
meelano_ensure_dirs();

/**
 * بارگذار خودکار PSR-4 برای فضای‌نام Meelano\
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Meelano\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = MEELANO_INCLUDES . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require MEELANO_INCLUDES . '/helpers.php';

use Meelano\Config;
use Meelano\Logger;

/* ── مدیریت خطا: در حالت دیباگ نمایش کامل، در پروداکشن فقط لاگ ───────────── */
$appConfig = Config::load();
$debug = (bool)($appConfig['app']['debug'] ?? false);

error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', MEELANO_LOGS . '/php-error.log');

set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    Logger::write('php', sprintf('%s in %s:%d', $str, $file, $line), 'warning');
    return false; // اجازه ادامه به هندلر داخلی PHP
});

set_exception_handler(static function (Throwable $e): void {
    Logger::write('exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 'error');
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'خطای داخلی سامانه',
        'detail' => (defined('MEELANO_DEBUG') && MEELANO_DEBUG) ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
});

/* ── سشن امن ──────────────────────────────────────────────────────────── */
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (meelano_is_https()) {
        ini_set('session.cookie_secure', '1');
    }
    session_name('MEELANO_SESS');
    @session_start();
}

if (!defined('MEELANO_DEBUG')) {
    define('MEELANO_DEBUG', $debug);
}
