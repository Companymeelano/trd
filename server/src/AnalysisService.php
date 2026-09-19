<?php
declare(strict_types=1);

use App\Core\{AnalysisContext,AnalysisEngine,ScoreCalculator};
use App\Experts\{QuantitativeFilter,TechnicalExpert,SentimentMacroExpert,RiskManagementGate};
use App\Indicators\IndicatorCalculator;
use App\Market\{MarketDataService,BinanceProvider,BitcoinMarketGuard};
use App\Repositories\{AnalysisLogRepository,SignalRepository,MarketSnapshotRepository};
use App\Services\WhaleActivityDetector;

final class AnalysisService
{
    private ?array $btcData = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly MarketDataService $market,
        private readonly AnalysisLogRepository $logs,
        private readonly SignalRepository $signals,
        private readonly MarketSnapshotRepository $snapshots,
        private readonly IndicatorCalculator $indicators,
        private readonly BitcoinMarketGuard $btcGuard,
    ) {}

    public function analyze(string $symbol,string $timeframe,array $settings=[]): array
    {
        $symbol=strtoupper($symbol);
        $market=$this->market->get($symbol,$timeframe,250);
        $btc=$this->btcRegime($symbol,$timeframe);
        $settings['btc_market']=$btc;
        $context=new AnalysisContext($symbol,$timeframe,$market,$settings);
        $engine=new AnalysisEngine([
            new QuantitativeFilter($this->indicators,new WhaleActivityDetector()),
            new TechnicalExpert($this->indicators),
            new SentimentMacroExpert($this->btcGuard),
            new RiskManagementGate($this->indicators),
        ],new ScoreCalculator());
        $output=$engine->run($context);
        $output=$this->normalizeApiOutput($output,$market);
        $this->snapshots->save($market);
        $this->logs->persist($context,$output);
        $this->signals->save($output,['capital'=>$settings['capital']??0,'risk_pct'=>$settings['risk_pct']??0.01,'news_status'=>$settings['news_status']??'normal','data_source'=>$market['provider']??'binance','data_time'=>$market['fetched_at']??null]);
        return $output;
    }

    public function recentHistory(int $limit=50): array { return $this->logs->history($limit); }

    public function scan(string $quote='USDT',array $symbols=[]): array
    {
        $symbols=$symbols?:['BTCUSDT','ETHUSDT','BNBUSDT','SOLUSDT','XRPUSDT','ADAUSDT','DOGEUSDT','AVAXUSDT','LINKUSDT','TONUSDT','TRXUSDT','DOTUSDT'];
        $out=[];
        foreach($symbols as $symbol) if(str_ends_with(strtoupper($symbol),strtoupper($quote))){
            try{$out[]=$this->analyze($symbol,'1h',['capital'=>null,'risk_pct'=>0.01,'news_status'=>'normal']);}
            catch(Throwable $e){$out[]=['symbol'=>$symbol,'decision'=>'REJECT','final_score'=>0,'confidence'=>0,'error'=>$e->getMessage()];}
        }
        usort($out,static fn($a,$b)=>($b['final_score']??0)<=>($a['final_score']??0));
        return $out;
    }


    public function backtest(string $symbol,string $timeframe,array $settings=[]): array
    {
        $symbol=strtoupper($symbol); $market=$this->market->get($symbol,$timeframe,800); $btc=$this->btcRegimeData($symbol,$timeframe,800); $candles=$market['candles']??[];
        if(count($candles)<120) throw new \RuntimeException('Not enough historical candles for backtest.');
        $equity=(float)($settings['capital']??1000); $initial=$equity; $peak=$equity; $maxDd=0.0; $position=null; $trades=[];
        $engine=new AnalysisEngine([new QuantitativeFilter($this->indicators,new WhaleActivityDetector()),new TechnicalExpert($this->indicators),new SentimentMacroExpert($this->btcGuard),new RiskManagementGate($this->indicators)],new ScoreCalculator());
        for($i=60;$i<count($candles)-1;$i++){
            if($position!==null){
                $hi=(float)$candles[$i]['high'];$lo=(float)$candles[$i]['low'];
                if($lo<=$position['sl']){$exit=$position['sl'];$pnl=($exit-$position['entry'])*$position['units'];$equity+=$pnl;$trades[]=['pnl'=>$pnl,'type'=>'SL'];$position=null;}
                elseif(!$position['tp1_hit'] && $hi>=$position['tp1']){$exit=$position['tp1'];$qty=$position['units']*0.5;$pnl=($exit-$position['entry'])*$qty;$equity+=$pnl;$trades[]=['pnl'=>$pnl,'type'=>'TP1'];$position['units']-=$qty;$position['tp1_hit']=true;$position['sl']=$position['entry'];}
                elseif($position['tp1_hit'] && $hi>=$position['tp2']){$exit=$position['tp2'];$pnl=($exit-$position['entry'])*$position['units'];$equity+=$pnl;$trades[]=['pnl'=>$pnl,'type'=>'TP2'];$position=null;}
            }
            if($position===null){
                $slice=array_slice($candles,0,$i+1);
                $btcForStep=$btc;
                if($symbol!=='BTCUSDT' && isset($btc['candles']) && is_array($btc['candles'])){$btcSlice=array_slice($btc['candles'],0,min($i+1,count($btc['candles'])));$btcForStep=$this->btcGuard->evaluate(['candles'=>$btcSlice]);}
                $ctx=new AnalysisContext($symbol,$timeframe,['candles'=>$slice,'order_book'=>[]],['capital'=>$equity,'risk_pct'=>(float)($settings['risk_pct']??0.01),'news_status'=>$settings['news_status']??'normal','btc_market'=>$btcForStep]);
                $r=$engine->run($ctx);
                if($r['decision']==='ACCEPT'&&is_array($r['trade_plan'])){$entry=(float)$candles[$i+1]['open'];$plan=$r['trade_plan'];$sl=(float)$plan['stop_loss'];$tp1=(float)$plan['take_profits'][0]['price'];$tp2=(float)$plan['take_profits'][1]['price'];$riskCash=$equity*(float)($settings['risk_pct']??0.01);$units=$riskCash/max(0.0000001,$entry-$sl);$position=['entry'=>$entry,'sl'=>$sl,'tp1'=>$tp1,'tp2'=>$tp2,'units'=>$units,'tp1_hit'=>false];}
            }
            $peak=max($peak,$equity);$maxDd=max($maxDd,$peak>0?(($peak-$equity)/$peak)*100:0);
        }
        if($position!==null){$last=end($candles)['close'];$equity+=($last-$position['entry'])*$position['units'];$trades[]=['pnl'=>($last-$position['entry'])*$position['units'],'type'=>'CLOSE'];}
        $wins=0;$gp=0;$gl=0;foreach($trades as $t){if($t['pnl']>0){$wins++;$gp+=$t['pnl'];}elseif($t['pnl']<0)$gl+=abs($t['pnl']);}$total=count($trades);return ['total_trades'=>$total,'win_rate'=>$total?round($wins/$total*100,2):0,'profit_factor'=>$gl>0?round($gp/$gl,2):($gp>0?999:0),'profit_pct'=>$initial>0?round(($equity-$initial)/$initial*100,2):0,'max_drawdown'=>round($maxDd,2),'final_equity'=>round($equity,2),'engine'=>'four-gate-pipeline'];
    }

    private function btcRegimeData(string $symbol,string $timeframe,int $limit): array
    {
        if($symbol==='BTCUSDT') return ['status'=>'SELF_REFERENCE','score'=>75,'reason'=>'BTC is the reference market.'];
        if($this->btcData===null) $this->btcData=$this->market->get('BTCUSDT',$timeframe,$limit);
        return $this->btcGuard->evaluate($this->btcData);
    }

    private function btcRegime(string $symbol,string $timeframe): array
    {
        if($symbol==='BTCUSDT') return ['status'=>'SELF_REFERENCE','score'=>75,'reason'=>'BTC is the reference market.'];
        if($this->btcData===null) $this->btcData=$this->market->get('BTCUSDT',$timeframe,250);
        return $this->btcGuard->evaluate($this->btcData);
    }

    private function normalizeApiOutput(array $output,array $market): array
    {
        $experts=[];$warnings=[];
        foreach(($output['results']??[]) as $key=>$r){
            $name=$key==='sentiment_macro'?'sentiment':$key;
            $experts[$name]=['score'=>$r['score'],'status'=>$r['decision']==='ACCEPT'?'PASS':($r['decision']==='WATCH'?'WATCH':'FAIL')];
            foreach(($r['reasons']??[]) as $reason) $warnings[]=$reason;
        }
        $plan=$output['trade_plan'];
        return [
            'status'=>'success',
            'symbol'=>$output['symbol'],
            'timeframe'=>$output['timeframe'],
            'decision'=>$output['decision'],
            'confidence'=>round(min(100, max(0, $output['final_score'])), 1),
            'trade_plan'=>$plan,
            'entry_zone'=>$plan['entry_zone']??null,
            'stop_loss'=>$plan['stop_loss']??null,
            'take_profits'=>$plan['take_profits']??[],
            'risk'=>$plan['risk']??['max_capital_risk_percent'=>1,'risk_reward_ratio'=>null,'suggested_position_size'=>null],
            'experts'=>$experts,
            'warnings'=>array_values(array_unique($warnings)),
            'rejected_by'=>$output['rejected_by']??null,
            'final_score'=>$output['final_score'],
            'price'=>(float)(end($market['candles'])['close'] ?? 0),
            'data_quality'=>['provider'=>$market['provider']??'unknown','stale'=>(bool)($market['stale']??false),'candles'=>count($market['candles']??[])],
            'results'=>$output['results']??[],
        ];
    }
}
