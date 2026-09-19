<?php
declare(strict_types=1);

define('TRD_APP', true);

require __DIR__ . '/config.php';

/* ---------- توابع ارسال ---------- */

function telegram_send(string $token, string $chatId, string $text): array {
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';

    $payload = [
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $decoded = json_decode((string)$resp, true);
    $ok = ($code >= 200 && $code < 300) && !empty($decoded['ok']);

    return [
        'ok'        => $ok,
        'provider'  => 'telegram',
        'http_code' => $code,
        'error'     => $ok ? null : ($decoded['description'] ?? ($err ?: 'خطای ناشناخته تلگرام')),
    ];
}

function webhook_send(string $url, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $ok = ($code >= 200 && $code < 300);

    return [
        'ok'        => $ok,
        'provider'  => 'webhook',
        'http_code' => $code,
        'error'     => $ok ? null : ($err ?: 'خطای ناشناخته وب‌هوک'),
        'response'  => mb_substr((string)$resp, 0, 300),
    ];
}

/* ---------- محافظ SSRF برای وب‌هوک ---------- */

/**
 * فقط HTTPS عمومی مجاز است؛ میزبان‌های loopback/private/link-local/internal
 * رد می‌شوند تا سرور به‌عنوان پروکسی به شبکه داخلی استفاده نشود.
 */
function webhook_url_is_safe(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    if (strtolower($parts['scheme'] ?? '') !== 'https') {
        return false;
    }

    $host = strtolower($parts['host'] ?? '');
    if ($host === '') {
        return false;
    }

    if (
        $host === 'localhost' ||
        str_ends_with($host, '.localhost') ||
        str_ends_with($host, '.local') ||
        str_ends_with($host, '.internal')
    ) {
        return false;
    }

    $publicFlags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, $publicFlags) !== false;
    }

    $ips = function_exists('gethostbynamel') ? gethostbynamel($host) : false;
    if (is_array($ips)) {
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, $publicFlags) === false) {
                return false;
            }
        }
    }

    return true;
}

/* ---------- اجرای اصلی ---------- */

try {
    require_auth();

    $body = get_json_body();

    if (!is_array($body)) {
        json_error('بدنه درخواست JSON معتبر نیست.', 400);
    }

    $channel = strtolower(trim((string)($body['channel'] ?? 'telegram')));
    $message = trim((string)($body['message'] ?? ''));

    $len = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);

    if ($message === '') {
        json_error('متن پیام خالی است.', 400);
    }

    if ($len > 4000) {
        json_error('متن پیام بیش از حد طولانی است.', 400);
    }

    $results = [];

    /* تلگرام */
    if ($channel === 'telegram' || $channel === 'all') {
        $tgToken  = trim((string)($body['tg_token']  ?? (defined('NOTIFY_TG_TOKEN')  ? NOTIFY_TG_TOKEN  : '')));
        $tgChatId = trim((string)($body['tg_chat_id'] ?? (defined('NOTIFY_TG_CHAT_ID') ? NOTIFY_TG_CHAT_ID : '')));

        if ($tgToken !== '' && $tgChatId !== '') {
            $results['telegram'] = telegram_send($tgToken, $tgChatId, $message);
        } else {
            $results['telegram'] = [
                'ok'     => false,
                'error'  => 'تنظیمات تلگرام (Bot Token یا Chat ID) موجود نیست.',
            ];
        }
    }

    /* وب‌هوک (واتس‌اپ بیزینس / Twilio / سرور اختصاصی) */
    if ($channel === 'webhook' || $channel === 'all') {
        $webhookUrl = trim((string)($body['webhook_url'] ?? (defined('NOTIFY_WEBHOOK_URL') ? NOTIFY_WEBHOOK_URL : '')));

        if ($webhookUrl !== '') {
            if (!webhook_url_is_safe($webhookUrl)) {
                $results['webhook'] = [
                    'ok'    => false,
                    'error' => 'Webhook URL is not allowed; public HTTPS addresses only.',
                ];
            } else {
                $results['webhook'] = webhook_send($webhookUrl, [
                    'text'    => $message,
                    'source'  => 'meelano-trading',
                    'time'    => date('c'),
                ]);
            }
        } else {
            $results['webhook'] = [
                'ok'    => false,
                'error' => 'URL وب‌هوک تنظیم نشده است.',
            ];
        }
    }

    if ($results === []) {
        json_error('کانال ارسال معتبر نیست (telegram یا webhook).', 400);
    }

    $allOk = true;
    foreach ($results as $r) {
        if (empty($r['ok'])) { $allOk = false; break; }
    }

    json_response([
        'status'  => $allOk ? 'success' : 'partial',
        'sent'    => $allOk,
        'results' => $results,
    ], $allOk ? 200 : 502);

} catch (Throwable $e) {
    json_error('Notify failed: ' . $e->getMessage(), 500, $e);
}
