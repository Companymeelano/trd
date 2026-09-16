<?php
/**
 * میلانو تریدینگ اینتلیجنس — پنل اطلاع‌رسانی چندکاناله (نسخهٔ ۵٫۳).
 * تلگرام · بله · روبیکا · واتساپ · پیامک — تنظیم کامل، تست سلامت،
 * پیش‌نمایش پیام، ارسال گزارش کیف و تاریخچهٔ ارسال.
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
Security::requireLogin();

$db = Db::make();
$dbOk = $db->isConnected();
$tablesOk = $dbOk && $db->tableExists('notify_log');

$channels = \Meelano\Crypto\Notifier::channelMeta();

m_layout_head('اطلاع‌رسانی چندکاناله — میلانو | هوش معاملاتی کریپتو', 'notify');
?>

<?php if (!$tablesOk): ?>
<div class="card-3d section" style="border-color:rgba(251,191,36,.4)">
    <div class="section__head" style="border:none;margin:0;padding:0">
        <h2 class="section__title"><i class="fa-solid fa-triangle-exclamation" style="color:#fbbf24"></i> پایگاه‌داده آماده نیست</h2>
    </div>
    <p class="section__hint" style="margin-top:10px">پنل اطلاع‌رسانی به جدول تاریخچهٔ ارسال نیاز دارد. از <a href="settings.php" style="color:var(--indigo-1)">تنظیمات ← پایگاه‌داده ← ساخت جداول</a> راه‌اندازی کنید.</p>
</div>
<?php else: ?>

<!-- ═══ سربرگ + کلید اصلی ═══════════════════════════════════════════ -->
<div class="section__head" style="margin-bottom:14px">
    <h2 class="section__title"><i class="fa-solid fa-bell"></i> اطلاع‌رسانی چندکاناله</h2>
    <div style="display:flex;gap:8px;align-items:center">
        <span id="nt_master_chip" class="chip chip--pending">وضعیت: در حال دریافت…</span>
        <span id="nt_refresh_stamp" class="badge">—</span>
    </div>
</div>

<div class="card-3d section" id="nt_master_card">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;align-items:end">
        <div style="display:flex;align-items:center;gap:12px">
            <label class="switch" style="gap:8px">
                <input type="checkbox" id="nt_enabled">
                <span class="switch__track"></span>
            </label>
            <div>
                <div style="font-weight:800">سامانهٔ اطلاع‌رسانی</div>
                <div style="font-size:11.5px;color:var(--text-dim)">کلید اصلی — خاموش بودن یعنی هیچ رویدادی ارسال نمی‌شود</div>
            </div>
        </div>
        <div>
            <label class="field-label" for="nt_min_tier">حداقل درجهٔ سیگنال برای اطلاع‌رسانی</label>
            <select id="nt_min_tier">
                <option value="C">C — همهٔ سیگنال‌ها</option>
                <option value="B">B و بالاتر</option>
                <option value="A">A و بالاتر</option>
                <option value="A+">فقط A+</option>
            </select>
        </div>
        <div>
            <label class="field-label" for="nt_throttle">فاصلهٔ حداقلی ارسال (ثانیه)</label>
            <input type="number" id="nt_throttle" min="0" max="3600" step="5" value="45">
            <span class="help" style="margin:4px 0 0">به‌ازای هر کانال × نوع رویداد — ضداسپم</span>
        </div>
        <div style="display:flex;align-items:center;gap:9px">
            <label class="switch"><input type="checkbox" id="nt_report_on_close"><span class="switch__track"></span></label>
            <span style="font-size:12px;color:var(--text-dim)">پس از هر بستن پوزیشن، گزارش کیف هم ارسال شود</span>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn btn--emerald btn--sm" id="btn_nt_save"><i class="fa-solid fa-floppy-disk"></i> ذخیرهٔ تنظیمات</button>
            <button class="btn btn--gold btn--sm" id="btn_nt_report"><i class="fa-solid fa-file-invoice-dollar"></i> ارسال گزارش کیف الان</button>
        </div>
    </div>
</div>

<!-- ═══ کارت‌های کانال‌ها ═══════════════════════════════════════════ -->
<div class="grid grid--2" id="nt_channels" style="margin-top:4px">
<?php foreach ($channels as $cid => $meta): ?>
    <div class="card-3d section nt-card" data-channel="<?= meelano_e($cid) ?>" style="border-top:3px solid <?= meelano_e($meta['color']) ?>">
        <div class="section__head" style="border:none;margin:0 0 10px;padding:0">
            <h3 class="section__title" style="font-size:15px">
                <i class="<?= meelano_e($meta['icon']) ?>" style="color:<?= meelano_e($meta['color']) ?>"></i>
                <?= meelano_e($meta['label']) ?>
                <span class="nt-dot" data-dot="<?= meelano_e($cid) ?>" title="وضعیت">•</span>
            </h3>
            <label class="switch"><input type="checkbox" data-nt-enable="<?= meelano_e($cid) ?>"><span class="switch__track"></span></label>
        </div>
        <p class="help" style="margin:0 0 12px"><?= meelano_e($meta['help']) ?></p>
        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px">
            <?php foreach ($meta['fields'] as $f): ?>
            <div>
                <label class="field-label" for="nt_f_<?= meelano_e($cid) ?>_<?= meelano_e($f['id']) ?>">
                    <?= meelano_e($f['label']) ?>
                    <span class="nt-secret-badge" data-secret-badge="<?= meelano_e($cid) ?>_<?= meelano_e($f['id']) ?>" hidden>● ذخیره شده</span>
                </label>
                <input type="text" id="nt_f_<?= meelano_e($cid) ?>_<?= meelano_e($f['id']) ?>"
                       data-nt-field="<?= meelano_e($cid) ?>|<?= meelano_e($f['id']) ?>"
                       placeholder="<?= meelano_e($f['ph']) ?>" autocomplete="off">
            </div>
            <?php endforeach; ?>
        </div>
        <div class="divider" style="margin:12px 0"></div>
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
            <span style="font-size:11.5px;color:var(--text-dim)">رویدادها:</span>
            <?php foreach (['signal' => 'سیگنال', 'trade_opened' => 'بازشدن', 'trade_closed' => 'بستن', 'wallet_report' => 'گزارش کیف'] as $ev => $evLabel): ?>
            <label class="nt-event">
                <input type="checkbox" data-nt-event="<?= meelano_e($cid) ?>|<?= meelano_e($ev) ?>">
                <span><?= meelano_e($evLabel) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;align-items:center">
            <button class="btn btn--sm" data-nt-check="<?= meelano_e($cid) ?>"><i class="fa-solid fa-heart-pulse"></i> تست سلامت</button>
            <button class="btn btn--indigo btn--sm" data-nt-test="<?= meelano_e($cid) ?>"><i class="fa-solid fa-paper-plane"></i> ارسال پیام تست</button>
            <span class="nt-last" data-last="<?= meelano_e($cid) ?>" style="font-size:11.5px;color:var(--text-dim);margin-inline-start:auto">آخرین ارسال: —</span>
        </div>
        <div class="nt-result" data-result="<?= meelano_e($cid) ?>" style="margin-top:10px"></div>
    </div>
<?php endforeach; ?>
</div>

<!-- ═══ پیش‌نمایش پیام‌ها ═══════════════════════════════════════════ -->
<div class="card-3d section" style="margin-top:18px">
    <div class="section__head" style="border:none;margin:0 0 12px;padding:0">
        <h3 class="section__title" style="font-size:15px"><i class="fa-solid fa-eye"></i> پیش‌نمایش پیام‌ها (دادهٔ نمونه)</h3>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
        <button class="btn btn--sm" data-nt-preview="signal"><i class="fa-solid fa-bullseye"></i> سیگنال</button>
        <button class="btn btn--sm" data-nt-preview="trade_opened"><i class="fa-solid fa-arrow-circle-down"></i> بازشدن</button>
        <button class="btn btn--sm" data-nt-preview="trade_closed"><i class="fa-solid fa-arrow-circle-up"></i> بستن</button>
        <button class="btn btn--sm" data-nt-preview="wallet_report"><i class="fa-solid fa-wallet"></i> گزارش کیف</button>
    </div>
    <div class="nt-preview" id="nt_preview_box">نمونهٔ پیام هر رویداد را اینجا ببینید — دقیقاً همان چیزی که برای کانال‌ها ارسال می‌شود (پیامک: نسخهٔ فشرده).</div>
</div>

<!-- ═══ تاریخچهٔ ارسال ═════════════════════════════════════════════ -->
<div class="card-3d section" style="margin-top:18px">
    <div class="section__head" style="border:none;margin:0 0 12px;padding:0">
        <h3 class="section__title" style="font-size:15px"><i class="fa-solid fa-clock-rotate-left"></i> تاریخچهٔ ارسال</h3>
        <button class="btn btn--sm" id="btn_nt_refresh"><i class="fa-solid fa-rotate"></i> تازه‌سازی</button>
    </div>
    <div class="table-wrap">
        <table class="data" id="nt_log_table">
            <thead>
            <tr>
                <th>زمان</th><th>کانال</th><th>رویداد</th><th>وضعیت</th><th>جزئیات</th>
            </tr>
            </thead>
            <tbody id="nt_log_body">
            <tr><td colspan="5" style="text-align:center;color:var(--text-dim)">—</td></tr>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php m_layout_toasts(); ?>
<?php m_layout_footer(); ?>
</div>
<?php m_layout_foot(['assets/js/notify.js']); ?>
