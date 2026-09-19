<?php
declare(strict_types=1);

if (!defined('TRD_APP')) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

function ema_series(array $values, int $period): array
{
    $values = array_values($values);
    $count = count($values);
    if ($period <= 0 || $count < $period) {
        return array_fill(0, $count, null);
    }
    $multiplier = 2 / ($period + 1);
    $result = array_fill(0, $count, null);
    $sum = array_sum(array_slice($values, 0, $period));
    $prev = $sum / $period;
    $result[$period - 1] = $prev;
    for ($i = $period; $i < $count; $i++) {
        $prev = ($values[$i] - $prev) * $multiplier + $prev;
        $result[$i] = $prev;
    }
    return $result;
}

function rsi(array $closes, int $period = 14): ?float
{
    $closes = array_values($closes);
    $count = count($closes);
    if ($count < $period + 1) {
        return null;
    }
    $gains = 0.0;
    $losses = 0.0;
    for ($i = $count - $period; $i < $count; $i++) {
        $diff = $closes[$i] - $closes[$i - 1];
        if ($diff >= 0) {
            $gains += $diff;
        } else {
            $losses -= $diff;
        }
    }
    $avgGain = $gains / $period;
    $avgLoss = $losses / $period;
    if ($avgLoss == 0.0) {
        return 100.0;
    }
    $rs = $avgGain / $avgLoss;
    return 100.0 - (100.0 / (1.0 + $rs));
}

function atr(array $highs, array $lows, array $closes, int $period = 14): ?float
{
    $count = count($closes);
    if ($count < $period + 1) {
        return null;
    }
    $tr = [];
    for ($i = 1; $i < $count; $i++) {
        $h = $highs[$i];
        $l = $lows[$i];
        $pc = $closes[$i - 1];
        $tr[] = max($h - $l, abs($h - $pc), abs($l - $pc));
    }
    $slice = array_slice($tr, -$period);
    return array_sum($slice) / $period;
}

function macd(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
{
    $emaFast = ema_series($closes, $fast);
    $emaSlow = ema_series($closes, $slow);
    $count = count($closes);
    $macdLine = [];
    for ($i = 0; $i < $count; $i++) {
        if ($emaFast[$i] !== null && $emaSlow[$i] !== null) {
            $macdLine[] = $emaFast[$i] - $emaSlow[$i];
        }
    }
    $macdLast = end($macdLine);
    $signalLast = null;
    if (count($macdLine) >= $signal) {
        $signalSeries = ema_series($macdLine, $signal);
        $signalLast = end($signalSeries);
    }
    $hist = ($macdLast !== null && $signalLast !== null) ? ($macdLast - $signalLast) : null;
    return [
        'macd'   => $macdLast,
        'signal' => $signalLast,
        'hist'   => $hist,
    ];
}

function stoch(array $highs, array $lows, array $closes, int $period = 14): array
{
    $count = count($closes);
    if ($count < $period) {
        return ['k' => null, 'd' => null];
    }
    $sliceH = array_slice($highs, -$period);
    $sliceL = array_slice($lows, -$period);
    $highest = max($sliceH);
    $lowest = min($sliceL);
    $current = end($closes);
    if ($highest == $lowest) {
        $k = 50.0;
    } else {
        $k = (($current - $lowest) / ($highest - $lowest)) * 100.0;
    }
    // نسخه ساده: D برابر K (در صورت نیاز می‌توانید SMA سه‌دوره‌ای K را محاسبه کنید)
    return ['k' => $k, 'd' => $k];
}

function volume_ratio(array $volumes, int $period = 20): ?float
{
    $volumes = array_values($volumes);
    $count = count($volumes);
    if ($count < $period + 1) {
        return null;
    }
    $current = end($volumes);
    $prevAvg = array_sum(array_slice($volumes, -$period - 1, $period)) / $period;
    if ($prevAvg == 0.0) {
        return 0.0;
    }
    return $current / $prevAvg;
}
