<?php
/**
 * POST /api/exchange.php — مدیریت اتصال صرافی‌ها (نسخهٔ ۵٫۵).
 *
 * action=status       وضعیت صرافی فعال + همهٔ صرافی‌ها (بدون افشای راز)
 * action=save_keys    {provider, api_key, api_secret, mode?} ذخیرهٔ رمزنگاری‌شده (راز خالی = حفظ قبلی)
 * action=select       {provider} تغییر صرافی فعال (فقط وقتی معاملهٔ زنده قفل است)
 * action=test         {provider?} تست دسترسی عمومی + موجودی
 * action=enable_live  {confirm:"ENABLE-LIVE"} بازکردن قفل معاملهٔ واقعی (دو مرحله‌ای)
 * action=disable_live قفل مجدد
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Config;
use Meelano\Crypto\AutoTrader;
use Meelano\Crypto\ConnectorFactory;
use Meelano\Db;
use Meelano\Security;

m_guard(false, 'exchange');
$in = m_input();
$action = m_clean_string($in['action'] ?? 'status', 16);
Security::requireRateLimit('exchange', 30);

/** صرافی درخواستی/فعلی — همیشه از فهرست مجاز. */
$currentProvider = static function (array $in): string {
    $p = strtolower(m_clean_string($in['provider'] ?? '', 16));
    if ($p !== '' && ConnectorFactory::isProvider($p)) {
        return $p;
    }
    $p = (string)Config::get('exchange.provider', 'binance');
    return ConnectorFactory::isProvider($p) ? $p : 'binance';
};

/** وضعیت خلاصهٔ همهٔ صرافی‌ها برای UI. */
$providersStatus = static function (): array {
    $out = [];
    foreach (ConnectorFactory::providers() as $id => $meta) {
        $creds = ConnectorFactory::credentials($id);
        $configured = $creds['api_key'] !== '' && ($id === 'wallex' || $creds['api_secret'] !== '');
        $out[$id] = [
            'label' => $meta['label_fa'],
            'icon' => $meta['icon'],
            'color' => $meta['color'],
            'quote' => $meta['quote'],
            'configured' => $configured,
            'api_key_masked' => $creds['api_key'] !== '' ? m_mask_secret($creds['api_key']) : null,
        ];
    }
    return $out;
};

