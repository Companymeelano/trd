<?php
declare(strict_types=1);

define('TRD_APP', true);
define('APP_ROOT', dirname(__DIR__));
define('SRC_DIR', APP_ROOT . '/src');
define('STORAGE_DIR', APP_ROOT . '/storage');
define('CACHE_DIR', STORAGE_DIR . '/cache');
define('LOG_DIR', STORAGE_DIR . '/logs');
define('RATE_LIMIT_FILE', CACHE_DIR . '/rate_limit.json');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
// بک‌تست و اسکن موتور سنگینی دارند؛ در صورت مجوز بودنِ هاست، سقف زمان را بالا ببر.
@set_time_limit(240);

$requestId = bin2hex(random_bytes(12));
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Request-ID: ' . $requestId);
header('Cache-Control: no-store, no-cache, must-revalidate');

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    $payload['request_id'] ??= $GLOBALS['requestId'] ?? null;
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    echo $body === false ? '{"status":"error","message":"Response encoding failed."}' : $body;
    exit;
}
function body_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/index.php';
    $base = rtrim(dirname($script), '/');
    if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
    $path = rtrim($path, '/');
    return $path === '' ? '/' : $path;
}
function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Storage directory is not writable.');
    }
}
function auth_ok(): bool
{
    $provided = trim((string)($_SERVER['HTTP_X_API_TOKEN'] ?? ''));
    if ($provided === '' || !class_exists('Config') || !Config::hasUsableAppToken()) return false;
    return hash_equals(Config::appToken(), $provided);
}
function rate_limit(string $identity, int $limit = 60, int $window = 60): bool
{
    ensure_dir(CACHE_DIR);
    $now = time();
    $bucket = intdiv($now, $window);
    $key = hash('sha256', $identity . '|' . $bucket);
    $handle = @fopen(RATE_LIMIT_FILE, 'c+');
    if ($handle === false) return false;
    try {
        if (!flock($handle, LOCK_EX)) return false;
        $raw = stream_get_contents($handle);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) $data = [];
        foreach ($data as $k => $v) if (($v['ts'] ?? 0) < $now - $window * 3) unset($data[$k]);
        $count = (int)($data[$key]['count'] ?? 0);
        if ($count >= $limit) return false;
        $data[$key] = ['count' => $count + 1, 'ts' => $now];
        ftruncate($handle, 0); rewind($handle);
        fwrite($handle, json_encode($data, JSON_UNESCAPED_SLASHES));
        fflush($handle);
        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
function validate_symbol(string $symbol): bool
{
    return preg_match('/^[A-Z0-9]{2,24}$/', $symbol) === 1;
}
function validate_timeframe(string $tf): bool
{
    return in_array($tf, ['1m','3m','5m','15m','30m','1h','2h','4h','6h','8h','12h','1d','3d','1w','1M'], true);
}

$path = request_path();
if ($path === '/health' || $path === '/health.php') {
    try {
        require APP_ROOT . '/config.php';
        require SRC_DIR . '/Logger.php';
        require SRC_DIR . '/Database.php';
        $logger = new Logger(LOG_DIR, 'INFO');
        $pdo = Database::connect($logger);
        $dbOk = $pdo instanceof PDO;
        $configErrors = Config::configurationErrors();
        json_response([
            'status' => $dbOk && $configErrors === [] ? 'ok' : 'degraded',
            'version' => '4.0.0',
            'database' => $dbOk ? 'connected' : 'down',
            'config' => $configErrors === [] ? 'valid' : 'incomplete',
            'php' => PHP_VERSION,
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            'time' => gmdate('c'),
        ], $dbOk && $configErrors === [] ? 200 : 503);
    } catch (Throwable $e) {
        json_response(['status' => 'down', 'version' => '4.0.0', 'database' => 'down', 'message' => 'Health check failed.'], 503);
    }
}

try {
    require APP_ROOT . '/config.php';
    ensure_dir(CACHE_DIR);
    ensure_dir(LOG_DIR);
    require SRC_DIR . '/Logger.php';
    require SRC_DIR . '/Cache.php';
    require SRC_DIR . '/CircuitBreaker.php';
    require SRC_DIR . '/Database.php';
    require SRC_DIR . '/AnalysisService.php';
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) return;
        $file = SRC_DIR . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require_once $file;
    });
    $logger = new Logger(LOG_DIR, 'INFO');
    if (!Config::hasUsableAppToken()) throw new RuntimeException('APP_TOKEN is missing or too short.');
    $pdo = Database::connect($logger);
    if (!$pdo) throw new RuntimeException('Database is unavailable.');
    $cache = new Cache(['ttl' => Config::cacheTtl()], CACHE_DIR, $logger);
    $breaker = new CircuitBreaker(CACHE_DIR, $logger, Config::breakerFailureThreshold(), Config::breakerOpenTimeout(), Config::breakerHalfOpenTrials(), Config::breakerCooldown());
    $indicators = new App\Indicators\IndicatorCalculator();
    $market = new App\Market\MarketDataService(
        new App\Market\BinanceProvider(Config::binanceBaseUrl(), Config::upstreamTimeout(), 2),
        $cache, $breaker, min(60, Config::cacheTtl())
    );
    $service = new AnalysisService(
        $pdo, $market,
        new App\Repositories\AnalysisLogRepository($pdo),
        new App\Repositories\SignalRepository($pdo),
        new App\Repositories\MarketSnapshotRepository($pdo),
        $indicators, new App\Market\BitcoinMarketGuard($indicators)
    );
} catch (Throwable $e) {
    if (isset($logger)) $logger->error('API initialization failed', ['error' => $e->getMessage(), 'request_id' => $requestId]);
    json_response(['status' => 'error', 'message' => 'Server initialization failed.'], 500);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($path, ['/health','/health.php','/auth-check.php'], true)) {
    // هویت سهمیه: در صورت وجود توکن، بر اساس هش توکن (تا کاربران پشت یک IP/NAT
    // سهمیه یکدیگر را مصرف نکنند) و در غیر این صورت بر اساس IP.
    $identity = 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $providedToken = trim((string)($_SERVER['HTTP_X_API_TOKEN'] ?? ''));
    if ($providedToken !== '') {
        $identity = 'tok:' . hash('sha256', $providedToken);
    }
    if (!rate_limit($identity)) json_response(['status' => 'error', 'message' => 'Rate limit exceeded.'], 429);
}

