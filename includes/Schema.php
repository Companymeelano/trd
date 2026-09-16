<?php
/**
 * تعریف ساختار دیتابیس — نسخه کریپتو.
 *
 * ستون‌ها دقیقاً با آنچه SignalEngine و api/* می‌نویسند/می‌خوانند هم‌تراز هستند.
 * ساختار بی‌طرف نسبت به نوع دیتابیس (MySQL یا SQLite).
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */

namespace Meelano;

final class Schema
{
    public const VERSION = '3.1.0-crypto';

    public static function tables(): array
    {
        return [
            'scans' => [
                'label' => 'دورهای اسکن بازار',
                'mysql' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    started_at DATETIME NULL,
                    finished_at DATETIME NULL,
                    coins_scanned INT NOT NULL DEFAULT 0,
                    signals_found INT NOT NULL DEFAULT 0,
                    mode VARCHAR(16) NOT NULL DEFAULT 'market',
                    status VARCHAR(16) NOT NULL DEFAULT 'done',
                    summary_json MEDIUMTEXT NULL,
                    error TEXT NULL,
                    PRIMARY KEY (id),
                    KEY idx_scans_started (started_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'sqlite' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    started_at TEXT,
                    finished_at TEXT,
                    coins_scanned INTEGER NOT NULL DEFAULT 0,
                    signals_found INTEGER NOT NULL DEFAULT 0,
                    mode TEXT NOT NULL DEFAULT 'market',
                    status TEXT NOT NULL DEFAULT 'done',
                    summary_json TEXT,
                    error TEXT
                )",
                'sqlite_indexes' => ['CREATE INDEX IF NOT EXISTS %i% ON %t% (started_at)'],
            ],

            'signals' => [
                'label' => 'سیگنال‌های معامله',
                'mysql' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    scan_id INT UNSIGNED NULL,
                    symbol VARCHAR(32) NOT NULL,
                    side VARCHAR(8) NOT NULL DEFAULT 'BUY',
                    timeframe VARCHAR(8) NOT NULL DEFAULT '1h',
                    tier VARCHAR(4) NOT NULL DEFAULT 'C',
                    regime VARCHAR(16) NOT NULL DEFAULT '',
                    confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
                    tech_score DECIMAL(5,2) NOT NULL DEFAULT 0,
                    ai_score DECIMAL(5,2) NOT NULL DEFAULT 0,
                    combined_score DECIMAL(5,2) NOT NULL DEFAULT 0,
                    mtf_score DECIMAL(5,2) NOT NULL DEFAULT 0,
                    entry_price DECIMAL(20,8) NOT NULL DEFAULT 0,
                    stop_loss DECIMAL(20,8) NOT NULL DEFAULT 0,
                    take_profit_1 DECIMAL(20,8) NOT NULL DEFAULT 0,
                    take_profit_2 DECIMAL(20,8) NOT NULL DEFAULT 0,
                    take_profit_3 DECIMAL(20,8) NOT NULL DEFAULT 0,
                    risk_reward DECIMAL(8,3) NOT NULL DEFAULT 0,
                    position_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
                    invalidation TEXT NULL,
                    filters_passed INT NOT NULL DEFAULT 0,
                    filters_total INT NOT NULL DEFAULT 0,
                    filters_json MEDIUMTEXT NULL,
                    mtf_json MEDIUMTEXT NULL,
                    ai_json MEDIUMTEXT NULL,
                    status VARCHAR(16) NOT NULL DEFAULT 'new',
                    created_at DATETIME NOT NULL,
                    hit_tp1 TINYINT NOT NULL DEFAULT 0,
                    hit_tp2 TINYINT NOT NULL DEFAULT 0,
                    hit_tp3 TINYINT NOT NULL DEFAULT 0,
                    hit_stop TINYINT NOT NULL DEFAULT 0,
                    outcome VARCHAR(16) NOT NULL DEFAULT '',
                    exit_price DECIMAL(20,8) NOT NULL DEFAULT 0,
                    r_multiple DECIMAL(8,3) NOT NULL DEFAULT 0,
                    bars_held INT NOT NULL DEFAULT 0,
                    resolved_at DATETIME NULL,
                    tracker_json MEDIUMTEXT NULL,
                    PRIMARY KEY (id),
                    KEY idx_signals_created (created_at),
                    KEY idx_signals_symbol (symbol),
                    KEY idx_signals_outcome (outcome),
                    KEY idx_signals_tier_regime (tier, regime)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'sqlite' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    scan_id INTEGER,
                    symbol TEXT NOT NULL,
                    side TEXT NOT NULL DEFAULT 'BUY',
                    timeframe TEXT NOT NULL DEFAULT '1h',
                    tier TEXT NOT NULL DEFAULT 'C',
                    regime TEXT NOT NULL DEFAULT '',
                    confidence REAL NOT NULL DEFAULT 0,
                    tech_score REAL NOT NULL DEFAULT 0,
                    ai_score REAL NOT NULL DEFAULT 0,
                    combined_score REAL NOT NULL DEFAULT 0,
                    mtf_score REAL NOT NULL DEFAULT 0,
                    entry_price REAL NOT NULL DEFAULT 0,
                    stop_loss REAL NOT NULL DEFAULT 0,
                    take_profit_1 REAL NOT NULL DEFAULT 0,
                    take_profit_2 REAL NOT NULL DEFAULT 0,
                    take_profit_3 REAL NOT NULL DEFAULT 0,
                    risk_reward REAL NOT NULL DEFAULT 0,
                    position_pct REAL NOT NULL DEFAULT 0,
                    invalidation TEXT,
                    filters_passed INTEGER NOT NULL DEFAULT 0,
                    filters_total INTEGER NOT NULL DEFAULT 0,
                    filters_json TEXT,
                    mtf_json TEXT,
                    ai_json TEXT,
                    status TEXT NOT NULL DEFAULT 'new',
                    created_at TEXT NOT NULL,
                    hit_tp1 INTEGER NOT NULL DEFAULT 0,
                    hit_tp2 INTEGER NOT NULL DEFAULT 0,
                    hit_tp3 INTEGER NOT NULL DEFAULT 0,
                    hit_stop INTEGER NOT NULL DEFAULT 0,
                    outcome TEXT NOT NULL DEFAULT '',
                    exit_price REAL NOT NULL DEFAULT 0,
                    r_multiple REAL NOT NULL DEFAULT 0,
                    bars_held INTEGER NOT NULL DEFAULT 0,
                    resolved_at TEXT,
                    tracker_json TEXT
                )",
                'sqlite_indexes' => [
                    'CREATE INDEX IF NOT EXISTS %i% ON %t% (created_at)',
                    'CREATE INDEX IF NOT EXISTS %i% ON %t% (symbol)',
                    'CREATE INDEX IF NOT EXISTS %i% ON %t% (outcome)',
                    'CREATE INDEX IF NOT EXISTS %i% ON %t% (tier, regime)',
                ],
            ],

            'watchlist' => [
                'label' => 'فهرست رصد ارزها',
                'mysql' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    symbol VARCHAR(32) NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    note VARCHAR(191) NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_watch_symbol (symbol)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'sqlite' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    symbol TEXT NOT NULL,
                    is_active INTEGER NOT NULL DEFAULT 1,
                    note TEXT,
                    created_at TEXT NOT NULL
                )",
                'sqlite_indexes' => ['CREATE UNIQUE INDEX IF NOT EXISTS %i% ON %t% (symbol)'],
            ],

