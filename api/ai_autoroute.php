<?php
/**
 * POST /api/ai_autoroute.php — مسیریابی هوشمند وظایف → ارائه‌دهنده.
 * ورودی: {apply: true} برای ذخیره نتیجه؛ در غیر این صورت فقط گزارش می‌دهد.
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Ai\Health;
use Meelano\Ai\Registry;
use Meelano\Ai\Router;
use Meelano\Config;
use Meelano\Security;

m_guard(true, 'ai_route');
$in = m_input();

$health = [];
try {
    $db = \Meelano\Db::make();
    if ($db->isConnected()) {
        $health = Health::cached($db);
    }
} catch (Throwable $e) {
    $health = [];
}

$router = new Router(Config::all(), $health);
$plan = $router->autoRoute();

$applied = false;
if (!empty($in['apply'])) {
    Config::set('routing.map', $plan['map']);
    Config::set('routing.fallback', $plan['fallback']);
    Config::set('routing.mode', 'auto');
    $applied = Config::save();
    Security::audit('ai', 'autoroute', sprintf('%d وظیفه مسیریابی شد', count($plan['map'])));
}

// افزودن اطلاعات نمایشی برای هر وظیفه
foreach ($plan['report'] as &$row) {
    $row['capabilities'] = Registry::provider((string)$row['provider'])['capabilities'] ?? [];
    $row['health'] = $health[$row['provider']] ?? null;
}
unset($row);

m_json([
    'ok' => true,
    'applied' => $applied,
    'plan' => $plan,
    'health_used' => count($health),
    'message' => $applied
        ? sprintf('مسیریابی هوشمند اعمال شد — %d وظیفه به بهترین ارائه‌دهنده اختصاص یافت.', count($plan['map']))
        : sprintf('برنامه مسیریابی آماده است — %d وظیفه بررسی شد.', count($plan['report'])),
]);
