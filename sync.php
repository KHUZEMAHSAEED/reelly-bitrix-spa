<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\SyncRunner;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

// Parse CLI options
$options = getopt('h', ['dry-run', 'limit:', 'refresh-fields', 'help']);

if (isset($options['h']) || isset($options['help'])) {
    echo <<<HELP
Reelly to Bitrix24 SPA Listing Sync CLI

Usage:
  php sync.php [options]

Options:
  --dry-run             Simulate sync without writing to Bitrix24, S3, or creating field_map.json
  --limit=N             Limit the sync to N listings from Reelly API (useful for testing)
  --refresh-fields      Deletes field_map.json, re-provisions missing SPA fields, and exits
  -h, --help            Show this help message

Examples:
  php sync.php --dry-run --limit=5
  php sync.php --limit=5
  php sync.php
  php sync.php --refresh-fields

HELP;
    exit(0);
}

$dryRun = isset($options['dry-run']);
$refreshFields = isset($options['refresh-fields']);
$limit = isset($options['limit']) && is_numeric($options['limit']) ? (int)$options['limit'] : null;

// Load configuration and field definitions
$config = require __DIR__ . '/config.php';
$fieldsFilePath = __DIR__ . '/fields.php';

if (!file_exists($fieldsFilePath)) {
    fwrite(STDERR, "Error: fields.php file not found at {$fieldsFilePath}\n");
    exit(1);
}

$fieldDefinitions = require $fieldsFilePath;

// Initialize Monolog Logger
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

$dateFormat = 'Y-m-d H:i:s';
$outputFormat = "[%datetime%] %channel%.%level_name%: %message% %context%\n";
$formatter = new LineFormatter($outputFormat, $dateFormat, true, true);

// 1. Daily rotating file handler
$rotatingHandler = new RotatingFileHandler($logPath, 30, $logLevel);
$rotatingHandler->setFormatter($formatter);

// 2. STDOUT stream handler for CLI console
$stdoutHandler = new StreamHandler('php://stdout', $logLevel);
$stdoutHandler->setFormatter($formatter);

$logger = new Logger('reelly-sync');
$logger->pushHandler($rotatingHandler);
$logger->pushHandler($stdoutHandler);

$logger->info("Initializing Reelly -> Bitrix24 SPA Sync CLI...", [
    'dry_run'        => $dryRun,
    'limit'          => $limit,
    'refresh_fields' => $refreshFields,
]);

// Execute Sync Runner
$runner = new SyncRunner(
    $config,
    $fieldDefinitions,
    $fieldsFilePath,
    $logger
);

$exitCode = $runner->run($dryRun, $limit, $refreshFields);
exit($exitCode);

