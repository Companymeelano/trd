<?php
/**
 * POST /api/settings_save.php — ذخیره تنظیمات (دیتابیس، AI، مسیریابی، قیمت‌گذاری).
 *
 * نکته امنیتی: فیلدهای کلید اگر خالی ارسال شوند، مقدار قبلی حفظ می‌شود
 * تا مرورگر (که فقط نسخه پنهان‌شده را دارد) کلیدها را پاک نکند.
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Ai\Registry;
use Meelano\Config;
use Meelano\Security;

m_guard(true, 'settings');
$in = m_input();
$changed = [];

/* ── دیتابیس ─────────────────────────────────────────────────────────── */
if (isset($in['db']) && is_array($in['db'])) {
    foreach (['host', 'name', 'user', 'socket', 'charset', 'prefix'] as $field) {
        if (isset($in['db'][$field]) && $in['db'][$field] !== '') {
            Config::set('db.' . $field, m_clean_string($in['db'][$field], 120));
            $changed[] = 'db.' . $field;
        }
    }
    if (isset($in['db']['port']) && $in['db']['port'] !== '') {
        Config::set('db.port', (int)$in['db']['port']);
        $changed[] = 'db.port';
    }
    if (isset($in['db']['driver']) && in_array($in['db']['driver'], ['mysql', 'sqlite'], true)) {
        Config::set('db.driver', $in['db']['driver']);
        $changed[] = 'db.driver';
    }
    if (!empty($in['db']['pass'])) {
        Config::set('db.pass', (string)$in['db']['pass']);
        $changed[] = 'db.pass';
    }
}

/* ── ارائه‌دهندگان هوش مصنوعی ────────────────────────────────────────── */
if (isset($in['ai']['providers']) && is_array($in['ai']['providers'])) {
    foreach ($in['ai']['providers'] as $id => $values) {
        if (!isset(Registry::providers()[$id]) || !is_array($values)) {
            continue;
        }
        $meta = Registry::provider($id);
        $keyField = $meta['key_field'] ?? 'api_key';

        if (isset($values['enabled'])) {
            Config::set("ai.providers.{$id}.enabled", (bool)$values['enabled']);
        }
        // فقط وقتی مقدار غیرخالی است جایگزین کن (حفظ کلید موجود)
        foreach (['api_key', 'api_token', 'account_id', 'base_url', 'model'] as $field) {
            if (!empty($values[$field])) {
                Config::set("ai.providers.{$id}.{$field}", m_clean_string($values[$field], 300));
                $changed[] = 'ai.providers.' . $id . '.' . $field;
            }
        }
        if (!empty($values[$keyField . '_clear'])) {
            Config::set("ai.providers.{$id}.{$keyField}", '');
            $changed[] = 'ai.providers.' . $id . '.' . $keyField . ' (پاک شد)';
        }
    }
}

/* ── مسیریابی دستی ──────────────────────────────────────────────────── */
if (isset($in['routing']) && is_array($in['routing'])) {
    if (isset($in['routing']['mode']) && in_array($in['routing']['mode'], ['auto', 'manual'], true)) {
        Config::set('routing.mode', $in['routing']['mode']);
        $changed[] = 'routing.mode';
    }
    if (isset($in['routing']['map']) && is_array($in['routing']['map'])) {
        $map = [];
        foreach ($in['routing']['map'] as $task => $provider) {
            if (isset(Registry::tasks()[$task]) && Registry::provider((string)$provider) !== null) {
                $map[$task] = (string)$provider;
            }
        }
        Config::set('routing.map', $map);
        $changed[] = 'routing.map';
    }
}

