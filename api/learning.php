<?php
/**
 * POST /api/learning.php — موتور یادگیری تطبیقی (نسخهٔ ۵٫۶).
 *
 * action=status   گزارش کامل: نسل، ضرایب، آمار هر فیلتر، رویدادها
 * action=run      اجرای فوری یک دور یادگیری از داوری‌های واقعی
 * action=reset    بازنشانی همهٔ ضرایب به پایه (۱٫۰)
 * action=config   {enabled?, eta?, min_samples?, disabled?: string[], overrides?: {key:mult}}
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Config;
use Meelano\Crypto\LearningEngine;
use Meelano\Db;
use Meelano\Security;

m_guard(false, 'learning');
$in = m_input();
$action = m_clean_string($in['action'] ?? 'status', 12);
Security::requireRateLimit('learning', 30);

$db = Db::make();
if (!$db->isConnected()) {
    m_json(['ok' => false, 'error' => 'پایگاه‌داده در دسترس نیست.'], 503);
}
if (!$db->tableExists('signals')) {
    m_json(['ok' => false, 'error' => 'جدول سیگنال‌ها ساخته نشده — ابتدا نصب را کامل کنید.'], 422);
}

switch ($action) {
    case 'run': {
        $r = LearningEngine::learn($db);
        m_json($r + ['status' => LearningEngine::status($db)]);
        break;
    }

    case 'reset': {
        $r = LearningEngine::reset($db);
        if (empty($r['ok'])) {
            m_json(['ok' => false, 'error' => $r['error'] ?? 'بازنشانی ناموفق بود.'], 422);
        }
        Security::audit('learning', 'reset', 'بازنشانی ضرایب فیلترها به پایه');
        m_json(['ok' => true, 'message' => 'همهٔ ضرایب به مقدار پایه (۱٫۰) بازگشتند. کارنامه حفظ شد.',
            'status' => LearningEngine::status($db)]);
        break;
    }

    case 'config': {
        if (isset($in['enabled'])) {
            Config::set('learning.enabled', (bool)$in['enabled']);
        }
        if (isset($in['eta'])) {
            Config::set('learning.eta', min(0.5, max(0.01, (float)$in['eta'])));
        }
        if (isset($in['min_samples'])) {
            Config::set('learning.min_samples', min(200, max(3, (int)$in['min_samples'])));
        }
        if (isset($in['disabled']) && is_array($in['disabled'])) {
            $keys = [];
            foreach ($in['disabled'] as $k) {
                $k = m_clean_string((string)$k, 32);
                if ($k !== '') {
                    $keys[] = $k;
                }
            }
            Config::set('learning.disabled', array_values(array_unique($keys)));
        }
        if (isset($in['overrides']) && is_array($in['overrides'])) {
            $ov = [];
            foreach ($in['overrides'] as $k => $m) {
                $k = m_clean_string((string)$k, 32);
                $m = (float)$m;
                if ($k !== '' && $m > 0) {
                    $ov[$k] = min(3.0, max(0.05, $m));
                }
            }
            Config::set('learning.overrides', $ov);
        }
        Config::save();
        Security::audit('learning', 'config', 'پیکربندی موتور یادگیری ذخیره شد');
        m_json(['ok' => true, 'message' => 'پیکربندی یادگیری ذخیره شد.',
            'status' => LearningEngine::status($db)]);
        break;
    }

    case 'status':
    default: {
        m_json(LearningEngine::status($db));
        break;
    }
}
