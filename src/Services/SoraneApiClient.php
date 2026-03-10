<?php

declare(strict_types=1);

namespace Sorane\Laravel\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SoraneApiClient
{
    protected string $apiUrl = 'https://api.ranetrace.com/v1';

    protected int $timeout = 10;

    public function __construct(
        protected ?string $apiKey = null
    ) {
        $this->apiKey = $apiKey ?? config('sorane.key');
    }

    /**
     * Send a batch of errors to Sorane.
     *
     * @param  array<int, array>  $errors
     * @return array<string, mixed>
     */
    public function sendErrorBatch(array $errors): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        if (empty($errors)) {
            return $this->formatErrorResponse('Empty batch provided');
        }

        try {
            $timeout = config('sorane.errors.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/Errors/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Sorane-API-Version' => '1.0',
                ])
                ->timeout($timeout)
                ->post($this->apiUrl.'/errors/store', [
                    'errors' => $errors,
                ]);

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Send a batch of JavaScript errors to Sorane.
     *
     * @param  array<int, array>  $errors
     * @return array<string, mixed>
     */
    public function sendJavaScriptErrorBatch(array $errors): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        if (empty($errors)) {
            return $this->formatErrorResponse('Empty batch provided');
        }

        try {
            $timeout = config('sorane.javascript_errors.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/JavaScriptErrors/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Sorane-API-Version' => '1.0',
                ])
                ->timeout($timeout)
                ->post($this->apiUrl.'/javascript-errors/store', [
                    'javascript_errors' => $errors,
                ]);

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Send a batch of events to Sorane.
     *
     * @param  array<int, array>  $events
     * @return array<string, mixed>
     */
    public function sendEventBatch(array $events): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        if (empty($events)) {
            return $this->formatErrorResponse('Empty batch provided');
        }

        try {
            $timeout = config('sorane.events.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/Events/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Sorane-API-Version' => '1.0',
                ])
                ->timeout($timeout)
                ->post($this->apiUrl.'/events/store', [
                    'events' => $events,
                ]);

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Send a batch of logs to Sorane.
     *
     * @param  array<int, array>  $logs
     * @return array<string, mixed>
     */
    public function sendLogBatch(array $logs): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        if (empty($logs)) {
            return $this->formatErrorResponse('Empty batch provided');
        }

        try {
            $timeout = config('sorane.logging.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/Logs/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Sorane-API-Version' => '1.0',
                ])
                ->timeout($timeout)
                ->post($this->apiUrl.'/logs/store', [
                    'logs' => $logs,
                ]);

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Send a batch of page visits to Sorane.
     *
     * @param  array<int, array>  $visits
     * @return array<string, mixed>
     */
    public function sendPageVisitBatch(array $visits): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        if (empty($visits)) {
            return $this->formatErrorResponse('Empty batch provided');
        }

        try {
            $timeout = config('sorane.website_analytics.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/PageVisits/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Sorane-API-Version' => '1.0',
                ])
                ->timeout($timeout)
                ->post($this->apiUrl.'/page-visits/store', [
                    'page_visits' => $visits,
                ]);

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Get the latest errors from Sorane.
     *
     * @param  array{limit?: int, environment?: string, type?: string}  $params
     * @return array<string, mixed>
     */
    public function getLatestErrors(array $params = []): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Sorane-API-Version' => '1.0',
                ])
                ->timeout($this->timeout)
                ->get($this->apiUrl.'/errors', $params)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Get a specific error by ID from Sorane.
     *
     * @return array<string, mixed>
     */
    public function getError(string $errorId): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Sorane-API-Version' => '1.0',
                ])
                ->timeout($this->timeout)
                ->get($this->apiUrl.'/errors/'.$errorId)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Get error statistics from Sorane.
     *
     * @param  array{period?: string}  $params
     * @return array<string, mixed>
     */
    public function getErrorStats(array $params = []): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Sorane-API-Version' => '1.0',
                ])
                ->timeout($this->timeout)
                ->get($this->apiUrl.'/errors/stats', $params)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Execute a request with retry logic for transient failures.
     *
     * @param  callable(): Response  $request
     *
     * @throws Throwable
     */
    protected function executeWithRetry(callable $request, int $maxAttempts = 3): Response
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            try {
                $response = $request();

                // Retry on 5xx server errors
                if ($response->serverError() && $attempt < $maxAttempts - 1) {
                    $attempt++;
                    $this->sleep($this->calculateBackoff($attempt));

                    continue;
                }

                return $response;
            } catch (ConnectionException $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < $maxAttempts) {
                    $this->sleep($this->calculateBackoff($attempt));
                }
            }
        }

        throw $lastException ?? new RuntimeException('Request failed after retries');
    }

    /**
     * Calculate exponential backoff delay in milliseconds.
     */
    protected function calculateBackoff(int $attempt): int
    {
        // Base delay: 100ms, 200ms, 400ms (exponential)
        return (int) (100 * pow(2, $attempt - 1));
    }

    /**
     * Sleep for the given number of milliseconds.
     * Extracted for testability.
     */
    protected function sleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    /**
     * Format API response for consistent handling.
     *
     * @param  \Illuminate\Http\Client\Response  $response
     * @return array<string, mixed>
     */
    protected function formatResponse($response): array
    {
        $data = $response->json();
        $isValidData = is_array($data);

        $result = [
            'status' => $response->status(),
            'success' => $response->successful() && $isValidData,
            'data' => $isValidData ? $data : [],
            'headers' => [
                'retry-after' => $response->header('Retry-After'),
            ],
        ];

        if (! $isValidData && $response->successful()) {
            $result['error'] = 'Invalid response format';
        }

        // Extract error_code from response data for specific error handling
        if ($isValidData && isset($data['error_code'])) {
            $result['error_code'] = $data['error_code'];
        }

        return $result;
    }

    /**
     * Format error response for network/exception errors.
     *
     * @return array<string, mixed>
     */
    protected function formatErrorResponse(string $message): array
    {
        return [
            'status' => 0,
            'success' => false,
            'data' => [],
            'error' => $message,
            'headers' => [],
        ];
    }
}
