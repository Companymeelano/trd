<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class MarketSnapshotRepository
{
    public function __construct(private readonly PDO $pdo) {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS market_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT, symbol TEXT NOT NULL, timeframe TEXT NOT NULL,
            provider TEXT NOT NULL, payload TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    }
    public function save(array $data): void {
        $s=$this->pdo->prepare("INSERT INTO market_snapshots(symbol,timeframe,provider,payload) VALUES(?,?,?,?)");
        $s->execute([$data['symbol']??'',$data['timeframe']??'',$data['provider']??'unknown',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
}
