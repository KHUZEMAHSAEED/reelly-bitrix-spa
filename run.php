<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\SyncRunner;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger;

// Set unlimited execution time for web triggers
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');
set_time_limit(0);
ignore_user_abort(true);

// Disable output buffering and enable real-time streaming
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
for ($i = 0; $i < ob_get_level(); $i++) {
    ob_end_flush();
}
ob_implicit_flush(true);

header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Initial output padding for browsers to start rendering immediately
echo str_repeat(" ", 1024) . "\n";
echo "========================================================\n";
echo " Reelly -> Bitrix24 SPA Web Trigger\n";
echo " Time: " . date('Y-m-d H:i:s') . "\n";
echo "========================================================\n\n";
flush();

// Load configuration
$config = require __DIR__ . '/config.php';
$fieldsFilePath = __DIR__ . '/fields.php';

if (!file_exists($fieldsFilePath)) {
    http_response_code(500);
    echo "[ERROR 500] fields.php file not found.\n";
    exit(1);
}

$fieldDefinitions = require $fieldsFilePath;

// Security Token Check
$configuredToken = (string)($_ENV['WEB_SYNC_TOKEN'] ?? 'reelly_sync_secret_token');
$providedToken = (string)($_GET['token'] ?? '');

if ($configuredToken !== '' && !hash_equals($configuredToken, $providedToken)) {
    http_response_code(403);
    echo "[ERROR 403] Access Denied: Invalid or missing ?token= parameter.\n";
    echo "Usage: https://your-domain.com/path/run.php?token=YOUR_TOKEN\n";
    exit(1);
}

// Parse Query Parameters
$dryRun = isset($_GET['dry_run']) && in_array(strtolower((string)$_GET['dry_run']), ['1', 'true', 'yes'], true);
$refreshFields = isset($_GET['refresh_fields']) && in_array(strtolower((string)$_GET['refresh_fields']), ['1', 'true', 'yes'], true);
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : null;

if ($limit !== null && $limit <= 0) {
    $limit = null;
}

echo "Parameters:\n";
echo " - Token:          Verified OK\n";
echo " - Dry Run:        " . ($dryRun ? 'YES (No writes)' : 'NO (Live sync)') . "\n";
echo " - Refresh Fields: " . ($refreshFields ? 'YES' : 'NO') . "\n";
echo " - Limit:          " . ($limit !== null ? (string)$limit : 'ALL listings') . "\n\n";
flush();

// Setup Logger: Daily rotating file + Real-time Web Stream
$logLevelStr = strtoupper((string)($config['sync']['log_level'] ?? 'INFO'));
$logLevel = match ($logLevelStr) {
    'DEBUG'   => Level::Debug,
    'NOTICE'  => Level::Notice,
    'WARNING' => Level::Warning,
    'ERROR'   => Level::Error,
    default   => Level::Info,
};

$logPath = (string)($config['sync']['log_path'] ?? __DIR__ . '/storage/logs/sync.log');
$logDir = dirname($logPath);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logger = new Logger('reelly-web-sync');

$fileHandler = new RotatingFileHandler($logPath, 30, $logLevel);
$fileHandler->setFormatter(new LineFormatter(
    "[%datetime%] %channel%.%level_name%: %message% %context%\n",
    'Y-m-d H:i:s'
));
$logger->pushHandler($fileHandler);

// Custom handler to stream logs directly to HTTP response in real time
$streamHandler = new class($logLevel) extends AbstractProcessingHandler {
    protected function write(LogRecord $record): void
    {
        $time = $record->datetime->format('H:i:s');
        $level = str_pad($record->level->getName(), 7);
        echo "[{$time}] [{$level}] {$record->message}\n";
        flush();
    }
};
$logger->pushHandler($streamHandler);

// Execute Sync
try {
    $runner = new SyncRunner(
        $config,
        $fieldDefinitions,
        $fieldsFilePath,
        $logger
    );
    $exitCode = $runner->run($dryRun, $limit, $refreshFields);

    echo "\n";
    if ($exitCode === 0) {
        echo "SUCCESS: Sync finished cleanly (Exit code 0).\n";
    } else {
        echo "COMPLETED WITH WARNINGS/ERRORS: Exit code {$exitCode}.\n";
    }
    exit($exitCode);
} catch (\Throwable $e) {
    echo "\nFATAL EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
