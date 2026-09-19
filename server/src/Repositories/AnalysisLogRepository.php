<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use App\Core\{AnalysisContext,AnalysisResult};

final class AnalysisLogRepository
{
    public function __construct(private readonly PDO $pdo) { $this->migrate(); }

    private function migrate(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS analysis_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, symbol TEXT NOT NULL, timeframe TEXT NOT NULL,
            decision TEXT NOT NULL, final_score REAL NOT NULL, rejected_by TEXT NULL,
            payload TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS analysis_gate_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER NOT NULL, gate TEXT NOT NULL,
            decision TEXT NOT NULL, score REAL NOT NULL, reasons TEXT NOT NULL, metrics TEXT NOT NULL,
            trade_plan TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    }

    public function persist(AnalysisContext $context, array $output): int
    {
        $stmt=$this->pdo->prepare("INSERT INTO analysis_runs(symbol,timeframe,decision,final_score,rejected_by,payload) VALUES(?,?,?,?,?,?)");
        $stmt->execute([$context->getSymbol(),$context->getTimeframe(),$output['decision'],$output['final_score'],$output['rejected_by']??null,json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $id=(int)$this->pdo->lastInsertId();
        $g=$this->pdo->prepare("INSERT INTO analysis_gate_results(run_id,gate,decision,score,reasons,metrics,trade_plan) VALUES(?,?,?,?,?,?,?)");
        foreach($context->getResults() as $r){
            $g->execute([$id,$r->gate,$r->decision,$r->score,json_encode($r->reasons,JSON_UNESCAPED_UNICODE),json_encode($r->metrics,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$r->tradePlan?json_encode($r->tradePlan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);
        }
        return $id;
    }

    public function history(int $limit=50): array
    {
        $stmt=$this->pdo->prepare("SELECT id,symbol,timeframe,decision,final_score,rejected_by,created_at FROM analysis_runs ORDER BY id DESC LIMIT :limit");
        $stmt->bindValue(':limit',max(1,min(200,$limit)),PDO::PARAM_INT);$stmt->execute();return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
