<?php
/**
 * میلانو تریدینگ اینتلیجنس — داشبورد سیگنال کریپتو (نسخهٔ ۵).
 * پالس زندهٔ بازار · رژیم بیت‌کوین · قیف ۹ مرحله‌ای تصمیم · سیگنال‌های درجه‌دار · بک‌تست.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

use Meelano\Config;
use Meelano\Crypto\Regime;
use Meelano\Db;
use Meelano\Installer;
use Meelano\Security;

Security::secureHeaders();

$db = Db::make();
$dbOk = $db->isConnected();
$install = $dbOk ? (new Installer($db))->status() : null;

$stats = null;
$recent = [];
if ($dbOk && $db->tableExists('signals')) {
    $row = $db->selectOne("SELECT COUNT(*) AS total, SUM(CASE WHEN side='BUY' THEN 1 ELSE 0 END) AS buys,
            SUM(CASE WHEN side='SELL' THEN 1 ELSE 0 END) AS sells,
            COALESCE(AVG(combined_score),0) AS avg_score, COALESCE(MAX(combined_score),0) AS max_score,
            SUM(CASE WHEN tier IN ('A+','A') THEN 1 ELSE 0 END) AS a_tier
            FROM " . $db->table('signals'));
    $stats = [
        'total' => (int)($row['total'] ?? 0),
        'buys' => (int)($row['buys'] ?? 0),
        'sells' => (int)($row['sells'] ?? 0),
        'avg_score' => round((float)($row['avg_score'] ?? 0), 1),
        'max_score' => round((float)($row['max_score'] ?? 0), 1),
        'a_tier' => (int)($row['a_tier'] ?? 0),
        'scans' => $db->tableExists('scans') ? $db->count('scans') : 0,
    ];
    $recent = $db->select('SELECT * FROM ' . $db->table('signals') . ' ORDER BY id DESC LIMIT 20');
}

$trading = (array)Config::get('trading', []);
$regimeLabels = [
    'trend_up' => ['روند صعودی', 'var(--emerald)'],
    'trend_down' => ['روند نزولی', 'var(--rose)'],
    'range' => ['رِنج/فشرده', 'var(--gold-1)'],
    'volatile' => ['نوسان بالا', 'var(--rose)'],
];

m_layout_head('داشبورد سیگنال', 'panel');
?>
<div class="shell">
<?php m_layout_nav('panel'); ?>

<?php if (!$dbOk || !$install || !$install['complete']): ?>
<div class="card-3d section" style="border-color:rgba(251,191,36,.4)">
    <div class="section__head" style="border:none;margin:0;padding:0">
        <h2 class="section__title"><i class="fa-solid fa-triangle-exclamation" style="color:#fbbf24"></i> راه‌اندازی دیتابیس کامل نشده</h2>
        <a class="btn btn--gold btn--sm" href="<?= meelano_e(m_url('settings.php')) ?>"><i class="fa-solid fa-sliders"></i> تنظیمات</a>
    </div>
    <p class="section__hint" style="margin-top:10px">سیگنال‌ها برای ذخیره و تاریخچه به دیتابیس نیاز دارند؛ تحلیل بازار بدون دیتابیس هم کار می‌کند.</p>
</div>
<?php endif; ?>

<div class="page-head">
    <div>
        <h1 class="page-title">موتور سیگنال هوشمند کریپتو</h1>
        <p class="page-sub">
            قیف ۹ مرحله‌ای تصمیم نهادی: نقدینگی ← رژیم بازار ← ۱۵ فیلتر هم‌گرایی ← تأیید چند تایم‌فریمی ←
            اجماع چندمدلی AI ← دروازهٔ بیت‌کوین ← ریسک ساختاری ← درجه‌بندی کیفیت ← خنک‌کردن تکرار.
        </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input type="text" id="single_symbol" placeholder="نماد تک‌ارز: BTCUSDT" class="mono" style="width:150px">
        <button class="btn btn--indigo btn--sm" id="btn_single"><i class="fa-solid fa-crosshairs"></i> تحلیل تک‌ارز</button>
        <button class="btn btn--gold" id="btn_scan"><i class="fa-solid fa-satellite-dish"></i> رصد کل بازار</button>
    </div>
</div>

<!-- ═══ پالس زندهٔ بازار ═══════════════════════════════════════════ -->
<div class="card-3d section" id="pulse_section">
    <div class="section__head">
        <h2 class="section__title"><i class="fa-solid fa-heart-pulse"></i> پالس زندهٔ بازار</h2>
        <span id="pulse_updated" class="badge">در حال دریافت…</span>
    </div>
    <div class="grid grid--4" style="margin-bottom:14px">
        <div class="stat stat--gold">
            <div class="stat__label"><i class="fa-brands fa-bitcoin"></i> بیت‌کوین — لنگر بازار</div>
            <div class="stat__value" id="btc_price">—</div>
            <div class="stat__sub" id="btc_regime">—</div>
        </div>
        <div class="stat stat--indigo">
            <div class="stat__label"><i class="fa-solid fa-scale-unbalanced"></i> عرض بازار</div>
            <div class="stat__value" id="breadth_value">—</div>
            <div class="stat__sub"><div class="breadth-bar"><div id="breadth_fill" class="breadth-bar__fill" style="width:50%"></div></div></div>
        </div>
        <div class="stat stat--emerald">
            <div class="stat__label"><i class="fa-solid fa-arrow-trend-up"></i> روحیهٔ بازار</div>
            <div class="stat__value" id="market_mood">—</div>
            <div class="stat__sub" id="market_mood_sub">—</div>
        </div>
        <div class="stat stat--rose">
            <div class="stat__label"><i class="fa-solid fa-fire"></i> صعودی‌ترین‌ها</div>
            <div class="stat__value" id="top_gainer">—</div>
            <div class="stat__sub" id="top_gainer_sub">—</div>
        </div>
        <div class="stat stat--gold">
            <div class="stat__label"><i class="fa-solid fa-face-meh"></i> ترس و طمع بازار</div>
            <div class="stat__value" id="fg_value">—</div>
            <div class="stat__sub"><div class="fg-gauge"><div id="fg_fill" class="fg-gauge__fill" style="width:50%"></div></div><span id="fg_label">—</span></div>
        </div>
    </div>
    <div class="ticker-strip" id="ticker_strip" aria-hidden="true"><div class="ticker-strip__inner" id="ticker_inner">
        <span class="mono dim">در انتظار دادهٔ بازار…</span>
    </div></div>
</div>

<?php if ($stats !== null): ?>
<div class="grid grid--6" style="margin-bottom:18px">
    <div class="stat stat--gold"><div class="stat__label"><i class="fa-solid fa-signal"></i> کل سیگنال‌ها</div><div class="stat__value"><?= m_persian_digits(number_format($stats['total'])) ?></div></div>
    <div class="stat stat--emerald"><div class="stat__label"><i class="fa-solid fa-arrow-trend-up"></i> خرید</div><div class="stat__value"><?= m_persian_digits(number_format($stats['buys'])) ?></div></div>
    <div class="stat stat--rose"><div class="stat__label"><i class="fa-solid fa-arrow-trend-down"></i> فروش</div><div class="stat__value"><?= m_persian_digits(number_format($stats['sells'])) ?></div></div>
    <div class="stat stat--gold"><div class="stat__label"><i class="fa-solid fa-medal"></i> درجهٔ A+/A</div><div class="stat__value"><?= m_persian_digits(number_format($stats['a_tier'])) ?></div></div>
    <div class="stat stat--indigo"><div class="stat__label"><i class="fa-solid fa-gauge-high"></i> میانگین اعتماد</div><div class="stat__value"><?= m_persian_digits((string)$stats['avg_score']) ?></div></div>
    <div class="stat stat--indigo"><div class="stat__label"><i class="fa-solid fa-rotate"></i> اسکن‌ها</div><div class="stat__value"><?= m_persian_digits(number_format($stats['scans'])) ?></div></div>
</div>
<?php endif; ?>

<!-- ═══ ردیاب سیگنال — عملکرد واقعی گذشته ═════════════════════════ -->
<div class="card-3d section" id="tracker_section" style="margin-top:18px;border-color:rgba(52,211,153,.25)">
    <div class="section__head">
        <h2 class="section__title"><i class="fa-solid fa-bullseye" style="color:#34d399"></i> ردیاب سیگنال — داوریِ گذشته با کندل واقعی</h2>
        <div style="display:flex;gap:8px;align-items:center">
            <span id="tracker_meta" class="badge">در انتظار داده</span>
            <button class="btn btn--emerald btn--sm" id="btn_tracker_run"><i class="fa-solid fa-rotate"></i> داوری سیگنال‌های باز</button>
        </div>
    </div>
    <p class="help" style="margin:0 0 12px">هر سیگنال صادرشده با کندل‌های بعدی داوری می‌شود: TP1/TP2/TP3/استاپ و R واقعی. این آمار، «درجهٔ A+» را از ادعا به عدد تبدیل می‌کند و سوخت قضاوت AI است (مدل خروج: ۵۰٪ در TP1 با انتقال استاپ به سربه‌سر، ۲۵٪ در TP2، بقیه تا TP3).</p>
    <div id="tracker_result"></div>
</div>

<!-- ═══ موتور یادگیری تطبیقی (نسخهٔ ۵٫۶) ═══════════════════════════ -->
<div class="card-3d section" id="learning_section" style="margin-top:18px;border-color:rgba(167,139,250,.25)">
    <div class="section__head">
        <h2 class="section__title"><i class="fa-solid fa-brain" style="color:#a78bfa"></i> موتور یادگیری تطبیقی — بهینه‌سازی وزن فیلترها از کارنامهٔ واقعی</h2>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <span id="learn_meta" class="badge">در حال دریافت…</span>
            <button class="btn btn--sm" id="btn_learn_toggle" hidden><i class="fa-solid fa-power-off"></i></button>
            <button class="btn btn--indigo btn--sm" id="btn_learn_run"><i class="fa-solid fa-graduation-cap"></i> یادگیری فوری</button>
            <button class="btn btn--ghost btn--sm" id="btn_learn_reset"><i class="fa-solid fa-rotate-left"></i> بازنشانی وزن‌ها</button>
        </div>
    </div>
    <p class="help" style="margin:0 0 12px">
        وزن هر فیلتر دیگر عددی ایستا نیست: هر بار ردیاب سیگنال‌ها را داوری کند، این موتور رأیِ تک‌تک فیلترها را با نتیجهٔ واقعی
        (سود/زیان R) مقایسه می‌کند و وزنِ شاهدهای درست‌گو را بالا، وزنِ خطاکارها را پایین می‌آورد — با کف و سقف سخت
        (۰٫۲۵ تا ۲٫۵)، حداقل نمونه و هموارسازی لاپلاس تا نویز نمونهٔ کوچک وزن را نلرزاند. فیلتری که اشتباهش تکرار شود
        <b>قرنطینه</b> می‌شود و اگر کارنامه‌اش بهبود یابد <b>بازسازی</b> و برمی‌گردد. همهٔ رویدادها پایین ثبت می‌شوند.
    </p>
    <div id="learn_summary" class="grid grid--4" style="margin-bottom:12px"></div>
    <div id="learn_filters" style="max-height:420px;overflow-y:auto"></div>
    <details class="collapse" style="margin-top:12px" id="learn_manual_box">
        <summary><i class="fa-solid fa-sliders"></i> مدیریت دستی فیلترها (فعال/غیرفعال + ضریب اجباری)</summary>
        <p class="help" style="margin:10px 0">ضریب دستی بر ضریبِ یادخرفته‌شده مقدم است؛ برای بازگشت به یادگیری خودکار، مقدار را خالی بگذارید و ذخیره کنید. فیلتر غیرفعال کاملاً از قیف حذف می‌شود (آستانهٔ عبور ۶۲٪ خودکار تنظیم می‌شود).</p>
        <div id="learn_manual_list"></div>
        <button class="btn btn--indigo btn--sm" id="btn_learn_manual_save" style="margin-top:10px"><i class="fa-solid fa-floppy-disk"></i> ذخیرهٔ مدیریت دستی</button>
    </details>
    <details class="collapse" style="margin-top:12px">
        <summary><i class="fa-solid fa-clock-rotate-left"></i> آخرین رویدادهای یادگیری</summary>
        <ul class="modal__log mono" id="learn_events" style="max-height:220px;margin-top:12px"></ul>
    </details>
</div>

<!-- ═══ نتایج زنده اسکن ═══════════════════════════════════════════ -->
<div class="card-3d section">
    <div class="section__head">
        <h2 class="section__title"><i class="fa-solid fa-bolt"></i> سیگنال‌های صادرشده (عبورکرده از قیف کامل)</h2>
        <span id="scan_meta" class="badge">در انتظار اسکن</span>
    </div>
    <div id="live_signals" class="stack"></div>
    <div id="no_signal" class="help" style="margin-top:8px">
        هنوز سیگنالی در این نشست صادر نشده. روی «رصد کل بازار» بزنید تا موتور، ارزهای عبورکرده از قیف ۹ مرحله‌ای را پیدا کند.
    </div>
</div>

<!-- ═══ تاریخچه ذخیره‌شده ═════════════════════════════════════════ -->
<?php if ($recent): ?>
<div class="card-3d section" style="margin-top:18px">
    <div class="section__head"><h2 class="section__title"><i class="fa-solid fa-clock-rotate-left"></i> تاریخچهٔ سیگنال‌های ذخیره‌شده</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>نماد</th><th>جهت</th><th>درجه</th><th>رژیم</th><th>اعتماد</th><th>MTF</th><th>ورود</th><th>استاپ</th><th>TP2</th><th>R:R</th><th>پوزیشن٪</th><th>فیلترها</th><th>زمان</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $s): $rl = $regimeLabels[$s['regime']] ?? [$s['regime'], 'var(--text-mute)']; ?>
            <tr>
                <td class="mono" style="font-weight:700"><?= meelano_e($s['symbol']) ?></td>
                <td><span class="badge <?= $s['side'] === 'BUY' ? 'badge--ok' : 'badge--bad' ?>"><?= meelano_e($s['side']) ?></span></td>
                <td><span class="tier-badge tier-<?= meelano_e(str_replace('+', 'ap', (string)$s['tier'])) ?>"><?= meelano_e((string)$s['tier']) ?></span></td>
                <td style="color:<?= $rl[1] ?>;font-size:11px"><?= meelano_e($rl[0]) ?></td>
                <td class="mono"><?= m_persian_digits((string)round((float)$s['combined_score'], 1)) ?></td>
                <td class="mono"><?= m_persian_digits((string)round((float)$s['mtf_score'], 0)) ?></td>
                <td class="mono"><?= meelano_e(rtrim(rtrim(number_format((float)$s['entry_price'], 6), '0'), '.')) ?></td>
                <td class="mono" style="color:var(--rose)"><?= meelano_e(rtrim(rtrim(number_format((float)$s['stop_loss'], 6), '0'), '.')) ?></td>
                <td class="mono" style="color:var(--emerald)"><?= meelano_e(rtrim(rtrim(number_format((float)$s['take_profit_2'], 6), '0'), '.')) ?></td>
                <td class="mono"><?= m_persian_digits((string)round((float)$s['risk_reward'], 1)) ?></td>
                <td class="mono"><?= m_persian_digits((string)round((float)$s['position_pct'], 1)) ?></td>
                <td class="mono"><?= m_persian_digits((string)(int)$s['filters_passed']) ?>/<?= m_persian_digits((string)(int)$s['filters_total']) ?></td>
                <td style="font-size:11px;color:var(--text-mute)"><?= meelano_e((string)$s['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ═══ بک‌تست استراتژی ═══════════════════════════════════════════ -->
<details class="collapse" style="margin-top:18px" id="backtest_section">
    <summary><i class="fa-solid fa-flask-vial"></i> بک‌تست استراتژی روی تاریخ (اعتبارسنجی قبل از اعتماد)</summary>
    <div class="card-3d" style="margin-top:14px;padding:16px;border-color:rgba(99,102,241,.3)">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <div>
                <label class="field-label" for="bt_symbol">نماد</label>
                <input type="text" id="bt_symbol" class="mono" value="BTCUSDT" style="width:130px">
            </div>
            <div>
                <label class="field-label" for="bt_tf">تایم‌فریم</label>
                <select id="bt_tf">
                    <?php foreach (['15m', '1h', '4h', '1d'] as $tf): ?>
                    <option value="<?= $tf ?>" <?= ($trading['timeframe'] ?? '1h') === $tf ? 'selected' : '' ?>><?= $tf ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label" for="bt_bars">تعداد کندل</label>
                <select id="bt_bars">
                    <option>300</option>
                    <option selected>500</option>
                    <option>1000</option>
                </select>
            </div>
            <div>
                <label class="field-label" for="bt_mode">حالت اعتبارسنجی</label>
                <select id="bt_mode">
                    <option value="standard" selected>بک‌تست استاندارد</option>
                    <option value="walkforward">واک‌فوروارد (پایداری)</option>
                    <option value="montecarlo">مونت‌کارلو (توزیع ریسک)</option>
                </select>
            </div>
            <button class="btn btn--indigo btn--sm" id="btn_backtest"><i class="fa-solid fa-flask"></i> اجرا</button>
            <span class="help" style="margin:0">ورود کندل بعدی · استاپ اولویت دارد · کارمزد + اسلیپیج لحاظ شده.</span>
        </div>
        <div id="backtest_result" style="margin-top:14px"></div>
    </div>
</details>

<!-- ═══ پارامترهای موتور ═══════════════════════════════════════════ -->
<details class="collapse" style="margin-top:18px">
    <summary><i class="fa-solid fa-sliders"></i> پارامترهای فعلی موتور (از تنظیمات)</summary>
    <div class="grid grid--4" style="margin-top:14px">
        <div class="kv"><span>تایم‌فریم اصلی</span><span><?= meelano_e((string)($trading['timeframe'] ?? '1h')) ?></span></div>
        <div class="kv"><span>تأیید MTF</span><span><?= meelano_e(implode(' + ', (array)($trading['timeframes'] ?? ['1h', '4h', '1d']))) ?></span></div>
        <div class="kv"><span>حداقل فیلتر عبوری</span><span><?= m_persian_digits((string)(int)($trading['min_filters_passed'] ?? 17)) ?> از ۲۵</span></div>
        <div class="kv"><span>حداقل امتیاز تکنیکال</span><span><?= m_persian_digits((string)(float)($trading['min_tech_score'] ?? 62)) ?></span></div>
        <div class="kv"><span>حداقل امتیاز ترکیبی</span><span><?= m_persian_digits((string)(float)($trading['min_combined_score'] ?? 70)) ?></span></div>
        <div class="kv"><span>حداقل R:R</span><span><?= m_persian_digits((string)(float)($trading['min_risk_reward'] ?? 2)) ?></span></div>
        <div class="kv"><span>الزام اجماع AI</span><span><?= !empty($trading['require_ai_agreement']) ? 'بله' : 'خیر' ?></span></div>
        <div class="kv"><span>الزام هم‌راستایی MTF</span><span><?= !empty($trading['require_mtf']) ? 'بله' : 'خیر' ?></span></div>
        <div class="kv"><span>دروازهٔ بیت‌کوین</span><span><?= !empty($trading['btc_filter']) ? 'فعال' : 'غیرفعال' ?></span></div>
        <div class="kv"><span>ریسک هر معامله</span><span><?= m_persian_digits((string)(float)($trading['risk_per_trade_percent'] ?? 1)) ?>٪</span></div>
        <div class="kv"><span>خنک‌کردن تکرار</span><span><?= m_persian_digits((string)(int)($trading['cooldown_hours'] ?? 12)) ?> ساعت</span></div>
        <div class="kv"><span>حداقل حجم ۲۴س</span><span><?= m_persian_digits(number_format((float)($trading['min_quote_volume'] ?? 5000000))) ?></span></div>
        <div class="kv"><span>سقف هم‌جهت پرتفوی</span><span><?= m_persian_digits((string)(int)($trading['max_same_side'] ?? 4)) ?> نماد</span></div>
        <div class="kv"><span>فیلترهای مشتقات</span><span><?= !empty($trading['enable_funding']) || !empty($trading['enable_open_interest']) ? 'فاندینگ/OI فعال' : 'غیرفعال' ?></span></div>
        <div class="kv"><span>وکیل مدافع AI</span><span><?= !empty($trading['red_team']) ? 'فعال' : 'غیرفعال' ?></span></div>
    </div>
</details>

<?php m_layout_toasts(); ?>
<?php m_layout_footer(); ?>
</div>
<?php m_layout_foot(['assets/js/crypto.js']); ?>
