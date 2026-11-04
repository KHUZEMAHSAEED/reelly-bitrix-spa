<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use RuntimeException;

class BitrixClient
{
    private GuzzleClient $httpClient;
    private string $webhookUrl;
    private int $rateLimitDelayMs;
    private int $maxRetries;
    private LoggerInterface $logger;
    private float $lastRequestTime = 0.0;

    public function __construct(
        string $webhookUrl,
        int $rateLimitDelayMs,
        int $timeout,
        int $maxRetries,
        LoggerInterface $logger,
        ?GuzzleClient $httpClient = null
    ) {
        $this->webhookUrl = rtrim($webhookUrl, '/') . '/';
        $this->rateLimitDelayMs = max(0, $rateLimitDelayMs);
        $this->maxRetries = max(1, $maxRetries);
        $this->logger = $logger;
        $this->httpClient = $httpClient ?? new GuzzleClient([
            'timeout' => $timeout,
            'headers' => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Executes a single Bitrix24 REST API method call with retry and rate-limiting.
     *
     * @param string $method Bitrix REST method (e.g. 'crm.item.list')
     * @param array<string, mixed> $params Request payload
     * @return array<string, mixed> Decoded JSON response
     *
     * @throws RuntimeException If all retry attempts fail
     */
    public function call(string $method, array $params = []): array
    {
        $url = $this->webhookUrl . ltrim($method, '/') . '.json';
        $attempt = 0;
        $backoffSeconds = 1;

        while ($attempt < $this->maxRetries) {
            $attempt++;
            $this->applyRateLimit();

            try {
                $response = $this->httpClient->post($url, [
                    'json' => $params,
                ]);

                $body = (string)$response->getBody();
                $data = json_decode($body, true);

                if (!is_array($data)) {
                    throw new RuntimeException("Invalid JSON response received from Bitrix24: {$body}");
                }

                // Bitrix sometimes returns HTTP 200 with error property
                if (!empty($data['error'])) {
                    $errorCode = (string)$data['error'];
                    $errorDesc = $data['error_description'] ?? 'No description';

                    if ($errorCode === 'QUERY_LIMIT_EXCEEDED' && $attempt < $this->maxRetries) {
                        $this->logger->warning("Bitrix24 QUERY_LIMIT_EXCEEDED on {$method}. Retrying in {$backoffSeconds}s (attempt {$attempt}/{$this->maxRetries})");
                        sleep($backoffSeconds);
                        $backoffSeconds *= 2;
                        continue;
                    }

                    throw new RuntimeException("Bitrix24 API Error [{$errorCode}]: {$errorDesc}");
                }

                return $data;

            } catch (RequestException $e) {
                $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
                $responseBody = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';

                $isRetryable = ($statusCode === 429 || $statusCode >= 500 || $statusCode === 0);

                // Check for Bitrix QUERY_LIMIT_EXCEEDED in error body
                if ($responseBody !== '') {
                    $errorJson = json_decode($responseBody, true);
                    if (isset($errorJson['error']) && $errorJson['error'] === 'QUERY_LIMIT_EXCEEDED') {
                        $isRetryable = true;
                    }
                }

                if ($isRetryable && $attempt < $this->maxRetries) {
                    $this->logger->warning(
                        "Bitrix24 call to {$method} failed with status {$statusCode}. Retrying in {$backoffSeconds}s (attempt {$attempt}/{$this->maxRetries}): " . $e->getMessage()
                    );
                    sleep($backoffSeconds);
                    $backoffSeconds *= 2;
                    continue;
                }

                $this->logger->error("Bitrix24 call to {$method} failed permanently after {$attempt} attempts: {$e->getMessage()}", [
                    'response' => $responseBody,
                ]);

                throw new RuntimeException("Bitrix24 call to '{$method}' failed: " . $e->getMessage(), $statusCode, $e);

            } catch (GuzzleException $e) {
                if ($attempt < $this->maxRetries) {
                    $this->logger->warning("Bitrix24 network error on {$method}. Retrying in {$backoffSeconds}s: " . $e->getMessage());
                    sleep($backoffSeconds);
                    $backoffSeconds *= 2;
                    continue;
                }

                throw new RuntimeException("Bitrix24 call to '{$method}' failed due to network error: " . $e->getMessage(), 0, $e);
            }
        }

        throw new RuntimeException("Bitrix24 call to '{$method}' exceeded max retries ({$this->maxRetries})");
    }

    /**
     * Executes batch commands (up to 50 commands per sub-call, automatically chunked).
     *
     * @param array<string, array<string, mixed>|string> $commands Map of cmd_name => ['method' => '...', 'params' => [...]] or raw query string
     * @param bool $halt Whether to stop on error
     * @return array{result: array<string, mixed>, result_error: array<string, mixed>}
     */
    public function batch(array $commands, bool $halt = false): array
    {
        if (empty($commands)) {
            return ['result' => [], 'result_error' => []];
        }

        $allResults = [];
        $allErrors = [];

        // Chunk commands into max 50 per request
        $chunks = array_chunk($commands, 50, true);

        foreach ($chunks as $chunkIndex => $chunkCommands) {
            $formattedCmd = [];

            foreach ($chunkCommands as $name => $cmdDef) {
                if (is_string($cmdDef)) {
                    $formattedCmd[$name] = $cmdDef;
                } elseif (is_array($cmdDef)) {
                    $method = (string)($cmdDef['method'] ?? '');
                    $params = (array)($cmdDef['params'] ?? []);
                    $formattedCmd[$name] = $method . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
                }
            }

            $response = $this->call('batch', [
                'halt' => $halt ? 1 : 0,
                'cmd'  => $formattedCmd,
            ]);

            $batchResult = $response['result']['result'] ?? [];
            $batchError  = $response['result']['result_error'] ?? [];

            if (is_array($batchResult)) {
                $allResults = array_merge($allResults, $batchResult);
            }

            if (is_array($batchError)) {
                $allErrors = array_merge($allErrors, $batchError);
            }

            if ($halt && !empty($allErrors)) {
                break;
            }
        }

        return [
            'result'       => $allResults,
            'result_error' => $allErrors,
        ];
    }

    /**
     * Enforces rate limiting delay to stay under ~2 requests per second.
     */
    private function applyRateLimit(): void
    {
        if ($this->rateLimitDelayMs <= 0) {
            return;
        }

        $now = microtime(true);
        $elapsedMs = ($now - $this->lastRequestTime) * 1000.0;
        $requiredDelayMs = $this->rateLimitDelayMs - $elapsedMs;

        if ($requiredDelayMs > 0) {
            usleep((int)($requiredDelayMs * 1000));
        }

        $this->lastRequestTime = microtime(true);
    }
}

