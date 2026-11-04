<?php

declare(strict_types=1);

namespace App;

use Aws\S3\S3Client;
use Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Throwable;

class ImageService
{
    private ?S3Client $s3Client;
    private GuzzleClient $httpClient;
    private BitrixClient $bitrixClient;
    private string $bucket;
    private ?string $s3PublicBaseUrl;
    private int $maxImagesPerListing;
    private int $maxWidth;
    private int $jpegQuality;
    private int $downloadTimeout;
    private int $maxFileSize;
    private LoggerInterface $logger;

    public function __construct(
        BitrixClient $bitrixClient,
        array $awsConfig,
        array $imageConfig,
        LoggerInterface $logger,
        ?S3Client $s3Client = null,
        ?GuzzleClient $httpClient = null
    ) {
        $this->bitrixClient = $bitrixClient;
        $this->logger = $logger;

        $this->bucket = (string)($awsConfig['bucket'] ?? '');
        $this->s3PublicBaseUrl = !empty($awsConfig['s3_public_base_url']) ? rtrim((string)$awsConfig['s3_public_base_url'], '/') : null;
        $this->maxImagesPerListing = max(1, (int)($imageConfig['max_per_listing'] ?? 10));
        $this->maxWidth = max(100, (int)($imageConfig['max_width'] ?? 1600));
        $this->jpegQuality = min(100, max(10, (int)($imageConfig['jpeg_quality'] ?? 85)));
        $this->downloadTimeout = max(1, (int)($imageConfig['download_timeout'] ?? 15));
        $this->maxFileSize = max(1024, (int)($imageConfig['max_file_size'] ?? 10485760));

        $this->httpClient = $httpClient ?? new GuzzleClient([
            'timeout' => $this->downloadTimeout,
        ]);

        if ($s3Client !== null) {
            $this->s3Client = $s3Client;
        } elseif (!empty($awsConfig['access_key_id']) && !empty($awsConfig['secret_access_key']) && !empty($this->bucket)) {
            $s3Options = [
                'version'     => 'latest',
                'region'      => $awsConfig['region'] ?? 'us-east-1',
                'credentials' => [
                    'key'    => $awsConfig['access_key_id'],
                    'secret' => $awsConfig['secret_access_key'],
                ],
            ];

            if (!empty($awsConfig['endpoint'])) {
                $s3Options['endpoint'] = $awsConfig['endpoint'];
                $s3Options['use_path_style_endpoint'] = true;
            }

            $this->s3Client = new S3Client($s3Options);
        } else {
            $this->s3Client = null;
        }
    }

    /**
     * Checks if S3 client is configured and available.
     */
    public function isS3Configured(): bool
    {
        return $this->s3Client !== null && !empty($this->bucket);
    }

