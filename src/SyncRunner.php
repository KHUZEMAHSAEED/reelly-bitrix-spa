<?php

declare(strict_types=1);

namespace App;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class SyncRunner
{
    private array $config;
    private array $fieldDefinitions;
    private string $fieldsFilePath;
    private LoggerInterface $logger;
    private BitrixClient $bitrixClient;
    private ReellyClient $reellyClient;
    private FieldProvisioner $provisioner;
    private ImageService $imageService;

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        array $config,
        array $fieldDefinitions,
        string $fieldsFilePath,
        LoggerInterface $logger,
        ?BitrixClient $bitrixClient = null,
        ?ReellyClient $reellyClient = null,
        ?FieldProvisioner $provisioner = null,
        ?ImageService $imageService = null
    ) {
        $this->config = $config;
        $this->fieldDefinitions = $fieldDefinitions;
        $this->fieldsFilePath = $fieldsFilePath;
        $this->logger = $logger;

        $bitrixConfig = $config['bitrix'] ?? [];
        $reellyConfig = $config['reelly'] ?? [];
        $awsConfig    = $config['aws'] ?? [];
        $imageConfig  = $config['images'] ?? [];

        $this->bitrixClient = $bitrixClient ?? new BitrixClient(
            (string)($bitrixConfig['webhook_url'] ?? ''),
            (int)($bitrixConfig['rate_limit_delay_ms'] ?? 500),
            (int)($bitrixConfig['timeout'] ?? 60),
            (int)($bitrixConfig['max_retries'] ?? 5),
            $this->logger
        );

        $this->reellyClient = $reellyClient ?? new ReellyClient(
            (string)($reellyConfig['base_url'] ?? ''),
            (string)($reellyConfig['api_key'] ?? ''),
            (string)($reellyConfig['auth_header'] ?? 'X-API-Key'),
            (int)($reellyConfig['page_size'] ?? 20),
            (int)($reellyConfig['timeout'] ?? 30),
            (int)($reellyConfig['max_retries'] ?? 3),
            $this->logger
        );

        $entityTypeId = (int)($bitrixConfig['entity_type_id'] ?? 0);

        $this->provisioner = $provisioner ?? new FieldProvisioner(
            $this->bitrixClient,
            $entityTypeId,
            $this->logger
        );

        $this->imageService = $imageService ?? new ImageService(
            $this->bitrixClient,
            $awsConfig,
            $imageConfig,
            $this->logger
        );
    }

    /**
     * Executes the sync workflow.
     *
     * @param bool $dryRun
     * @param int|null $limit
     * @param bool $refreshFields
     * @return int CLI Exit code (0 = success, 1 = failure)
     */
    public function run(bool $dryRun = false, ?int $limit = null, bool $refreshFields = false): int
    {
        $startTime = microtime(true);
        $syncConfig = $this->config['sync'] ?? [];
        $lockFilePath = (string)($syncConfig['lock_file_path'] ?? dirname(__DIR__) . '/storage/sync.lock');
        $fieldMapPath = (string)($syncConfig['field_map_path'] ?? dirname(__DIR__) . '/storage/field_map.json');
        $entityTypeId = (int)($this->config['bitrix']['entity_type_id'] ?? 0);

        if ($entityTypeId <= 0) {
            $this->logger->error("Invalid or missing BITRIX_SPA_ENTITY_TYPE_ID in configuration.");
            return 1;
        }

        // 1. Acquire flock-based lock
        if (!$this->acquireLock($lockFilePath)) {
            $this->logger->info("Another sync process is currently running. Exiting cleanly.");
            return 0;
        }

        try {
            // 2. Handle --refresh-fields flag
            if ($refreshFields) {
                $this->logger->info("Received --refresh-fields flag. Deleting cached field map and re-provisioning...");
                if (file_exists($fieldMapPath)) {
                    unlink($fieldMapPath);
                }
                $this->provisioner->provision($this->fieldDefinitions, $this->fieldsFilePath, $fieldMapPath, $dryRun);
                $this->logger->info("Field refresh completed successfully. Exiting.");
                return 0;
            }

            // 3. Field Provisioning Check (First run vs normal cron run)
            $fieldMapData = null;

            if (!$this->provisioner->isCachePresent($fieldMapPath)) {
                $this->logger->info("Field cache not found (first run detected). Provisioning SPA custom fields...");
                $fieldMapData = $this->provisioner->provision(
                    $this->fieldDefinitions,
                    $this->fieldsFilePath,
                    $fieldMapPath,
                    $dryRun
                );
            } else {
                // Regular run: load cache, do NOT query field metadata
                $fieldMapData = $this->provisioner->loadAndValidateCache($fieldMapPath, $this->fieldsFilePath);
                $this->logger->info("Loaded verified field cache from disk. No Bitrix field configuration calls needed.");
            }

            $fieldMap = $fieldMapData['fields'] ?? [];
            $mapper = new Mapper($this->fieldDefinitions, $fieldMap, $this->logger);

            // 4. Load existing SPA items from Bitrix (Bitrix is the single source of truth)
            $this->logger->info("Loading existing SPA items from Bitrix24 (crm.item.list)...");
            $webhookUrl = (string)($this->config['bitrix']['webhook_url'] ?? '');
            $isPlaceholderBitrix = empty($webhookUrl) || str_contains($webhookUrl, 'your-domain.bitrix24.com') || str_contains($webhookUrl, 'your_webhook_token');

            if ($dryRun && $isPlaceholderBitrix) {
                $this->logger->info("[DRY-RUN] BITRIX_WEBHOOK_URL is a placeholder. Simulating empty existing items in Bitrix.");
                $spaMap = [];
            } else {
                $spaMap = $this->loadExistingSpaItems($entityTypeId, $mapper);
            }
            $this->logger->info("Loaded " . count($spaMap) . " existing unique property listings from Bitrix24.");

            // 5. Fetch listings from Reelly API
            $this->logger->info("Fetching listings from Reelly API" . ($limit ? " (limit: {$limit})" : "") . "...");
            $reellyApiKey = (string)($this->config['reelly']['api_key'] ?? '');
            $isPlaceholderReelly = empty($reellyApiKey) || str_contains($reellyApiKey, 'your_reelly_api_key');
            $reellyListings = [];

            if ($dryRun && $isPlaceholderReelly) {
                $this->logger->info("[DRY-RUN] REELLY_API_KEY is a placeholder. Simulating sample property listings.");
                $reellyListings = $this->getMockReellyListings($limit ?? 5);
            } else {
                try {
                    foreach ($this->reellyClient->fetchListings($limit) as $listing) {
                        $reellyListings[] = $listing;
                    }
                } catch (Throwable $e) {
                    $this->logger->error("Failed to fetch listings from Reelly API: " . $e->getMessage());
                    return 1;
                }
            }

            $totalFetched = count($reellyListings);
            $this->logger->info("Total Reelly listings retrieved: {$totalFetched}");

            // 6. Process Upserts (Add / Update / Skip)
            $stats = [
                'created'         => 0,
                'updated'         => 0,
                'skipped'         => 0,
                'marked_removed'  => 0,
                'failed'          => 0,
                'images_uploaded' => 0,
            ];

            $seenReellyIds = [];
            $batchCommands = []; // cmdKey => ['method' => '...', 'params' => [...]]
            $pendingAddMeta = []; // cmdKey => ['reelly_id' => ..., 'listing' => ...]
            $pendingUpdateMeta = []; // cmdKey => ['reelly_id' => ..., 'bitrix_id' => ..., 'listing' => ...]

            $reellyIdCode = $mapper->getBitrixCode('reelly_id');
            $syncHashCode = $mapper->getBitrixCode('sync_hash');
            $s3LinksCode  = $mapper->getBitrixCode('s3_image_links');
            $bitrixFileCode = $mapper->getBitrixCode('bitrix_images');

            foreach ($reellyListings as $index => $listing) {
                try {
                    $rawReellyId = $mapper->extractValue($listing, 'id');
                    if ($rawReellyId === null || $rawReellyId === '') {
                        $this->logger->error("Listing at index {$index} has no valid ID. Skipping.");
                        $stats['failed']++;
                        continue;
                    }

                    $reellyId = (string)$rawReellyId;
                    $seenReellyIds[] = $reellyId;

                    $normalizedFields = $mapper->mapListing($listing, false);
                    $newHash = $mapper->computePayloadHash($normalizedFields);

                    // Case A: Listing not yet in Bitrix -> crm.item.add
                    if (!isset($spaMap[$reellyId])) {
                        $addFields = $normalizedFields;
                        if ($reellyIdCode !== null) {
                            $addFields[$reellyIdCode] = $reellyId;
                        }
                        if ($syncHashCode !== null) {
                            $addFields[$syncHashCode] = $newHash;
                        }

                        // Standard SPA fields
                        $rawTitle = $mapper->extractValue($listing, 'name') ?? "Property {$reellyId}";
                        $addFields['title'] = (string)$rawTitle;
                        $addFields['stageId'] = (string)($this->config['bitrix']['stage_active'] ?? 'DT1032_NEW');
                        $addFields['assignedById'] = (int)($this->config['bitrix']['assigned_by_id'] ?? 1);

                        $cmdKey = "add_{$reellyId}";
                        $batchCommands[$cmdKey] = [
                            'method' => 'crm.item.add',
                            'params' => [
                                'entityTypeId' => $entityTypeId,
                                'fields'       => $addFields,
                            ],
                        ];
                        $pendingAddMeta[$cmdKey] = [
                            'reelly_id' => $reellyId,
                            'hash'      => $newHash,
                            'listing'   => $listing,
                        ];

                    } else {
                        // Case B: Listing exists in Bitrix
                        $existingItem = $spaMap[$reellyId];
                        $existingHash = $existingItem['hash'];
                        $bitrixId     = (int)$existingItem['bitrix_id'];

                        // Hash unchanged -> skip text update
                        if ($existingHash !== '' && $existingHash === $newHash) {
                            $stats['skipped']++;

                            // Still evaluate images if S3 links differ
                            $uploadedCount = $this->imageService->syncListingImages(
                                $reellyId,
                                $bitrixId,
                                $entityTypeId,
                                $this->extractListingImages($listing),
                                $existingItem['s3_links'] ?? null,
                                $s3LinksCode,
                                $bitrixFileCode,
                                $dryRun
                            );
                            $stats['images_uploaded'] += $uploadedCount;

                        } else {
                            // Hash changed -> crm.item.update with reelly_owned fields only
                            $updateFields = $mapper->mapListing($listing, true);
                            if ($reellyIdCode !== null) {
                                $updateFields[$reellyIdCode] = $reellyId;
                            }
                            if ($syncHashCode !== null) {
                                $updateFields[$syncHashCode] = $newHash;
                            }

                            $cmdKey = "update_{$reellyId}";
                            $batchCommands[$cmdKey] = [
                                'method' => 'crm.item.update',
                                'params' => [
                                    'entityTypeId' => $entityTypeId,
                                    'id'           => $bitrixId,
                                    'fields'       => $updateFields,
                                ],
                            ];
                            $pendingUpdateMeta[$cmdKey] = [
                                'reelly_id' => $reellyId,
                                'bitrix_id' => $bitrixId,
                                'hash'      => $newHash,
                                'listing'   => $listing,
                            ];
                        }
                    }

                } catch (Throwable $e) {
                    $this->logger->error("Error preparing listing '{$index}': " . $e->getMessage(), [
                        'exception' => $e,
                    ]);
                    $stats['failed']++;
                }
            }

            // Execute batched adds and updates
            if (!empty($batchCommands)) {
                $this->logger->info("Executing " . count($batchCommands) . " batch command(s) against Bitrix24...");

                if ($dryRun) {
                    $this->logger->info("[DRY-RUN] Simulating batch execution of adds and updates.");
                    foreach ($pendingAddMeta as $meta) {
                        $stats['created']++;
                    }
                    foreach ($pendingUpdateMeta as $meta) {
                        $stats['updated']++;
                    }
                } else {
                    $batchResult = $this->bitrixClient->batch($batchCommands, false);
                    $results = $batchResult['result'] ?? [];
                    $errors  = $batchResult['result_error'] ?? [];

                    // Process add results
                    foreach ($pendingAddMeta as $cmdKey => $meta) {
                        if (isset($errors[$cmdKey])) {
                            $this->logger->error("Failed to add listing '{$meta['reelly_id']}': " . json_encode($errors[$cmdKey]));
                            $stats['failed']++;
                        } else {
                            $createdId = (int)($results[$cmdKey]['item']['id'] ?? $results[$cmdKey]['id'] ?? 0);
                            $stats['created']++;

                            // Update in-memory state
                            $spaMap[$meta['reelly_id']] = [
                                'bitrix_id' => $createdId,
                                'hash'      => $meta['hash'],
                                's3_links'  => [],
                                'stage'     => (string)($this->config['bitrix']['stage_active'] ?? 'DT1032_NEW'),
                            ];

                            // Process images for newly created listing
                            if ($createdId > 0) {
                                $uploadedCount = $this->imageService->syncListingImages(
                                    $meta['reelly_id'],
                                    $createdId,
                                    $entityTypeId,
                                    $this->extractListingImages($meta['listing']),
                                    null,
                                    $s3LinksCode,
                                    $bitrixFileCode,
                                    $dryRun
                                );
                                $stats['images_uploaded'] += $uploadedCount;
                            }
                        }
                    }

                    // Process update results
                    foreach ($pendingUpdateMeta as $cmdKey => $meta) {
                        if (isset($errors[$cmdKey])) {
                            $this->logger->error("Failed to update listing '{$meta['reelly_id']}': " . json_encode($errors[$cmdKey]));
                            $stats['failed']++;
                        } else {
                            $stats['updated']++;
                            $bitrixId = $meta['bitrix_id'];

                            // Process images for updated listing
                            $uploadedCount = $this->imageService->syncListingImages(
                                $meta['reelly_id'],
                                $bitrixId,
                                $entityTypeId,
                                $this->extractListingImages($meta['listing']),
                                $spaMap[$meta['reelly_id']]['s3_links'] ?? null,
                                $s3LinksCode,
                                $bitrixFileCode,
                                $dryRun
                            );
                            $stats['images_uploaded'] += $uploadedCount;
                        }
                    }
                }
            }

            // 7. Handle Removed Listings (Only during full sync, never when --limit is supplied)
            if ($limit === null) {
                $removedCount = $this->handleRemovedListings(
                    $spaMap,
                    $seenReellyIds,
                    $totalFetched,
                    $entityTypeId,
                    $dryRun
                );
                $stats['marked_removed'] = $removedCount;
            } else {
                $this->logger->info("Partial run (--limit={$limit}); skipping removal check to avoid marking un-fetched items as removed.");
            }

            // 8. Log summary metrics
            $duration = round(microtime(true) - $startTime, 2);
            $this->logger->info("=================== SYNC SUMMARY ===================");
            $this->logger->info("Duration:        {$duration}s");
            $this->logger->info("Created:         {$stats['created']}");
            $this->logger->info("Updated:         {$stats['updated']}");
            $this->logger->info("Skipped:         {$stats['skipped']}");
            $this->logger->info("Marked Removed:  {$stats['marked_removed']}");
            $this->logger->info("Failed:          {$stats['failed']}");
            $this->logger->info("Images Uploaded: {$stats['images_uploaded']}");
            $this->logger->info("====================================================");

            return ($stats['failed'] > 0) ? 1 : 0;

        } catch (Throwable $e) {
            $this->logger->critical("Fatal error occurred during sync execution: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return 1;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Loads existing SPA items from Bitrix via crm.item.list with full pagination.
     *
     * @param int $entityTypeId
     * @param Mapper $mapper
     * @return array<string, array{bitrix_id: int, hash: string, s3_links: mixed, stage: string}> Keyed by reelly_id
     */
    private function loadExistingSpaItems(int $entityTypeId, Mapper $mapper): array
    {
        $spaMap = [];
        $reellyIdCode = $mapper->getBitrixCode('reelly_id');
        $syncHashCode = $mapper->getBitrixCode('sync_hash');
        $s3LinksCode  = $mapper->getBitrixCode('s3_image_links');

        $select = ['id', 'stageId', 'title'];
        if ($reellyIdCode) {
            $select[] = $reellyIdCode;
        }
        if ($syncHashCode) {
            $select[] = $syncHashCode;
        }
        if ($s3LinksCode) {
            $select[] = $s3LinksCode;
        }

        $start = 0;
        $page = 1;

        while (true) {
            $response = $this->bitrixClient->call('crm.item.list', [
                'entityTypeId' => $entityTypeId,
                'select'       => $select,
                'start'        => $start,
            ]);

            $items = $response['result']['items'] ?? [];
            if (!is_array($items) || empty($items)) {
                break;
            }

            foreach ($items as $item) {
                $bitrixId = (int)($item['id'] ?? 0);
                if ($bitrixId <= 0) {
                    continue;
                }

                $rawReellyId = $reellyIdCode ? ($item[$reellyIdCode] ?? null) : null;
                if ($rawReellyId === null || $rawReellyId === '') {
                    continue;
                }

                $reellyId = (string)$rawReellyId;
                $hash = (string)($syncHashCode ? ($item[$syncHashCode] ?? '') : '');
                $s3Links = $s3LinksCode ? ($item[$s3LinksCode] ?? null) : null;
                $stage = (string)($item['stageId'] ?? '');

                // Duplicate Reelly ID check: log warning and use lowest Bitrix ID
                if (isset($spaMap[$reellyId])) {
                    $existingBitrixId = $spaMap[$reellyId]['bitrix_id'];
                    $this->logger->warning(
                        "Duplicate Reelly ID detected in Bitrix! Reelly ID: '{$reellyId}'. Existing Bitrix ID: {$existingBitrixId}, New Bitrix ID: {$bitrixId}. Retaining lowest Bitrix ID."
                    );
                    if ($bitrixId < $existingBitrixId) {
                        $spaMap[$reellyId] = [
                            'bitrix_id' => $bitrixId,
                            'hash'      => $hash,
                            's3_links'  => $s3Links,
                            'stage'     => $stage,
                        ];
                    }
                } else {
                    $spaMap[$reellyId] = [
                        'bitrix_id' => $bitrixId,
                        'hash'      => $hash,
                        's3_links'  => $s3Links,
                        'stage'     => $stage,
                    ];
                }
            }

            if (isset($response['next']) && (int)$response['next'] > 0) {
                $start = (int)$response['next'];
                $page++;
            } else {
                break;
            }
        }

        return $spaMap;
    }

    /**
     * Marks listings absent from Reelly API response as removed.
     *
     * Aborts with warning if:
     * - Reelly returned 0 listings
     * - More than removal_threshold_percent (default 20%) would be marked removed
     *
     * @param array<string, array<string, mixed>> $spaMap
     * @param array<int, string> $seenReellyIds
     * @param int $totalFetchedFromReelly
     * @param int $entityTypeId
     * @param bool $dryRun
     * @return int Number of items marked removed
     */
    private function handleRemovedListings(
        array $spaMap,
        array $seenReellyIds,
        int $totalFetchedFromReelly,
        int $entityTypeId,
        bool $dryRun = false
    ): int {
        $totalBitrixItems = count($spaMap);
        if ($totalBitrixItems === 0) {
            return 0;
        }

        // Safety Guard 1: Zero listings returned from Reelly
        if ($totalFetchedFromReelly === 0) {
            $this->logger->warning("Reelly API returned 0 listings. Aborting removal step to protect against data loss.");
            return 0;
        }

        $seenLookup = array_flip($seenReellyIds);
        $removedStage = (string)($this->config['bitrix']['stage_removed'] ?? 'DT1032_FAIL');
        $missingItems = [];

        foreach ($spaMap as $reellyId => $item) {
            if (!isset($seenLookup[$reellyId])) {
                // If item is already in removed stage, no need to update again
                if ($item['stage'] !== $removedStage) {
                    $missingItems[$reellyId] = $item['bitrix_id'];
                }
            }
        }

        $missingCount = count($missingItems);
        if ($missingCount === 0) {
            $this->logger->info("No removed or absent listings found.");
            return 0;
        }

        // Safety Guard 2: Exceeding removal percentage threshold
        $thresholdPercent = (float)($this->config['sync']['removal_threshold_percent'] ?? 20.0);
        $removalPercent = ($missingCount / $totalBitrixItems) * 100.0;

        if ($removalPercent > $thresholdPercent) {
            $this->logger->warning(
                "Safety guard triggered: {$missingCount}/{$totalBitrixItems} listings (" . round($removalPercent, 1) . "%) would be marked removed, which exceeds the {$thresholdPercent}% threshold. Aborting removal step."
            );
            return 0;
        }

        $this->logger->info("Marking {$missingCount} listing(s) as removed (stage: {$removedStage})...");

        if ($dryRun) {
            $this->logger->info("[DRY-RUN] Would mark {$missingCount} items as removed in Bitrix.");
            return $missingCount;
        }

        $removeCommands = [];
        foreach ($missingItems as $reellyId => $bitrixId) {
            $removeCommands["remove_{$reellyId}"] = [
                'method' => 'crm.item.update',
                'params' => [
                    'entityTypeId' => $entityTypeId,
                    'id'           => $bitrixId,
                    'fields'       => [
                        'stageId' => $removedStage,
                    ],
                ],
            ];
        }

        $batchResult = $this->bitrixClient->batch($removeCommands, false);
        $errors = $batchResult['result_error'] ?? [];
        $successCount = count($removeCommands) - count($errors);

        if (!empty($errors)) {
            $this->logger->warning("Some listings failed to update to removed stage: " . json_encode($errors));
        }

        return max(0, $successCount);
    }

    /**
     * Acquires non-blocking exclusive file lock using flock.
     */
    private function acquireLock(string $lockFilePath): bool
    {
        $dir = dirname($lockFilePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($lockFilePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Failed to open lock file: {$lockFilePath}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        $this->lockHandle = $handle;
        ftruncate($handle, 0);
        fwrite($handle, (string)getmypid());
        fflush($handle);

        register_shutdown_function([$this, 'releaseLock']);

        return true;
    }

    /**
     * Releases file lock.
     */
    public function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * Extracts all available image URLs from a Reelly listing payload.
     *
     * @param array<string, mixed> $listing
     * @return array<int, string>
     */
    private function extractListingImages(array $listing): array
    {
        $images = [];

        if (!empty($listing['cover_image']['url'])) {
            $images[] = (string)$listing['cover_image']['url'];
        }

        if (!empty($listing['media']['gallery']) && is_array($listing['media']['gallery'])) {
            foreach ($listing['media']['gallery'] as $img) {
                $url = is_array($img) ? ($img['url'] ?? '') : (string)$img;
                if (!empty($url)) {
                    $images[] = $url;
                }
            }
        }

        if (!empty($listing['photos']) && is_array($listing['photos'])) {
            foreach ($listing['photos'] as $p) {
                $url = is_array($p) ? ($p['url'] ?? '') : (string)$p;
                if (!empty($url)) {
                    $images[] = $url;
                }
            }
        }

        return array_values(array_unique(array_filter($images)));
    }

    /**
     * Generates sample mock listings for dry-run simulation when placeholder credentials are used.
     *
     * @param int $count
     * @return array<int, array<string, mixed>>
     */
    private function getMockReellyListings(int $count): array
    {
        $mockProjects = [
            [
                'id' => 1127,
                'name' => 'Muraba Veil',
                'developer' => 'Muraba Properties',
                'construction_status' => 'under_construction',
                'sale_status' => 'out_of_stock',
                'min_price' => 12576212.00,
                'max_price' => 72873192.00,
                'price_currency' => 'AED',
                'min_size' => 3838.01,
                'max_size' => 9903.07,
                'area_unit' => 'sqft',
                'bedrooms' => 3,
                'bathrooms' => 4,
                'completion_date' => '2028-12-31',
                'description' => 'Architectural sanctuary along the Dubai Canal.',
                'location' => [
                    'country' => 'United Arab Emirates',
                    'city' => 'Dubai',
                    'community' => 'Dubai Canal',
                    'latitude' => 25.1972,
                    'longitude' => 55.2744,
                ],
                'payment_plan' => [
                    'down_payment_percent' => 20.0,
                ],
                'media' => [
                    'gallery' => [
                        'https://cdn.reelly.ai/images/proj_1127_1.jpg',
                        'https://cdn.reelly.ai/images/proj_1127_2.jpg',
                    ],
                ],
            ],
            [
                'id' => 1128,
                'name' => 'Emaar Beachfront Tower 3',
                'developer' => 'Emaar Properties',
                'construction_status' => 'off_plan',
                'sale_status' => 'on_sale',
                'min_price' => 3200000.00,
                'max_price' => 9500000.00,
                'price_currency' => 'AED',
                'min_size' => 1150.00,
                'max_size' => 2800.00,
                'area_unit' => 'sqft',
                'bedrooms' => 2,
                'bathrooms' => 3,
                'completion_date' => '2027-06-30',
                'description' => 'Luxury waterfront residences at Dubai Harbour.',
                'location' => [
                    'country' => 'United Arab Emirates',
                    'city' => 'Dubai',
                    'community' => 'Dubai Harbour',
                    'latitude' => 25.0921,
                    'longitude' => 55.1487,
                ],
                'payment_plan' => [
                    'down_payment_percent' => 10.0,
                ],
                'media' => [
                    'gallery' => [
                        'https://cdn.reelly.ai/images/proj_1128_1.jpg',
                    ],
                ],
            ],
        ];

        return array_slice($mockProjects, 0, $count);
    }
}