/** حساب معامله‌گر را به حالت live/paper می‌برد (اگر جداول موجودند). */
$flipAccountMode = static function (string $mode, string $provider): void {
    try {
        $db = Db::make();
        if (!$db->isConnected() || !$db->tableExists('trade_accounts')) {
            return;
        }
        $trader = new AutoTrader($db);
        $trader->ensureAccount();
        $db->update('trade_accounts', [
            'mode' => $mode,
            'exchange' => $provider,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = 1');
    } catch (Throwable $e) { /* حساب اختیاری است — پیکربندی مرجع است */ }
};

$provider = $currentProvider($in);

switch ($action) {
    case 'save_keys': {
        $apiKey = trim((string)($in['api_key'] ?? ''));
        $apiSecret = trim((string)($in['api_secret'] ?? ''));
        if ($provider === 'binance') {
            if ($apiKey !== '') {
                Config::set('exchange.api_key', $apiKey);
            }
            if ($apiSecret !== '') {
                Config::set('exchange.api_secret', $apiSecret);
            }
            $mode = ($in['mode'] ?? 'testnet') === 'live' ? 'live' : 'testnet';
            Config::set('exchange.mode', $mode);
        } else {
            if ($apiKey !== '') {
                Config::set('exchange.providers.' . $provider . '.api_key', $apiKey);
            }
            if ($apiSecret !== '' && $provider === 'nobitex') {
                Config::set('exchange.providers.' . $provider . '.api_secret', $apiSecret);
            }
        }
        // تغییر کلید = قفل مجدد معاملهٔ زنده
        Config::set('exchange.live_enabled', false);
        Config::save();
        $flipAccountMode('paper', $provider);
        Security::audit('exchange', 'save_keys', 'کلیدهای ' . $provider . ' ذخیره شد');
        m_json(['ok' => true, 'provider' => $provider, 'message' => 'کلیدها به‌صورت رمزنگاری‌شده ذخیره شد. برای معاملهٔ زنده، مجوز جداگانه لازم است.']);
        break;
    }

    case 'select': {
        $requested = strtolower(m_clean_string($in['provider'] ?? '', 16));
        if ($requested === '' || !ConnectorFactory::isProvider($requested)) {
            m_json(['ok' => false, 'error' => 'صرافی پشتیبانی نمی‌شود: ' . ($requested !== '' ? $requested : '—')], 422);
        }
        if ((bool)Config::get('exchange.live_enabled', false)) {
            m_json(['ok' => false, 'error' => 'ابتدا معاملهٔ زنده را قفل کنید، سپس صرافی را عوض کنید.'], 422);
        }
        $provider = $requested;
        Config::set('exchange.provider', $provider);
        Config::set('exchange.live_enabled', false);
        Config::save();
        $flipAccountMode('paper', $provider);
        Security::audit('exchange', 'select', 'صرافی فعال شد: ' . $provider);
        m_json(['ok' => true, 'provider' => $provider]);
        break;
    }

    case 'enable_live': {
        $confirm = strtoupper(trim((string)($in['confirm'] ?? '')));
        if ($confirm !== 'ENABLE-LIVE') {
            m_json(['ok' => false, 'error' => 'برای فعال‌سازی معاملهٔ واقعی دقیقاً عبارت ENABLE-LIVE را تایپ کنید.'], 422);
        }
        $creds = ConnectorFactory::credentials($provider);
        if ($creds['api_key'] === '' || ($provider !== 'wallex' && $creds['api_secret'] === '')) {
            m_json(['ok' => false, 'error' => 'ابتدا کلیدهای API صرافی «' . $provider . '» را ذخیره و تست کنید.'], 422);
        }
        Config::set('exchange.live_enabled', true);
        if ($provider === 'binance') {
            Config::set('exchange.mode', 'live');
        }
        Config::save();
        $flipAccountMode('live', $provider);
        Security::audit('exchange', 'enable_live', 'معاملهٔ زنده فعال شد — ' . $provider . ' — تأیید صریح کاربر');
        m_json(['ok' => true, 'provider' => $provider, 'message' => 'معاملهٔ زنده فعال شد. تمام مسئولیت اجرا با شماست — با مبلغ کم شروع کنید.']);
        break;
    }

    case 'disable_live': {
        Config::set('exchange.live_enabled', false);
        Config::save();
        $flipAccountMode('paper', $provider);
        Security::audit('exchange', 'disable_live', 'معاملهٔ زنده غیرفعال شد');
        m_json(['ok' => true, 'message' => 'معاملهٔ زنده قفل شد.']);
        break;
    }

    case 'test': {
        [$conn, $err] = ConnectorFactory::make();
        if ($conn === null) {
            m_json(['ok' => false, 'error' => $err ?? 'ساخت اتصال ناموفق بود.'], 422);
        }
        $ping = $conn->ping();
        $creds = ConnectorFactory::credentials($provider);
        $out = [
            'ok' => $ping['ok'],
            'provider' => $provider,
            'mode' => (string)Config::get('exchange.mode', 'testnet'),
            'endpoint' => method_exists($conn, 'baseUrl') ? $conn->baseUrl() : null,
            'ping_ms' => $ping['latency_ms'],
            'keys_set' => $creds['api_key'] !== '' && ($provider === 'wallex' || $creds['api_secret'] !== ''),
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
    }

    case 'status':
    default: {
        $creds = ConnectorFactory::credentials($provider);
        m_json([
            'ok' => true,
            'provider' => $provider,
            'mode' => (string)Config::get('exchange.mode', 'testnet'),
            'keys_set' => $creds['api_key'] !== '' && ($provider === 'wallex' || $creds['api_secret'] !== ''),
            'api_key_masked' => $creds['api_key'] !== '' ? m_mask_secret($creds['api_key']) : null,
            'live_enabled' => (bool)Config::get('exchange.live_enabled', false),
            'providers' => $providersStatus(),
            'testnet_note' => 'بایننس: کلید تست رایگان از testnet.binance.vision — نوبیتکس/والکس: کلید از پنل خود صرافی.',
        ]);
        break;
    }
}
