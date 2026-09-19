<?php
namespace Meelano\Ai;

/**
 * رجیستری توانمندی ارائه‌دهندگان هوش مصنوعی + تعریف وظایف بات ترید کریپتو.
 *
 * این ماتریس مغز «مسیریابی خودکار» است: برای هر وظیفه مشخص می‌کند کدام
 * ارائه‌دهنده چه امتیاز تخصصی دارد، چه نوع قابلیتی لازم است و چه کلاس
 * تأخیر/هزینه‌ای دارد.
 *
 * نسخهٔ ۵: پاک‌سازی کامل — فقط وظایف و ارائه‌دهندگانِ مرتبط با تحلیل و
 * سیگنال کریپتو باقی مانده‌اند (وظایف انبار/سئو/تصویر/ویدیوی پروژهٔ قبلی حذف شدند).
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Registry
{
    /* ── قابلیت‌ها ────────────────────────────────────────────────────── */
    public const CAP_CHAT = 'chat';
    public const CAP_JSON = 'json_mode';
    public const CAP_VISION = 'vision';

    /**
     * تعریف وظایف برنامه.
     * weight: اهمیت کیفیت در برابر سرعت (۰..۱).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function tasks(): array
    {
        return [
            'crypto.signal' => [
                'label' => 'قضاوت مستقل سیگنال کریپتو (اجماع)',
                'section' => 'موتور سیگنال',
                'icon' => 'fa-chart-line',
                'requires' => [self::CAP_CHAT, self::CAP_JSON],
                'quality_weight' => 0.9,
                'speed_weight' => 0.1,
                'max_tokens' => 400,
            ],
            'crypto.review' => [
                'label' => 'بازبینی ریسک و سناریوی بازار کریپتو',
                'section' => 'موتور سیگنال',
                'icon' => 'fa-magnifying-glass-chart',
                'requires' => [self::CAP_CHAT, self::CAP_JSON],
                'quality_weight' => 0.8,
                'speed_weight' => 0.2,
                'max_tokens' => 600,
            ],
            'crypto.regime' => [
                'label' => 'دومین نظر دربارهٔ رژیم بازار',
                'section' => 'موتور سیگنال',
                'icon' => 'fa-wave-square',
                'requires' => [self::CAP_CHAT, self::CAP_JSON],
                'quality_weight' => 0.75,
                'speed_weight' => 0.25,
                'max_tokens' => 350,
            ],
            'crypto.report' => [
                'label' => 'گزارش فارسی خوانا برای هر سیگنال',
                'section' => 'گزارش‌سازی',
                'icon' => 'fa-file-lines',
                'requires' => [self::CAP_CHAT],
                'quality_weight' => 0.6,
                'speed_weight' => 0.4,
                'max_tokens' => 500,
            ],
        ];
    }

    /**
     * مشخصات ارائه‌دهندگان — همه با پروتکل متنی سازگار با موتور سیگنال.
     * speed_class: 1=فوق‌سریع … 5=کند. cost_class: 1=ارزان … 5=گران.
     * affinity: امتیاز تخصصی ۰..۳۰ برای هر وظیفه.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function providers(): array
    {
        return [
            'openai' => [
                'label' => 'OpenAI',
                'icon' => 'fa-brain',
                'protocol' => 'openai',
                'capabilities' => [self::CAP_CHAT, self::CAP_JSON, self::CAP_VISION],
                'speed_class' => 3,
                'cost_class' => 3,
                'key_field' => 'api_key',
                'affinity' => [
                    'crypto.signal' => 28, 'crypto.review' => 30, 'crypto.regime' => 27, 'crypto.report' => 26,
                ],
                'notes' => 'قوی‌ترین استدلال کمی و JSON ساخت‌یافته؛ لنگر کیفیت پنل اجماع.',
            ],
            'gemini' => [
                'label' => 'Google Gemini',
                'icon' => 'fa-gem',
                'protocol' => 'gemini',
                'capabilities' => [self::CAP_CHAT, self::CAP_JSON, self::CAP_VISION],
                'speed_class' => 2,
                'cost_class' => 2,
                'key_field' => 'api_key',
                'affinity' => [
                    'crypto.signal' => 27, 'crypto.review' => 26, 'crypto.regime' => 28, 'crypto.report' => 30,
                ],
                'notes' => 'پنجرهٔ زمینه بزرگ و فارسی روان؛ عالی برای گزارش و تحلیل رژیم.',
            ],
            'groq' => [
                'label' => 'Groq (LPU)',
                'icon' => 'fa-bolt',
                'protocol' => 'openai',
                'capabilities' => [self::CAP_CHAT, self::CAP_JSON],
                'speed_class' => 1,
                'cost_class' => 1,
                'key_field' => 'api_key',
                'affinity' => [
                    'crypto.signal' => 24, 'crypto.regime' => 25, 'crypto.report' => 22, 'crypto.review' => 18,
                ],
                'notes' => 'کمترین تأخیر جهان؛ رأی سریع و ارزان در پنل اجماع.',
            ],
            'deepseek' => [
                'label' => 'DeepSeek',
                'icon' => 'fa-robot',
                'protocol' => 'openai',
                'capabilities' => [self::CAP_CHAT, self::CAP_JSON],
                'speed_class' => 2,
                'cost_class' => 1,
                'key_field' => 'api_key',
                'affinity' => [
                    'crypto.signal' => 26, 'crypto.review' => 27, 'crypto.regime' => 24, 'crypto.report' => 20,
                ],
                'notes' => 'استدلال ریاضی قوی با هزینهٔ بسیار پایین؛ ارزش بالای رأی.',
            ],
            'gapgpt' => [
                'label' => 'GapGPT',
                'icon' => 'fa-plug',
                'protocol' => 'openai',
                'capabilities' => [self::CAP_CHAT, self::CAP_JSON],
                'speed_class' => 3,
                'cost_class' => 2,
                'key_field' => 'api_key',
                'affinity' => [
                    'crypto.signal' => 20, 'crypto.review' => 18, 'crypto.regime' => 19, 'crypto.report' => 24,
                ],
                'notes' => 'سازگار با OpenAI؛ مناسب دسترسی از ایران و پرداخت ریالی.',
            ],
            'maxrouter' => [
                'label' => 'MaxRouter',
                'icon' => 'fa-route',
                'protocol' => 'openai',
                'capabilities' => [self::CAP_CHAT, self::CAP_JSON],
                'speed_class' => 3,
                'cost_class' => 2,
                'key_field' => 'api_key',
                'affinity' => [
                    'crypto.signal' => 18, 'crypto.review' => 16, 'crypto.regime' => 17, 'crypto.report' => 19,
                ],
                'notes' => 'مسیریاب چندمدلی؛ نقش پشتیبان (fallback) را خوب بازی می‌کند.',
            ],
            'cloudflare' => [
                'label' => 'Cloudflare Workers AI',
                'icon' => 'fa-cloud',
                'protocol' => 'cloudflare',
                'capabilities' => [self::CAP_CHAT],
                'speed_class' => 2,
                'cost_class' => 1,
                'key_field' => 'api_token',
                'requires_extra' => ['account_id'],
                'affinity' => [
                    'crypto.report' => 20, 'crypto.regime' => 16, 'crypto.signal' => 12, 'crypto.review' => 12,
                ],
                'notes' => 'رایگان/ارزان با CDN جهانی؛ خروجی JSON تضمینی ندارد — برای گزارش.',
            ],
        ];
    }

    public static function provider(string $id): ?array
    {
        $all = self::providers();
        return $all[$id] ?? null;
    }

    public static function task(string $id): ?array
    {
        $all = self::tasks();
        return $all[$id] ?? null;
    }

    /** ارائه‌دهندگانی که یک قابلیت خاص دارند. */
    public static function withCapability(string $capability): array
    {
        $out = [];
        foreach (self::providers() as $id => $p) {
            if (in_array($capability, $p['capabilities'], true)) {
                $out[] = $id;
            }
        }
        return $out;
    }
}
