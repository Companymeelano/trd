<?php
declare(strict_types=1);

namespace App\Contracts;

interface MarketDataProviderInterface
{
    public function getMarketData(string $symbol, string $timeframe, int $limit = 250): array;
}
