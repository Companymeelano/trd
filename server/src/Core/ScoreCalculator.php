<?php
declare(strict_types=1);

namespace App\Core;

final class ScoreCalculator
{
    /** @var array<string,float> */
    private array $weights = [
        'quantitative' => 0.30,
        'technical' => 0.35,
        'sentiment_macro' => 0.15,
        'risk_management' => 0.20,
    ];

    public function calculate(array $results): float
    {
        $sum = 0.0;
        foreach ($this->weights as $gate => $weight) {
            $result = $results[$gate] ?? null;
            if ($result instanceof AnalysisResult) {
                $sum += max(0.0, min(100.0, $result->score)) * $weight;
            }
        }
        return round($sum, 2);
    }

    public function getWeights(): array { return $this->weights; }
}
