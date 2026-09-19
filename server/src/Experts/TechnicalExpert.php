<?php
declare(strict_types=1);

namespace App\Experts;

use App\Contracts\AnalysisGateInterface;
use App\Core\{AnalysisContext,AnalysisResult,Decision};
use App\Indicators\IndicatorCalculator;

final class TechnicalExpert implements AnalysisGateInterface
{
    public function __construct(private readonly IndicatorCalculator $i) {}
    public function getName(): string { return 'technical'; }

    public function analyze(AnalysisContext $context): AnalysisResult
    {
        $c=$context->getMarketData()['candles']??[]; $cl=array_column($c,'close');$hi=array_column($c,'high');$lo=array_column($c,'low');
        if(count($cl)<60) return new AnalysisResult($this->getName(),Decision::REJECT,0,['Insufficient candles for technical analysis.']);
        $price=(float)end($cl);$e9=$this->i->ema($cl,9);$e21=$this->i->ema($cl,21);$e50=$this->i->ema($cl,50);$rsi=$this->i->rsi($cl);$macd=$this->i->macd($cl);$ich=$this->i->ichimoku($hi,$lo,$cl);
        $score=0;$reasons=[];$hard=[];
        if($e9!==null&&$e21!==null&&$e9>$e21){$score+=20;}else{$reasons[]='EMA 9 is not above EMA 21.';}
        if($e50!==null&&$price>$e50){$score+=20;}else{$hard[]='Price is below EMA 50.';}
        if(($rsi??50)>=50&&($rsi??50)<=72){$score+=15;}elseif(($rsi??50)>72){$score+=5;$reasons[]='RSI is overextended.';}else{$score+=4;$reasons[]='RSI lacks bullish strength.';}
        if(($macd['histogram']??-1)>0){$score+=20;}else{$reasons[]='MACD histogram is negative.';}
        if(($ich['above_cloud']??false)){$score+=15;}else{$reasons[]='Price is not above Ichimoku cloud.';}
        $recent=array_slice($c,-3);$bull=true;
        foreach($recent as $x) if((float)$x['close']<=(float)$x['open']) $bull=false;
        $score+= $bull?10:4;
        if($hard) return new AnalysisResult($this->getName(),Decision::REJECT,$score,$hard+$reasons,['ema9'=>$e9,'ema21'=>$e21,'ema50'=>$e50,'rsi'=>$rsi,'macd'=>$macd,'ichimoku'=>$ich]);
        return new AnalysisResult($this->getName(),Decision::ACCEPT,$score,$reasons,['ema9'=>$e9,'ema21'=>$e21,'ema50'=>$e50,'rsi'=>$rsi,'macd'=>$macd,'ichimoku'=>$ich]);
    }
}
