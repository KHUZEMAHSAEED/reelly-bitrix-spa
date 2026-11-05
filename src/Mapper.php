<?php

declare(strict_types=1);

namespace App;

use DateTimeInterface;
use DateTimeImmutable;
use Exception;
use Psr\Log\LoggerInterface;

class Mapper
{
    /** @var array<int, array<string, mixed>> */
    private array $fieldDefinitions;

    /** @var array<string, array{bitrix_code: string, type: string, multiple?: bool, enum_map?: array<string, int>}> */
    private array $fieldMap;

    private LoggerInterface $logger;

    /**
     * @param array<int, array<string, mixed>> $fieldDefinitions Loaded from fields.php
     * @param array<string, array{bitrix_code: string, type: string, multiple?: bool, enum_map?: array<string, int>}> $fieldMap Loaded strictly from storage/field_map.json
     * @param LoggerInterface $logger
     */
    public function __construct(
        array $fieldDefinitions,
        array $fieldMap,
        LoggerInterface $logger
    ) {
        $this->fieldDefinitions = $fieldDefinitions;
        $this->fieldMap = $fieldMap;
        $this->logger = $logger;
    }

    /**
     * Maps a Reelly listing payload to Bitrix24 SPA fields.
     *
     * @param array<string, mixed> $reellyListing
     * @param bool $reellyOwnedOnly If true, includes only reelly_owned: true fields (used for updates)
     * @return array<string, mixed> Keyed by resolved Bitrix field codes
     */
    public function mapListing(array $reellyListing, bool $reellyOwnedOnly = false): array
    {
        $result = [];

        foreach ($this->fieldDefinitions as $def) {
            $key = (string)$def['key'];
            $isReellyOwned = !empty($def['reelly_owned']);

            if ($reellyOwnedOnly && !$isReellyOwned) {
                continue;
            }

            // File fields (images) are handled separately by ImageService
            if (($def['type'] ?? '') === 'file') {
                continue;
            }

            $reellyPath = $def['reelly_path'] ?? null;
            if ($reellyPath === null) {
                // Computed/internal fields like sync_hash are added explicitly by runner
                continue;
            }

            $bitrixCode = $this->getBitrixCode($key);
            if ($bitrixCode === null) {
                continue;
            }

            $rawValue = $this->extractValue($reellyListing, (string)$reellyPath);
            $transformedValue = $this->transformValue($rawValue, $def);

            if ($transformedValue !== null) {
                $result[$bitrixCode] = $transformedValue;
            }
        }

        return $result;
    }

    /**
     * Computes deterministic SHA1 hash of normalized payload data.
     *
     * Excludes SYNC_HASH and file fields so hash accurately reflects listing state.
     *
     * @param array<string, mixed> $normalizedFields
     * @return string
     */
    public function computePayloadHash(array $normalizedFields): string
    {
        // Remove hash field itself if present
        $syncHashCode = $this->getBitrixCode('sync_hash');
        if ($syncHashCode !== null && array_key_exists($syncHashCode, $normalizedFields)) {
            unset($normalizedFields[$syncHashCode]);
        }

        // Exclude image file field if present
        $fileFieldCode = $this->getBitrixCode('bitrix_images');
        if ($fileFieldCode !== null && array_key_exists($fileFieldCode, $normalizedFields)) {
            unset($normalizedFields[$fileFieldCode]);
        }

        ksort($normalizedFields);

        $json = json_encode($normalizedFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return sha1($json !== false ? $json : serialize($normalizedFields));
    }

    /**
     * Resolves the Bitrix API field code for a given field key from field_map.json.
     */
    public function getBitrixCode(string $key): ?string
    {
        return $this->fieldMap[$key]['bitrix_code'] ?? null;
    }

    /**
     * Extracts a nested value from an array using dot notation (e.g. 'location.city').
     *
     * @param array<string, mixed> $data
     * @param string $path
     * @return mixed
     */
    public function extractValue(array $data, string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Applies configured transform and type casting to a raw value.
     *
     * @param mixed $rawValue
     * @param array<string, mixed> $fieldDef
     * @return mixed
     */
    private function transformValue(mixed $rawValue, array $fieldDef): mixed
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        $type = (string)($fieldDef['type'] ?? 'string');
        $transform = $fieldDef['transform'] ?? null;
        $val = $rawValue;

        // Apply transformer if specified
        if (is_callable($transform)) {
            $val = $transform($val);
        } elseif (is_string($transform)) {
            $val = match ($transform) {
                'uppercase' => is_string($val) ? strtoupper($val) : $val,
                'lowercase' => is_string($val) ? strtolower($val) : $val,
                'trim'      => is_string($val) ? trim($val) : $val,
                'int', 'integer' => is_numeric($val) ? (int)$val : null,
                'float', 'double' => is_numeric($val) ? (float)$val : null,
                'string'    => (string)$val,
                'country_name' => ($val == 219 || $val === 'United Arab Emirates') ? 'United Arab Emirates' : (string)$val,
                'date'      => $this->formatDate($val, 'Y-m-d'),
                'datetime'  => $this->formatDate($val, DateTimeInterface::ATOM),
                default     => $val,
            };
        }

        if ($val === null) {
            return null;
        }

        // Cast to required Bitrix type
        return match ($type) {
            'string', 'url' => is_array($val) ? $val : (string)$val,
            'integer'       => is_numeric($val) ? (int)$val : null,
            'double'        => is_numeric($val) ? (float)$val : null,
            'boolean'       => !empty($val) ? 'Y' : 'N',
            'date'          => $this->formatDate($val, 'Y-m-d'),
            'datetime'      => $this->formatDate($val, DateTimeInterface::ATOM),
            'enumeration'   => $this->resolveEnumValue($val, $fieldDef),
            default         => $val,
        };
    }

    /**
     * Formats date / timestamp to target date format.
     */
    private function formatDate(mixed $value, string $format): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            if ($value instanceof DateTimeInterface) {
                return $value->format($format);
            }

            if (is_numeric($value)) {
                $dt = (new DateTimeImmutable())->setTimestamp((int)$value);
                return $dt->format($format);
            }

            if (is_string($value)) {
                if ($format === 'Y-m-d' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches)) {
                    return $matches[1];
                }
                $dt = new DateTimeImmutable($value);
                return $dt->format($format);
            }
        } catch (Exception $e) {
            $this->logger->warning("Failed to parse date value '{$value}': " . $e->getMessage());
        }

        return null;
    }

    /**
     * Resolves an enumeration string value to Bitrix enum ID.
     *
     * If value is not configured in the enum list, logs a warning and returns null.
     */
    private function resolveEnumValue(mixed $val, array $fieldDef): ?int
    {
        $key = (string)$fieldDef['key'];
        $valStr = strtolower(trim((string)$val));
        $allowedEnums = array_map('strtolower', (array)($fieldDef['enum_values'] ?? []));

        // Check if value is in defined enum choices
        if (!in_array($valStr, $allowedEnums, true)) {
            $this->logger->warning(
                "Enumeration value '{$val}' for field '{$key}' is not in defined enum_values. Leaving field empty."
            );
            return null;
        }

        // Look up Bitrix enum ID from field_map
        $enumMap = $this->fieldMap[$key]['enum_map'] ?? [];
        if (isset($enumMap[$valStr])) {
            return (int)$enumMap[$valStr];
        }

        // If enum ID mapping not cached, return null
        return null;
    }
}

