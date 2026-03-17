<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class RanetraceApiClient
{
    protected string $apiUrl = 'https://api.ranetrace.com/v1';

    protected int $timeout = 10;

    public function __construct(
        protected ?string $apiKey = null
    ) {
        $this->apiKey = $apiKey ?? config('ranetrace.key');
    }

    /**
     * Send a batch of errors to Ranetrace.
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
            $timeout = config('ranetrace.errors.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/Errors/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
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
     * Send a batch of JavaScript errors to Ranetrace.
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
            $timeout = config('ranetrace.javascript_errors.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/JavaScriptErrors/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
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
     * Send a batch of events to Ranetrace.
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
            $timeout = config('ranetrace.events.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/Events/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
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
     * Send a batch of logs to Ranetrace.
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
            $timeout = config('ranetrace.logging.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/Logs/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
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
     * Send a batch of page visits to Ranetrace.
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
            $timeout = config('ranetrace.website_analytics.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/PageVisits/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
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
     * Get the latest errors from Ranetrace.
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
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
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
     * Get a specific error by ID from Ranetrace.
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
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
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
     * Get error statistics from Ranetrace.
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
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
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
     * Create a note on an error.
     *
     * @param  array{body: string}  $data
     * @return array<string, mixed>
     */
    public function createNote(string $errorId, array $data): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->timeout($this->timeout)
                ->post($this->apiUrl.'/errors/'.$errorId.'/notes', $data)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * List notes on an error.
     *
     * @param  array{limit?: int, offset?: int, author?: string, from?: string, to?: string, include_archived?: bool}  $params
     * @return array<string, mixed>
     */
    public function listNotes(string $errorId, array $params = []): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->timeout($this->timeout)
                ->get($this->apiUrl.'/errors/'.$errorId.'/notes', $params)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Get a specific note on an error.
     *
     * @return array<string, mixed>
     */
    public function getNote(string $errorId, string $noteId): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->timeout($this->timeout)
                ->get($this->apiUrl.'/errors/'.$errorId.'/notes/'.$noteId)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Update a note on an error.
     *
     * @param  array{body: string}  $data
     * @return array<string, mixed>
     */
    public function updateNote(string $errorId, string $noteId, array $data): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->timeout($this->timeout)
                ->put($this->apiUrl.'/errors/'.$errorId.'/notes/'.$noteId, $data)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Delete (archive) a note on an error.
     *
     * @return array<string, mixed>
     */
    public function deleteNote(string $errorId, string $noteId): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->timeout($this->timeout)
                ->delete($this->apiUrl.'/errors/'.$errorId.'/notes/'.$noteId)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Bulk create notes on an error.
     *
     * @param  array{notes: array<int, array{body: string}>}  $data
     * @return array<string, mixed>
     */
    public function createNotesBulk(string $errorId, array $data): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->timeout($this->timeout)
                ->post($this->apiUrl.'/errors/'.$errorId.'/notes/bulk', $data)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Resolve an error.
     *
     * @return array<string, mixed>
     */
    public function resolveError(string $errorId, string $type = 'php'): array
    {
        return $this->performErrorAction($errorId, 'resolve', $type);
    }

    /**
     * Reopen a resolved error.
     *
     * @return array<string, mixed>
     */
    public function reopenError(string $errorId, string $type = 'php'): array
    {
        return $this->performErrorAction($errorId, 'reopen', $type);
    }

    /**
     * Ignore an error.
     *
     * @return array<string, mixed>
     */
    public function ignoreError(string $errorId, string $type = 'php'): array
    {
        return $this->performErrorAction($errorId, 'ignore', $type);
    }

    /**
     * Unignore an error.
     *
     * @return array<string, mixed>
     */
    public function unignoreError(string $errorId, string $type = 'php'): array
    {
        return $this->performErrorAction($errorId, 'unignore', $type);
    }

    /**
     * Snooze an error.
     *
     * @param  array{duration?: string, until?: string}  $data
     * @return array<string, mixed>
     */
    public function snoozeError(string $errorId, array $data, string $type = 'php'): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        try {
            $url = $this->apiUrl.'/errors/'.$errorId.'/snooze?'.http_build_query(['type' => $type]);

            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->timeout($this->timeout)
                ->post($url, $data)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Unsnooze an error.
     *
     * @return array<string, mixed>
     */
    public function unsnoozeError(string $errorId, string $type = 'php'): array
    {
        return $this->performErrorAction($errorId, 'unsnooze', $type);
    }

    /**
     * Delete (archive) an error.
     *
     * @return array<string, mixed>
     */
    public function deleteError(string $errorId, string $type = 'php'): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        try {
            $url = $this->apiUrl.'/errors/'.$errorId.'?'.http_build_query(['type' => $type]);

            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->timeout($this->timeout)
                ->delete($url)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Get activity log for an error.
     *
     * @param  array{limit?: int, offset?: int}  $params
     * @return array<string, mixed>
     */
    public function getErrorActivity(string $errorId, array $params = [], string $type = 'php'): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        try {
            $queryParams = array_merge($params, ['type' => $type]);

            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->timeout($this->timeout)
                ->get($this->apiUrl.'/errors/'.$errorId.'/activity', $queryParams)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Bulk resolve errors.
     *
     * @param  array<int, string>  $errorIds
     * @return array<string, mixed>
     */
    public function bulkResolveErrors(array $errorIds, string $type = 'php'): array
    {
        return $this->performBulkErrorAction($errorIds, 'resolve', $type);
    }

    /**
     * Bulk reopen errors.
     *
     * @param  array<int, string>  $errorIds
     * @return array<string, mixed>
     */
    public function bulkReopenErrors(array $errorIds, string $type = 'php'): array
    {
        return $this->performBulkErrorAction($errorIds, 'reopen', $type);
    }

    /**
     * Bulk ignore errors.
     *
     * @param  array<int, string>  $errorIds
     * @return array<string, mixed>
     */
    public function bulkIgnoreErrors(array $errorIds, string $type = 'php'): array
    {
        return $this->performBulkErrorAction($errorIds, 'ignore', $type);
    }

    /**
     * Bulk delete (archive) errors.
     *
     * @param  array<int, string>  $errorIds
     * @return array<string, mixed>
     */
    public function bulkDeleteErrors(array $errorIds, string $type = 'php'): array
    {
        return $this->performBulkErrorAction($errorIds, 'delete', $type);
    }

    /**
     * Search errors with advanced filtering.
     *
     * @param  array{
     *     type?: string,
     *     status?: string|array,
     *     environments?: array,
     *     exclude_environments?: array,
     *     first_occurred_period?: string,
     *     first_occurred_from?: string,
     *     first_occurred_to?: string,
     *     last_occurred_period?: string,
     *     last_occurred_from?: string,
     *     last_occurred_to?: string,
     *     occurrence_level?: string,
     *     min_occurrences?: int,
     *     max_occurrences?: int,
     *     sort?: string,
     *     direction?: string,
     *     limit?: int,
     *     cursor?: string,
     *     include_archived?: bool
     * }  $params
     * @return array<string, mixed>
     */
    public function searchErrors(array $params = []): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->timeout($this->timeout)
                ->get($this->apiUrl.'/errors/search', $params)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Restore a soft-deleted error.
     *
     * @return array<string, mixed>
     */
    public function restoreError(string $errorId, string $type = 'php'): array
    {
        return $this->performErrorAction($errorId, 'restore', $type);
    }

    /**
     * Bulk restore soft-deleted errors.
     *
     * @param  array<int, string>  $errorIds
     * @return array<string, mixed>
     */
    public function bulkRestoreErrors(array $errorIds, string $type = 'php'): array
    {
        return $this->performBulkErrorAction($errorIds, 'restore', $type);
    }

    /**
     * Perform a single error action (resolve, reopen, ignore, unignore, unsnooze).
     *
     * @return array<string, mixed>
     */
    protected function performErrorAction(string $errorId, string $action, string $type): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        try {
            $url = $this->apiUrl.'/errors/'.$errorId.'/'.$action.'?'.http_build_query(['type' => $type]);

            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->timeout($this->timeout)
                ->post($url)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Perform a bulk error action (resolve, reopen, ignore, delete).
     *
     * @param  array<int, string>  $errorIds
     * @return array<string, mixed>
     */
    protected function performBulkErrorAction(array $errorIds, string $action, string $type): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->timeout($this->timeout)
                ->post($this->apiUrl.'/errors/bulk/'.$action, [
                    'error_ids' => $errorIds,
                    'type' => $type,
                ])
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
     * @param  Response  $response
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
