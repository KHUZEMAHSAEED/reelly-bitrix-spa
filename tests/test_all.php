<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\BitrixClient;
use App\FieldProvisioner;
use App\ImageService;
use App\Mapper;
use App\ReellyClient;
use App\SyncRunner;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

function assertCondition(bool $condition, string $testName): void {
    if (!$condition) {
        echo "[FAIL] {$testName}\n";
        exit(1);
    }
    echo "[PASS] {$testName}\n";
}

$logger = new Logger('test');
$logger->pushHandler(new NullHandler());

echo "--- RUNNING TESTS ---\n";

// 1. Mapper Tests
$fieldDefinitions = require __DIR__ . '/../fields.php';
$mockFieldMap = [];
foreach ($fieldDefinitions as $def) {
    $mockFieldMap[$def['key']] = [
        'bitrix_code' => "ufCrm1032_{$def['bitrix_name']}",
        'type'        => $def['type'],
        'multiple'    => !empty($def['multiple']),
        'enum_map'    => ($def['type'] === 'enumeration') ? [
            'under_construction' => 101,
            'completed'          => 102,
            'on_sale'            => 201,
        ] : [],
    ];
}

$mapper = new Mapper($fieldDefinitions, $mockFieldMap, $logger);

$sampleListing = [
    'id' => 1127,
    'name' => 'Muraba Veil',
    'developer' => 'Muraba Properties',
    'construction_status' => 'UNDER_CONSTRUCTION',
    'sale_status' => 'on_sale',
    'min_price' => '12576212.50',
    'max_price' => 72873192,
    'price_currency' => 'aed',
    'min_size' => '3838.01',
    'max_size' => 9903.07,
    'area_unit' => 'SQFT',
    'min_bedrooms' => '3',
    'min_bathrooms' => 4,
    'completion_datetime' => '2028-12-31T00:00:00Z',
    'overview' => 'Luxury waterfront property on Dubai Canal',
    'location' => [
        'country' => 'United Arab Emirates',
        'region' => 'Dubai',
        'district' => 'Dubai Canal',
        'latitude' => '25.1972',
        'longitude' => 55.2744,
    ],
    'payment_plan' => [
        'down_payment_percent' => '20.0',
    ],
    'media' => [
        'gallery' => [
            'https://cdn.reelly.ai/images/proj_1127_1.jpg',
            'https://cdn.reelly.ai/images/proj_1127_2.jpg',
        ],
    ],
];

// Test Mapper full mapping
$fullMapped = $mapper->mapListing($sampleListing, false);
assertCondition(isset($fullMapped['ufCrm1032_REELLY_ID']) && $fullMapped['ufCrm1032_REELLY_ID'] === '1127', 'Mapper maps reelly_id to string');
assertCondition(isset($fullMapped['ufCrm1032_REELLY_MIN_PRICE']) && $fullMapped['ufCrm1032_REELLY_MIN_PRICE'] === 12576212.5, 'Mapper transforms min_price to float');
assertCondition(isset($fullMapped['ufCrm1032_REELLY_CURRENCY']) && $fullMapped['ufCrm1032_REELLY_CURRENCY'] === 'AED', 'Mapper transforms currency to uppercase');
assertCondition(isset($fullMapped['ufCrm1032_REELLY_AREA_UNIT']) && $fullMapped['ufCrm1032_REELLY_AREA_UNIT'] === 'sqft', 'Mapper transforms area_unit to lowercase');
assertCondition(isset($fullMapped['ufCrm1032_REELLY_BEDROOMS']) && $fullMapped['ufCrm1032_REELLY_BEDROOMS'] === 3, 'Mapper casts bedrooms to int');
assertCondition(isset($fullMapped['ufCrm1032_REELLY_CONSTRUCTION_STATUS']) && $fullMapped['ufCrm1032_REELLY_CONSTRUCTION_STATUS'] === 101, 'Mapper resolves enum ID for under_construction');
assertCondition(isset($fullMapped['ufCrm1032_REELLY_CITY']) && $fullMapped['ufCrm1032_REELLY_CITY'] === 'Dubai', 'Mapper extracts nested location.city');

// Test Mapper reelly_owned filtering (updates)
$ownedMapped = $mapper->mapListing($sampleListing, true);
assertCondition(!isset($ownedMapped['ufCrm1032_REELLY_ID']), 'Mapper excludes non-reelly_owned field (reelly_id) on update');
assertCondition(isset($ownedMapped['ufCrm1032_REELLY_MIN_PRICE']), 'Mapper includes reelly_owned field on update');

