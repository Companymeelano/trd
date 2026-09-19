<?php
declare(strict_types=1);

if (!defined('TRD_APP')) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

function evaluate_signal(array $klines, string $news_status = 'normal'): array
{
    $closes  = array_column($klines, 'close');
    $highs   = array_column($klines, 'high');
    $lows    = array_column($klines, 'low');
    $volumes = array_column($klines, 'volume');

    if (count($closes) < 60) {
        return [
            'decision' => 'VOID',
            'composite' => 0.0,
            'gate' => 'INSUFFICIENT_DATA',
            'reason' => 'Not enough data',
            'trend' => 'neutral',
            'indicators' => [],
            'scores' => [],
        ];
    }

    $lastClose = end($closes);
    $ema9  = end(ema_series($closes, 9));
    $ema21 = end(ema_series($closes, 21));
    $ema50 = end(ema_series($closes, 50));

    $rsiVal = rsi($closes, 14);
    $atrVal = atr($highs, $lows, $closes, 14);
    $atrPct = ($atrVal !== null && $lastClose > 0) ? ($atrVal / $lastClose) * 100 : null;
    $macdData = macd($closes, 12, 26, 9);
    $macdHist = $macdData['hist'];
    $stochData = stoch($highs, $lows, $closes, 14);
    $volRatio = volume_ratio($volumes, 20);

    // Trend
    $trend = 'neutral';
    if ($ema9 !== null && $ema21 !== null && $ema50 !== null) {
        if ($ema9 > $ema21 && $ema21 > $ema50 && $lastClose > $ema50) {
            $trend = 'bullish';
        } elseif ($ema9 < $ema21 && $ema21 < $ema50 && $lastClose < $ema50) {
            $trend = 'bearish';
        }
    }

    $scores = [
        'g1'    => $trend === 'bullish' ? 20.0 : ($trend === 'bearish' ? 0.0 : 10.0),
        'g2'    => 0.0,
        'g3'    => 0.0,
        'g4'    => 0.0,
        'b3'    => 0.0,
        'b4'    => 0.0,
        'b5'    => 0.0,
        'trend' => $trend === 'bullish' ? 1.0 : ($trend === 'bearish' ? -1.0 : 0.0),
    ];

    // Momentum
    if ($rsiVal !== null && $rsiVal >= 52 && $rsiVal <= 68 && $macdHist > 0) {
        $scores['g2'] = 25.0;
    } elseif ($rsiVal !== null && $rsiVal > 50 && $macdHist > 0) {
        $scores['g2'] = 15.0;
    } elseif ($rsiVal !== null && $rsiVal > 45 && $macdHist > 0) {
        $scores['g2'] = 8.0;
    }

    // Volume
    if ($volRatio !== null && $volRatio >= 1.2) {
        $scores['g3'] = 20.0;
    } elseif ($volRatio !== null && $volRatio >= 1.0) {
        $scores['g3'] = 10.0;
    } elseif ($volRatio !== null && $volRatio >= 0.8) {
        $scores['g3'] = 5.0;
    }

    // Stochastic
    $k = $stochData['k'] ?? null;
    $d = $stochData['d'] ?? null;
    if ($k !== null && $d !== null) {
        if ($k >= 50 && $k <= 80 && $k > $d) {
            $scores['g4'] = 15.0;
        } elseif ($k >= 50) {
            $scores['g4'] = 10.0;
        } elseif ($k > $d) {
            $scores['g4'] = 5.0;
        }
    }

    // Bonus ATR
    if ($atrPct !== null && $atrPct >= 1.0 && $atrPct <= 5.0) {
        $scores['b3'] = 5.0;
    }

    // Bonus distance from EMA50
    if ($lastClose > 0 && $ema50 !== null) {
        $ratio = $lastClose / $ema50;
        if ($ratio >= 1.0 && $ratio <= 1.08) {
            $scores['b4'] = 5.0;
        }
    }

    // Bonus news
    $scores['b5'] = ($news_status === 'normal') ? 5.0 : 0.0;

    $composite = $scores['g1'] + $scores['g2'] + $scores['g3'] + $scores['g4'] + $scores['b3'] + $scores['b4'] + $scores['b5'];

    $gate = 'RISK_OK';
    $decision = 'VOID';
    $reason = 'Conditions not met';

    if ($news_status === 'cpi') {
        $gate = 'NEWS_LOCK';
        $decision = 'VOID';
        $reason = 'CPI news lock: no trading during high-impact news';
    } elseif ($trend !== 'bullish') {
        $gate = 'TREND_GATE';
        $decision = 'VOID';
        $reason = 'Market trend is not bullish';
    } elseif ($composite < 65) {
        $gate = 'SCORE_LOW';
        $decision = 'VOID';
        $reason = 'Composite score below threshold';
    } elseif ($atrPct === null || $atrPct < 0.2) {
        $gate = 'VOLATILITY_GATE';
        $decision = 'VOID';
        $reason = 'ATR too low for a valid setup';
    } else {
        $decision = 'EXECUTE';
        $reason = 'Bullish trend with positive momentum and risk gate OK';
    }

    return [
        'decision' => $decision,
        'composite' => round($composite, 2),
        'gate' => $gate,
        'reason' => $reason,
        'trend' => $trend,
        'indicators' => [
            'rsi'          => $rsiVal !== null ? round($rsiVal, 2) : null,
            'atr_pct'      => $atrPct !== null ? round($atrPct, 2) : null,
            'macd_hist'    => $macdHist !== null ? round($macdHist, 6) : null,
            'volume_ratio' => $volRatio !== null ? round($volRatio, 2) : null,
            'stoch_k'      => $k !== null ? round($k, 2) : null,
            'stoch_d'      => $d !== null ? round($d, 2) : null,
            'ema9'         => $ema9 !== null ? round($ema9, 4) : null,
            'ema21'        => $ema21 !== null ? round($ema21, 4) : null,
            'ema50'        => $ema50 !== null ? round($ema50, 4) : null,
        ],
        'scores' => $scores,
    ];
}

function compute_trade_plan(array $signal, array $klines, float $capital, float $riskPctDecimal): array
{
    $lastClose = end(array_column($klines, 'close'));
    $atrVal = atr(array_column($klines, 'high'), array_column($klines, 'low'), array_column($klines, 'close'), 14);

    if ($atrVal === null || $atrVal <= 0) {
        return [
            'price' => $lastClose,
            'entry' => $lastClose,
            'stop_loss' => $lastClose,
            'tp1' => $lastClose,
            'tp2' => $lastClose,
            'position_usd' => 0.0,
            'units' => 0.0,
            'net_rr' => 0.0,
        ];
    }

    $entry = $lastClose;
    $stopLoss = $entry - 1.5 * $atrVal;
    $tp1 = $entry + 2 * $atrVal;
    $tp2 = $entry + 4 * $atrVal;
    $distance = $entry - $stopLoss;
    $riskAmount = $capital * $riskPctDecimal;
    $units = ($distance > 0) ? ($riskAmount / $distance) : 0.0;
    $positionUsd = $units * $entry;
    $netRr = ($distance > 0) ? ((($tp1 - $entry) + ($tp2 - $entry)) / 2) / $distance : 0.0;

    return [
        'price' => $lastClose,
        'entry' => $entry,
        'stop_loss' => $stopLoss,
        'tp1' => $tp1,
        'tp2' => $tp2,
        'position_usd' => $positionUsd,
        'units' => $units,
        'net_rr' => $netRr,
    ];
}
