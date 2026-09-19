<?php
/**
 * GET|POST /api/signals.php — فهرست سیگنال‌ها، آمار و فهرست رصد.
 * ورودی: {action: list|stats|history|watch_add|watch_list|watch_remove, symbol?, limit?}
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Db;
use Meelano\Security;

m_guard(false, 'signals');
$in = m_input();
$action = (string)($in['action'] ?? ($_GET['action'] ?? 'list'));

$db = Db::make();
if (!$db->isConnected()) {
    m_json(['ok' => false, 'error' => 'اتصال دیتابیس برقرار نیست.'], 503);
}

switch ($action) {
    case 'stats':
        $t = $db->table('signals');
        $row = $db->tableExists('signals') ? $db->selectOne("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN side='BUY' THEN 1 ELSE 0 END) AS buys,
                   SUM(CASE WHEN side='SELL' THEN 1 ELSE 0 END) AS sells,
                   COALESCE(AVG(combined_score),0) AS avg_score,
                   COALESCE(MAX(combined_score),0) AS max_score,
                   COALESCE(AVG(CASE WHEN tier='A+' THEN 1 ELSE 0 END),0) AS ap_ratio
            FROM {$t}") : [];
        $scans = $db->tableExists('scans') ? $db->count('scans') : 0;
        $apCount = 0;
        if ($db->tableExists('signals')) {
            $ap = $db->selectOne("SELECT COUNT(*) AS c FROM {$t} WHERE tier IN ('A+','A')");
            $apCount = (int)($ap['c'] ?? 0);
        }
        m_json(['ok' => true, 'stats' => [
            'total' => (int)($row['total'] ?? 0),
            'buys' => (int)($row['buys'] ?? 0),
            'sells' => (int)($row['sells'] ?? 0),
            'avg_score' => round((float)($row['avg_score'] ?? 0), 1),
            'max_score' => round((float)($row['max_score'] ?? 0), 1),
            'a_tier' => $apCount,
            'scans' => $scans,
        ]]);
        break;

    case 'history':
        $limit = max(1, min(200, (int)($in['limit'] ?? 40)));
        if (!$db->tableExists('signals')) {
            m_json(['ok' => true, 'signals' => []]);
        }
        $t = $db->table('signals');
        $rows = $db->select(
            "SELECT id, symbol, side, timeframe, tier, regime, combined_score, tech_score, ai_score, mtf_score,
                    entry_price, stop_loss, take_profit_2, risk_reward, position_pct, filters_passed, filters_total, created_at
             FROM {$t} ORDER BY id DESC LIMIT " . $limit
        );
        m_json(['ok' => true, 'signals' => $rows]);
        break;

    case 'watch_add':
        Security::requireCsrf();
        $symbol = strtoupper(m_clean_string($in['symbol'] ?? '', 20));
        if ($symbol === '') { m_json(['ok' => false, 'error' => 'نماد لازم است.'], 422); }
        try {
            $db->insert('watchlist', ['symbol' => $symbol, 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
            m_json(['ok' => true, 'message' => $symbol . ' به فهرست رصد اضافه شد.']);
        } catch (Throwable $e) {
            m_json(['ok' => false, 'error' => 'قبلاً اضافه شده است.']);
        }
        break;

    case 'watch_list':
        if (!$db->tableExists('watchlist')) {
            m_json(['ok' => true, 'watchlist' => []]);
        }
        $t = $db->table('watchlist');
        $rows = $db->select("SELECT symbol, is_active, note, created_at FROM {$t} WHERE is_active = 1 ORDER BY id DESC LIMIT 100");
        m_json(['ok' => true, 'watchlist' => array_column($rows, 'symbol')]);
        break;

    case 'watch_remove':
        Security::requireCsrf();
        $symbol = strtoupper(m_clean_string($in['symbol'] ?? '', 20));
        if ($symbol === '') { m_json(['ok' => false, 'error' => 'نماد لازم است.'], 422); }
        $t = $db->table('watchlist');
        $db->execute("UPDATE {$t} SET is_active = 0 WHERE symbol = ?", [$symbol]);
        m_json(['ok' => true, 'message' => $symbol . ' از فهرست رصد حذف شد.']);
        break;

    case 'list':
    default:
        if (!$db->tableExists('signals')) {
            m_json(['ok' => true, 'signals' => []]);
        }
        $limit = max(1, min(100, (int)($in['limit'] ?? 20)));
        $t = $db->table('signals');
        $rows = $db->select("SELECT * FROM {$t} ORDER BY id DESC LIMIT " . $limit);
        m_json(['ok' => true, 'signals' => $rows]);
        break;
}
