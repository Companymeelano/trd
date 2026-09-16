<?php
/**
 * POST /api/exchange.php — مدیریت اتصال صرافی.
 *
 * action=status     وضعیت اتصال (بدون افشای راز)
 * action=test       تست دسترسی عمومی + موجودی با کلید فعلی
 * action=save_keys  {api_key, api_secret, mode} ذخیرهٔ رمزنگاری‌شدهٔ کلیدها
 * action=enable_live {confirm:"ENABLE-LIVE"} بازکردن قفل معاملهٔ واقعی (دو مرحله‌ای)
 * action=disable_live قفل مجدد
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Config;
use Meelano\Crypto\BinanceSpot;
use Meelano\Security;

m_guard(false, 'exchange');
$in = m_input();
$action = m_clean_string($in['action'] ?? 'status', 16);
Security::requireRateLimit('exchange', 20);

$provider = (string)Config::get('exchange.provider', 'binance');
if ($provider !== 'binance') {
    m_json(['ok' => false, 'error' => 'در این نسخه فقط Binance Spot پشتیبانی می‌شود؛ سایر صرافی‌ها در نقشه راه هستند.'], 422);
}

switch ($action) {
    case 'save_keys':
        $apiKey = trim((string)($in['api_key'] ?? ''));
        $apiSecret = trim((string)($in['api_secret'] ?? ''));
        $mode = ($in['mode'] ?? 'testnet') === 'live' ? 'live' : 'testnet';
        if ($apiKey !== '') {
            Config::set('exchange.api_key', $apiKey);
        }
        if ($apiSecret !== '') {
            Config::set('exchange.api_secret', $apiSecret);
        }
        Config::set('exchange.mode', $mode);
        // تغییر کلید = قفل مجدد معاملهٔ زنده
        Config::set('exchange.live_enabled', false);
        Config::save();
        Security::audit('exchange', 'save_keys', 'کلیدهای صرافی ذخیره شد (mode=' . $mode . ')');
        m_json(['ok' => true, 'message' => 'کلیدها به‌صورت رمزنگاری‌شده ذخیره شد. برای معاملهٔ زنده، مجوز جداگانه لازم است.']);
        break;

    case 'enable_live':
        $confirm = strtoupper(trim((string)($in['confirm'] ?? '')));
        if ($confirm !== 'ENABLE-LIVE') {
            m_json(['ok' => false, 'error' => 'برای فعال‌سازی معاملهٔ واقعی دقیقاً عبارت ENABLE-LIVE را تایپ کنید.'], 422);
        }
        if (Config::get('exchange.api_key', '') === '' || Config::get('exchange.api_secret', '') === '') {
            m_json(['ok' => false, 'error' => 'ابتدا کلیدهای API صرافی را ذخیره و تست کنید.'], 422);
        }
        Config::set('exchange.live_enabled', true);
        Config::set('exchange.mode', 'live');
        Config::save();
        Security::audit('exchange', 'enable_live', 'معاملهٔ زنده فعال شد — تأیید صریح کاربر');
        m_json(['ok' => true, 'message' => 'معاملهٔ زنده فعال شد. تمام مسئولیت اجرا با شماست — با مبلغ کم شروع کنید.']);
        break;

    case 'disable_live':
        Config::set('exchange.live_enabled', false);
        Config::save();
        Security::audit('exchange', 'disable_live', 'معاملهٔ زنده غیرفعال شد');
        m_json(['ok' => true, 'message' => 'معاملهٔ زنده قفل شد.']);
        break;

    case 'test':
        $conn = new BinanceSpot(null, (array)Config::get('exchange', []));
        $ping = $conn->ping();
        $out = [
            'ok' => $ping['ok'],
            'provider' => 'binance',
            'mode' => (string)Config::get('exchange.mode', 'testnet'),
            'endpoint' => $conn->baseUrl(),
            'ping_ms' => $ping['latency_ms'],
            'keys_set' => Config::get('exchange.api_key', '') !== '' && Config::get('exchange.api_secret', '') !== '',
            'live_enabled' => (bool)Config::get('exchange.live_enabled', false),
            'balance' => null,
        ];
        if ($out['keys_set']) {
            $bal = $conn->balances();
            $out['balance'] = $bal['ok']
                ? ['USDT' => round((float)($bal['balances']['USDT'] ?? 0), 2),
                   'assets' => count($bal['balances'])]
                : ['error' => $bal['error']];
            if (!$bal['ok']) {
                $out['balance_error'] = $bal['error'];
            }
        }
        m_json($out + ['error' => $ping['ok'] ? null : $ping['error']]);
        break;

    case 'status':
    default:
        m_json([
            'ok' => true,
            'provider' => 'binance',
            'mode' => (string)Config::get('exchange.mode', 'testnet'),
            'keys_set' => Config::get('exchange.api_key', '') !== '' && Config::get('exchange.api_secret', '') !== '',
            'api_key_masked' => Config::get('exchange.api_key', '') !== '' ? m_mask_secret((string)Config::get('exchange.api_key', '')) : null,
            'live_enabled' => (bool)Config::get('exchange.live_enabled', false),
            'testnet_note' => 'کلید تست رایگان از testnet.binance.vision قابل دریافت است.',
        ]);
        break;
}
