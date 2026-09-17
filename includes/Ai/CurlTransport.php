<?php
namespace Meelano\Ai;

/** انتقال‌دهنده واقعی مبتنی بر cURL. */
final class CurlTransport implements Transport
{
    public function request(string $method, string $url, array $options = []): array
    {
        $started = m_microtime();
        $headers = $options['headers'] ?? [];
        $timeout = (int)($options['timeout'] ?? 45);

        $ch = curl_init();
        $flatHeaders = [];
        foreach ($headers as $k => $v) {
            $flatHeaders[] = is_int($k) ? $v : ($k . ': ' . $v);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $flatHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'MeelanoPanel/' . MEELANO_VERSION,
        ]);

        if (isset($options['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
        }

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $body === false ? (string)curl_error($ch) : '';
        curl_close($ch);

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'headers' => [],
            'error' => $error,
            'elapsed_ms' => round((m_microtime() - $started) * 1000, 1),
        ];
    }
}
