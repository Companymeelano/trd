<?php
declare(strict_types=1);

namespace App\Market;

use App\Contracts\MarketDataProviderInterface;

final class MarketDataService
{
    public function __construct(
        private readonly MarketDataProviderInterface $provider,
        private readonly ?\Cache $cache = null,
        private readonly ?\CircuitBreaker $breaker = null,
        private readonly int $ttl = 15,
    ) {}

    public function get(string $symbol,string $timeframe,int $limit=250): array
    {
        $key='market:'.strtoupper($symbol).':'.$timeframe.':'.$limit;
        if($this->cache){
            $cached=$this->cache->get($key,$this->ttl*4);
            // Cache::get یک پوشه ['data'=>value,'layer'=>..,'stale'=>bool] برمی‌گرداند؛
            // پیش‌تر اشتباهاً isset($cached['candles']) بررسی می‌شد و کش هرگز hit نمی‌شد.
            if(is_array($cached) && isset($cached['data']['candles'])){
                $market=$cached['data'];
                $market['stale']=!empty($cached['stale']);
                return $market;
            }
        }
        if($this->breaker && !$this->breaker->canProceed('binance')) {
            throw new \RuntimeException('Binance circuit breaker is OPEN.');
        }
        try {
            $data=$this->provider->getMarketData($symbol,$timeframe,$limit);
            $this->breaker?->recordSuccess('binance');
            $this->cache?->set($key,$data,$this->ttl);
            return $data;
        } catch(\Throwable $e) {
            $this->breaker?->recordFailure('binance');
            if($this->cache){
                $stale=$this->cache->get($key,$this->ttl*20);
                if(is_array($stale) && isset($stale['data']['candles'])) return $stale['data']+['stale'=>true];
            }
            throw $e;
        }
    }
}
