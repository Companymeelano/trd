<?php
declare(strict_types=1);

namespace App\Market;

use App\Contracts\MarketDataProviderInterface;
use RuntimeException;

final class BinanceProvider implements MarketDataProviderInterface
{
    public function __construct(
        private readonly string $baseUrl = 'https://api.binance.com',
        private readonly int $timeout = 8,
        private readonly int $retries = 2,
    ) {}

    public function getMarketData(string $symbol, string $timeframe, int $limit = 250): array
    {
        $symbol=strtoupper(trim($symbol)); $limit=max(60,min(1000,$limit));
        if (!preg_match('/^[A-Z0-9]{2,24}$/', $symbol)) throw new RuntimeException('Invalid market symbol.');
        $klines=$this->get('/api/v3/klines',['symbol'=>$symbol,'interval'=>$timeframe,'limit'=>$limit]);
        $candles=[];
        foreach($klines as $k){
            $candles[]=[
                'open_time'=>(int)$k[0], 'open'=>(float)$k[1], 'high'=>(float)$k[2],
                'low'=>(float)$k[3], 'close'=>(float)$k[4], 'volume'=>(float)$k[5],
                'close_time'=>(int)$k[6],
            ];
        }
        $book=$this->get('/api/v3/depth',['symbol'=>$symbol,'limit'=>100]);
        $bids=array_map(static fn($x)=>[(float)$x[0],(float)$x[1]],$book['bids']??[]);
        $asks=array_map(static fn($x)=>[(float)$x[0],(float)$x[1]],$book['asks']??[]);
        return ['provider'=>'binance','symbol'=>$symbol,'timeframe'=>$timeframe,'candles'=>$candles,'order_book'=>['bids'=>$bids,'asks'=>$asks],'fetched_at'=>gmdate('c')];
    }

    private function get(string $path,array $query): array
    {
        $url=$this->baseUrl.$path.'?'.http_build_query($query);
        $last='upstream request failed';
        for($attempt=0;$attempt<=$this->retries;$attempt++){
            $ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>$this->timeout,CURLOPT_TIMEOUT=>$this->timeout,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_USERAGENT=>'Milano-AnalysisEngine/3.0']);
            $body=curl_exec($ch); $errno=curl_errno($ch); $error=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
            if($errno===0 && $code>=200 && $code<300 && is_string($body)){
                $data=json_decode($body,true);
                if(is_array($data)) {
                    if (str_ends_with($path, '/klines') && (!array_is_list($data) || count($data) < 60)) throw new RuntimeException('Upstream returned insufficient candle data.');
                    return $data;
                }
                $last='invalid upstream JSON';
            } else $last='HTTP '.$code.' / cURL '.$errno.' '.$error;
            if($attempt<$this->retries) usleep((int)(250000*($attempt+1)));
        }
        throw new RuntimeException($last);
    }
}
