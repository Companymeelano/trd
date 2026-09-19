<?php
declare(strict_types=1);

namespace App\Core;

use App\Contracts\AnalysisGateInterface;
use Throwable;

final class AnalysisEngine
{
    /** @param AnalysisGateInterface[] $gates */
    public function __construct(
        private readonly array $gates,
        private readonly ScoreCalculator $scoreCalculator,
    ) {}

    public function run(AnalysisContext $context): array
    {
        $results = [];
        foreach ($this->gates as $gate) {
            try {
                $result = $gate->analyze($context);
            } catch (Throwable $e) {
                $result = new AnalysisResult(
                    $gate->getName(),
                    Decision::REJECT,
                    0.0,
                    ['Gate execution failed: '.$e->getMessage()],
                    ['exception' => get_class($e)]
                );
            }
            $results[$result->gate] = $result;
            $context->addResult($result);

            if ($result->isRejected()) {
                return $this->buildResponse($context, $results, $gate->getName());
            }
        }

        return $this->buildResponse($context, $results, null);
    }

    private function buildResponse(AnalysisContext $context, array $results, ?string $rejectedBy): array
    {
        $score = $this->scoreCalculator->calculate($results);
        $risk = $results['risk_management'] ?? null;
        $hardPass = $rejectedBy === null && $risk instanceof AnalysisResult && $risk->isAccepted();
        $decision = $hardPass && $score >= 75 ? Decision::ACCEPT : ($rejectedBy ? Decision::REJECT : Decision::WATCH);

        return [
            'symbol' => $context->getSymbol(),
            'timeframe' => $context->getTimeframe(),
            'decision' => $decision,
            'final_score' => $score,
            'confidence' => $score,
            'rejected_by' => $rejectedBy,
            'results' => array_map(static fn(AnalysisResult $r) => $r->toArray(), $results),
            'trade_plan' => $context->getTradePlan(),
        ];
    }
}