/* ── پارامترهای معامله و ریسک ────────────────────────────────────────── */
if (isset($in['trading']) && is_array($in['trading'])) {
    $numKeys = [
        'scan_limit', 'min_quote_volume', 'min_filters_passed', 'min_tech_score', 'min_combined_score',
        'tech_weight', 'ai_weight', 'ai_panel_size', 'mtf_min_alignment',
        'risk_per_trade_percent', 'atr_stop_multiplier', 'max_atr_stop_multiplier', 'structure_stop_buffer',
        'max_position_percent', 'min_risk_reward', 'cooldown_hours', 'max_signals_per_scan',
        'max_same_side', 'max_portfolio_position_pct', 'tracker_max_age_hours', 'backtest_slippage_bps',
    ];
    foreach ($numKeys as $key) {
        if (isset($in['trading'][$key])) {
            Config::set('trading.' . $key, m_numeric($in['trading'][$key]));
            $changed[] = 'trading.' . $key;
        }
    }
    $allowedTf = ['5m', '15m', '30m', '1h', '2h', '4h', '6h', '12h', '1d', '1w'];
    if (isset($in['trading']['timeframe']) && in_array($in['trading']['timeframe'], $allowedTf, true)) {
        Config::set('trading.timeframe', $in['trading']['timeframe']);
        $changed[] = 'trading.timeframe';
    }
    if (isset($in['trading']['timeframes']) && is_array($in['trading']['timeframes'])) {
        $tfs = array_values(array_filter(array_map(static function ($t) use ($allowedTf) {
            return in_array((string)$t, $allowedTf, true) ? (string)$t : null;
        }, $in['trading']['timeframes']), static function ($t) {
            return $t !== null;
        }));
        Config::set('trading.timeframes', $tfs);
        $changed[] = 'trading.timeframes';
    }
    foreach (['require_ai_agreement', 'require_mtf', 'btc_filter', 'red_team', 'self_consistency',
                  'ai_history_stats', 'enable_funding', 'enable_open_interest', 'enable_fear_greed'] as $flag) {
        if (isset($in['trading'][$flag])) {
            Config::set('trading.' . $flag, (bool)$in['trading'][$flag]);
            $changed[] = 'trading.' . $flag;
        }
    }
}

/* ── بازار ──────────────────────────────────────────────────────────── */
if (isset($in['market']) && is_array($in['market'])) {
    if (isset($in['market']['file_cache'])) {
        Config::set('market.file_cache', (bool)$in['market']['file_cache']);
    }
    if (isset($in['market']['ticker_cache_ttl'])) {
        Config::set('market.ticker_cache_ttl', max(10, min(600, (int)$in['market']['ticker_cache_ttl'])));
    }
    if (isset($in['market']['candle_cache_ttl'])) {
        Config::set('market.candle_cache_ttl', max(10, min(600, (int)$in['market']['candle_cache_ttl'])));
    }
    if (isset($in['market']['timeout'])) {
        Config::set('market.timeout', max(3, min(60, (int)$in['market']['timeout'])));
    }
}

/* ── امنیت: تغییر رمز + کلید کران ردیاب ──────────────────────────────── */
if (isset($in['security']) && is_array($in['security'])) {
    if (!empty($in['security']['password'])) {
        $pass = (string)$in['security']['password'];
        if (mb_strlen($pass) < 8) {
            m_json(['ok' => false, 'error' => 'رمز باید حداقل ۸ نویسه باشد.'], 422);
        }
        Security::setPassword($pass);
        $changed[] = 'app.admin_hash';
    }
    if (array_key_exists('cron_key', $in['security'])) {
        $key = trim((string)$in['security']['cron_key']);
        if ($key === '') {
            Config::set('security.cron_key', '');
            $changed[] = 'security.cron_key (حذف)';
        } elseif (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key) === 1) {
            Config::set('security.cron_key', $key);
            $changed[] = 'security.cron_key';
        } else {
            m_json(['ok' => false, 'error' => 'کلید کران باید ۱۶ تا ۶۴ نویسهٔ حرف/رقم باشد.'], 422);
        }
    }
}

if (!Config::save()) {
    m_json(['ok' => false, 'error' => 'ذخیره تنظیمات ناموفق بود — مجوز نوشتن پوشه config را بررسی کنید.'], 500);
}

Security::audit('settings', 'save', implode('، ', array_slice($changed, 0, 40)));
m_json([
    'ok' => true,
    'changed' => $changed,
    'message' => 'تنظیمات با موفقیت ذخیره شد.',
    'config' => Config::publicView(),
]);
