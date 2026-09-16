<?php
namespace Meelano\Ai;

/**
 * انتقال‌دهنده ساختگی برای تست — پاسخ‌ها از پیش تعریف می‌شوند.
 */
final class MockTransport implements Transport
{
    /** @var array<string,array> */
    private $responses = [];
    /** @var array<int,array> */
    public $calls = [];

    public function on(string $urlPattern, array $response): self
    {
        $this->responses[$urlPattern] = array_merge([
            'status' => 200, 'body' => '{}', 'headers' => [], 'error' => '', 'elapsed_ms' => 5.0,
        ], $response);
        return $this;
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];
        foreach ($this->responses as $pattern => $response) {
            if (strpos($url, $pattern) !== false) {
                return $response;
            }
        }
        return ['status' => 404, 'body' => '{"error":"no mock for ' . $url . '"}', 'headers' => [], 'error' => '', 'elapsed_ms' => 1.0];
    }
}
