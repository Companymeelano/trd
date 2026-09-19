<?php
declare(strict_types=1);

namespace App\Market;

use App\Indicators\IndicatorCalculator;

final class BitcoinMarketGuard
{
    public function __construct(private readonly IndicatorCalculator $indicators) {}

    public function evaluate(array $btcData): array
    {
        $c=$btcData['candles']??[]; if(count($c)<60) return ['status'=>'UNKNOWN','score'=>50,'reason'=>'BTC data is insufficient.'];
        $closes=array_column($c,'close'); $rsi=$this->indicators->rsi($closes); $ema50=$this->indicators->ema($closes,50); $price=(float)end($closes);
        $momentum=$this->indicators->momentumPct($closes,10);
        $danger=($ema50!==null && $price<$ema50 && ($momentum??0)<-3 && ($rsi??50)<45);
        $status=$danger?'CRITICAL':'NORMAL';
        return ['status'=>$status,'score'=>$danger?25:75,'reason'=>$danger?'BTC trend/momentum conditions are defensive.':'BTC market regime is not classified as critical.','rsi'=>$rsi,'momentum_pct'=>$momentum];
    }
}