    /**
     * Synchronizes images for a single listing.
     *
     * @param string $listingId
     * @param int $bitrixId
     * @param int $entityTypeId
     * @param array<int, string> $sourceImageUrls
     * @param mixed $storedS3Links Current S3 links stored in Bitrix SPA item
     * @param string|null $s3LinksFieldCode Bitrix field code for S3 links
     * @param string|null $bitrixFileFieldCode Bitrix field code for file upload
     * @param bool $dryRun
     * @return int Number of images processed/uploaded
     */
    public function syncListingImages(
        string $listingId,
        int $bitrixId,
        int $entityTypeId,
        array $sourceImageUrls,
        mixed $storedS3Links,
        ?string $s3LinksFieldCode,
        ?string $bitrixFileFieldCode,
        bool $dryRun = false
    ): int {
        if (empty($sourceImageUrls)) {
            return 0;
        }

        // Cap images per listing
        $cappedUrls = array_slice(array_unique(array_filter($sourceImageUrls)), 0, $this->maxImagesPerListing);
        if (empty($cappedUrls)) {
            return 0;
        }

        // Compute deterministic S3 keys and URLs
        $expectedKeyMap = []; // source_url => s3_key
        $expectedUrls = [];

        foreach ($cappedUrls as $srcUrl) {
            $s3Key = "reelly/{$listingId}/" . sha1($srcUrl) . '.jpg';
            $expectedKeyMap[$srcUrl] = $s3Key;
            $expectedUrls[] = $this->buildS3Url($s3Key);
        }

        // Normalize stored S3 links for comparison
        $storedNormalized = $this->normalizeStoredUrls($storedS3Links);

        // Check if images already match exactly
        if ($expectedUrls === $storedNormalized) {
            $this->logger->info("Image URLs for listing '{$listingId}' match Bitrix SPA item. Skipping image processing.");
            return 0;
        }

        if ($dryRun) {
            $this->logger->info(
                "[DRY-RUN] Listing '{$listingId}' images differ. Would process " . count($cappedUrls) . " image(s) to S3 and update Bitrix."
            );
            return count($cappedUrls);
        }

        if (!$this->isS3Configured()) {
            $this->logger->warning("AWS S3 is not fully configured (missing credentials or bucket). Skipping image sync for listing '{$listingId}'.");
            return 0;
        }

        $processedImages = []; // list of ['key' => ..., 'bytes' => ...]
        $imagesUploaded = 0;

        try {
            foreach ($expectedKeyMap as $srcUrl => $s3Key) {
                $imageBytes = null;

                // Check if already in S3
                if ($this->s3Client->doesObjectExist($this->bucket, $s3Key)) {
                    $this->logger->debug("S3 object already exists: {$s3Key}");
                    // Fetch bytes from S3 for Bitrix file field
                    $s3Obj = $this->s3Client->getObject([
                        'Bucket' => $this->bucket,
                        'Key'    => $s3Key,
                    ]);
                    $imageBytes = (string)$s3Obj['Body']->getContents();
                } else {
                    // Download, resize/compress, and upload
                    $this->logger->info("Downloading and processing image: {$srcUrl}");
                    $rawBytes = $this->downloadImage($srcUrl);
                    $imageBytes = $this->resizeAndCompressImage($rawBytes);

                    $this->logger->info("Uploading to S3: {$s3Key}");
                    $this->s3Client->putObject([
                        'Bucket'      => $this->bucket,
                        'Key'         => $s3Key,
                        'Body'        => $imageBytes,
                        'ContentType' => 'image/jpeg',
                    ]);

                    $imagesUploaded++;
                }

                $processedImages[] = [
                    's3_key' => $s3Key,
                    'bytes'  => $imageBytes,
                ];
            }

            // Cleanup orphaned S3 objects under listing prefix
            $this->cleanOrphanedS3Objects($listingId, array_values($expectedKeyMap));

            // Update Bitrix SPA item with S3 links and base64 files
            $this->updateBitrixImages(
                $bitrixId,
                $entityTypeId,
                $expectedUrls,
                $processedImages,
                $s3LinksFieldCode,
                $bitrixFileFieldCode
            );

            return $imagesUploaded;

        } catch (Throwable $e) {
            // An image failure must not fail the whole listing:
            // Log it, leave S3 links field unchanged so next run retries
            $this->logger->error("Image sync failed for listing '{$listingId}': " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return 0;
        }
    }

    /**
     * Builds S3 public URL for a given key.
     */
    public function buildS3Url(string $key): string
    {
        if ($this->s3PublicBaseUrl !== null) {
            return $this->s3PublicBaseUrl . '/' . ltrim($key, '/');
        }

        if ($this->s3Client !== null && !empty($this->bucket)) {
            return $this->s3Client->getObjectUrl($this->bucket, $key);
        }

        return "https://{$this->bucket}.s3.amazonaws.com/{$key}";
    }

    /**
     * Downloads an image URL with timeout and max size limit.
     */
    private function downloadImage(string $url): string
    {
        try {
            $response = $this->httpClient->get($url, [
                'timeout' => $this->downloadTimeout,
                'headers' => [
                    'User-Agent' => 'ReellySync/1.0',
                ],
            ]);

            $contentLength = (int)$response->getHeaderLine('Content-Length');
            if ($contentLength > $this->maxFileSize) {
                throw new Exception("Image size ({$contentLength} bytes) exceeds limit ({$this->maxFileSize} bytes)");
            }

            $bytes = (string)$response->getBody();

            if (strlen($bytes) > $this->maxFileSize) {
                throw new Exception("Downloaded image size exceeds limit ({$this->maxFileSize} bytes)");
            }

            return $bytes;

        } catch (GuzzleException $e) {
            throw new Exception("Failed to download image from '{$url}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Resizes and compresses image to JPEG using GD.
     */
    private function resizeAndCompressImage(string $rawBytes): string
    {
        $img = @imagecreatefromstring($rawBytes);
        if ($img === false) {
            throw new Exception("Failed to decode image data into GD resource");
        }

        $origWidth = imagesx($img);
        $origHeight = imagesy($img);

        if ($origWidth > $this->maxWidth) {
            $newWidth = $this->maxWidth;
            $newHeight = (int)round(($origHeight / $origWidth) * $newWidth);

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if ($resized === false) {
                imagedestroy($img);
                throw new Exception("Failed to create truecolor GD image for resizing");
            }

            // Fill with white background for transparency handling in JPEG
            $white = imagecolorallocate($resized, 255, 255, 255);
            imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $white);

            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
            imagedestroy($img);
            $img = $resized;
        }

        ob_start();
        imagejpeg($img, null, $this->jpegQuality);
        $jpegBytes = (string)ob_get_clean();
        imagedestroy($img);

        if (empty($jpegBytes)) {
            throw new Exception("Failed to compress image to JPEG");
        }

        return $jpegBytes;
    }

    /**
     * Removes objects in S3 under reelly/{listingId}/ that are no longer in the expected list.
     *
     * @param string $listingId
     * @param array<int, string> $expectedKeys
     */
    private function cleanOrphanedS3Objects(string $listingId, array $expectedKeys): void
    {
        try {
            $prefix = "reelly/{$listingId}/";
            $objects = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
            ]);

            $contents = $objects['Contents'] ?? [];
            if (!is_array($contents)) {
                return;
            }

            foreach ($contents as $obj) {
                $key = (string)($obj['Key'] ?? '');
                if ($key !== '' && !in_array($key, $expectedKeys, true)) {
                    $this->logger->info("Deleting orphaned S3 object: {$key}");
                    $this->s3Client->deleteObject([
                        'Bucket' => $this->bucket,
                        'Key'    => $key,
                    ]);
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning("Failed to clean orphaned S3 objects for listing '{$listingId}': " . $e->getMessage());
        }
    }

    /**
     * Updates Bitrix SPA item with S3 links and base64 files.
     *
     * @param int $bitrixId
     * @param int $entityTypeId
     * @param array<int, string> $expectedUrls
     * @param array<int, array{s3_key: string, bytes: string}> $processedImages
     * @param string|null $s3LinksFieldCode
     * @param string|null $bitrixFileFieldCode
     */
    private function updateBitrixImages(
        int $bitrixId,
        int $entityTypeId,
        array $expectedUrls,
        array $processedImages,
        ?string $s3LinksFieldCode,
        ?string $bitrixFileFieldCode
    ): void {
        $updateFields = [];

        if ($s3LinksFieldCode !== null) {
            $updateFields[$s3LinksFieldCode] = $expectedUrls;
        }

        if ($bitrixFileFieldCode !== null && !empty($processedImages)) {
            if (count($processedImages) === 1) {
                $updateFields[$bitrixFileFieldCode] = [
                    'fileData' => [
                        basename($processedImages[0]['s3_key']),
                        base64_encode($processedImages[0]['bytes']),
                    ]
                ];
            } else {
                $bitrixFiles = [];
                foreach ($processedImages as $img) {
                    $bitrixFiles[] = [
                        'fileData' => [
                            basename($img['s3_key']),
                            base64_encode($img['bytes']),
                        ],
                    ];
                }
                $updateFields[$bitrixFileFieldCode] = $bitrixFiles;
            }
        }

        if (!empty($updateFields)) {
            $this->logger->info("Updating Bitrix SPA item {$bitrixId} with image links and files...");
            $this->bitrixClient->call('crm.item.update', [
                'entityTypeId' => $entityTypeId,
                'id'           => $bitrixId,
                'fields'       => $updateFields,
            ]);
        }
    }

    /**
     * Normalizes stored URL data into array of strings.
     */
    private function normalizeStoredUrls(mixed $stored): array
    {
        if (is_array($stored)) {
            return array_values(array_filter(array_map('strval', $stored)));
        }

        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map('strval', $decoded)));
            }
            return array_values(array_filter(preg_split('/[\r\n,]+/', $stored) ?: []));
        }

        return [];
    }
}

