<?php
declare(strict_types=1);
namespace App\Indicators;
final class IndicatorCalculator
{
    public function ema(array $values,int $period):?float { $s=$this->emaSeriesRaw($values,$period); $v=end($s); return $v===false?null:$v; }
    public function rsi(array $closes,int $period=14):?float {
        $n=count($closes); if($period<1||$n<$period+1)return null; $v=array_map('floatval',$closes); $gain=0;$loss=0;
        for($i=1;$i<=$period;$i++){ $d=$v[$i]-$v[$i-1]; $gain+=max(0,$d); $loss+=max(0,-$d); }
        $avgGain=$gain/$period; $avgLoss=$loss/$period;
        for($i=$period+1;$i<$n;$i++){ $d=$v[$i]-$v[$i-1]; $g=max(0,$d);$l=max(0,-$d);$avgGain=(($avgGain*($period-1))+$g)/$period;$avgLoss=(($avgLoss*($period-1))+$l)/$period; }
        if($avgLoss==0.0)return 100.0; return round(100-(100/(1+($avgGain/$avgLoss))),4);
    }
    public function atr(array $highs,array $lows,array $closes,int $period=14):?float {
        $n=count($closes); if($period<1||$n<$period+1)return null; $tr=[]; for($i=1;$i<$n;$i++){$h=(float)$highs[$i];$l=(float)$lows[$i];$pc=(float)$closes[$i-1];$tr[]=max($h-$l,abs($h-$pc),abs($l-$pc));}
        if(count($tr)<$period)return null; $atr=array_sum(array_slice($tr,0,$period))/$period; for($i=$period;$i<count($tr);$i++)$atr=(($atr*($period-1))+$tr[$i])/$period; return $atr;
    }
    public function macd(array $closes):array {
        $fast=$this->emaSeriesRaw($closes,12);$slow=$this->emaSeriesRaw($closes,26);$line=[];foreach($closes as $i=>$_){$line[]=$fast[$i]!==null&&$slow[$i]!==null?$fast[$i]-$slow[$i]:null;}
        $valid=array_values(array_filter($line,static fn($x)=>$x!==null));$sig=$this->ema($valid,9);$mac=end($line);return ['macd'=>$mac,'signal'=>$sig,'histogram'=>($mac!==null&&$sig!==null)?$mac-$sig:null];
    }
    public function volumeRatio(array $volumes,int $period=20):?float { if(count($volumes)<$period+1)return null;$v=array_map('floatval',$volumes);$last=(float)end($v);$base=array_slice($v,-$period-1,$period);$avg=array_sum($base)/$period;return $avg>0?$last/$avg:null; }
    public function momentumPct(array $closes,int $lookback=10):?float { if(count($closes)<$lookback+1)return null;$v=array_map('floatval',$closes);$a=$v[count($v)-$lookback-1];$b=end($v);return $a!=0?(($b/$a)-1)*100:null; }
    public function divergence(array $closes,array $rsiSeries=[]):string {
        if(count($closes)<40)return 'NONE'; $v=array_map('floatval',$closes);$a=array_slice($v,-20,10);$b=array_slice($v,-10);$oldR=$this->rsi($a,7);$newR=$this->rsi($b,7);if($oldR===null||$newR===null)return 'NONE';
        if(max($b)>max($a)&&$newR<$oldR-2)return 'BEARISH'; if(min($b)<min($a)&&$newR>$oldR+2)return 'BULLISH'; return 'NONE';
    }
    public function ichimoku(array $highs,array $lows,array $closes):array {
        $n=count($closes); if($n<78)return ['tenkan'=>null,'kijun'=>null,'span_a'=>null,'span_b'=>null,'above_cloud'=>null];
        $mid=function(array $h,array $l,int $end,int $p):float{$hh=max(array_map('floatval',array_slice($h,$end-$p+1,$p)));$ll=min(array_map('floatval',array_slice($l,$end-$p+1,$p)));return($hh+$ll)/2;};
        $i=$n-27;$ten=$mid($highs,$lows,$i,9);$kij=$mid($highs,$lows,$i,26);$spanA=($ten+$kij)/2;$spanB=$mid($highs,$lows,$i,52);$price=(float)end($closes);return ['tenkan'=>$this->midCurrent($highs,$lows,9),'kijun'=>$this->midCurrent($highs,$lows,26),'span_a'=>$spanA,'span_b'=>$spanB,'above_cloud'=>$price>max($spanA,$spanB)];
    }
    private function midCurrent(array $h,array $l,int $p):float{$hh=max(array_map('floatval',array_slice($h,-$p)));$ll=min(array_map('floatval',array_slice($l,-$p)));return($hh+$ll)/2;}
    private function emaSeriesRaw(array $values,int $period):array{$v=array_values(array_map('floatval',$values));$out=array_fill(0,count($v),null);if($period<1||count($v)<$period)return$out;$ema=array_sum(array_slice($v,0,$period))/$period;$out[$period-1]=$ema;$k=2/($period+1);for($i=$period;$i<count($v);$i++){$ema=($v[$i]-$ema)*$k+$ema;$out[$i]=$ema;}return$out;}
}
