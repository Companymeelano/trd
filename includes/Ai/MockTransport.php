<?php
namespace Meelano\Ai;

/**
 * انتقال‌دهنده ساختگی برای تست — پاسخ‌ها از پیش تعریف می‌شوند.
 */
final class MockTransport implements Transport
{
    /** @var array<string,array> */
    private $responses = [];
    /** @var array<string,array> پاسخ‌های متوالی (برای تست خودسازگاری/رتیتیم) */
    private $sequences = [];
    /** @var array<string,int> */
    private $seqPos = [];
    /** @var array<int,array> */
    public $calls = [];

    public function on(string $urlPattern, array $response): self
    {
        $this->responses[$urlPattern] = array_merge([
            'status' => 200, 'body' => '{}', 'headers' => [], 'error' => '', 'elapsed_ms' => 5.0,
        ], $response);
        return $this;
    }

    /** پاسخ‌های متوالی: هر فراخوانی پاسخ بعدی را می‌گیرد (آخرین تکرار می‌شود). */
    public function onSequence(string $urlPattern, array $responses): self
    {
        $this->sequences[$urlPattern] = array_values($responses);
        $this->seqPos[$urlPattern] = 0;
        return $this;
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];
        foreach ($this->sequences as $pattern => $responses) {
            if (strpos($url, $pattern) !== false) {
                $i = $this->seqPos[$pattern]++;
                return array_merge([
                    'status' => 200, 'body' => '{}', 'headers' => [], 'error' => '', 'elapsed_ms' => 5.0,
                ], $responses[min($i, count($responses) - 1)]);
            }
        }
        foreach ($this->responses as $pattern => $response) {
            if (strpos($url, $pattern) !== false) {
                return $response;
            }
        }
        return ['status' => 404, 'body' => '{"error":"no mock for ' . $url . '"}', 'headers' => [], 'error' => '', 'elapsed_ms' => 1.0];
    }
}
