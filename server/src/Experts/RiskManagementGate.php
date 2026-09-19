<?php
declare(strict_types=1);
namespace App\Experts;
use App\Contracts\AnalysisGateInterface;
use App\Core\{AnalysisContext,AnalysisResult,Decision};
use App\Indicators\IndicatorCalculator;
final class RiskManagementGate implements AnalysisGateInterface
{
    public function __construct(private readonly IndicatorCalculator $i) {}
    public function getName(): string { return 'risk_management'; }
    public function analyze(AnalysisContext $context): AnalysisResult
    {
        $m=$context->getMarketData();$c=$m['candles']??[];$cl=array_column($c,'close');$hi=array_column($c,'high');$lo=array_column($c,'low');
        if(count($cl)<60)return new AnalysisResult($this->getName(),Decision::REJECT,0,['Insufficient market data for risk calculation.']);
        $price=(float)end($cl);$atr=$this->i->atr($hi,$lo,$cl,14);
        if($price<=0||$atr===null||$atr<=0)return new AnalysisResult($this->getName(),Decision::REJECT,0,['Cannot calculate a safe trade plan without valid price and ATR.']);
        $atrPct=($atr/$price)*100;$maxAtrPct=\Config::maxAtrPct();$minRr=\Config::minNetRr();
        $entryMin=$price*0.995;$entryMax=$price*1.005;$entry=($entryMin+$entryMax)/2;
        $stop=max(0.0,$entry-$atr*1.25);$riskPerUnit=$entry-$stop;
        if($stop<=0||$riskPerUnit<=0)return new AnalysisResult($this->getName(),Decision::REJECT,0,['Calculated stop loss is invalid.']);
        $tp1=$entry+$riskPerUnit*2.5;$tp2=$entry+$riskPerUnit*4.0;$rr1=($tp1-$entry)/$riskPerUnit;$rr2=($tp2-$entry)/$riskPerUnit;
        $settings=$context->getSettings();$capital=is_numeric($settings['capital']??null)?(float)$settings['capital']:null;$riskPct=(float)($settings['risk_pct']??0.01);$riskPct=min(0.01,max(0.0,$riskPct));
        $riskCash=$capital!==null&&$capital>0?$capital*$riskPct:null;$units=$riskCash!==null?$riskCash/$riskPerUnit:null;$positionUsd=$units!==null?$units*$entry:null;
        $score=100.0;$reasons=[];$decision=Decision::ACCEPT;
        if($atrPct>$maxAtrPct){$score-=35;$reasons[]='ATR volatility exceeds the configured risk ceiling.';$decision=Decision::REJECT;}
        elseif($atrPct>$maxAtrPct*0.75){$score-=15;$reasons[]='ATR volatility is elevated; position sizing should remain conservative.';}
        if($rr1<$minRr){$score-=40;$reasons[]='Net risk/reward is below the configured minimum.';$decision=Decision::REJECT;}
        if($riskPct>0.01){$score=0;$reasons[]='Requested risk exceeds the hard maximum of 1% of capital.';$decision=Decision::REJECT;}
        $plan=['entry_zone'=>['min'=>round($entryMin,8),'max'=>round($entryMax,8)],'stop_loss'=>round($stop,8),'take_profits'=>[['price'=>round($tp1,8),'rr'=>round($rr1,2)],['price'=>round($tp2,8),'rr'=>round($rr2,2)]],'risk'=>['max_capital_risk_percent'=>round($riskPct*100,2),'risk_reward_ratio'=>round($rr1,2),'suggested_position_size'=>$units!==null?round($units,8):null,'position_size_usd'=>$positionUsd!==null?round($positionUsd,2):null,'risk_amount'=>$riskCash!==null?round($riskCash,2):null]];
        $context->setTradePlan($plan);
        return new AnalysisResult($this->getName(),$decision,round(max(0,$score),2),$reasons,['atr'=>$atr,'atr_pct'=>round($atrPct,3),'max_atr_pct'=>$maxAtrPct,'risk_per_unit'=>$riskPerUnit,'risk_pct'=>$riskPct,'risk_amount'=>$riskCash,'risk_reward_ratio'=>$rr1,'suggested_position_size'=>$units,'position_size_usd'=>$positionUsd,'max_capital_risk_percent'=>$riskPct*100],$plan);
    }
}
