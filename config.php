<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$baseDir = __DIR__;

if (file_exists($baseDir . '/.env')) {
    $dotenv = Dotenv::createImmutable($baseDir);
    $dotenv->safeLoad();
}

/**
 * Returns configuration settings array.
 *
 * @return array<string, mixed>
 */
return [
    'reelly' => [
        'base_url'    => rtrim($_ENV['REELLY_API_BASE_URL'] ?? 'https://api-reelly.up.railway.app/api/v2/clients', '/'),
        'api_key'     => (string)($_ENV['REELLY_API_KEY'] ?? ''),
        'auth_header' => (string)($_ENV['REELLY_AUTH_HEADER'] ?? 'X-API-Key'),
        'page_size'   => (int)($_ENV['REELLY_PAGE_SIZE'] ?? 20),
        'timeout'     => (int)($_ENV['REELLY_TIMEOUT'] ?? 30),
        'max_retries' => (int)($_ENV['REELLY_MAX_RETRIES'] ?? 3),
    ],
    'bitrix' => [
        'webhook_url'          => rtrim($_ENV['BITRIX_WEBHOOK_URL'] ?? '', '/'),
        'entity_type_id'       => (int)($_ENV['BITRIX_SPA_ENTITY_TYPE_ID'] ?? 0),
        'stage_active'         => (string)($_ENV['BITRIX_STAGE_ACTIVE'] ?? 'DT1032_NEW'),
        'stage_removed'        => (string)($_ENV['BITRIX_STAGE_REMOVED'] ?? 'DT1032_FAIL'),
        'assigned_by_id'       => (int)($_ENV['BITRIX_ASSIGNED_BY_ID'] ?? 1),
        'rate_limit_delay_ms'  => (int)($_ENV['BITRIX_RATE_LIMIT_DELAY_MS'] ?? 500),
        'timeout'              => (int)($_ENV['BITRIX_TIMEOUT'] ?? 60),
        'max_retries'          => (int)($_ENV['BITRIX_MAX_RETRIES'] ?? 5),
    ],
    'aws' => [
        'access_key_id'     => (string)($_ENV['AWS_ACCESS_KEY_ID'] ?? ''),
        'secret_access_key' => (string)($_ENV['AWS_SECRET_ACCESS_KEY'] ?? ''),
        'region'            => (string)($_ENV['AWS_DEFAULT_REGION'] ?? 'us-east-1'),
        'bucket'            => (string)($_ENV['AWS_BUCKET'] ?? ''),
        'endpoint'          => !empty($_ENV['AWS_ENDPOINT']) ? (string)$_ENV['AWS_ENDPOINT'] : null,
        's3_public_base_url'=> !empty($_ENV['S3_PUBLIC_BASE_URL']) ? rtrim((string)$_ENV['S3_PUBLIC_BASE_URL'], '/') : null,
    ],
    'images' => [
        'max_per_listing'    => (int)($_ENV['MAX_IMAGES_PER_LISTING'] ?? 10),
        'max_width'          => (int)($_ENV['IMAGE_MAX_WIDTH'] ?? 1600),
        'jpeg_quality'       => (int)($_ENV['IMAGE_JPEG_QUALITY'] ?? 85),
        'download_timeout'   => (int)($_ENV['IMAGE_DOWNLOAD_TIMEOUT'] ?? 15),
        'max_file_size'      => (int)($_ENV['IMAGE_MAX_FILE_SIZE_BYTES'] ?? 10485760), // 10 MB
    ],
    'sync' => [
        'removal_threshold_percent' => (float)($_ENV['REMOVAL_THRESHOLD_PERCENT'] ?? 20.0),
        'log_level'                 => (string)($_ENV['LOG_LEVEL'] ?? 'INFO'),
        'storage_path'              => $baseDir . '/storage',
        'field_map_path'            => $baseDir . '/storage/field_map.json',
        'lock_file_path'            => $baseDir . '/storage/sync.lock',
        'log_path'                  => $baseDir . '/storage/logs/sync.log',
    ],
];

