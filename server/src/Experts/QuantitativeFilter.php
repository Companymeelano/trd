<?php
declare(strict_types=1);

namespace App\Experts;

use App\Contracts\AnalysisGateInterface;
use App\Core\{AnalysisContext,AnalysisResult,Decision};
use App\Indicators\IndicatorCalculator;
use App\Services\WhaleActivityDetector;

final class QuantitativeFilter implements AnalysisGateInterface
{
    public function __construct(private readonly IndicatorCalculator $i, private readonly WhaleActivityDetector $whale) {}
    public function getName(): string { return 'quantitative'; }

    public function analyze(AnalysisContext $context): AnalysisResult
    {
        $m=$context->getMarketData(); $c=$m['candles']??[];
        if(count($c)<60) return new AnalysisResult($this->getName(),Decision::REJECT,0,['At least 60 candles are required.']);
        $cl=array_column($c,'close');$hi=array_column($c,'high');$lo=array_column($c,'low');$vol=array_column($c,'volume');
        $atr=$this->i->atr($hi,$lo,$cl,14);$price=(float)end($cl);$atrPct=$atr!==null&&$price>0?$atr/$price*100:null;
        $vr=$this->i->volumeRatio($vol,20);$mom=$this->i->momentumPct($cl,10);$div=$this->i->divergence($cl);
        $whale=$this->whale->detect($m['order_book']??[],$c);
        $score=0;$reasons=[];$hard=[];
        if(($vr??0)>=1.0){$score+=25;}else{$hard[]='Volume is below its 20-period average.';}
        if(($mom??0)>0){$score+=25;}elseif(($mom??0)>-1){$score+=12;}else{$hard[]='Short-term momentum is negative.';}
        if($atrPct!==null && $atrPct<=8){$score+=20;}else{$score+=5;$reasons[]='ATR is elevated; volatility is higher than preferred.';}
        if($div==='BULLISH'||$div==='NONE'){$score+=15;}else{$score+=3;$reasons[]='Bearish divergence detected.';}
        if(($whale['order_book_imbalance']??0)>-0.10){$score+=15;}else{$reasons[]='Order book shows notable ask pressure.';}
        $score=min(100,$score);
        if($hard) return new AnalysisResult($this->getName(),Decision::REJECT,$score,$hard+ $reasons,['price'=>$price,'volume_ratio'=>$vr,'momentum_pct'=>$mom,'atr_pct'=>$atrPct,'divergence'=>$div,'whale'=>$whale]);
        return new AnalysisResult($this->getName(),Decision::ACCEPT,$score,$reasons,['price'=>$price,'volume_ratio'=>$vr,'momentum_pct'=>$mom,'atr_pct'=>$atrPct,'divergence'=>$div,'whale'=>$whale]);
    }
}
