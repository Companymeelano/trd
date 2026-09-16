<?php
namespace Meelano\Crypto\Notify;

/**
 * قرارداد واحد کانال‌های اطلاع‌رسانی (نسخهٔ ۵٫۳).
 *
 * هر پیام‌رسان (تلگرام/واتساپ/بله/روبیکا/پیامک) یک درایور با همین قرارداد است؛
 * افزودن سرویس جدید فقط یک کلاس تازه — Notifier بدون تغییر با همه کار می‌کند.
 *
 * امنیت: توکن/کلید فقط در Config رمزنگاری‌شده؛ هرگز در لاگ یا پاسخ API.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
interface Channel
{
    /** شناسهٔ یکتا (کلید ذخیره‌سازی پیکربندی). */
    public function id(): string;

    /** نام نمایشی فارسی. */
    public function label(): string;

    /** آیا همهٔ فیلدهای ضروری تنظیم شده‌اند؟ (بدون تماس شبکه) */
    public function configured(): bool;

    /**
     * ارسال پیام متنی.
     * @param string $text متن کامل (یا فشرده برای پیامک)
     * @return array{ok:bool,error:?string,info?:array}
     */
    public function send(string $text): array;

    /**
     * بررسی سلامت اتصال (بدون ارسال پیام به کاربر).
     * @return array{ok:bool,configured:bool,error:?string,info?:array}
     */
    public function check(): array;
}
