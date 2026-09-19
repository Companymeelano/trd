<?php
declare(strict_types=1);

namespace App\Services;

final class WhaleActivityDetector
{
    public function detect(array $orderBook, array $candles): array
    {
        $bids=$orderBook['bids']??[]; $asks=$orderBook['asks']??[];
        $bidQty=0.0;$askQty=0.0;
        foreach(array_slice($bids,0,20) as $x) $bidQty+=(float)($x[1]??0);
        foreach(array_slice($asks,0,20) as $x) $askQty+=(float)($x[1]??0);
        $total=$bidQty+$askQty; $imbalance=$total>0?($bidQty-$askQty)/$total:0;
        $volume=end($candles)['volume']??0;
        $prev=array_slice(array_column($candles,'volume'),-21,20);
        $avg=$prev?array_sum(array_map('floatval',$prev))/count($prev):0;
        $spike=$avg>0 ? (float)$volume/$avg : 1;
        return ['order_book_imbalance'=>$imbalance,'volume_spike'=>$spike,'bias'=>$imbalance>0.15?'BID_SUPPORT':($imbalance<-0.15?'ASK_PRESSURE':'BALANCED')];
    }
}
