<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ScoreCalculator;

final class ConsensusService
{
    public function __construct(private readonly ScoreCalculator $calculator) {}
    public function score(array $results): float { return $this->calculator->calculate($results); }
}
