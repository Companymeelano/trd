<?php
declare(strict_types=1);

namespace App\Services;

final class RiskCalculator
{
    public function positionSize(float $capital, float $riskPct, float $entry, float $stop): ?float
    {
        if($capital<=0||$entry<=0||$stop<=0||$entry===$stop) return null;
        $riskCash=$capital*min(0.01,max(0.0,$riskPct));
        return $riskCash/abs($entry-$stop);
    }
}
