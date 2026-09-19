<?php
/**
 * POST /api/sentiment.php — لایهٔ هفتم: نبض سنتیمنت آن‌چین (نسخهٔ ۵٫۷).
 *
 * action=status    نبض کش‌شده (بدون ضربهٔ جدید به منابع)
 * action=refresh   ضربهٔ تازه به هر سه منبع (ترس/طمع، مارکت کلان، اخبار RSS)
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Crypto\Sentiment;
use Meelano\Security;

m_guard(false, 'sentiment');
$in = m_input();
$action = m_clean_string($in['action'] ?? 'status', 12);
Security::requireRateLimit('sentiment', 30);

$db = m_ready_db();

switch ($action) {
    case 'refresh': {
        $pulse = Sentiment::sharedPulse(true);
        Security::audit('sentiment', 'refresh', 'به‌روزرسانی دستی نبض سنتیمنت آن‌چین');
        m_json($pulse);
        break;
    }

    case 'status':
    default: {
        $pulse = Sentiment::sharedPulse();
        $risk = (new Sentiment())->riskLevel($pulse);
        m_json($pulse + ['risk_level' => $risk]);
        break;
    }
}
