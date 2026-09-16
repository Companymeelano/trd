<?php
namespace Meelano\Crypto;

/**
 * قرارداد واحد اتصال به صرافی‌ها (نسخهٔ ۵٫۲).
 *
 * امروز Binance Spot پیاده شده (BinanceSpot)؛ افزودن Bybit/OKX فقط یک
 * کلاس جدید با همین قرارداد است — موتور معامله‌گر (AutoTrader) بدون
 * تغییر با هر Connector کار می‌کند.
 *
 * امنیت: کلید/راز فقط در Config رمزنگاری‌شده؛ هرگز در لاگ یا پاسخ API.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
interface Connector
{
    public function name(): string;

    /** دسترسی عمومی صرافی. @return array{ok:bool,latency_ms:float,error:?string} */
    public function ping(): array;

    /** قیمت لحظه‌ای نماد. */
    public function ticker(string $symbol): ?array;

    /** موجودی حساب (نقشهٔ دارایی => مقدار). */
    public function balances(): array;

    /** سفارش بازار — فقط در حالت live با کلید معتبر کار می‌کند. */
    public function marketOrder(string $symbol, string $side, float $quantity, float $quoteUsdt = 0.0): array;

    /** حداقل گام quantity نماد (برای رُند کردن سفارش واقعی). */
    public function lotStep(string $symbol): ?float;
}
