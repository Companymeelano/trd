<?php
declare(strict_types=1);

if (!defined('TRD_APP')) define('TRD_APP', true);

function env_value(string $key, string $default = ''): string {
    $v = getenv($key);
    if ($v !== false) return (string)$v;
    if (isset($_ENV[$key])) return (string)$_ENV[$key];
    if (isset($_SERVER[$key])) return (string)$_SERVER[$key];
    return $default;
}

$envPath = __DIR__ . '/.env';

if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;

        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);

        if ($k !== '' && getenv($k) === false) {
            $v = trim($v, " \t\n\r\0\x0B\"'");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
    }
}

define('APP_TOKEN', env_value('APP_TOKEN'));
define('APP_DEBUG', filter_var(env_value('APP_DEBUG', '0'), FILTER_VALIDATE_BOOL));
define('DB_PATH', __DIR__ . '/../data/signals.sqlite');
define('CACHE_DIR', __DIR__ . '/../storage/cache');
define('LOG_DIR', __DIR__ . '/../storage/logs');
define('CACHE_TTL', (int)env_value('CACHE_TTL', '60'));
define('BINANCE_BASE_URL', rtrim(env_value('BINANCE_BASE_URL', 'https://api.binance.com'), '/'));

/* ---------- Notify / Telegram / Webhook (اختیاری) ---------- */
define('NOTIFY_TG_TOKEN', env_value('NOTIFY_TG_TOKEN'));
define('NOTIFY_TG_CHAT_ID', env_value('NOTIFY_TG_CHAT_ID'));
define('NOTIFY_WEBHOOK_URL', env_value('NOTIFY_WEBHOOK_URL'));

/* ---------- ثابت‌های مورد نیاز backtest مستقل ---------- */
define('DEFAULT_SYMBOLS', [
    'BTCUSDT','ETHUSDT','BNBUSDT','SOLUSDT','XRPUSDT','ADAUSDT','DOGEUSDT','AVAXUSDT',
    'LINKUSDT','TONUSDT','TRXUSDT','DOTUSDT','LTCUSDT','BCHUSDT','NEARUSDT','APTUSDT',
    'SUIUSDT','ATOMUSDT','FILUSDT','ETCUSDT',
]);

define('DEFAULT_TIMEFRAMES', ['15m', '1h', '4h', '1d']);

/* ---------- Helperهای مشترک API ---------- */

function require_auth(): void {
    $token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    $valid = defined('APP_TOKEN')
        && APP_TOKEN !== ''
        && hash_equals(APP_TOKEN, (string)$token);

    if (!$valid) {
        json_error('Invalid API token.', 401);
    }
}

function get_json_body(): ?array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return null;

    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function json_error(string $message, int $status = 500, ?Throwable $exception = null): void {
    $payload = ['status' => 'error', 'message' => $message];

    if (APP_DEBUG && $exception !== null) {
        $payload['detail'] = $exception->getMessage();
        $payload['file']   = basename($exception->getFile());
        $payload['line']   = $exception->getLine();
    }

    json_response($payload, $status);
}
