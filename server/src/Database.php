<?php
declare(strict_types=1);

final class Database
{
    public static function connect(?Logger $logger = null): ?PDO
    {
        $path = Config::sqlitePath();
        $dir = dirname($path);
        try {
            if (!extension_loaded('pdo_sqlite')) {
                throw new RuntimeException('PDO SQLite extension is not enabled.');
            }
            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                throw new RuntimeException('SQLite directory cannot be created.');
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            self::migrate($pdo);
            return $pdo;
        } catch (Throwable $e) {
            $logger?->critical('Database initialization failed.', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, k TEXT NOT NULL UNIQUE, v TEXT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS analysis_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, symbol TEXT NOT NULL, timeframe TEXT NOT NULL,
            decision TEXT NOT NULL, final_score REAL NOT NULL, rejected_by TEXT NULL,
            payload TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_analysis_runs_symbol_tf ON analysis_runs(symbol,timeframe)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_analysis_runs_created ON analysis_runs(created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS analysis_gate_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER NOT NULL, gate TEXT NOT NULL,
            decision TEXT NOT NULL, score REAL NOT NULL, reasons TEXT NOT NULL, metrics TEXT NOT NULL,
            trade_plan TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(run_id) REFERENCES analysis_runs(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gate_run ON analysis_gate_results(run_id)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS market_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT, symbol TEXT NOT NULL, timeframe TEXT NOT NULL,
            provider TEXT NOT NULL, payload TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_snapshots_symbol_tf ON market_snapshots(symbol,timeframe)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS signals (
            id INTEGER PRIMARY KEY AUTOINCREMENT, symbol TEXT NOT NULL, timeframe TEXT NOT NULL,
            capital REAL NOT NULL DEFAULT 0, risk_pct REAL NOT NULL DEFAULT 0.01,
            news_status TEXT NOT NULL DEFAULT 'normal', decision TEXT NOT NULL,
            composite_score REAL NOT NULL, gate TEXT NULL, reason TEXT NULL,
            price REAL NULL, entry REAL NULL, stop_loss REAL NULL, tp1 REAL NULL, tp2 REAL NULL,
            position_usd REAL NULL, units REAL NULL, net_rr REAL NULL, indicators TEXT NULL, scores TEXT NULL,
            data_source TEXT NULL, data_time TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_signals_symbol_tf ON signals(symbol,timeframe)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS backtests (
            id INTEGER PRIMARY KEY AUTOINCREMENT, symbol TEXT NOT NULL, timeframe TEXT NOT NULL,
            win_rate REAL NOT NULL, profit_factor REAL NOT NULL, profit_pct REAL NOT NULL,
            max_drawdown REAL NOT NULL, total_trades INTEGER NOT NULL, payload TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
    }
}
