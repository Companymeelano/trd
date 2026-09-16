<?php
/**
 * تنظیمات — اتصال دیتابیس، ساخت جداول، کلیدهای هوش مصنوعی و مسیریابی.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

use Meelano\Ai\Registry;
use Meelano\Config;
use Meelano\Db;
use Meelano\Installer;
use Meelano\Security;

Security::secureHeaders();
Security::requireLogin();

$public = Config::publicView();
$providers = Registry::providers();
$tasks = Registry::tasks();
$routingMap = (array)Config::get('routing.map', []);

$db = Db::make();
$dbOk = $db->isConnected();
$install = $dbOk ? (new Installer($db))->status() : null;

m_layout_head('تنظیمات', 'settings');
?>
<div class="shell">
<?php m_layout_nav('settings'); ?>

<div class="page-head">
    <div>
        <h1 class="page-title">تنظیمات سامانه</h1>
        <p class="page-sub">
            اتصال پایگاه‌داده، ساخت خودکار جدول‌ها با گزارش لحظه‌ای، اعتبارسنجی کلیدهای هوش مصنوعی
            و مسیریابی هوشمند یا دستی وظایف به موتورهای تخصصی.
        </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn--ghost btn--sm" id="btn_health_all"><i class="fa-solid fa-stethoscope"></i> بررسی سلامت کل سامانه</button>
        <a class="btn btn--ghost btn--sm" href="<?= meelano_e(m_url('login.php?logout=1')) ?>"><i class="fa-solid fa-right-from-bracket"></i> خروج</a>
    </div>
</div>

<div class="tabs" role="tablist" style="margin-bottom:18px">
    <button class="is-active" data-tab="db"><i class="fa-solid fa-database"></i> پایگاه‌داده</button>
    <button data-tab="ai"><i class="fa-solid fa-microchip"></i> هوش مصنوعی</button>
    <button data-tab="routing"><i class="fa-solid fa-route"></i> مسیریابی وظایف</button>
    <button data-tab="pricing"><i class="fa-solid fa-chart-line"></i> موتور سیگنال و ریسک</button>
    <button data-tab="security"><i class="fa-solid fa-shield-halved"></i> امنیت</button>
</div>

<!-- ═══════════ تب دیتابیس ═══════════ -->
<section class="tab-panel card-3d section" id="tab-db">
    <div class="section__head">
        <h2 class="section__title"><i class="fa-solid fa-database"></i> اتصال به پایگاه‌داده</h2>
        <span id="db_status_chip" class="badge"><?= $dbOk ? 'متصل' : 'قطع' ?></span>
    </div>

    <div class="grid grid--3">
        <div>
            <label class="field-label" for="db_host">میزبان (Host)</label>
            <input type="text" id="db_host" class="mono" value="<?= meelano_e($public['db']['host']) ?>" placeholder="localhost">
            <p class="help">در هاست اشتراکی cPanel همیشه <b>localhost</b> است.</p>
        </div>
        <div>
            <label class="field-label" for="db_port">پورت</label>
            <input type="number" id="db_port" class="mono" value="<?= (int)$public['db']['port'] ?>">
        </div>
        <div>
            <label class="field-label" for="db_name">نام پایگاه‌داده</label>
            <input type="text" id="db_name" class="mono" value="<?= meelano_e($public['db']['name']) ?>" placeholder="db_username">
        </div>
        <div>
            <label class="field-label" for="db_user">نام کاربری</label>
            <input type="text" id="db_user" class="mono" value="<?= meelano_e($public['db']['user']) ?>" autocomplete="off">
        </div>
        <div>
            <label class="field-label" for="db_pass">رمز عبور</label>
            <input type="password" id="db_pass" class="mono" placeholder="<?= meelano_e($public['db']['pass_set'] ? '••• ذخیره شده' : 'وارد کنید') ?>" autocomplete="new-password">
            <p class="help">اگر خالی بگذارید، رمز ذخیره‌شده تغییر نمی‌کند.</p>
        </div>
        <div>
            <label class="field-label" for="db_prefix">پیشوند جدول‌ها</label>
            <input type="text" id="db_prefix" class="mono" value="<?= meelano_e($public['db']['prefix']) ?>">
        </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
        <button class="btn btn--indigo" id="btn_db_test"><i class="fa-solid fa-plug-circle-check"></i> تست اتصال</button>
        <button class="btn btn--emerald" id="btn_db_save"><i class="fa-solid fa-floppy-disk"></i> ذخیره تنظیمات</button>
        <button class="btn btn--gold" id="btn_db_install"><i class="fa-solid fa-hammer"></i> ساخت جداول مورد نیاز</button>
    </div>

    <div id="db_test_result" style="margin-top:16px"></div>

    <!-- پیشرفت ساخت جداول -->
    <div class="card-3d" style="margin-top:18px;padding:18px;border-color:rgba(245,183,49,.25)">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px">
            <h3 class="section__title" style="font-size:14px"><i class="fa-solid fa-table-list"></i> پیشرفت ساخت جدول‌ها</h3>
            <span id="install_percent_label" class="mono" style="font-size:13px;color:var(--gold-1)">
                <?= $install ? m_persian_digits((string)$install['percent']) . '٪' : '۰٪' ?>
            </span>
        </div>
        <div class="progress"><div id="install_bar" class="progress__bar" style="width:<?= $install ? (int)$install['percent'] : 0 ?>%"></div></div>
        <ul id="install_log" class="modal__log mono" style="max-height:230px;margin-top:14px">
            <?php if ($install && $install['complete']): ?>
            <li class="ok">✓ همه <?= (int)$install['total'] ?> جدول آماده است (نسخه ساختار <?= meelano_e($install['schema_version']) ?>)</li>
            <?php else: ?>
            <li>برای شروع، روی «ساخت جداول مورد نیاز» بزنید.</li>
            <?php endif; ?>
        </ul>
    </div>
</section>

<!-- ═══════════ تب هوش مصنوعی ═══════════ -->
<section class="tab-panel card-3d section" id="tab-ai" hidden>
    <div class="section__head">
        <h2 class="section__title"><i class="fa-solid fa-microchip"></i> ارائه‌دهندگان هوش مصنوعی</h2>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn btn--indigo btn--sm" id="btn_ai_test_all"><i class="fa-solid fa-satellite-dish"></i> تست همه کلیدها</button>
            <button class="btn btn--emerald btn--sm" id="btn_ai_save"><i class="fa-solid fa-floppy-disk"></i> ذخیره کلیدها</button>
        </div>
    </div>
    <p class="section__hint" style="margin-bottom:16px">
        کلیدها به‌صورت رمزنگاری‌شده روی سرور ذخیره می‌شوند و هرگز به مرورگر برنمی‌گردند.
        برای پاک کردن یک کلید، تیک «پاک کردن» را بزنید.
    </p>

    <div class="grid grid--2" id="provider_grid">
        <?php foreach ($providers as $id => $meta):
            $cfg = (array)Config::get('ai.providers.' . $id, []);
            $keyField = $meta['key_field'] ?? 'api_key';
            $hasKey = !empty($cfg[$keyField]);
            $masked = $hasKey ? m_mask_secret((string)$cfg[$keyField]) : '';
        ?>
        <div class="provider-card" data-provider="<?= meelano_e($id) ?>">
            <div class="provider-card__head">
                <div class="provider-card__icon"><i class="fa-solid <?= meelano_e($meta['icon']) ?>"></i></div>
                <div style="flex:1">
                    <p class="provider-card__name"><?= meelano_e($meta['label']) ?></p>
                    <p class="provider-card__notes"><?= meelano_e($meta['notes']) ?></p>
                </div>
                <label class="switch" title="فعال/غیرفعال">
                    <input type="checkbox" data-field="enabled" <?= !empty($cfg['enabled']) ? 'checked' : '' ?>>
                    <span class="switch__track"></span>
                </label>
            </div>

            <div class="caps">
                <?php foreach ($meta['capabilities'] as $cap): ?>
                <span class="cap cap--on"><?= meelano_e($cap) ?></span>
                <?php endforeach; ?>
                <span class="cap">سرعت <?= m_persian_digits((string)$meta['speed_class']) ?>/۵</span>
                <span class="cap">هزینه <?= m_persian_digits((string)$meta['cost_class']) ?>/۵</span>
            </div>

            <div>
                <label class="field-label" for="key_<?= meelano_e($id) ?>"><?= meelano_e($keyField === 'api_token' ? 'توکن API' : 'کلید API') ?></label>
                <input type="password" class="mono" id="key_<?= meelano_e($id) ?>" data-field="<?= meelano_e($keyField) ?>"
                       placeholder="<?= meelano_e($hasKey ? $masked : 'کلید را وارد کنید') ?>" autocomplete="off">
            </div>

            <?php if (!empty($meta['requires_extra'])): ?>
            <div>
                <label class="field-label" for="acc_<?= meelano_e($id) ?>">شناسه حساب (Account ID)</label>
                <input type="text" class="mono" id="acc_<?= meelano_e($id) ?>" data-field="account_id" value="<?= meelano_e((string)($cfg['account_id'] ?? '')) ?>">
            </div>
            <?php endif; ?>

            <div class="grid grid--2">
                <?php foreach (['base_url' => 'Base URL', 'model' => 'مدل متنی'] as $field => $label): ?>
                    <?php if (!empty($cfg[$field]) || $field === 'base_url'): ?>
                    <div>
                        <label class="field-label" for="fld_<?= meelano_e($id . '_' . $field) ?>"><?= meelano_e($label) ?></label>
                        <input type="text" class="mono" id="fld_<?= meelano_e($id . '_' . $field) ?>" data-field="<?= meelano_e($field) ?>" value="<?= meelano_e((string)($cfg[$field] ?? '')) ?>" style="font-size:11.5px">
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div style="display:flex;gap:8px;align-items:center">
                <button class="btn btn--ghost btn--sm" data-action="test"><i class="fa-solid fa-vial-circle-check"></i> تست اتصال</button>
                <label style="display:flex;gap:6px;align-items:center;font-size:11px;color:var(--text-mute)">
                    <input type="checkbox" data-field="clear" style="width:auto"> پاک کردن کلید
                </label>
            </div>

            <div class="provider-card__status" data-role="status">هنوز تست نشده است.</div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ═══════════ تب مسیریابی ═══════════ -->
<section class="tab-panel card-3d section" id="tab-routing" hidden>
    <div class="section__head">
        <h2 class="section__title"><i class="fa-solid fa-route"></i> مسیریابی وظایف به موتورهای تخصصی</h2>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn btn--gold btn--sm" id="btn_autoroute"><i class="fa-solid fa-wand-magic-sparkles"></i> انتخاب هوشمند خودکار</button>
            <button class="btn btn--emerald btn--sm" id="btn_route_save"><i class="fa-solid fa-floppy-disk"></i> ذخیره مسیریابی دستی</button>
        </div>
    </div>

    <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:16px">
        <span style="font-size:12.5px;color:var(--text-dim)">حالت مسیریابی:</span>
        <label style="display:flex;gap:7px;align-items:center;font-size:12.5px">
            <input type="radio" name="route_mode" value="auto" style="width:auto" <?= Config::get('routing.mode', 'auto') === 'auto' ? 'checked' : '' ?>> خودکار (پیشنهاد سامانه)
        </label>
        <label style="display:flex;gap:7px;align-items:center;font-size:12.5px">
            <input type="radio" name="route_mode" value="manual" style="width:auto" <?= Config::get('routing.mode', 'auto') === 'manual' ? 'checked' : '' ?>> دستی (انتخاب من)
        </label>
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>وظیفه</th><th>بخش</th><th>قابلیت لازم</th><th>ارائه‌دهنده</th><th>دلیل انتخاب</th></tr></thead>
            <tbody id="routing_rows">
            <?php foreach ($tasks as $task => $def):
                $current = $routingMap[$task] ?? '';
            ?>
                <tr data-task="<?= meelano_e($task) ?>">
                    <td><b><?= meelano_e($def['label']) ?></b><br><span class="mono dim" style="font-size:10.5px"><?= meelano_e($task) ?></span></td>
                    <td style="font-size:11.5px;color:var(--text-dim)"><?= meelano_e($def['section']) ?></td>
                    <td><span class="cap cap--on"><?= meelano_e(implode(' + ', $def['requires'])) ?></span></td>
                    <td>
                        <select data-role="provider" style="font-size:12px;min-width:150px">
                            <option value="">— انتخاب نشده —</option>
                            <?php foreach (Registry::withCapability($def['requires'][0]) as $pid): ?>
                            <option value="<?= meelano_e($pid) ?>" <?= $current === $pid ? 'selected' : '' ?>><?= meelano_e($providers[$pid]['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td data-role="reason" style="font-size:11.5px;color:var(--text-dim);max-width:320px">
                        <?= $current !== '' ? 'انتخاب دستی کاربر' : 'هنوز مسیریابی نشده' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="route_report" style="margin-top:16px"></div>
</section>

<!-- ═══════════ تب قیمت‌گذاری ═══════════ -->
<section class="tab-panel card-3d section" id="tab-pricing" hidden>
    <div class="section__head">
        <h2 class="section__title"><i class="fa-solid fa-chart-line"></i> پارامترهای موتور سیگنال و ریسک</h2>
        <button class="btn btn--emerald btn--sm" id="btn_pricing_save"><i class="fa-solid fa-floppy-disk"></i> ذخیره</button>
    </div>

    <h3 class="field-label" style="grid-column:1/-1;margin-top:4px"><i class="fa-solid fa-filter"></i> آستانه‌های قیف تصمیم</h3>
    <div class="grid grid--4">
        <?php
        $trLabels = [
            'scan_limit' => 'تعداد ارز در هر اسکن',
            'min_quote_volume' => 'حداقل حجم ۲۴س (USDT)',
            'min_filters_passed' => 'حداقل فیلتر عبوری (از ۱۵)',
            'min_tech_score' => 'حداقل امتیاز تکنیکال',
            'min_combined_score' => 'حداقل امتیاز ترکیبی',
            'tech_weight' => 'وزن تکنیکال (۰..۱)',
            'ai_weight' => 'وزن هوش مصنوعی (۰..۱)',
            'ai_panel_size' => 'تعداد مدل‌های پنل AI',
            'mtf_min_alignment' => 'حداقل هم‌راستایی MTF (۰..۱)',
            'min_risk_reward' => 'حداقل نسبت ریسک/ریوارد (TP2)',
            'risk_per_trade_percent' => 'ریسک هر معامله ٪',
            'max_position_percent' => 'سقف سایز پوزیشن ٪',
        ];
        foreach ($trLabels as $key => $label): ?>
        <div>
            <label class="field-label" for="pr_<?= meelano_e($key) ?>"><?= meelano_e($label) ?></label>
            <input type="number" step="0.1" id="pr_<?= meelano_e($key) ?>" data-pricing="<?= meelano_e($key) ?>" value="<?= meelano_e((string)Config::get('trading.' . $key)) ?>">
        </div>
        <?php endforeach; ?>
    </div>

    <h3 class="field-label" style="grid-column:1/-1;margin-top:18px"><i class="fa-solid fa-shield-halved"></i> استاپ و مدیریت ریسک</h3>
    <div class="grid grid--4">
        <?php
        $riskLabels = [
            'atr_stop_multiplier' => 'ضریب ATR برای استاپ',
            'max_atr_stop_multiplier' => 'سقف فاصلهٔ استاپ (×ATR)',
            'structure_stop_buffer' => 'بافر استاپ ساختاری (×ATR)',
            'cooldown_hours' => 'خنک‌کردن تکرار نماد (ساعت)',
            'max_signals_per_scan' => 'سقف سیگنال در هر اسکن',
            'max_same_side' => 'سقف نماد هم‌جهت در پرتفوی',
            'max_portfolio_position_pct' => 'سقف سایز تجمعی پرتفوی ٪',
            'backtest_slippage_bps' => 'اسلیپیج هر سمت (بی‌پی‌اس)',
            'tracker_max_age_hours' => 'افق داوری ردیاب (ساعت)',
        ];
        foreach ($riskLabels as $key => $label): ?>
        <div>
            <label class="field-label" for="pr_<?= meelano_e($key) ?>"><?= meelano_e($label) ?></label>
            <input type="number" step="0.1" id="pr_<?= meelano_e($key) ?>" data-pricing="<?= meelano_e($key) ?>" value="<?= meelano_e((string)Config::get('trading.' . $key)) ?>">
        </div>
        <?php endforeach; ?>
        <div>
            <label class="field-label" for="pr_tf">تایم‌فریم تحلیل اصلی</label>
            <select id="pr_tf">
                <?php foreach (['15m','30m','1h','2h','4h','1d'] as $tf): ?>
                <option value="<?= $tf ?>" <?= Config::get('trading.timeframe','1h') === $tf ? 'selected' : '' ?>><?= $tf ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <h3 class="field-label" style="grid-column:1/-1;margin-top:18px"><i class="fa-solid fa-layer-group"></i> تأیید چند تایم‌فریمی (MTF)</h3>
    <div class="grid grid--2">
        <div>
            <p class="help" style="margin-bottom:8px">تایم‌فریم‌های هم‌راستایی — سیگنال فقط وقتی صادر می‌شود که جهت با اکثریت وزنی این تایم‌فریم‌ها هم‌راستا باشد.</p>
            <div style="display:flex;flex-wrap:wrap;gap:10px">
                <?php
                $activeTfs = (array)Config::get('trading.timeframes', ['1h', '4h', '1d']);
                foreach (['15m','30m','1h','2h','4h','6h','12h','1d'] as $tf): ?>
                <label class="switch" style="gap:6px">
                    <input type="checkbox" class="pr_tf_multi" value="<?= $tf ?>" <?= in_array($tf, $activeTfs, true) ? 'checked' : '' ?>>
                    <span class="switch__track"></span>
                    <span class="mono" style="font-size:12px"><?= $tf ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="stack" style="gap:12px">
            <div style="display:flex;align-items:center;gap:9px">
                <label class="switch"><input type="checkbox" id="pr_require_mtf" <?= Config::get('trading.require_mtf', true) ? 'checked' : '' ?>><span class="switch__track"></span></label>
                <span style="font-size:12px;color:var(--text-dim)">الزام تأیید چند تایم‌فریمی (MTF)</span>
            </div>
            <div style="display:flex;align-items:center;gap:9px">
                <label class="switch"><input type="checkbox" id="pr_btc_filter" <?= Config::get('trading.btc_filter', true) ? 'checked' : '' ?>><span class="switch__track"></span></label>
                <span style="font-size:12px;color:var(--text-dim)">دروازهٔ بیت‌کوین (فیلتر رژیم BTC 4h)</span>
            </div>
            <div style="display:flex;align-items:center;gap:9px">
                <label class="switch"><input type="checkbox" id="pr_require_ai" <?= Config::get('trading.require_ai_agreement', true) ? 'checked' : '' ?>><span class="switch__track"></span></label>
                <span style="font-size:12px;color:var(--text-dim)">الزام اجماع AI برای صدور سیگنال</span>
            </div>
        </div>
    </div>

    <h3 class="field-label" style="grid-column:1/-1;margin-top:18px"><i class="fa-solid fa-microchip"></i> لایه‌های داده و هوش مصنوعی</h3>
    <div class="grid grid--2">
        <div class="stack" style="gap:12px">
            <div style="display:flex;align-items:center;gap:9px">
                <label class="switch"><input type="checkbox" id="pr_red_team" <?= Config::get('trading.red_team', true) ? 'checked' : '' ?>><span class="switch__track"></span></label>
                <span style="font-size:12px;color:var(--text-dim)">وکیل مدافع AI (رأی مخالف — وتوی ستاپ‌های حفره‌دار)</span>
            </div>
            <div style="display:flex;align-items:center;gap:9px">
                <label class="switch"><input type="checkbox" id="pr_self_consistency" <?= Config::get('trading.self_consistency', true) ? 'checked' : '' ?>><span class="switch__track"></span></label>
                <span style="font-size:12px;color:var(--text-dim)">خودسازگاری (پرسش دوم از مدل برتر — حذف رأی ناپایدار)</span>
            </div>
            <div style="display:flex;align-items:center;gap:9px">
                <label class="switch"><input type="checkbox" id="pr_ai_history" <?= Config::get('trading.ai_history_stats', true) ? 'checked' : '' ?>><span class="switch__track"></span></label>
                <span style="font-size:12px;color:var(--text-dim)">تزریق سابقهٔ واقعی ردیاب در قضاوت AI</span>
            </div>
        </div>
        <div class="stack" style="gap:12px">
            <div style="display:flex;align-items:center;gap:9px">
                <label class="switch"><input type="checkbox" id="pr_funding" <?= Config::get('trading.enable_funding', true) ? 'checked' : '' ?>><span class="switch__track"></span></label>
                <span style="font-size:12px;color:var(--text-dim)">فیلتر فاندینگ (شلوغی پوزیشن‌های اهرمی)</span>
            </div>
            <div style="display:flex;align-items:center;gap:9px">
                <label class="switch"><input type="checkbox" id="pr_oi" <?= Config::get('trading.enable_open_interest', true) ? 'checked' : '' ?>><span class="switch__track"></span></label>
                <span style="font-size:12px;color:var(--text-dim)">فیلتر اوپن اینترست (سوخت واقعی حرکت)</span>
            </div>
            <div style="display:flex;align-items:center;gap:9px">
                <label class="switch"><input type="checkbox" id="pr_fg" <?= Config::get('trading.enable_fear_greed', true) ? 'checked' : '' ?>><span class="switch__track"></span></label>
                <span style="font-size:12px;color:var(--text-dim)">شاخص ترس و طمع (لایهٔ سنتیمنت کلان)</span>
            </div>
        </div>
    </div>

    <p class="help" style="margin-top:12px">
        این آستانه‌ها سخت‌گیری موتور را کنترل می‌کنند؛ مقادیر بالاتر = سیگنال کمتر اما خطای کمتر.
        پیش‌فرض‌ها برای بازار کریپتو محافظه‌کارانه تنظیم شده‌اند.
    </p>
</section>

<!-- ═══════════ تب امنیت ═══════════ -->
<section class="tab-panel card-3d section" id="tab-security" hidden>
    <div class="section__head"><h2 class="section__title"><i class="fa-solid fa-shield-halved"></i> امنیت دسترسی</h2></div>
    <div class="grid grid--2">
        <div>
            <label class="field-label" for="new_pass">رمز جدید ورود به تنظیمات</label>
            <input type="password" id="new_pass" autocomplete="new-password" placeholder="حداقل ۸ نویسه">
            <p class="help">رمز فعلی به‌صورت هش شده نگهداری می‌شود. پس از تغییر، از شما خواسته می‌شود دوباره وارد شوید.</p>
            <button class="btn btn--indigo btn--sm" id="btn_pass_save" style="margin-top:10px"><i class="fa-solid fa-key"></i> تغییر رمز</button>
        </div>
        <div>
            <label class="field-label">هشدارهای امنیتی</label>
            <div id="security_notes" class="stack"></div>
        </div>
    </div>
    <div class="divider"></div>
    <div class="grid grid--2">
        <div>
            <label class="field-label" for="cron_key">کلید کران ردیاب (اختیاری)</label>
            <input type="text" id="cron_key" class="mono" autocomplete="off" placeholder="مثلاً 32 نویسهٔ تصادفی" value="<?= meelano_e((string)Config::get('security.cron_key', '')) ?>">
            <p class="help">برای داوری خودکار سیگنال‌های گذشته از cron-job.org یا cPanel، هر ساعت این نشانی را صدا بزنید:<br>
            <code class="mono" style="font-size:11px">api/tracker.php?action=run&amp;key=کلید</code><br>
            بدون کلید، کران غیرفعال است و ردیاب فقط از داخل پنل اجرا می‌شود.</p>
            <button class="btn btn--indigo btn--sm" id="btn_cron_save" style="margin-top:10px"><i class="fa-solid fa-robot"></i> ذخیرهٔ کلید کران</button>
        </div>
        <div>
            <label class="field-label">راهنمای امنیتی</label>
            <ul class="help" style="margin:0;padding-inline-start:18px;line-height:2">
                <li>کلیدهای API که قبلاً در فایل/چت رد و بدل شده‌اند را باطل و دوباره صادر کنید.</li>
                <li>کلید کران را مثل رمز نگه دارید؛ هر کس آن را داشته باشد می‌تواند ردیاب را اجرا کند (فقط داوری — نه تغییر تنظیمات).</li>
                <li>دسترسی وب به <code class="mono">config/</code> و <code class="mono">storage/</code> با htaccess مسدود است.</li>
            </ul>
        </div>
    </div>
    <div class="divider"></div>
    <details class="collapse">
        <summary><i class="fa-solid fa-clock-rotate-left"></i> آخرین رویدادهای لاگ سامانه</summary>
        <ul class="modal__log mono" style="max-height:260px;margin-top:12px">
            <?php foreach (\Meelano\Logger::tail(40) as $entry): ?>
            <li>[<?= meelano_e($entry['ts'] ?? '') ?>] <?= meelano_e($entry['channel'] ?? '') ?> — <?= meelano_e($entry['msg'] ?? '') ?></li>
            <?php endforeach; ?>
        </ul>
    </details>
</section>

<?php m_layout_toasts(); ?>
<?php m_layout_footer(); ?>
</div>
<?php m_layout_foot(['assets/js/settings.js']); ?>