try {
    switch ($path) {
        case '/':
            json_response(['status' => 'ok', 'version' => '4.0.0', 'service' => 'MeeLano Trading Intelligence']);
        case '/auth-check.php':
            $ok = auth_ok();
            json_response(['status' => $ok ? 'success' : 'error', 'authenticated' => $ok], $ok ? 200 : 401);
        case '/analyze': case '/analyze.php':
            if ($method !== 'POST') json_response(['status'=>'error','message'=>'Method not allowed.'],405);
            if (!auth_ok()) json_response(['status'=>'error','message'=>'Invalid API token.'],401);
            $in = body_json();
            $symbol = strtoupper(trim((string)($in['symbol'] ?? '')));
            $tf = trim((string)($in['timeframe'] ?? '1h'));
            if (!validate_symbol($symbol) || !validate_timeframe($tf)) json_response(['status'=>'error','message'=>'Invalid symbol or timeframe.'],400);
            $capital = array_key_exists('capital',$in) && is_numeric($in['capital']) ? (float)$in['capital'] : null;
            $risk = array_key_exists('risk_pct',$in) && is_numeric($in['risk_pct']) ? (float)$in['risk_pct'] : 0.01;
            if (($capital !== null && (!is_finite($capital) || $capital < 0)) || !is_finite($risk) || $risk < 0 || $risk > 0.01) json_response(['status'=>'error','message'=>'Invalid capital or risk.'],400);
            json_response($service->analyze($symbol,$tf,['capital'=>$capital,'risk_pct'=>$risk,'news_status'=>(string)($in['news_status']??'normal')]));
        case '/history': case '/history.php': case '/signals.php':
            if (!auth_ok()) json_response(['status'=>'error','message'=>'Invalid API token.'],401);
            $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT);
            json_response(['status'=>'success','items'=>$service->recentHistory($limit === false ? 50 : $limit)]);
        case '/backtest': case '/backtest.php':
            if ($method !== 'POST') json_response(['status'=>'error','message'=>'Method not allowed.'],405);
            if (!auth_ok()) json_response(['status'=>'error','message'=>'Invalid API token.'],401);
            $in=body_json(); $symbol=strtoupper(trim((string)($in['symbol']??'BTCUSDT'))); $tf=trim((string)($in['timeframe']??'1h'));
            $capital=array_key_exists('capital',$in)&&is_numeric($in['capital'])?(float)$in['capital']:1000; $risk=array_key_exists('risk_pct',$in)&&is_numeric($in['risk_pct'])?(float)$in['risk_pct']:0.01;
            if (!validate_symbol($symbol) || !validate_timeframe($tf) || !is_finite($capital) || $capital < 10 || !is_finite($risk) || $risk <= 0 || $risk > 0.01) json_response(['status'=>'error','message'=>'Invalid backtest parameters.'],400);
            $result=$service->backtest($symbol,$tf,['capital'=>$capital,'risk_pct'=>$risk,'news_status'=>(string)($in['news_status']??'normal')]);
            json_response(['status'=>'success','result'=>$result]);
        case '/scan': case '/scan.php':
            if ($method !== 'GET') json_response(['status'=>'error','message'=>'Method not allowed.'],405);
            if (!auth_ok()) json_response(['status'=>'error','message'=>'Invalid API token.'],401);
            $quote=strtoupper(trim((string)($_GET['quote']??'USDT')));
            if (!preg_match('/^[A-Z]{3,6}$/',$quote)) json_response(['status'=>'error','message'=>'Invalid quote asset.'],400);
            json_response(['status'=>'success','quote'=>$quote,'items'=>$service->scan($quote)]);
        default:
            json_response(['status'=>'error','message'=>'Route not found.'],404);
    }
} catch (Throwable $e) {
    $logger->error('Unhandled API exception',['error'=>$e->getMessage(),'request_id'=>$requestId]);
    json_response(['status'=>'error','message'=>'Analysis service failed.'],500);
}