// Test unlisted enum value
$sampleWithBadEnum = $sampleListing;
$sampleWithBadEnum['construction_status'] = 'unknown_status_foo';
$mappedBadEnum = $mapper->mapListing($sampleWithBadEnum, false);
assertCondition(!isset($mappedBadEnum['ufCrm1032_REELLY_CONSTRUCTION_STATUS']), 'Mapper drops unlisted enum value');

// Test payload hash calculation determinism
$hash1 = $mapper->computePayloadHash($fullMapped);
$hash2 = $mapper->computePayloadHash($fullMapped);
assertCondition($hash1 === $hash2 && strlen($hash1) === 40, 'Hash computation is deterministic and 40-char SHA1');

// Test hash excludes sync_hash itself
$fullMappedWithHash = $fullMapped;
$fullMappedWithHash['ufCrm1032_SYNC_HASH'] = 'previous_hash_value';
$hash3 = $mapper->computePayloadHash($fullMappedWithHash);
assertCondition($hash1 === $hash3, 'Hash computation excludes SYNC_HASH field');

// 2. FieldProvisioner Tests
$mockBitrixClient = new BitrixClient('https://example.bitrix24.com/rest/1/dummy/', 0, 10, 1, $logger);
$provisioner = new FieldProvisioner($mockBitrixClient, 1032, $logger);

$crmFieldsFixture = [
    'id' => ['type' => 'integer'],
    'title' => ['type' => 'string'],
    'ufCrm1032_REELLY_ID' => ['type' => 'string'],
    'UF_CRM_1032_REELLY_TITLE' => ['type' => 'string'],
    'ufCrm1032_reelly_developer' => ['type' => 'string'],
];

assertCondition(
    $provisioner->resolveFieldCode('REELLY_ID', $crmFieldsFixture) === 'ufCrm1032_REELLY_ID',
    'FieldProvisioner resolves camelCase code'
);
assertCondition(
    $provisioner->resolveFieldCode('REELLY_TITLE', $crmFieldsFixture) === 'UF_CRM_1032_REELLY_TITLE',
    'FieldProvisioner resolves uppercase code'
);
assertCondition(
    $provisioner->resolveFieldCode('REELLY_DEVELOPER', $crmFieldsFixture) === 'ufCrm1032_reelly_developer',
    'FieldProvisioner resolves case-insensitively'
);

// Test hash mismatch validation
$tempFieldsFile = __DIR__ . '/temp_fields.php';
file_put_contents($tempFieldsFile, '<?php return [];');
$tempCacheFile = __DIR__ . '/temp_field_map.json';
file_put_contents($tempCacheFile, json_encode([
    'entityTypeId' => 1032,
    'fields_hash' => 'outdated_hash_value',
    'fields' => [],
]));

$mismatchDetected = false;
try {
    $provisioner->loadAndValidateCache($tempCacheFile, $tempFieldsFile);
} catch (RuntimeException $e) {
    if (str_contains($e->getMessage(), 'fields.php changed, run php sync.php --refresh-fields')) {
        $mismatchDetected = true;
    }
}
unlink($tempFieldsFile);
unlink($tempCacheFile);
assertCondition($mismatchDetected, 'FieldProvisioner stops with error when fields_hash mismatches');

// 3. ImageService S3 deterministic key and skipping test
$imageService = new ImageService($mockBitrixClient, [
    'access_key_id' => 'dummy',
    'secret_access_key' => 'dummy',
    'region' => 'us-east-1',
    'bucket' => 'test-bucket',
    's3_public_base_url' => 'https://cdn.example.com',
], [
    'max_per_listing' => 5,
    'max_width' => 1600,
    'jpeg_quality' => 85,
], $logger);

$s3Url = $imageService->buildS3Url('reelly/1127/test.jpg');
assertCondition($s3Url === 'https://cdn.example.com/reelly/1127/test.jpg', 'ImageService builds correct public S3 URL');

// 4. Locking test
$lockFile = __DIR__ . '/test.lock';
$h1 = fopen($lockFile, 'c+');
$acquired = flock($h1, LOCK_EX | LOCK_NB);
assertCondition($acquired, 'First process acquires flock');

$h2 = fopen($lockFile, 'c+');
$secondAcquired = flock($h2, LOCK_EX | LOCK_NB);
assertCondition(!$secondAcquired, 'Second process fails to acquire flock when locked');

flock($h1, LOCK_UN);
fclose($h1);
fclose($h2);
if (file_exists($lockFile)) {
    unlink($lockFile);
}

echo "--- ALL TESTS PASSED SUCCESSFULLY! ---\n";

