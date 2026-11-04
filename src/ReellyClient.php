<?php

declare(strict_types=1);

namespace App;

use Generator;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ReellyClient
{
    private GuzzleClient $httpClient;
    private string $baseUrl;
    private string $apiKey;
    private string $authHeader;
    private int $pageSize;
    private int $maxRetries;
    private LoggerInterface $logger;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        string $authHeader,
        int $pageSize,
        int $timeout,
        int $maxRetries,
        LoggerInterface $logger,
        ?GuzzleClient $httpClient = null
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->authHeader = $authHeader;
        $this->pageSize = max(1, $pageSize);
        $this->maxRetries = max(1, $maxRetries);
        $this->logger = $logger;

        $headers = [
            'Accept' => 'application/json',
        ];

        if (!empty($this->apiKey)) {
            if (strcasecmp($this->authHeader, 'Authorization') === 0 && !str_starts_with($this->apiKey, 'Bearer ')) {
                $headers[$this->authHeader] = 'Bearer ' . $this->apiKey;
            } else {
                $headers[$this->authHeader] = $this->apiKey;
            }
        }

        $this->httpClient = $httpClient ?? new GuzzleClient([
            'timeout' => $timeout,
            'headers' => $headers,
        ]);
    }

    /**
     * Generator that streams property listings across paginated API requests.
     *
     * @param int|null $limit Maximum number of listings to fetch (null for all)
     * @return Generator<int, array<string, mixed>>
     */
    public function fetchListings(?int $limit = null): Generator
    {
        $offset = 0;
        $page = 1;
        $yieldedCount = 0;

        while (true) {
            $this->logger->info("Fetching Reelly listings (limit: {$this->pageSize}, offset: {$offset}, page: {$page})...");

            $queryParams = [
                'limit'  => $this->pageSize,
                'offset' => $offset,
            ];

            $response = $this->request('GET', '/projects', $queryParams);
            $items = $this->extractItemsFromResponse($response);

            if (empty($items)) {
                $this->logger->info("No more listings returned at offset {$offset}. Pagination finished.");
                break;
            }

            $countOnPage = count($items);
            $this->logger->info("Retrieved {$countOnPage} listings on page {$page}.");

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                yield $item;
                $yieldedCount++;

                if ($limit !== null && $yieldedCount >= $limit) {
                    $this->logger->info("Reached limit of {$limit} listings. Stopping fetch.");
                    return;
                }
            }

            $offset += $countOnPage;
            $page++;

            // If fewer items returned than requested page size, or offset exceeds total, we have reached the end
            if ($countOnPage < $this->pageSize) {
                $this->logger->info("Retrieved items count ({$countOnPage}) is less than page size ({$this->pageSize}). End of listings.");
                break;
            }

            if (isset($response['count']) && $offset >= (int)$response['count']) {
                $this->logger->info("Reached total count of {$response['count']} listings. End of listings.");
                break;
            }

            if (array_key_exists('next', $response) && $response['next'] === null) {
                $this->logger->info("API indicated next page is null. End of listings.");
                break;
            }
        }
    }

    /**
     * Fetches all listings into an array.
     *
     * @param int|null $limit Maximum number of listings to fetch
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllListings(?int $limit = null): array
    {
        return iterator_to_array($this->fetchListings($limit), false);
    }

    /**
     * Executes HTTP request with exponential backoff on retryable errors.
     *
     * @param string $method
     * @param string $endpoint
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function request(string $method, string $endpoint, array $query = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $attempt = 0;
        $backoffSeconds = 1;

        while ($attempt < $this->maxRetries) {
            $attempt++;

            try {
                $response = $this->httpClient->request($method, $url, [
                    'query' => $query,
                ]);

                $body = (string)$response->getBody();
                $data = json_decode($body, true);

                if (!is_array($data)) {
                    throw new RuntimeException("Invalid JSON response received from Reelly API: {$body}");
                }

                return $data;

            } catch (RequestException $e) {
                $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
                $isRetryable = ($statusCode === 429 || $statusCode >= 500 || $statusCode === 0);

                if ($isRetryable && $attempt < $this->maxRetries) {
                    $this->logger->warning(
                        "Reelly API request to {$endpoint} failed with status {$statusCode}. Retrying in {$backoffSeconds}s (attempt {$attempt}/{$this->maxRetries}): " . $e->getMessage()
                    );
                    sleep($backoffSeconds);
                    $backoffSeconds *= 2;
                    continue;
                }

                $this->logger->error("Reelly API request to {$endpoint} failed permanently: " . $e->getMessage());
                throw new RuntimeException("Reelly API call failed: " . $e->getMessage(), $statusCode, $e);

            } catch (GuzzleException $e) {
                if ($attempt < $this->maxRetries) {
                    $this->logger->warning("Reelly API network error. Retrying in {$backoffSeconds}s: " . $e->getMessage());
                    sleep($backoffSeconds);
                    $backoffSeconds *= 2;
                    continue;
                }

                throw new RuntimeException("Reelly API network failure: " . $e->getMessage(), 0, $e);
            }
        }

        throw new RuntimeException("Reelly API request to {$endpoint} exceeded max retries ({$this->maxRetries})");
    }

    /**
     * Extracts items array from various JSON response formats.
     *
     * @param array<string, mixed> $response
     * @return array<int, mixed>
     */
    private function extractItemsFromResponse(array $response): array
    {
        if (isset($response['results']) && is_array($response['results'])) {
            return $response['results'];
        }

        if (isset($response['data']['projects']) && is_array($response['data']['projects'])) {
            return $response['data']['projects'];
        }

        if (isset($response['data']['items']) && is_array($response['data']['items'])) {
            return $response['data']['items'];
        }

        if (isset($response['data']) && is_array($response['data']) && array_is_list($response['data'])) {
            return $response['data'];
        }

        if (isset($response['projects']) && is_array($response['projects'])) {
            return $response['projects'];
        }

        if (isset($response['items']) && is_array($response['items'])) {
            return $response['items'];
        }

        if (array_is_list($response)) {
            return $response;
        }

        return [];
    }
}

