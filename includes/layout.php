<?php
/**
 * لایه مشترک صفحات: هدر، فوتر و منابع.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */

use Meelano\Config;
use Meelano\Security;

if (!function_exists('m_layout_head')) {
    /**
     * @param string $title      عنوان صفحه
     * @param string $activeNav  کلید منوی فعال
     * @param array  $extraHead  HTML اضافی برای <head>
     */
    function m_layout_head(string $title = '', string $activeNav = '', array $extraHead = []): void
    {
        $brand = (string)Config::get('app.brand', 'پنل هوشمند میلانو');
        $pageTitle = $title !== '' ? $title . ' — ' . $brand : $brand;
        $csrf = Security::csrfToken();
        $base = m_base_url();
        ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b1020">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= meelano_e($csrf) ?>">
<meta name="app-base" content="<?= meelano_e($base) ?>">
<title><?= meelano_e($pageTitle) ?></title>
<link rel="icon" type="image/png" href="<?= meelano_e(m_url('assets/icon/icon-192.png')) ?>">
<link rel="apple-touch-icon" href="<?= meelano_e(m_url('assets/icon/icon-512.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@600;800&family=Vazirmatn:wght@300;400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= meelano_e(m_url('assets/css/app.css')) ?>">
<script>window.MEELANO = { base: "<?= meelano_e($base) ?>", csrf: "<?= meelano_e($csrf) ?>", version: "<?= meelano_e(MEELANO_VERSION) ?>" };</script>
<?php foreach ($extraHead as $html) {
            echo $html . "\n";
        } ?>
</head>
<body data-nav="<?= meelano_e($activeNav) ?>">
<div class="bg-orb bg-orb--gold" aria-hidden="true"></div>
<div class="bg-orb bg-orb--indigo" aria-hidden="true"></div>
<div class="bg-grid" aria-hidden="true"></div>
<?php
    }
}

if (!function_exists('m_layout_nav')) {
    /** نوار ناوبری بالای صفحه. */
    function m_layout_nav(string $activeNav = ''): void
    {
        $items = [
            'panel' => ['url' => 'index.php', 'icon' => 'fa-chart-line', 'label' => 'داشبورد سیگنال'],
            'settings' => ['url' => 'settings.php', 'icon' => 'fa-sliders', 'label' => 'تنظیمات'],
        ];
        ?>
<nav class="topnav glass-3d">
    <div class="topnav__brand">
        <span class="brand-mark" aria-hidden="true"><i class="fa-solid fa-chart-line"></i></span>
        <span class="brand-text">میلانو <b>تریدینگ اینتلیجنس</b></span>
        <span class="brand-ver">v<?= meelano_e(MEELANO_VERSION) ?></span>
    </div>
    <ul class="topnav__links">
        <?php foreach ($items as $key => $item): ?>
        <li>
            <a href="<?= meelano_e(m_url($item['url'])) ?>" class="<?= $activeNav === $key ? 'is-active' : '' ?>">
                <i class="fa-solid <?= meelano_e($item['icon']) ?>"></i><span><?= meelano_e($item['label']) ?></span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="topnav__status">
        <span id="nav_db_chip" class="chip chip--pending" title="وضعیت پایگاه‌داده">
            <i class="fa-solid fa-database"></i><b>در حال بررسی…</b>
        </span>
        <span id="nav_ai_chip" class="chip chip--pending" title="وضعیت هوش مصنوعی">
            <i class="fa-solid fa-microchip"></i><b>AI</b>
        </span>
    </div>
</nav>
<?php
    }
}

if (!function_exists('m_layout_footer')) {
    /** فوتر برندینگ استودیو میلانو. */
    function m_layout_footer(): void
    {
        $year = (int)date('Y');
        ?>
<footer class="studio-footer">
    <div class="studio-footer__inner">
        <div class="studio-footer__logo" aria-hidden="true">
            <span class="cube-3d"><span></span><span></span><span></span></span>
        </div>
        <div class="studio-footer__text">
            <p class="studio-name">Meelano Studio <span>Design</span></p>
            <p class="studio-line">طراحی و توسعهٔ سامانه‌های هوشمند معاملاتی کریپتو</p>
            <p class="studio-author">صاحب اثر و طراح: <b>Milad Yaghoobi</b></p>
        </div>
        <div class="studio-footer__meta">
            <span class="mono">v<?= meelano_e(MEELANO_VERSION) ?> · <?= meelano_e(MEELANO_CODENAME) ?></span>
            <span class="mono">© <?= $year ?> Meelano Studio</span>
            <span class="mono" id="footer_clock">—</span>
        </div>
    </div>
    <div class="studio-footer__beam" aria-hidden="true"></div>
</footer>
<?php
    }
}

if (!function_exists('m_layout_foot')) {
    /** بستن بدنه + اسکریپت‌ها. */
    function m_layout_foot(array $scripts = []): void
    {
        ?>
<script src="<?= meelano_e(m_url('assets/js/core.js')) ?>"></script>
<?php foreach ($scripts as $src): ?>
<script src="<?= meelano_e(m_url($src)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
    }
}

if (!function_exists('m_layout_toasts')) {
    /** ظرف نوتیفیکیشن‌ها و مودال لودینگ مشترک. */
    function m_layout_toasts(): void
    {
        ?>
<div id="toast_stack" class="toast-stack" role="status" aria-live="polite"></div>

<div id="ai_modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="ai_modal_title" hidden>
    <div class="modal__backdrop" data-close="1"></div>
    <div class="modal__card card-3d">
        <div class="modal__rings" aria-hidden="true">
            <span class="ring ring--1"></span>
            <span class="ring ring--2"></span>
            <span class="ring ring--3"></span>
            <i class="fa-solid fa-cube modal__core"></i>
        </div>
        <h3 id="ai_modal_title" class="modal__title">تحلیل هوشمند در جریان است</h3>
        <p id="ai_modal_status" class="modal__status">آماده‌سازی موتورهای هوش مصنوعی…</p>
        <div class="progress"><div id="ai_modal_bar" class="progress__bar"></div></div>
        <div class="progress__meta">
            <span id="ai_modal_percent" class="mono">۰٪</span>
            <span id="ai_modal_provider" class="mono dim">—</span>
        </div>
        <ul id="ai_modal_log" class="modal__log mono"></ul>
    </div>
</div>
<?php
    }
}
