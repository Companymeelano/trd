<?php
declare(strict_types=1);

$url = 'https://api.binance.com/api/v3/ping';

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 15,

    CURLOPT_PROXY          => 'http://127.0.0.1:7897',
    CURLOPT_PROXYTYPE      => CURLPROXY_HTTP,
    CURLOPT_HTTPPROXYTUNNEL => true,

    CURLOPT_USERAGENT      => 'MeeLano-Proxy-Test/1.0',
]);

$response = curl_exec($ch);

$result = [
    'http_code'  => curl_getinfo($ch, CURLINFO_HTTP_CODE),
    'curl_errno' => curl_errno($ch),
    'curl_error' => curl_error($ch),
    'response'   => $response,
];

curl_close($ch);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
