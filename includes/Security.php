<?php
namespace Meelano;

use Throwable;

/**
 * امنیت: CSRF، ورود مدیر، محدودسازی نرخ، سرصفحه‌های امن.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Security
{
    /* ── CSRF ────────────────────────────────────────────────────────── */

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf'];
    }

    public static function csrfCheck(?string $token = null): bool
    {
        $token = $token ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? $_GET['_csrf'] ?? '');
        return is_string($token) && $token !== '' && hash_equals((string)($_SESSION['csrf'] ?? ''), $token);
    }

    /** در صورت نامعتبر بودن توکن، پاسخ ۴۱۹ می‌دهد. */
    public static function requireCsrf(): void
    {
        // CSRF فقط برای درخواست‌های مرورگری معنا دارد؛ در CLI (تست/E2E)
        // امکان حمله cross-site وجود ندارد.
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (!self::csrfCheck()) {
            m_json(['ok' => false, 'error' => 'توکن امنیتی نامعتبر یا منقضی است. صفحه را تازه کنید.'], 419);
        }
    }

    /* ── ورود مدیر ───────────────────────────────────────────────────── */

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['admin_ok']);
    }

    /**
     * رمز پیش‌فرض: `meelano-admin` — کاربر باید فوراً عوض کند.
     * اگر هش تنظیم نشده باشد، همین رمز پذیرفته می‌شود.
     */
    public const DEFAULT_ADMIN_PASSWORD = 'meelano-admin';

    public static function attemptLogin(string $password): bool
    {
        $hash = (string)Config::get('app.admin_hash', '');
        $ok = $hash !== ''
            ? password_verify($password, $hash)
            : hash_equals(self::DEFAULT_ADMIN_PASSWORD, $password);

        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = true;
            $_SESSION['admin_since'] = time();
            self::audit('admin', 'login', 'ورود موفق به بخش تنظیمات');
            return true;
        }
        self::rateLimitHit('login');
        Logger::write('security', 'تلاش ناموفق ورود به تنظیمات', 'warning', ['ip' => self::ip()]);
        return false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        @session_destroy();
    }

    public static function setPassword(string $plain): bool
    {
        Config::set('app.admin_hash', password_hash($plain, PASSWORD_DEFAULT));
        return Config::save();
    }

    /** محافظت از صفحات تنظیمات. */
    public static function requireLogin(): void
    {
        if ((bool)Config::get('app.require_admin', true) && !self::isLoggedIn()) {
            header('Location: ' . m_url('login.php'));
            exit;
        }
    }

    /* ── محدودسازی نرخ ───────────────────────────────────────────────── */

    /**
     * سهمیهٔ نرخ بر اساس IP در فایل سرور (نه نشست!).
     *
     * پیاده‌سازی قبلی شمارنده را در $_SESSION نگه می‌داشت؛ یعنی با دور انداختن
     * کوکی (یا حالت ناشناس) کل سهمیه صفر می‌شد و محافظت ورود در برابر بروت‌فورس
     * عملاً بی‌اثر بود. اکنون شمارنده سمت سرور و با قفل فایل نگهداری می‌شود.
     */
    public static function rateLimit(string $bucket, ?int $max = null): array
    {
        $max = $max ?? (int)Config::get('security.rate_limit_per_minute', 60);
        $now = time();
        $file = MEELANO_CACHE . '/rl_' . sha1($bucket . '|' . self::ip()) . '.json';

        $count = 1;
        $reset = $now + 60;

        if (!is_dir(MEELANO_CACHE)) {
            @mkdir(MEELANO_CACHE, 0755, true);
        }

        $handle = @fopen($file, 'c+');
        if ($handle !== false) {
            try {
                if (flock($handle, LOCK_EX)) {
                    $raw = stream_get_contents($handle);
                    $data = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
                    if (is_array($data) && isset($data['count'], $data['reset']) && $now <= (int)$data['reset']) {
                        $count = (int)$data['count'] + 1;
                        $reset = (int)$data['reset'];
                    }
                    ftruncate($handle, 0);
                    rewind($handle);
                    fwrite($handle, json_encode(['count' => $count, 'reset' => $reset]));
                    fflush($handle);
                    flock($handle, LOCK_UN);
                }
            } finally {
                fclose($handle);
            }
        }

        return [
            'allowed' => $count <= $max,
            'remaining' => max(0, $max - $count),
            'reset_in' => max(0, $reset - $now),
        ];
    }

    public static function requireRateLimit(string $bucket, ?int $max = null): void
    {
        $rl = self::rateLimit($bucket, $max);
        if (!$rl['allowed']) {
            m_json(['ok' => false, 'error' => 'تعداد درخواست‌ها بیش از حد مجاز است. ' . $rl['reset_in'] . ' ثانیه صبر کنید.'], 429);
        }
    }

    private static function rateLimitHit(string $bucket): void
    {
        self::rateLimit($bucket, 10);
    }

    /* ── سرصفحه‌های امن ──────────────────────────────────────────────── */

    public static function secureHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header_remove('X-Powered-By');
    }

    /* ── ممیزی ───────────────────────────────────────────────────────── */

    public static function audit(string $section, string $action, string $detail = '', string $actor = 'admin', ?Db $dbOverride = null): void
    {
        try {
            $db = $dbOverride ?: Db::make();
            if (!$db->isConnected() || !$db->tableExists('settings_audit')) {
                return;
            }
            // ستون جدول settings_audit طبق Schema نامش «user» است
            $db->insert('settings_audit', [
                'user' => $actor,
                'section' => $section,
                'action' => $action,
                'detail' => mb_substr($detail, 0, 2000),
                'ip' => self::ip(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Logger::write('security', 'ثبت ممیزی ناموفق: ' . $e->getMessage(), 'warning');
        }
    }

    /**
     * IP واقعی کلاینت.
     *
     * سرصفحه‌های CF-Connecting-IP / X-Forwarded-For تنها زمانی خوانده می‌شوند
     * که `security.trust_proxy_headers` در تنظیمات روشن باشد (پشت Cloudflare/
     * پروکسی معتبر)؛ در غیر این صورت هر کلاینت می‌توانست با جعل سرصفحه، سهمیهٔ
     * نرخ و ممیزی را دور بزند.
     */
    public static function ip(): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $trustProxy = (bool)Config::get('security.trust_proxy_headers', false);
        $candidates = $trustProxy
            ? [
                $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
                $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
                $remote,
            ]
            : [$remote];
        foreach ($candidates as $c) {
            $c = trim(explode(',', (string)$c)[0]);
            if ($c !== '' && filter_var($c, FILTER_VALIDATE_IP)) {
                return $c;
            }
        }
        return '0.0.0.0';
    }
}
