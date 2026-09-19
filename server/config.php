<?php
declare(strict_types=1);

/**
 * Milano Studio Trading Engine — Configuration Bootstrap
 *
 * محل فایل:
 * /home/meelanoi/public_html/trader/config.php
 *
 * فایل تنظیمات محیطی:
 * /home/meelanoi/public_html/trader/.env
 */

// ---------------------------------------------------------------------
// Environment Parser
// ---------------------------------------------------------------------

/**
 * فایل .env را بدون بازنویسی متغیرهای واقعی سیستم بارگذاری می‌کند.
 */
function milano_load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file(
        $path,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    if ($lines === false) {
        return;
    }

    foreach ($lines as $lineNumber => $line) {
        /*
         * حذف BOM از ابتدای اولین خط برای جلوگیری از خراب شدن نام کلیدها
         */
        if ($lineNumber === 0) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
        }

        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);

        $key = trim($key);
        $value = trim($value);

        if (
            $key === ''
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1
        ) {
            continue;
        }

        /*
         * حذف کوتیشن‌های احاطه‌کننده (Single & Double Quotes)
         */
        if (strlen($value) >= 2) {
            $firstChar = $value[0];
            $lastChar = $value[strlen($value) - 1];

            if ($firstChar === '"' && $lastChar === '"') {
                $value = substr($value, 1, -1);
                $value = str_replace(
                    ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                    ["\n", "\r", "\t", '"', '\\'],
                    $value
                );
            } elseif ($firstChar === "'" && $lastChar === "'") {
                $value = substr($value, 1, -1);
                $value = str_replace(
                    ["\\'", '\\\\'],
                    ["'", '\\'],
                    $value
                );
            }
        }

        $systemValue = getenv($key);
        $serverHasValue = array_key_exists($key, $_SERVER);
        $envHasValue = array_key_exists($key, $_ENV);

        if (
            $systemValue !== false
            || $serverHasValue
            || $envHasValue
        ) {
            continue;
        }

        if (function_exists('putenv')) {
            @putenv($key . '=' . $value);
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

// بارگذاری خودکار فایل .env از مسیر ریشه
milano_load_env(__DIR__ . '/.env');

// ---------------------------------------------------------------------
// Central Configuration Class
// ---------------------------------------------------------------------

final class Config
{
    private const TOKEN_PLACEHOLDERS = [
        '',
        'CHANGE-THIS-TO-A-LONG-RANDOM-STRING',
        'PUT-A-NEW-LONG-RANDOM-TOKEN-HERE',
        'YOUR_REAL_APP_TOKEN',
        'YOUR_APP_TOKEN',
        'APP_TOKEN_VALUE',
    ];

    private const DATABASE_PLACEHOLDERS = [
        '',
        'cpaneluser_milano',
        'FULL_DATABASE_NAME',
        'FULL_DATABASE_USER',
    ];

    private const PASSWORD_PLACEHOLDERS = [
        '',
        'CHANGE-THIS-PASSWORD',
        'PUT-A-NEW-STRONG-DATABASE-PASSWORD-HERE',
        'REAL_DATABASE_PASSWORD',
        'FULL_DATABASE_PASSWORD',
    ];

    private function __construct()
    {
    }

    private static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);

        if ($value !== false) {
            return (string)$value;
        }

        if (array_key_exists($key, $_ENV)) {
            return (string)$_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER)) {
            return (string)$_SERVER[$key];
        }

        return $default;
    }

    private static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);
        return trim((string)$value);
    }

    private static function boolean(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null || trim($value) === '') {
            return $default;
        }

        $normalized = strtolower(trim($value));

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    private static function integer(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = self::get($key);

        if (
            $value === null
            || trim($value) === ''
            || filter_var(trim($value), FILTER_VALIDATE_INT) === false
        ) {
            return $default;
        }

        $number = (int)$value;
        return max($minimum, min($maximum, $number));
    }

    private static function number(string $key, float $default, float $minimum, float $maximum): float
    {
        $value = self::get($key);

        if ($value === null || trim($value) === '' || !is_numeric($value)) {
            return $default;
        }

        $number = (float)$value;

        if (!is_finite($number)) {
            return $default;
        }

        return max($minimum, min($maximum, $number));
    }

    // -----------------------------------------------------------------
    // Application
    // -----------------------------------------------------------------

    public static function appEnv(): string
    {
        $environment = strtolower(self::string('APP_ENV', 'production'));
        $allowed = ['production', 'staging', 'development', 'testing'];

        return in_array($environment, $allowed, true) ? $environment : 'production';
    }

    public static function appDebug(): bool
    {
        return self::boolean('APP_DEBUG', false);
    }

    public static function appToken(): string
    {
        return self::string('APP_TOKEN');
    }

    public static function hasUsableAppToken(): bool
    {
        $token = self::appToken();

        if (in_array($token, self::TOKEN_PLACEHOLDERS, true)) {
            return false;
        }

        return strlen($token) >= 32;
    }

    // -----------------------------------------------------------------
    // SQLite (پایگاه داده پیش‌فرض پروژه برای هاست اشتراکی)

    public static function sqlitePath(): string
    {
        $configured = self::string('SQLITE_PATH');
        return $configured !== '' ? $configured : __DIR__ . '/data/signals.sqlite';
    }

    public static function upstreamUrl(): string
    {
        return self::binanceBaseUrl();
    }

    // -----------------------------------------------------------------
    // Database (سازگار با هر دو فرمت DB_NAME/DB_DATABASE و DB_USER/DB_USERNAME)
    // -----------------------------------------------------------------

    public static function dbHost(): string
    {
        return self::string('DB_HOST', 'localhost');
    }

    public static function dbPort(): int
    {
        return self::integer('DB_PORT', 3306, 1, 65535);
    }

    public static function dbName(): string
    {
        $name = self::string('DB_NAME');
        if ($name !== '') {
            return $name;
        }
        return self::string('DB_DATABASE');
    }

    public static function dbUser(): string
    {
        $user = self::string('DB_USER');
        if ($user !== '') {
            return $user;
        }
        return self::string('DB_USERNAME');
    }

    public static function dbPass(): string
    {
        $password = self::get('DB_PASSWORD');

        if ($password !== null && $password !== '') {
            return $password;
        }

        $passAlt = self::get('DB_PASS');
        if ($passAlt !== null && $passAlt !== '') {
            return $passAlt;
        }

        return '';
    }

    public static function hasUsableDatabaseConfig(): bool
    {
        $host = self::dbHost();
        $name = self::dbName();
        $user = self::dbUser();
        $password = self::dbPass();

        if ($host === '') {
            return false;
        }

        if (in_array($name, self::DATABASE_PLACEHOLDERS, true)) {
            return false;
        }

        if (in_array($user, self::DATABASE_PLACEHOLDERS, true)) {
            return false;
        }

        if (in_array($password, self::PASSWORD_PLACEHOLDERS, true)) {
            return false;
        }

        return true;
    }

    // -----------------------------------------------------------------
    // Redis & Cache
    // -----------------------------------------------------------------

    public static function redisHost(): ?string
    {
        $host = self::string('REDIS_HOST');
        return $host === '' ? null : $host;
    }

    public static function redisPort(): int
    {
        return self::integer('REDIS_PORT', 6379, 1, 65535);
    }

    public static function redisPassword(): string
    {
        return (string)(self::get('REDIS_PASSWORD', '') ?? '');
    }

    public static function cacheTtl(): int
    {
        return self::integer('CACHE_TTL', 60, 1, 86400);
    }

    public static function cacheStaleTtl(): int
    {
        $staleTtl = self::integer('CACHE_STALE_TTL', 300, 1, 604800);
        return max(self::cacheTtl(), $staleTtl);
    }

    // -----------------------------------------------------------------
    // Circuit Breaker
    // -----------------------------------------------------------------

    public static function breakerFailureThreshold(): int
    {
        return self::integer('BREAKER_FAILURE_THRESHOLD', 3, 1, 100);
    }

    public static function breakerOpenTimeout(): int
    {
        return self::integer('BREAKER_OPEN_TIMEOUT', 60, 1, 3600);
    }

    public static function breakerHalfOpenTrials(): int
    {
        return self::integer('BREAKER_HALF_OPEN_TRIALS', 2, 1, 20);
    }

    public static function breakerCooldown(): int
    {
        return self::integer('BREAKER_COOLDOWN', 2, 0, 300);
    }

    // -----------------------------------------------------------------
    // Binance and HTTP
    // -----------------------------------------------------------------

    public static function binanceBaseUrl(): string
    {
        $url = rtrim(
            self::string('BINANCE_BASE_URL', 'https://api.binance.com'),
            '/'
        );

        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || !str_starts_with(strtolower($url), 'https://')
        ) {
            return 'https://api.binance.com';
        }

        return $url;
    }

    public static function upstreamTimeout(): int
    {
        return self::integer('UPSTREAM_TIMEOUT', 8, 2, 30);
    }

    // -----------------------------------------------------------------
    // Trading Risk Controls
    // -----------------------------------------------------------------

    public static function maxRiskPct(): float
    {
        return self::number('MAX_RISK_PCT', 5.0, 0.1, 10.0);
    }

    public static function maxAtrPct(): float
    {
        return self::number('MAX_ATR_PCT', 8.0, 0.1, 100.0);
    }

    public static function minNetRr(): float
    {
        return self::number('MIN_NET_RR', 1.5, 0.1, 20.0);
    }

    // -----------------------------------------------------------------
    // Safe Diagnostics
    // -----------------------------------------------------------------

    public static function configurationErrors(): array
    {
        $errors = [];
        if (!self::hasUsableAppToken()) $errors[] = 'APP_TOKEN';
        $path = self::sqlitePath();
        if ($path === '' || (!is_file($path) && !is_dir(dirname($path)))) $errors[] = 'SQLITE_PATH';
        return array_values(array_unique($errors));
    }

    public static function assertProductionReady(): void
    {
        $errors = self::configurationErrors();

        if ($errors === []) {
            return;
        }

        throw new RuntimeException(
            'Application configuration is incomplete: ' . implode(', ', $errors)
        );
    }
}

// ---------------------------------------------------------------------
// تعریف ثابت‌های سازگاری برای توابع API
// ---------------------------------------------------------------------
if (!defined('APP_TOKEN')) {
    define('APP_TOKEN', Config::appToken());
}

if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', Config::appDebug());
}

if (!defined('BINANCE_BASE_URL')) {
    define('BINANCE_BASE_URL', Config::binanceBaseUrl());
}
