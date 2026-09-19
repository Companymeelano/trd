<?php
declare(strict_types=1);

namespace App\Core;

final class AnalysisContext
{
    private array $results = [];
    private ?array $tradePlan = null;

    public function __construct(
        private readonly string $symbol,
        private readonly string $timeframe,
        private readonly array $marketData,
        private readonly array $settings = [],
    ) {}

    public function getSymbol(): string { return $this->symbol; }
    public function getTimeframe(): string { return $this->timeframe; }
    public function getMarketData(): array { return $this->marketData; }
    public function getSettings(): array { return $this->settings; }

    public function addResult(AnalysisResult $result): void
    {
        $this->results[$result->gate] = $result;
        if ($result->tradePlan !== null) {
            $this->tradePlan = $result->tradePlan;
        }
    }

    public function getResults(): array { return $this->results; }
    public function setTradePlan(array $tradePlan): void { $this->tradePlan = $tradePlan; }
    public function getTradePlan(): ?array { return $this->tradePlan; }
}
