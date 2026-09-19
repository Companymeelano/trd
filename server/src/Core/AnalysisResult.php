<?php
declare(strict_types=1);

namespace App\Core;

final class AnalysisResult
{
    public function __construct(
        public readonly string $gate,
        public readonly string $decision,
        public readonly float $score,
        public readonly array $reasons = [],
        public readonly array $metrics = [],
        public readonly ?array $tradePlan = null,
    ) {}

    public function isRejected(): bool { return $this->decision === Decision::REJECT; }
    public function isAccepted(): bool { return $this->decision === Decision::ACCEPT; }
    public function isWatch(): bool { return $this->decision === Decision::WATCH; }

    public function toArray(): array
    {
        return [
            'gate' => $this->gate,
            'decision' => $this->decision,
            'score' => round($this->score, 2),
            'reasons' => array_values($this->reasons),
            'metrics' => $this->metrics,
            'trade_plan' => $this->tradePlan,
        ];
    }
}
