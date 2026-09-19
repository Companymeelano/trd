<?php
/**
 * ورود به بخش مدیریت.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

use Meelano\Config;
use Meelano\Security;

Security::secureHeaders();

if (isset($_GET['logout'])) {
    Security::logout();
    header('Location: ' . m_url('login.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrf();
    $rate = Security::rateLimit('login', 8);
    if (!$rate['allowed']) {
        $error = 'تعداد تلاش‌ها بیش از حد است. ' . $rate['reset_in'] . ' ثانیه صبر کنید.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        if (Security::attemptLogin($password)) {
            header('Location: ' . m_url('settings.php'));
            exit;
        }
        $error = 'رمز عبور نادرست است.';
    }
}

if (Security::isLoggedIn()) {
    header('Location: ' . m_url('settings.php'));
    exit;
}

m_layout_head('ورود', 'login');
?>
<div class="login-wrap">
    <div class="login-card card-3d">
        <div class="login-mark"><i class="fa-solid fa-cube"></i></div>
        <h1 class="page-title" style="font-size:22px">پنل هوشمند میلانو</h1>
        <p class="page-sub" style="margin:8px auto 20px;text-align:center">برای دسترسی به تنظیمات و مدیریت کالا وارد شوید</p>

        <?php if ($error !== ''): ?>
        <div class="audit audit--critical" style="margin-bottom:14px">
            <i class="fa-solid fa-circle-xmark"></i>
            <div><p class="audit__title">ورود ناموفق</p><p class="audit__detail"><?= meelano_e($error) ?></p></div>
        </div>
        <?php endif; ?>

        <?php if ((string)Config::get('app.admin_hash', '') === ''): ?>
        <div class="audit audit--warning" style="margin-bottom:14px">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <p class="audit__title">رمز پیش‌فرض فعال است</p>
                <p class="audit__detail mono">meelano-admin — بلافاصله پس از ورود، از تب «امنیت» آن را تغییر دهید.</p>
            </div>
        </div>
        <?php endif; ?>

        <form method="post" action="<?= meelano_e(m_url('login.php')) ?>">
            <input type="hidden" name="_csrf" value="<?= meelano_e(Security::csrfToken()) ?>">
            <label class="field-label" for="password">رمز عبور</label>
            <input type="password" id="password" name="password" required autofocus autocomplete="current-password">
            <button type="submit" class="btn btn--gold btn--block" style="margin-top:16px">
                <i class="fa-solid fa-right-to-bracket"></i> ورود
            </button>
        </form>

        <p class="help" style="margin-top:18px;text-align:center">
            <a href="<?= meelano_e(m_url('index.php')) ?>" style="color:var(--indigo-1);text-decoration:none">بازگشت به پنل کالا</a>
        </p>
    </div>

<?php m_layout_toasts(); ?>
<?php m_layout_footer(); ?>
</div>
<?php m_layout_foot([]); ?>
