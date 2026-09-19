<?php
declare(strict_types=1);
namespace App\Repositories;
use PDO;
final class SignalRepository
{
    public function __construct(private readonly PDO $pdo) {}
    public function save(array $output, array $settings=[]): void
    {
        try {
            $plan=$output['trade_plan']??[];
            $entry=$plan['entry_zone']??[];
            $tp=$plan['take_profits']??[];
            $reasons=[];
            foreach (($output['results']??[]) as $r) foreach (($r['reasons']??[]) as $reason) $reasons[]=(string)$reason;
            $stmt=$this->pdo->prepare("INSERT INTO signals(symbol,timeframe,capital,risk_pct,news_status,decision,composite_score,gate,reason,price,entry,stop_loss,tp1,tp2,position_usd,units,net_rr,indicators,scores,data_source,data_time) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $output['symbol']??'', $output['timeframe']??'', (float)($settings['capital']??0), (float)($settings['risk_pct']??0.01), (string)($settings['news_status']??'normal'),
                $output['decision']??'WATCH', (float)($output['final_score']??0), $output['rejected_by']??null, implode(' | ',array_unique($reasons)),
                $output['price']??null, $entry['min']??null, $plan['stop_loss']??null, $tp[0]['price']??null, $tp[1]['price']??null,
                null, $plan['risk']['suggested_position_size']??null, $plan['risk']['risk_reward_ratio']??null,
                json_encode($output['results']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), json_encode($output['experts']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                $settings['data_source']??'binance', $settings['data_time']??null
            ]);
        } catch (Throwable $e) { error_log('SignalRepository save failed: '.$e->getMessage()); }
    }
}