            'trade_accounts' => [
                'label' => 'حساب‌های معاملاتی (کاغذی/زنده)',
                'mysql' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    label VARCHAR(64) NOT NULL DEFAULT 'حساب کاغذی',
                    mode VARCHAR(8) NOT NULL DEFAULT 'paper',
                    exchange VARCHAR(24) NOT NULL DEFAULT 'internal',
                    initial_usdt DECIMAL(16,2) NOT NULL DEFAULT 10000,
                    balance_usdt DECIMAL(16,2) NOT NULL DEFAULT 0,
                    status VARCHAR(16) NOT NULL DEFAULT 'active',
                    auto_config_json MEDIUMTEXT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'sqlite' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    label TEXT NOT NULL DEFAULT 'حساب کاغذی',
                    mode TEXT NOT NULL DEFAULT 'paper',
                    exchange TEXT NOT NULL DEFAULT 'internal',
                    initial_usdt REAL NOT NULL DEFAULT 10000,
                    balance_usdt REAL NOT NULL DEFAULT 0,
                    status TEXT NOT NULL DEFAULT 'active',
                    auto_config_json TEXT,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )",
                'sqlite_indexes' => [],
            ],

            'trade_positions' => [
                'label' => 'پوزیشن‌های باز معامله‌گر',
                'mysql' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    account_id INT UNSIGNED NOT NULL DEFAULT 1,
                    symbol VARCHAR(32) NOT NULL,
                    side VARCHAR(8) NOT NULL DEFAULT 'BUY',
                    entry_price DECIMAL(20,8) NOT NULL,
                    quantity DECIMAL(20,8) NOT NULL,
                    entry_usdt DECIMAL(16,2) NOT NULL,
                    stop_loss DECIMAL(20,8) NOT NULL DEFAULT 0,
                    take_profit_1 DECIMAL(20,8) NOT NULL DEFAULT 0,
                    take_profit_2 DECIMAL(20,8) NOT NULL DEFAULT 0,
                    take_profit_3 DECIMAL(20,8) NOT NULL DEFAULT 0,
                    remaining_pct DECIMAL(5,2) NOT NULL DEFAULT 100,
                    realized_usdt DECIMAL(16,2) NOT NULL DEFAULT 0,
                    fees_usdt DECIMAL(16,4) NOT NULL DEFAULT 0,
                    signal_id INT UNSIGNED NULL,
                    tier VARCHAR(4) NOT NULL DEFAULT 'C',
                    regime VARCHAR(16) NOT NULL DEFAULT '',
                    combined_score DECIMAL(5,2) NOT NULL DEFAULT 0,
                    mark_price DECIMAL(20,8) NOT NULL DEFAULT 0,
                    hit_tp1 TINYINT NOT NULL DEFAULT 0,
                    stop_moved TINYINT NOT NULL DEFAULT 0,
                    last_check_ts INT NOT NULL DEFAULT 0,
                    source VARCHAR(16) NOT NULL DEFAULT 'auto',
                    opened_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_pos_account (account_id),
                    KEY idx_pos_symbol (symbol)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'sqlite' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL DEFAULT 1,
                    symbol TEXT NOT NULL,
                    side TEXT NOT NULL DEFAULT 'BUY',
                    entry_price REAL NOT NULL,
                    quantity REAL NOT NULL,
                    entry_usdt REAL NOT NULL,
                    stop_loss REAL NOT NULL DEFAULT 0,
                    take_profit_1 REAL NOT NULL DEFAULT 0,
                    take_profit_2 REAL NOT NULL DEFAULT 0,
                    take_profit_3 REAL NOT NULL DEFAULT 0,
                    remaining_pct REAL NOT NULL DEFAULT 100,
                    realized_usdt REAL NOT NULL DEFAULT 0,
                    fees_usdt REAL NOT NULL DEFAULT 0,
                    signal_id INTEGER,
                    tier TEXT NOT NULL DEFAULT 'C',
                    regime TEXT NOT NULL DEFAULT '',
                    combined_score REAL NOT NULL DEFAULT 0,
                    mark_price REAL NOT NULL DEFAULT 0,
                    hit_tp1 INTEGER NOT NULL DEFAULT 0,
                    stop_moved INTEGER NOT NULL DEFAULT 0,
                    last_check_ts INTEGER NOT NULL DEFAULT 0,
                    source TEXT NOT NULL DEFAULT 'auto',
                    opened_at TEXT NOT NULL
                )",
                'sqlite_indexes' => [
                    'CREATE INDEX IF NOT EXISTS %i% ON %t% (account_id)',
                    'CREATE INDEX IF NOT EXISTS %i% ON %t% (symbol)',
                ],
            ],

            'trade_trades' => [
                'label' => 'کارنامهٔ معاملات (کاغذی/زنده)',
                'mysql' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    account_id INT UNSIGNED NOT NULL DEFAULT 1,
                    position_id INT UNSIGNED NULL,
                    symbol VARCHAR(32) NOT NULL,
                    side VARCHAR(8) NOT NULL DEFAULT 'BUY',
                    entry_price DECIMAL(20,8) NOT NULL,
                    exit_price DECIMAL(20,8) NOT NULL,
                    quantity DECIMAL(20,8) NOT NULL,
                    entry_usdt DECIMAL(16,2) NOT NULL,
                    exit_usdt DECIMAL(16,2) NOT NULL,
                    pnl_usdt DECIMAL(16,4) NOT NULL DEFAULT 0,
                    pnl_pct DECIMAL(8,3) NOT NULL DEFAULT 0,
                    r_multiple DECIMAL(8,3) NOT NULL DEFAULT 0,
                    fees_usdt DECIMAL(16,4) NOT NULL DEFAULT 0,
                    exit_reason VARCHAR(24) NOT NULL DEFAULT 'manual',
                    tier VARCHAR(4) NOT NULL DEFAULT 'C',
                    regime VARCHAR(16) NOT NULL DEFAULT '',
                    source VARCHAR(16) NOT NULL DEFAULT 'auto',
                    opened_at DATETIME NOT NULL,
                    closed_at DATETIME NOT NULL,
                    duration_min INT NOT NULL DEFAULT 0,
                    PRIMARY KEY (id),
                    KEY idx_trades_account (account_id),
                    KEY idx_trades_closed (closed_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'sqlite' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL DEFAULT 1,
                    position_id INTEGER,
                    symbol TEXT NOT NULL,
                    side TEXT NOT NULL DEFAULT 'BUY',
                    entry_price REAL NOT NULL,
                    exit_price REAL NOT NULL,
                    quantity REAL NOT NULL,
                    entry_usdt REAL NOT NULL,
                    exit_usdt REAL NOT NULL,
                    pnl_usdt REAL NOT NULL DEFAULT 0,
                    pnl_pct REAL NOT NULL DEFAULT 0,
                    r_multiple REAL NOT NULL DEFAULT 0,
                    fees_usdt REAL NOT NULL DEFAULT 0,
                    exit_reason TEXT NOT NULL DEFAULT 'manual',
                    tier TEXT NOT NULL DEFAULT 'C',
                    regime TEXT NOT NULL DEFAULT '',
                    source TEXT NOT NULL DEFAULT 'auto',
                    opened_at TEXT NOT NULL,
                    closed_at TEXT NOT NULL,
                    duration_min INTEGER NOT NULL DEFAULT 0
                )",
                'sqlite_indexes' => [
                    'CREATE INDEX IF NOT EXISTS %i% ON %t% (account_id)',
                    'CREATE INDEX IF NOT EXISTS %i% ON %t% (closed_at)',
                ],
            ],

            'notify_log' => [
                'label' => 'تاریخچهٔ ارسال اطلاع‌رسانی (نسخهٔ ۵٫۳)',
                'mysql' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    channel VARCHAR(24) NOT NULL,
                    event VARCHAR(24) NOT NULL,
                    ok TINYINT NOT NULL DEFAULT 0,
                    error VARCHAR(500) NULL,
                    preview VARCHAR(300) NULL,
                    created_ts INT UNSIGNED NOT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_notify_channel (channel, event)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'sqlite' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    channel TEXT NOT NULL,
                    event TEXT NOT NULL,
                    ok INTEGER NOT NULL DEFAULT 0,
                    error TEXT,
                    preview TEXT,
                    created_ts INTEGER NOT NULL,
                    created_at TEXT NOT NULL
                )",
                'sqlite_indexes' => [
                    'CREATE INDEX IF NOT EXISTS %i% ON %t% (channel, event)',
                ],
            ],

            'learning_state' => [
                'label' => 'وضعیت موتور یادگیری تطبیقی (نسخهٔ ۵٫۶)',
                'mysql' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    generation INT NOT NULL DEFAULT 0,
                    weights_json MEDIUMTEXT NULL,
                    stats_json MEDIUMTEXT NULL,
                    total_learned INT NOT NULL DEFAULT 0,
                    last_run DATETIME NULL,
                    PRIMARY KEY (id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'sqlite' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    generation INTEGER NOT NULL DEFAULT 0,
                    weights_json TEXT,
                    stats_json TEXT,
                    total_learned INTEGER NOT NULL DEFAULT 0,
                    last_run TEXT
                )",
            ],
            'learning_events' => [
                'label' => 'رویدادهای یادگیری (تطبیق/قرنطینه/بازسازی)',
                'mysql' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    filter_key VARCHAR(32) NOT NULL,
                    type VARCHAR(16) NOT NULL,
                    old_mult DECIMAL(6,3) NOT NULL DEFAULT 1,
                    new_mult DECIMAL(6,3) NOT NULL DEFAULT 1,
                    detail VARCHAR(500) NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_learning_events_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'sqlite' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    filter_key TEXT NOT NULL,
                    type TEXT NOT NULL,
                    old_mult REAL NOT NULL DEFAULT 1,
                    new_mult REAL NOT NULL DEFAULT 1,
                    detail TEXT,
                    created_at TEXT NOT NULL
                )",
                'sqlite_indexes' => [
                    'CREATE INDEX IF NOT EXISTS %i% ON %t% (created_at)',
                ],
            ],

            'ai_tasks' => [
                'label' => 'تعریف وظایف هوش مصنوعی',
                'mysql' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    task_key VARCHAR(64) NOT NULL,
                    title VARCHAR(128) NOT NULL,
                    capabilities VARCHAR(128) NOT NULL,
                    preferred VARCHAR(64) NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_task (task_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'sqlite' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    task_key TEXT NOT NULL,
                    title TEXT NOT NULL,
                    capabilities TEXT NOT NULL,
                    preferred TEXT
                )",
                'sqlite_indexes' => ['CREATE UNIQUE INDEX IF NOT EXISTS %i% ON %t% (task_key)'],
            ],

            'ai_runs' => [
                'label' => 'کارنامهٔ اجرای موتورهای هوش مصنوعی',
                'mysql' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    task VARCHAR(64) NOT NULL,
                    provider VARCHAR(64) NOT NULL,
                    model VARCHAR(128) NULL,
                    status VARCHAR(16) NOT NULL DEFAULT 'ok',
                    latency_ms INT NOT NULL DEFAULT 0,
                    prompt_tokens INT NOT NULL DEFAULT 0,
                    completion_tokens INT NOT NULL DEFAULT 0,
                    http_code INT NOT NULL DEFAULT 0,
                    error VARCHAR(191) NULL,
                    input_hash CHAR(40) NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_runs_task (task),
                    KEY idx_runs_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'sqlite' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    task TEXT NOT NULL,
                    provider TEXT NOT NULL,
                    model TEXT,
                    status TEXT NOT NULL DEFAULT 'ok',
                    latency_ms INTEGER NOT NULL DEFAULT 0,
                    prompt_tokens INTEGER NOT NULL DEFAULT 0,
                    completion_tokens INTEGER NOT NULL DEFAULT 0,
                    http_code INTEGER NOT NULL DEFAULT 0,
                    error TEXT,
                    input_hash TEXT,
                    created_at TEXT NOT NULL
                )",
                'sqlite_indexes' => [
                    'CREATE INDEX IF NOT EXISTS %i% ON %t% (task)',
                    'CREATE INDEX IF NOT EXISTS %i% ON %t% (created_at)',
                ],
            ],

            'ai_health' => [
                'label' => 'تاریخچه سلامت سرویس‌های AI',
                'mysql' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    provider VARCHAR(64) NOT NULL,
                    ok TINYINT(1) NOT NULL DEFAULT 0,
                    latency_ms INT NOT NULL DEFAULT 0,
                    error VARCHAR(191) NULL,
                    checked_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_health_provider (provider),
                    KEY idx_health_checked (checked_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'sqlite' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    provider TEXT NOT NULL,
                    ok INTEGER NOT NULL DEFAULT 0,
                    latency_ms INTEGER NOT NULL DEFAULT 0,
                    error TEXT,
                    checked_at TEXT NOT NULL
                )",
                'sqlite_indexes' => [
                    'CREATE INDEX IF NOT EXISTS %i% ON %t% (provider)',
                    'CREATE INDEX IF NOT EXISTS %i% ON %t% (checked_at)',
                ],
            ],

            'settings_audit' => [
                'label' => 'رویدادهای تغییر تنظیمات',
                'mysql' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user VARCHAR(64) NOT NULL DEFAULT 'admin',
                    section VARCHAR(64) NOT NULL,
                    action VARCHAR(32) NOT NULL,
                    detail VARCHAR(255) NULL,
                    ip VARCHAR(45) NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_audit_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'sqlite' => "CREATE TABLE IF NOT EXISTS %t% (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user TEXT NOT NULL DEFAULT 'admin',
                    section TEXT NOT NULL,
                    action TEXT NOT NULL,
                    detail TEXT,
                    ip TEXT,
                    created_at TEXT NOT NULL
                )",
                'sqlite_indexes' => ['CREATE INDEX IF NOT EXISTS %i% ON %t% (created_at)'],
            ],

            'app_state' => [
                'label' => 'وضعیت اجرایی',
                'mysql' => "CREATE TABLE IF NOT EXISTS %t% (
                    state_key VARCHAR(64) NOT NULL,
                    state_value TEXT NULL,
                    updated_at DATETIME NULL,
                    PRIMARY KEY (state_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'sqlite' => "CREATE TABLE IF NOT EXISTS %t% (
                    state_key TEXT PRIMARY KEY,
                    state_value TEXT,
                    updated_at TEXT
                )",
            ],
        ];
    }

    public static function names(): array
    {
        return array_keys(self::tables());
    }

    /** @return string[] فهرست دستورات SQL لازم برای ساخت یک جدول (با جای‌گذاری پیشوند) */
    public static function statements(string $table, string $driver, string $prefix = 'mln_'): array
    {
        $tables = self::tables();
        if (!isset($tables[$table])) { return []; }
        $def = $tables[$table];
        $full = $prefix . $table;
        $stmts = [];
        if ($driver === 'mysql' && isset($def['mysql'])) {
            $stmts[] = str_replace('%t%', $full, $def['mysql']);
        } elseif (isset($def['sqlite'])) {
            $stmts[] = str_replace('%t%', $full, $def['sqlite']);
            foreach ($def['sqlite_indexes'] ?? [] as $idx) {
                $i = 0;
                $stmts[] = preg_replace_callback('/%i%/', function () use ($table, &$i) {
                    $i++; return 'idx_' . $table . '_' . $i;
                }, $idx);
                $stmts[count($stmts) - 1] = str_replace('%t%', $full, $stmts[count($stmts) - 1]);
            }
        }
        return $stmts;
    }
}
