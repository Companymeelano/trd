<?php
namespace Meelano;

/**
 * خزانهٔ تنظیمات — با رمزنگاری در حالت سکون (AES-256-GCM).
 *
 * فایل `config/settings.php` در .gitignore است و مجوز 0600 می‌گیرد؛ کلید رمزنگاری
 * در `config/.app_key` نگهداری می‌شود. در نتیجه کلیدهای API هرگز وارد گیت نمی‌شوند.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Config
{
    /** @var array|null */
    private static $data = null;

    private const FILE = MEELANO_CONFIG . '/settings.php';
    private const KEYFILE = MEELANO_CONFIG . '/.app_key';

    /** مقادیر پیش‌فرض سامانه. */
    public static function defaults(): array
    {
        return [
            'app' => [
                'debug' => false,
                'brand' => 'میلانو | هوش معاملاتی کریپتو',
                'studio' => 'Meelano Studio Design',
                'author' => 'Milad Yaghoobi',
                'currency' => 'USD',
                'language' => 'fa',
                'installed' => false,
                'admin_hash' => '',           // hash رمز ورود به بخش تنظیمات
                'require_admin' => true,
            ],
            'db' => [
                'driver' => 'mysql',          // mysql | sqlite
                'host' => 'localhost',
                'port' => 3306,
                'name' => '',
                'user' => '',
                'pass' => '',
                'charset' => 'utf8mb4',
                'prefix' => 'mln_',
                'socket' => '',
                'sqlite_path' => '',
                'persist' => true,
            ],
            'ai' => [
                'builtin' => false,           // حالت آفلاین/دمو بدون کلید
                'default_timeout' => 45,
                'max_retries' => 2,
                'json_mode' => true,
                'temperature' => 0.2,
                'providers' => [
                    'openai' => [
                        'enabled' => true, 'api_key' => '', 'base_url' => 'https://api.openai.com/v1',
                        'model' => 'gpt-4o-mini',
                    ],
                    'gemini' => [
                        'enabled' => true, 'api_key' => '',
                        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                        'model' => 'gemini-2.5-flash',
                    ],
                    'groq' => [
                        'enabled' => true, 'api_key' => '', 'base_url' => 'https://api.groq.com/openai/v1',
                        'model' => 'llama-3.1-8b-instant',
                    ],
                    'deepseek' => [
                        'enabled' => true, 'api_key' => '', 'base_url' => 'https://api.deepseek.com/v1',
                        'model' => 'deepseek-chat',
                    ],
                    'gapgpt' => [
                        'enabled' => true, 'api_key' => '', 'base_url' => 'https://api.gapgpt.app/v1',
                        'model' => 'gpt-4o-mini',
                    ],
                    'maxrouter' => [
                        'enabled' => true, 'api_key' => '', 'base_url' => 'https://api.maxrouter.com/v1',
                        'model' => 'gpt-4o-mini',
                    ],
                    'cloudflare' => [
                        'enabled' => true, 'api_token' => '', 'account_id' => '',
                        'base_url' => 'https://api.cloudflare.com/client/v4/accounts',
                        'model' => '@cf/meta/llama-3.1-8b-instruct',
                    ],
                ],
            ],
            'routing' => [
                'mode' => 'auto',   // auto | manual
                'map' => [],        // task => provider
                'fallback' => [],   // task => [providers]
            ],
            'market' => [
                'file_cache' => true,          // کش فایلی دادهٔ بازار (احترام به rate-limit)
                'ticker_cache_ttl' => 45,      // ثانیه
                'candle_cache_ttl' => 45,      // ثانیه
                'timeout' => 12,
            ],
            'trading' => [
                'quote' => 'USDT',
                'timeframe' => '1h',
                'timeframes' => ['1h', '4h', '1d'],   // تأیید چند تایم‌فریمی
                'scan_limit' => 40,
                'min_quote_volume' => 5000000,
                'min_filters_passed' => 17,            // از ۲۵ فیلتر
                'min_tech_score' => 62.0,
                'min_combined_score' => 70.0,
                'require_ai_agreement' => true,
                'require_mtf' => true,
                'mtf_min_alignment' => 0.55,
                'tech_weight' => 0.6,
                'ai_weight' => 0.4,
                'ai_panel_size' => 3,
                'red_team' => true,                    // وکیل مدافع AI
                'self_consistency' => true,            // پرسش دوم از مدل برتر
                'ai_history_stats' => true,            // تزریق سابقهٔ ردیاب در پرامپت
                'btc_filter' => true,
                'enable_funding' => true,              // فیلتر فاندینگ
                'enable_open_interest' => true,        // فیلتر اوپن اینترست (تنبل)
                'enable_fear_greed' => true,           // شاخص ترس و طمع
                'max_same_side' => 4,                  // دروازهٔ همبستگی: سقف هم‌جهت
                'max_portfolio_position_pct' => 60.0,  // سقف سایز تجمعی پرتفوی
                'tracker_max_age_hours' => 96,         // افق داوری ردیاب
                'risk_per_trade_percent' => 1.0,
                'atr_stop_multiplier' => 2.0,
                'max_atr_stop_multiplier' => 3.0,
                'structure_stop_buffer' => 0.25,
                'max_position_percent' => 25.0,
                'min_risk_reward' => 2.0,
                'cooldown_hours' => 12,
                'max_signals_per_scan' => 8,
                'backtest_fee_bps' => 8.0,
                'backtest_slippage_bps' => 3.0,        // اسلیپیج هر سمت
                'backtest_horizon_bars' => 72,

                /* ── معامله‌گر خودکار (نسخهٔ ۵٫۲) ───────────────────── */
                'auto_trade_enabled' => false,         // کلید اصلی (پیش‌فرض خاموش)
                'auto_mode' => 'buy_sell',             // buy_sell | buy_only | sell_only
                'auto_amount_mode' => 'percent',       // percent | fixed
                'auto_amount_percent' => 10.0,         // ٪ موجودی در هر معامله
                'auto_amount_fixed' => 100.0,          // مبلغ ثابت USDT
                'auto_max_open_positions' => 5,        // سقف پوزیشن هم‌زمان
                'auto_min_tier' => 'A',                // حداقل درجهٔ سیگنال
                'auto_min_combined' => 75.0,           // حداقل امتیاز ترکیبی
                'auto_tp_mode' => 'ladder',            // ladder (۵۰/۲۵/۲۵) | tp2 | tp3
                'auto_honor_stop' => true,             // اجرای استاپ سیگنال
                'auto_close_on_opposite' => true,      // بستن با سیگنال مخالف
                'auto_dry_run' => true,                // پیش‌فرض: ثبت رویداد بدون اجرا
                'paper_initial_usdt' => 10000.0,       // موجودی اولیهٔ کیف تست
            ],
            'security' => [
                'rate_limit_per_minute' => 60,
                'allowed_origins' => [],
                'cron_key' => '',   // کلید اجرای کران ردیاب (api/tracker.php?action=run&key=…)
            ],
            'exchange' => [
                'provider' => 'binance',        // binance (بقیه در نقشه راه)
                'mode' => 'testnet',            // testnet | live
                'api_key' => '',
                'api_secret' => '',
                'live_enabled' => false,        // معاملهٔ واقعی فقط با تأیید صریح
                'receive_window' => 5000,
            ],
        ];
    }

    /** بارگذاری تنظیمات (یک‌بار). */
    public static function load(bool $reload = false): array
    {
        if (self::$data !== null && !$reload) {
            return self::$data;
        }
        $data = self::defaults();
        if (is_file(self::FILE)) {
            $raw = @include self::FILE;
            if (is_array($raw)) {
                $decoded = self::decode($raw);
                if (is_array($decoded)) {
                    $data = self::mergeDeep($data, $decoded);
                }
            }
        }
        self::$data = $data;
        return $data;
    }

    public static function all(): array
    {
        return self::load();
    }

    public static function get(string $path, $default = null)
    {
        return m_get(self::load(), $path, $default);
    }

    public static function set(string $path, $value): void
    {
        $data = self::load();
        $segments = explode('.', $path);
        $ref = &$data;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
        unset($ref);
        self::$data = $data;
    }

    /** ذخیره روی دیسک با مجوز سخت‌گیرانه. */
    public static function save(): bool
    {
        $encoded = self::encode(self::load());
        $php = "<?php\n// تولید خودکار توسط پنل هوشمند میلانو — دستی ویرایش نکنید.\nreturn "
            . var_export($encoded, true) . ";\n";

        $tmp = self::FILE . '.tmp';
        if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, self::FILE)) {
            @unlink($tmp);
            return false;
        }
        @chmod(self::FILE, 0600);
        return true;
    }

    /** آیا فایل تنظیمات وجود دارد؟ */
    public static function exists(): bool
    {
        return is_file(self::FILE);
    }

    /** نسخه پنهان‌شده برای ارسال به مرورگر (بدون افشای کلید). */
    public static function publicView(): array
    {
        $data = self::load();
        foreach ($data['ai']['providers'] as $id => $provider) {
            foreach ($provider as $field => $value) {
                if (in_array($field, ['api_key', 'api_token'], true) && is_string($value) && $value !== '') {
                    $data['ai']['providers'][$id][$field . '_masked'] = m_mask_secret($value);
                    $data['ai']['providers'][$id][$field . '_set'] = true;
                    $data['ai']['providers'][$id][$field] = '';
                } else {
                    $data['ai']['providers'][$id][$field . '_set'] = isset($provider[$field]) && $provider[$field] !== '';
                }
            }
        }
        if (!empty($data['db']['pass'])) {
            $data['db']['pass_masked'] = m_mask_secret((string)$data['db']['pass']);
            $data['db']['pass_set'] = true;
            $data['db']['pass'] = '';
        }
        foreach (['api_key', 'api_secret'] as $secretField) {
            if (!empty($data['exchange'][$secretField])) {
                $data['exchange'][$secretField . '_masked'] = m_mask_secret((string)$data['exchange'][$secretField]);
                $data['exchange'][$secretField . '_set'] = true;
                $data['exchange'][$secretField] = '';
            }
        }
        $data['app']['admin_hash'] = $data['app']['admin_hash'] !== '' ? '***' : '';
        return $data;
    }

    /* ── رمزنگاری ─────────────────────────────────────────────────────── */

    private static function key(): ?string
    {
        if (!function_exists('openssl_encrypt')) {
            return null;
        }
        if (is_file(self::KEYFILE)) {
            $key = @file_get_contents(self::KEYFILE);
            if (is_string($key) && strlen($key) === 32) {
                return $key;
            }
        }
        $key = random_bytes(32);
        if (@file_put_contents(self::KEYFILE, $key, LOCK_EX) !== false) {
            @chmod(self::KEYFILE, 0600);
            return $key;
        }
        return null; // دیسک فقط‌خواندنی → ذخیره بدون رمزنگاری
    }

    private static function encode(array $data): array
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        $key = self::key();
        if ($key === null) {
            return ['__enc' => false, 'data' => base64_encode((string)$payload)];
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt((string)$payload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return [
            '__enc' => true,
            'iv' => base64_encode($iv),
            'tag' => base64_encode((string)$tag),
            'data' => base64_encode((string)$cipher),
        ];
    }

    private static function decode(array $raw): ?array
    {
        $payload = base64_decode((string)($raw['data'] ?? ''), true);
        if ($payload === false) {
            return null;
        }
        if (empty($raw['__enc'])) {
            $decoded = json_decode($payload, true);
            return is_array($decoded) ? $decoded : null;
        }
        $key = is_file(self::KEYFILE) ? @file_get_contents(self::KEYFILE) : false;
        if (!is_string($key) || strlen($key) !== 32) {
            return null; // کلید گم شده — کاربر باید دوباره ذخیره کند
        }
        $iv = base64_decode((string)($raw['iv'] ?? ''), true);
        $tag = base64_decode((string)($raw['tag'] ?? ''), true);
        if ($iv === false || $tag === false) {
            return null;
        }
        $plain = openssl_decrypt($payload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            return null;
        }
        $decoded = json_decode($plain, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** ادغام عمیق آرایه‌ها (مقادیر جدید جایگزین می‌شوند). */
    public static function mergeDeep(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::mergeDeep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
