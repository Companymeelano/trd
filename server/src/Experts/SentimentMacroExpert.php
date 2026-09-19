<?php
declare(strict_types=1);

namespace App\Experts;

use App\Contracts\AnalysisGateInterface;
use App\Core\{AnalysisContext,AnalysisResult,Decision};
use App\Market\BitcoinMarketGuard;

final class SentimentMacroExpert implements AnalysisGateInterface
{
    public function __construct(private readonly BitcoinMarketGuard $guard) {}
    public function getName(): string { return 'sentiment_macro'; }

    public function analyze(AnalysisContext $context): AnalysisResult
    {
        $settings=$context->getSettings(); $btc=$settings['btc_market']??null;
        $status=$btc['status']??'UNKNOWN';$news=(string)($settings['news_status']??'normal');
        if($status==='CRITICAL') return new AnalysisResult($this->getName(),Decision::REJECT,25,['BTC market regime is critical; downstream AI/news resources are intentionally skipped.'],['btc'=>$btc,'news_status'=>$news]);
        $score=75;$reasons=[];
        if($news==='cpi'){$score=60;$reasons[]='High-impact macro event flag is active; exposure should be conservative.';}
        if($status==='UNKNOWN'){$score=50;$reasons[]='BTC regime could not be fully verified.';}
        return new AnalysisResult($this->getName(),$score>=50?Decision::ACCEPT:Decision::WATCH,$score,$reasons,['btc'=>$btc,'news_status'=>$news]);
    }
}
