<?php
namespace Meelano\Crypto;

use Meelano\Ai\Transport;
use Meelano\Config;

/**
 * کارخانهٔ اتصال به صرافی‌ها (نسخهٔ ۵٫۵).
 *
 * صرافی فعال در `exchange.provider` انتخاب می‌شود؛ اعتبارنامه‌ها:
 *   binance → exchange.api_key / api_secret (+ mode: testnet|live)
 *   nobitex → exchange.providers.nobitex.{api_key, api_secret}
 *   wallex  → exchange.providers.wallex.api_key
 *
 * افزودن صرافی جدید = یک کلاس با قرارداد Connector + یک ردیف در
 * providers() — موتور معامله‌گر (AutoTrader) بدون تغییر کار می‌کند.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class ConnectorFactory
{
    /** فرادادهٔ صرافی‌های پشتیبانی‌شده (برای UI و تست). */
    public static function providers(): array
    {
        return [
            'binance' => [
                'label' => 'Binance Spot',
                'label_fa' => 'بایننس (اسپات)',
                'icon' => 'fa-brands fa-bitcoin',
                'color' => '#f7931a',
                'quote' => 'USDT',
                'help' => 'کلید تست رایگان از testnet.binance.vision یا کلید اصلی از پنل بایننس (فقط دسترسی Spot Trade). محیط Testnet/Live قابل انتخاب است.',
                'fields' => [
                    'api_key' => ['label' => 'API Key', 'secret' => false, 'ph' => 'کلید عمومی ۶۴ کاراکتری'],
                    'api_secret' => ['label' => 'API Secret', 'secret' => true, 'ph' => 'فقط اگر می‌خواهید عوض شود'],
                ],
                'has_mode' => true,
            ],
            'nobitex' => [
                'label' => 'Nobitex',
                'label_fa' => 'نوبیتکس (ایرانی)',
                'icon' => 'fa-solid fa-building-columns',
                'color' => '#0ea5e9',
                'quote' => 'USDT (بازارهای usdt نوبیتکس)',
                'help' => 'از پنل نوبیتکس ← بخش API، کلید با دسترسی READ + TRADE بسازید. «کلید عمومی» و «کلید خصوصی Ed25519» (Base64-url) را اینجا بگذارید. امضا سمت سرور با libsodium انجام می‌شود.',
                'fields' => [
                    'api_key' => ['label' => 'کلید عمومی (Nobitex-Key)', 'secret' => false, 'ph' => 'کلید عمومی'],
                    'api_secret' => ['label' => 'کلید خصوصی Ed25519', 'secret' => true, 'ph' => 'Base64-url — فقط اگر می‌خواهید عوض شود'],
                ],
                'has_mode' => false,
            ],
            'wallex' => [
                'label' => 'Wallex',
                'label_fa' => 'والکس (ایرانی)',
                'icon' => 'fa-solid fa-wallet',
                'color' => '#10b981',
                'quote' => 'TMN → معادل USDT لحظه‌ای',
                'help' => 'از پنل والکس ← مدیریت API، توکن بسازید. اجرا روی بازارهای تومانی (مثل BTCTMN) انجام می‌شود و حسابداری با نرخ لحظه‌ای USDTTMN به معادل USDT تبدیل می‌گردد.',
                'fields' => [
                    'api_key' => ['label' => 'توکن API (x-api-key)', 'secret' => true, 'ph' => 'توکن والکس — فقط اگر می‌خواهید عوض شود'],
                ],
                'has_mode' => false,
            ],
        ];
    }

    /** آیا صرافی شناسایی شده؟ */
    public static function isProvider(string $provider): bool
    {
        return isset(self::providers()[$provider]);
    }

    /** اعتبارنامه‌های یک صرافی از پیکربندی (بدون ماسک — فقط سمت سرور). */
    public static function credentials(string $provider, ?array $cfg = null): array
    {
        $cfg = $cfg ?? (array)Config::get('exchange', []);
        if ($provider === 'binance') {
            return [
                'api_key' => (string)($cfg['api_key'] ?? ''),
                'api_secret' => (string)($cfg['api_secret'] ?? ''),
                'mode' => (string)($cfg['mode'] ?? 'testnet'),
            ];
        }
        $p = (array)($cfg['providers'][$provider] ?? []);
        return [
            'api_key' => (string)($p['api_key'] ?? ''),
            'api_secret' => (string)($p['api_secret'] ?? ''),
        ];
    }

    /**
     * ساخت Connector صرافی فعال.
     * @return array{0:Connector|null,1:string|null} [کانکتور یا null، خطا]
     */
    public static function make(?Transport $transport = null, ?array $cfg = null): array
    {
        $cfg = $cfg ?? (array)Config::get('exchange', []);
        $provider = (string)($cfg['provider'] ?? 'binance');
        switch ($provider) {
            case 'binance':
                return [new BinanceSpot($transport, self::credentials('binance', $cfg)), null];
            case 'nobitex':
                return [new Nobitex($transport, self::credentials('nobitex', $cfg)), null];
            case 'wallex':
                return [new Wallex($transport, self::credentials('wallex', $cfg)), null];
        }
        return [null, 'صرافی پشتیبانی نمی‌شود: ' . $provider];
    }
}
