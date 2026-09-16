<?php
/**
 * توابع کمکی سراسری — پنل هوشمند میلانو
 * Global helpers.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */

declare(strict_types=1);

/** آیا درخواست روی HTTPS است؟ */
function meelano_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** گریز HTML — جلوگیری از XSS در همه خروجی‌ها. */
function meelano_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** خواندن امن کلید از آرایه تو در تو با نقطه: `m_get($a,'db.host')` */
function m_get(array $data, string $path, $default = null)
{
    $cursor = $data;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            return $default;
        }
        $cursor = $cursor[$segment];
    }
    return $cursor;
}

/** مقدار عددی امن از ورودی کاربر (ریال/تومان با کاما). */
function m_numeric($value, float $default = 0.0): float
{
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }
    $clean = preg_replace('/[^\d\.\-]/u', '', (string)$value);
    if ($clean === '' || $clean === '-' || $clean === '.') {
        return $default;
    }
    $num = (float)$clean;
    return is_finite($num) ? $num : $default;
}

/** تبدیل ارقام لاتین/عربی به فارسی برای نمایش. */
function m_persian_digits(string $input): string
{
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    return str_replace(array_merge($en, $ar), $fa, $input);
}

/** جداکننده هزارگان. */
function m_money(float $value): string
{
    return number_format((float)round($value), 0, '.', ',');
}

/** گرد کردن به نزدیک‌ترین ۱٬۰۰۰ ریال — قیمت‌های حرفه‌ای بازار ایران. */
function m_round_market(float $value, float $step = 1000.0): float
{
    if ($step <= 0) {
        return round($value);
    }
    return round($value / $step) * $step;
}

/** تاریخ شمسی (بدون وابستگی به ext-jalali). */
function m_jalali(?int $timestamp = null): string
{
    $ts = $timestamp ?? time();
    $gy = (int)date('Y', $ts);
    $gm = (int)date('n', $ts);
    $gd = (int)date('j', $ts);

    $gdm = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100)
        + (int)(($gy2 + 399) / 400) + $gd;
    for ($i = 0; $i < $gm - 1; $i++) {
        $days += $gdm[$i];
    }
    if ($gm > 2 && (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0))) {
        $days++;
    }
    $jy = -1595 + (33 * (int)($days / 12053));
    $days %= 12053;
    $jy += 4 * (int)($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

/**
 * پایهٔ URL برنامه — برای حالت نصب در زیرپوشه (ainetmee.ir/trader).
 */
function m_base_url(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = rtrim(str_replace(basename($script), '', $script), '/');
    if ($dir === '') {
        $dir = '';
    }
    return $dir;
}

/** ساخت آدرس نسبی برنامه. */
function m_url(string $path = ''): string
{
    return m_base_url() . '/' . ltrim($path, '/');
}

/** پاسخ JSON و پایان اجرا. */
function m_json(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** پاک‌سازی رشته ورودی از کاراکترهای کنترلی و فاصله‌های اضافی. */
function m_clean_string($value, int $maxLen = 500): string
{
    $value = (string)$value;
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    // حذف RLO/LTR-override که برای فریب کاربر در نام نماد/متن استفاده می‌شود
    $value = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value) ?? '';
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLen, 'UTF-8');
    }
    return substr($value, 0, $maxLen);
}

/** پنهان‌سازی کلید API برای نمایش در UI. */
function m_mask_secret(string $secret): string
{
    $len = strlen($secret);
    if ($len === 0) {
        return '—';
    }
    if ($len <= 12) {
        // کلید کوتاه: نمایش کامل دم/سر، خودِ کلید را می‌سازد — هرگز کامل نشان نده
        return substr($secret, 0, 4) . str_repeat('•', 8);
    }
    return substr($secret, 0, 6) . str_repeat('•', min(18, $len - 10)) . substr($secret, -4);
}

/** تولید شناسه یکتا بدون وابستگی به ramsey/uuid. */
function m_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/** زمان اجرای دقیق برای بنچمارک. */
function m_microtime(): float
{
    return microtime(true);
}
