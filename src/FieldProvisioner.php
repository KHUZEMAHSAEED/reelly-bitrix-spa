<?php

declare(strict_types=1);

namespace App;

use Psr\Log\LoggerInterface;
use RuntimeException;

class FieldProvisioner
{
    private BitrixClient $bitrixClient;
    private int $entityTypeId;
    private LoggerInterface $logger;
    private ?int $spaId = null;

    public function __construct(
        BitrixClient $bitrixClient,
        int $entityTypeId,
        LoggerInterface $logger
    ) {
        $this->bitrixClient = $bitrixClient;
        $this->entityTypeId = $entityTypeId;
        $this->logger = $logger;
    }

    /**
     * Resolves internal SPA primary key ID from crm.type.list.
     */
    public function getSpaId(): int
    {
        if ($this->spaId !== null) {
            return $this->spaId;
        }

        try {
            $typeList = $this->bitrixClient->call('crm.type.list');
            $types = $typeList['result']['types'] ?? [];
            foreach ($types as $t) {
                if ((int)($t['entityTypeId'] ?? 0) === $this->entityTypeId) {
                    $this->spaId = (int)$t['id'];
                    $this->logger->info("Resolved SPA definition ID {$this->spaId} for entityTypeId {$this->entityTypeId}.");
                    return $this->spaId;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning("Could not resolve SPA ID from crm.type.list: " . $e->getMessage());
        }

        $this->spaId = $this->entityTypeId;
        return $this->spaId;
    }

    /**
     * Checks if field cache exists on disk.
     */
    public function isCachePresent(string $fieldMapPath): bool
    {
        return file_exists($fieldMapPath);
    }

    /**
     * Validates that cached field_map.json hash matches fields.php content hash.
     *
     * @return array<string, mixed> Cached field map data
     * @throws RuntimeException If cache hash does not match fields.php
     */
    public function loadAndValidateCache(string $fieldMapPath, string $fieldsFilePath): array
    {
        if (!file_exists($fieldMapPath)) {
            throw new RuntimeException("Field map cache file not found at: {$fieldMapPath}");
        }

        $rawJson = (string)file_get_contents($fieldMapPath);
        $cached = json_decode($rawJson, true);

        if (!is_array($cached) || !isset($cached['fields_hash'], $cached['fields'])) {
            throw new RuntimeException("Corrupted field map cache at {$fieldMapPath}. Run php sync.php --refresh-fields");
        }

        $currentHash = sha1((string)file_get_contents($fieldsFilePath));

        if ($cached['fields_hash'] !== $currentHash) {
            $msg = "fields.php changed, run php sync.php --refresh-fields";
            $this->logger->error($msg);
            throw new RuntimeException($msg);
        }

        return $cached;
    }

    /**
     * Provisions missing custom SPA fields in Bitrix24 and writes storage/field_map.json.
     *
     * @param array<int, array<string, mixed>> $fieldDefinitions Fields from fields.php
     * @param string $fieldsFilePath Path to fields.php
     * @param string $fieldMapPath Path to storage/field_map.json
     * @param bool $dryRun If true, simulate only without calling create or writing files
     * @return array<string, mixed> Resolved field mapping array
     */
    public function provision(
        array $fieldDefinitions,
        string $fieldsFilePath,
        string $fieldMapPath,
        bool $dryRun = false
    ): array {
        $this->logger->info("Starting field provisioning for SPA entityTypeId: {$this->entityTypeId}...");

        $fieldsHash = sha1((string)file_get_contents($fieldsFilePath));
        $spaId = $this->getSpaId();
        $entityId = "CRM_{$spaId}";

        if ($dryRun) {
            $this->logger->info("[DRY-RUN] Simulating field provisioning. No changes will be written to Bitrix24 or disk.");
            return $this->buildDryRunMap($fieldDefinitions, $fieldsHash);
        }

        // 1. Fetch existing user fields for this SPA entity
        $this->logger->info("Querying userfieldconfig.list for entityId {$entityId}...");
        $listResponse = $this->bitrixClient->call('userfieldconfig.list', [
            'moduleId' => 'crm',
        ]);

        $rawFieldsList = $listResponse['result']['fields'] ?? [];
        $existingFieldsByName = [];

        foreach ($rawFieldsList as $existing) {
            $currEntityId = (string)($existing['entityId'] ?? '');
            if ($currEntityId !== $entityId && $currEntityId !== "CRM_{$this->entityTypeId}") {
                continue;
            }

            $fullFieldName = (string)($existing['fieldName'] ?? '');
            $userTypeId    = (string)($existing['userTypeId'] ?? '');

            // Strip UF_CRM_{id}_ or UF_CRM_{entityTypeId}_ prefix
            $prefixSpa = "UF_CRM_{$spaId}_";
            $prefixType = "UF_CRM_{$this->entityTypeId}_";

            if (str_starts_with($fullFieldName, $prefixSpa)) {
                $bitrixName = substr($fullFieldName, strlen($prefixSpa));
            } elseif (str_starts_with($fullFieldName, $prefixType)) {
                $bitrixName = substr($fullFieldName, strlen($prefixType));
            } else {
                $bitrixName = $fullFieldName;
            }

            $existingFieldsByName[strtoupper($bitrixName)] = [
                'id'         => $existing['id'] ?? null,
                'fieldName'  => $fullFieldName,
                'userTypeId' => $userTypeId,
                'multiple'   => ($existing['multiple'] ?? 'N') === 'Y',
                'raw'        => $existing,
            ];
        }

        // 2 & 3. Diff and create missing fields
        foreach ($fieldDefinitions as $def) {
            $bitrixName = strtoupper((string)$def['bitrix_name']);
            $targetType = (string)$def['type'];

            if (isset($existingFieldsByName[$bitrixName])) {
                $existing = $existingFieldsByName[$bitrixName];
                $existingType = $existing['userTypeId'];

                if (strtolower($existingType) !== strtolower($targetType)) {
                    $this->logger->warning(
                        "Field '{$bitrixName}' already exists with type '{$existingType}', but fields.php defines type '{$targetType}'. Skipping field modification."
                    );
                } else {
                    $this->logger->info("Field '{$bitrixName}' ({$existing['fieldName']}) already exists. Skipping.");
                }
                continue;
            }

            // Create missing field via userfieldconfig.add
            $this->logger->info("Creating missing SPA custom field: {$bitrixName} (type: {$targetType})...");

            $fieldPayload = [
                'entityId'        => $entityId,
                'fieldName'       => "UF_CRM_{$spaId}_{$bitrixName}",
                'userTypeId'      => $targetType,
                'multiple'        => !empty($def['multiple']) ? 'Y' : 'N',
                'mandatory'       => 'N',
                'showFilter'      => 'Y',
                'editFormLabel'   => ['en' => (string)$def['label']],
                'listColumnLabel' => ['en' => (string)$def['label']],
                'listFilterLabel' => ['en' => (string)$def['label']],
            ];

            if ($targetType === 'enumeration' && !empty($def['enum_values'])) {
                $enumItems = [];
                $sort = 100;
                foreach ($def['enum_values'] as $val) {
                    $enumItems[] = [
                        'value' => (string)$val,
                        'def'   => 'N',
                        'sort'  => $sort,
                    ];
                    $sort += 100;
                }
                $fieldPayload['enum'] = $enumItems;
            }

            $this->bitrixClient->call('userfieldconfig.add', [
                'moduleId' => 'crm',
                'field'    => $fieldPayload,
            ]);

            $this->logger->info("Successfully created field '{$bitrixName}'.");

            // Pause slightly between field creations to respect Bitrix rate limits
            usleep(600000);
        }

        // 4. Call crm.item.fields once and resolve final field codes
        $this->logger->info("Calling crm.item.fields to resolve camelCase field codes and enum IDs...");
        $fieldsResponse = $this->bitrixClient->call('crm.item.fields', [
            'entityTypeId' => $this->entityTypeId,
        ]);

        $crmFields = $fieldsResponse['result']['fields'] ?? [];
        $resolvedFieldMap = [];

        foreach ($fieldDefinitions as $def) {
            $key = (string)$def['key'];
            $bitrixName = (string)$def['bitrix_name'];
            $type = (string)$def['type'];

            $resolvedCode = $this->resolveFieldCode($bitrixName, $crmFields);

            $fieldMeta = [
                'bitrix_code' => $resolvedCode,
                'type'        => $type,
                'multiple'    => !empty($def['multiple']),
            ];

            // Resolve enum IDs for enumeration fields
            if ($type === 'enumeration') {
                $fieldMeta['enum_map'] = $this->resolveEnumMap($resolvedCode, $crmFields);
            }

            $resolvedFieldMap[$key] = $fieldMeta;
            $this->logger->info("Mapped '{$key}' => code: '{$resolvedCode}' (type: {$type})");
        }

        // 5. Write storage/field_map.json
        $mapData = [
            'entityTypeId' => $this->entityTypeId,
            'fields_hash'  => $fieldsHash,
            'created_at'   => date('c'),
            'fields'       => $resolvedFieldMap,
        ];

        $storageDir = dirname($fieldMapPath);
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $jsonOutput = json_encode($mapData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($jsonOutput === false || file_put_contents($fieldMapPath, $jsonOutput) === false) {
            throw new RuntimeException("Failed to write field map cache to: {$fieldMapPath}");
        }

        $this->logger->info("Field provisioning completed successfully. Cache written to: {$fieldMapPath}");

        return $mapData;
    }

    /**
     * Resolves the Bitrix API field code for a given bitrix_name.
     *
     * Handles camelCase ufCrm{spaId}{CamelName}, ufCrm{entityTypeId}_{NAME}, etc.
     *
     * @param string $bitrixName e.g. "REELLY_ID"
     * @param array<string, mixed> $crmFields Fields array from crm.item.fields
     * @return string
     */
    public function resolveFieldCode(string $bitrixName, array $crmFields): string
    {
        $spaId = $this->getSpaId();
        $cleanBitrix = str_replace('_', '', strtolower($bitrixName));
        $expectedCleanSpa = "ufcrm{$spaId}{$cleanBitrix}";
        $expectedCleanType = "ufcrm{$this->entityTypeId}{$cleanBitrix}";

        // Direct check
        $expectedCamelSpa = "ufCrm{$spaId}_{$bitrixName}";
        if (isset($crmFields[$expectedCamelSpa])) {
            return $expectedCamelSpa;
        }

        $expectedCamelType = "ufCrm{$this->entityTypeId}_{$bitrixName}";
        if (isset($crmFields[$expectedCamelType])) {
            return $expectedCamelType;
        }

        // Clean match check (handles Bitrix's ufCrm37ReellyId / ufCrm37TestKey camelCase)
        foreach (array_keys($crmFields) as $code) {
            $codeStr = (string)$code;
            $cleanCode = str_replace('_', '', strtolower($codeStr));

            if ($cleanCode === $expectedCleanSpa || $cleanCode === $expectedCleanType) {
                return $codeStr;
            }

            if (str_ends_with($cleanCode, $cleanBitrix)) {
                return $codeStr;
            }
        }

        // Fallback default format used by crm.item.*
        return "ufCrm{$spaId}_{$bitrixName}";
    }

    /**
     * Extracts enum value => id mapping from crm.item.fields metadata.
     *
     * @param string $fieldCode
     * @param array<string, mixed> $crmFields
     * @return array<string, int>
     */
    private function resolveEnumMap(string $fieldCode, array $crmFields): array
    {
        $enumMap = [];
        $fieldInfo = $crmFields[$fieldCode] ?? [];
        $items = $fieldInfo['items'] ?? [];

        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = (int)($item['ID'] ?? $item['id'] ?? 0);
                $value = (string)($item['VALUE'] ?? $item['value'] ?? '');

                if ($id > 0 && $value !== '') {
                    $enumMap[strtolower($value)] = $id;
                }
            }
        }

        return $enumMap;
    }

    /**
     * Constructs simulated field map for --dry-run when cache does not exist.
     *
     * @param array<int, array<string, mixed>> $fieldDefinitions
     * @param string $fieldsHash
     * @return array<string, mixed>
     */
    private function buildDryRunMap(array $fieldDefinitions, string $fieldsHash): array
    {
        $fields = [];
        foreach ($fieldDefinitions as $def) {
            $key = (string)$def['key'];
            $bitrixName = (string)$def['bitrix_name'];
            $type = (string)$def['type'];
            $fields[$key] = [
                'bitrix_code' => "ufCrm{$this->entityTypeId}_{$bitrixName}",
                'type'        => $type,
                'multiple'    => !empty($def['multiple']),
                'enum_map'    => [],
            ];
        }

        return [
            'entityTypeId' => $this->entityTypeId,
            'fields_hash'  => $fieldsHash,
            'created_at'   => date('c'),
            'fields'       => $fields,
        ];
    }
}

