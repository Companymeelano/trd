<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Core\AnalysisContext;
use App\Core\AnalysisResult;

interface AiExpertInterface
{
    public function getName(): string;
    public function analyze(AnalysisContext $context): AnalysisResult;
}
