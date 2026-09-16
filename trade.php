<?php
/**
 * میلانو تریدینگ اینتلیجنس — پنل معامله‌گر خودکار (نسخهٔ ۵٫۲).
 * کیف پول تست (کاغذی) · اجرای خودکار سیگنال‌ها · پایش خروج ·
 * کارنامهٔ تفکیک‌شده با نمودار · اتصال صرافی (Binance Spot / Testnet).
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

use Meelano\Config;
use Meelano\Db;
use Meelano\Security;

Security::secureHeaders();

$db = Db::make();
$dbOk = $db->isConnected();
$tablesOk = $dbOk && $db->tableExists('trade_accounts');

$exchangeProvider = (string)Config::get('exchange.provider', 'binance');
if (!\Meelano\Crypto\ConnectorFactory::isProvider($exchangeProvider)) {
    $exchangeProvider = 'binance';
}

$auto = [
    'auto_trade_enabled' => (bool)Config::get('trading.auto_trade_enabled', false),
    'auto_mode' => (string)Config::get('trading.auto_mode', 'buy_sell'),
    'auto_amount_mode' => (string)Config::get('trading.auto_amount_mode', 'percent'),
    'auto_amount_percent' => (float)Config::get('trading.auto_amount_percent', 10),
    'auto_amount_fixed' => (float)Config::get('trading.auto_amount_fixed', 100),
    'auto_max_open_positions' => (int)Config::get('trading.auto_max_open_positions', 5),
    'auto_min_tier' => (string)Config::get('trading.auto_min_tier', 'A'),
    'auto_min_combined' => (float)Config::get('trading.auto_min_combined', 75),
    'auto_tp_mode' => (string)Config::get('trading.auto_tp_mode', 'ladder'),
    'auto_honor_stop' => (bool)Config::get('trading.auto_honor_stop', true),
    'auto_close_on_opposite' => (bool)Config::get('trading.auto_close_on_opposite', true),
    'auto_dry_run' => (bool)Config::get('trading.auto_dry_run', true),
];

m_layout_head('معامله‌گر خودکار — میلانو | هوش معاملاتی کریپتو', 'trade');
?>

<?php if (!$tablesOk): ?>
<div class="card-3d section" style="border-color:rgba(251,191,36,.4)">
    <div class="section__head" style="border:none;margin:0;padding:0">
        <h2 class="section__title"><i class="fa-solid fa-triangle-exclamation" style="color:#fbbf24"></i> پایگاه‌داده آماده نیست</h2>
    </div>
    <p class="section__hint" style="margin-top:10px">پنل معامله‌گر به جداول حساب/پوزیشن/معامله نیاز دارد. از <a href="settings.php" style="color:var(--indigo-1)">تنظیمات ← پایگاه‌داده ← ساخت جداول</a> راه‌اندازی کنید.</p>
</div>
<?php else: ?>

<!-- ═══ وضعیت کیف و کلیدهای آماری ═══════════════════════════════ -->
<div class="section__head" style="margin-bottom:14px">
    <h2 class="section__title"><i class="fa-solid fa-robot"></i> معامله‌گر خودکار — کیف پول تست</h2>
    <div style="display:flex;gap:8px;align-items:center">
        <span id="auto_status_chip" class="chip chip--pending">وضعیت: در حال دریافت…</span>
        <span id="paper_refresh_stamp" class="badge">—</span>
    </div>
</div>

<div class="grid grid--5" id="wallet_cards" style="margin-bottom:18px">
    <div class="stat stat--gold tilt-3d">
        <div class="stat__label"><i class="fa-solid fa-wallet"></i> موجودی آزاد (USDT)</div>
        <div class="stat__value" id="w_balance">—</div>
        <div class="stat__sub" id="w_balance_sub">نقد قابل استفاده برای معاملات جدید</div>
    </div>
    <div class="stat stat--indigo tilt-3d">
        <div class="stat__label"><i class="fa-solid fa-coins"></i> ارزش کل پرتفوی</div>
        <div class="stat__value" id="w_equity">—</div>
        <div class="stat__sub" id="w_equity_sub">موجودی + ارزش لحظه‌ای پوزیشن‌های باز</div>
    </div>
    <div class="stat tilt-3d" id="w_pnl_card">
        <div class="stat__label"><i class="fa-solid fa-chart-simple"></i> سود/ضرر کل</div>
        <div class="stat__value" id="w_pnl">—</div>
        <div class="stat__sub" id="w_pnl_sub">نسبت به موجودی اولیه</div>
    </div>
    <div class="stat stat--emerald tilt-3d">
        <div class="stat__label"><i class="fa-solid fa-briefcase"></i> پوزیشن‌های باز</div>
        <div class="stat__value" id="w_open">—</div>
        <div class="stat__sub" id="w_open_sub">در پایش استاپ/تارگت</div>
    </div>
    <div class="stat stat--rose tilt-3d">
        <div class="stat__label"><i class="fa-solid fa-arrows-rotate"></i> معاملات بسته‌شده</div>
        <div class="stat__value" id="w_trades">—</div>
        <div class="stat__sub" id="w_trades_sub">وین‌ریت <span id="w_winrate">—</span></div>
    </div>
</div>

<!-- ═══ کنترل معاملهٔ خودکار ═════════════════════════════════════ -->
<div class="card-3d section" id="auto_panel" style="border-color:rgba(99,102,241,.35)">
    <div class="section__head">
        <h2 class="section__title"><i class="fa-solid fa-sliders"></i> تنظیمات معاملهٔ خودکار</h2>
        <div style="display:flex;gap:10px;align-items:center">
            <label class="switch" style="gap:8px">
                <input type="checkbox" id="at_enabled" <?= $auto['auto_trade_enabled'] ? 'checked' : '' ?>>
                <span class="switch__track"></span>
                <span style="font-size:12px;font-weight:700" id="at_enabled_label"><?= $auto['auto_trade_enabled'] ? 'روشن' : 'خاموش' ?></span>
            </label>
            <button class="btn btn--emerald btn--sm" id="btn_auto_save"><i class="fa-solid fa-floppy-disk"></i> ذخیرهٔ تنظیمات</button>
        </div>
    </div>

    <div class="grid grid--3" style="margin-top:6px">
        <?php
        $modes = [
            'buy_sell' => ['fa-arrows-up-down', 'خرید + فروش', 'ورود با سیگنال خرید، خروج با سیگنال فروش یا استاپ/تارگت'],
            'buy_only' => ['fa-cart-shopping', 'فقط خرید', 'ورود با سیگنال؛ خروج فقط با استاپ/تارگت (بدون سیگنال مخالف)'],
            'sell_only' => ['fa-hand-holding-dollar', 'فقط فروش/خروج', 'ورود جدید انجام نمی‌شود؛ فقط خروج پوزیشن‌های باز با سیگنال فروش'],
        ];
        foreach ($modes as $key => [$icon, $title, $desc]): ?>
        <label class="mode-card <?= $auto['auto_mode'] === $key ? 'is-active' : '' ?>" data-mode="<?= $key ?>">
            <input type="radio" name="at_mode" value="<?= $key ?>" <?= $auto['auto_mode'] === $key ? 'checked' : '' ?> hidden>
            <i class="fa-solid <?= $icon ?>"></i>
            <b><?= $title ?></b>
            <span><?= $desc ?></span>
        </label>
        <?php endforeach; ?>
    </div>

    <div class="grid grid--4" style="margin-top:16px">
        <div>
            <label class="field-label">مبلغ هر معامله</label>
            <div style="display:flex;gap:8px">
                <select id="at_amount_mode" style="width:110px">
                    <option value="percent" <?= $auto['auto_amount_mode'] === 'percent' ? 'selected' : '' ?>>درصدی</option>
                    <option value="fixed" <?= $auto['auto_amount_mode'] === 'fixed' ? 'selected' : '' ?>>ثابت</option>
                </select>
                <input type="number" id="at_amount" step="0.1" min="0.5" value="<?= $auto['auto_amount_mode'] === 'percent' ? $auto['auto_amount_percent'] : $auto['auto_amount_fixed'] ?>">
                <span class="help" style="align-self:center" id="at_amount_unit">٪</span>
            </div>
        </div>
        <div>
            <label class="field-label" for="at_max_open">سقف پوزیشن هم‌زمان</label>
            <input type="number" id="at_max_open" min="1" max="20" value="<?= $auto['auto_max_open_positions'] ?>">
        </div>
        <div>
            <label class="field-label" for="at_min_tier">حداقل درجهٔ سیگنال</label>
            <select id="at_min_tier">
                <?php foreach (['A+', 'A', 'B', 'C'] as $t): ?>
                <option value="<?= $t ?>" <?= $auto['auto_min_tier'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label" for="at_min_combined">حداقل امتیاز ترکیبی</label>
            <input type="number" id="at_min_combined" step="1" min="0" max="100" value="<?= $auto['auto_min_combined'] ?>">
        </div>
    </div>

    <div class="grid grid--4" style="margin-top:12px">
        <div>
            <label class="field-label" for="at_tp_mode">استراتژی خروج</label>
            <select id="at_tp_mode">
                <option value="ladder" <?= $auto['auto_tp_mode'] === 'ladder' ? 'selected' : '' ?>>لایه‌نردبانی ۵۰/۲۵/۲۵</option>
                <option value="tp2" <?= $auto['auto_tp_mode'] === 'tp2' ? 'selected' : '' ?>>خروج کامل در TP2</option>
                <option value="tp3" <?= $auto['auto_tp_mode'] === 'tp3' ? 'selected' : '' ?>>خروج کامل در TP3</option>
            </select>
        </div>
        <div style="display:flex;align-items:center;gap:9px">
            <label class="switch"><input type="checkbox" id="at_honor_stop" <?= $auto['auto_honor_stop'] ? 'checked' : '' ?>><span class="switch__track"></span></label>
            <span style="font-size:12px;color:var(--text-dim)">اجرای استاپ سیگنال (بستن در ضررِ تعریف‌شده)</span>
        </div>
        <div style="display:flex;align-items:center;gap:9px">
            <label class="switch"><input type="checkbox" id="at_close_opposite" <?= $auto['auto_close_on_opposite'] ? 'checked' : '' ?>><span class="switch__track"></span></label>
            <span style="font-size:12px;color:var(--text-dim)">بستن پوزیشن با سیگنال مخالف (فروش)</span>
        </div>
        <div style="display:flex;align-items:center;gap:9px">
            <label class="switch"><input type="checkbox" id="at_dry_run" <?= $auto['auto_dry_run'] ? 'checked' : '' ?>><span class="switch__track"></span></label>
            <span style="font-size:12px;color:var(--text-dim)">حالت شبیه‌سازی (Dry-Run) — فقط ثبت، بدون اجرا</span>
        </div>
    </div>

    <div class="divider" style="margin:16px 0"></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <button class="btn btn--gold btn--sm" id="btn_wallet_reset"><i class="fa-solid fa-rotate-left"></i> بازنشانی کیف پول تست</button>
        <span class="help" style="margin:0">موجودی اولیه:</span>
        <input type="number" id="wallet_initial" value="10000" min="100" step="100" style="width:120px">
        <span class="help" style="margin:0">USDT — تاریخچهٔ پوزیشن‌ها و معاملات پاک می‌شود.</span>
        <span class="help" style="margin:0;margin-inline-start:auto"><i class="fa-solid fa-circle-info"></i> هر اسکن بازار (از داشبورد یا این پنل) سیگنال‌ها را به‌صورت خودکار اجرا می‌کند.</span>
    </div>
</div>

<!-- ═══ نمودارها ═══════════════════════════════════════════════ -->
<div class="grid grid--2" style="margin-top:18px">
    <div class="card-3d section tilt-3d">
        <div class="section__head" style="border:none;margin:0;padding:0">
            <h3 class="section__title" style="font-size:15px"><i class="fa-solid fa-chart-area" style="color:var(--gold-1)"></i> منحنی سرمایه</h3>
            <span class="badge" id="eq_roi">—</span>
        </div>
        <div id="equity_chart" class="chart-box"></div>
        <div class="stat__sub" id="equity_note">موجودی اولیه تا ارزش فعلی — هر نقطه یک معاملهٔ بسته‌شده</div>
    </div>
    <div class="card-3d section tilt-3d">
        <div class="section__head" style="border:none;margin:0;padding:0">
            <h3 class="section__title" style="font-size:15px"><i class="fa-solid fa-chart-pie" style="color:var(--emerald)"></i> برد/باخت و سود به تفکیک نماد</h3>
            <span class="badge" id="dn_summary">—</span>
        </div>
        <div id="donut_chart" class="chart-box" style="height:120px"></div>
        <div id="symbol_bars" style="margin-top:12px"></div>
    </div>
</div>

<!-- ═══ پوزیشن‌های باز ══════════════════════════════════════════ -->
<div class="card-3d section" style="margin-top:18px">
    <div class="section__head">
        <h3 class="section__title"><i class="fa-solid fa-briefcase" style="color:var(--indigo-1)"></i> پوزیشن‌های باز</h3>
        <div style="display:flex;gap:8px">
            <button class="btn btn--indigo btn--sm" id="btn_paper_update"><i class="fa-solid fa-arrows-rotate"></i> پایش دستی خروج</button>
            <button class="btn btn--gold btn--sm" id="btn_paper_open"><i class="fa-solid fa-cart-plus"></i> معاملهٔ دستی</button>
        </div>
    </div>
    <div id="open_positions"></div>
</div>

<!-- ═══ کارنامهٔ معاملات ═════════════════════════════════════════ -->
<div class="card-3d section" style="margin-top:18px">
    <div class="section__head">
        <h3 class="section__title"><i class="fa-solid fa-receipt" style="color:var(--emerald)"></i> کارنامهٔ معاملات (تفکیک‌شده)</h3>
        <span class="badge" id="trades_meta">—</span>
    </div>
    <div id="trades_box"></div>
</div>

<!-- ═══ اتصال صرافی‌ها (بایننس + ایرانی) ═══════════════════════════ -->
<div class="card-3d section" style="margin-top:18px;border-color:rgba(251,191,36,.3)">
    <div class="section__head">
        <h3 class="section__title"><i class="fa-solid fa-plug-circle-bolt" style="color:#fbbf24"></i> اتصال صرافی (معاملهٔ واقعی — اختیاری)</h3>
        <span id="ex_status_chip" class="chip chip--pending">در حال بررسی…</span>
    </div>
    <p class="help" style="margin:0 0 14px">
        <i class="fa-solid fa-shield-halved"></i>
        کلیدها فقط سمت سرور و <b>رمزنگاری‌شده (AES-256-GCM)</b> ذخیره می‌شوند و هرگز در خروجی API ظاهر نمی‌شوند.
        صرافی فعال را انتخاب کنید، کلیدها را ذخیره و <b>تست اتصال</b> بگیرید.
        معاملهٔ زنده پیش‌فرض <b>قفل</b> است و فعال‌سازی آن نیازمند تأیید دو مرحله‌ای است.
        <b>نوبیتکس و والکس</b> برای کاربران ایرانی بدون محدودیت جغرافیایی قابل استفاده‌اند.
    </p>
    <div class="grid grid--3" style="margin-bottom:14px" id="ex_cards">
        <?php $i = 0; foreach (\Meelano\Crypto\ConnectorFactory::providers() as $pid => $pm): $i++; ?>
        <div class="ex-card<?= $pid === $exchangeProvider ? ' is-active' : '' ?>" data-ex-card="<?= meelano_e($pid) ?>" style="cursor:pointer">
            <i class="<?= meelano_e($pm['icon']) ?>" style="font-size:24px;color:<?= meelano_e($pm['color']) ?>"></i>
            <b><?= meelano_e($pm['label_fa']) ?></b>
            <span data-ex-quote="<?= meelano_e($pid) ?>"><?= meelano_e($pm['quote']) ?></span>
            <span class="nt-dot" data-ex-dot="<?= meelano_e($pid) ?>" title="وضعیت کلیدها" style="font-size:14px">•</span>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="grid grid--4">
        <div>
            <label class="field-label" for="ex_provider">صرافی فعال</label>
            <select id="ex_provider">
                <?php foreach (\Meelano\Crypto\ConnectorFactory::providers() as $pid => $pm): ?>
                <option value="<?= meelano_e($pid) ?>" <?= $pid === $exchangeProvider ? 'selected' : '' ?>><?= meelano_e($pm['label_fa']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label" for="ex_key">API Key / کلید عمومی</label>
            <input type="text" id="ex_key" class="mono" autocomplete="off" placeholder="کلید عمومی صرافی انتخابی">
        </div>
        <div id="ex_secret_box">
            <label class="field-label" for="ex_secret">API Secret / کلید خصوصی</label>
            <input type="password" id="ex_secret" autocomplete="new-password" placeholder="فقط اگر می‌خواهید عوض شود">
        </div>
        <div id="ex_mode_box">
            <label class="field-label" for="ex_mode">محیط اجرا (فقط بایننس)</label>
            <select id="ex_mode">
                <option value="testnet">Testnet (تست امن)</option>
                <option value="live">Live (واقعی)</option>
            </select>
        </div>
        <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
            <button class="btn btn--indigo btn--sm" id="btn_ex_save"><i class="fa-solid fa-key"></i> ذخیرهٔ کلیدها</button>
            <button class="btn btn--gold btn--sm" id="btn_ex_test"><i class="fa-solid fa-satellite-dish"></i> تست اتصال</button>
        </div>
    </div>
    <p class="help" id="ex_provider_help" style="margin:10px 0 0"></p>
    <div id="ex_result" style="margin-top:12px"></div>
    <div class="divider" style="margin:14px 0"></div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button class="btn btn--bad btn--sm" id="btn_ex_live" style="border:1px solid rgba(251,113,133,.5)">
            <i class="fa-solid fa-unlock"></i> فعال‌سازی معاملهٔ واقعی
        </button>
        <button class="btn btn--sm" id="btn_ex_live_off" hidden><i class="fa-solid fa-lock"></i> قفل کردن معاملهٔ واقعی</button>
        <span class="help" style="margin:0">فعال‌سازی نیازمند تایپ عبارت تأیید است — دو مرحله امن.</span>
    </div>
</div>

<?php endif; ?>

<?php m_layout_toasts(); ?>
<?php m_layout_footer(); ?>
</div>
<script>
<?php
$exMeta = [];
foreach (\Meelano\Crypto\ConnectorFactory::providers() as $pid => $pm) {
    $exMeta[$pid] = [
        'label_fa' => $pm['label_fa'],
        'help' => $pm['help'],
        'key_ph' => $pm['fields']['api_key']['ph'] ?? '',
        'key_is_secret' => (bool)($pm['fields']['api_key']['secret'] ?? false),
        'secret_ph' => $pm['fields']['api_secret']['ph'] ?? '',
        'has_secret_field' => isset($pm['fields']['api_secret']),
    ];
}
?>
window.MEELANO_EX_META = <?= json_encode($exMeta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<?php m_layout_foot(['assets/js/trade.js']); ?>
