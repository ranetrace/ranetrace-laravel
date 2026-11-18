<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Services;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Ranetrace\Laravel\Support\CoreConfig;
use Ranetrace\Laravel\Support\Endpoints;
use Ranetrace\Php\Http\Endpoint;
use Ranetrace\Php\Http\Heartbeat;
use Throwable;

class RanetraceApiClient
{
    /**
     * Connection-phase timeout (seconds) for batch sends: fail fast on a dead
     * or unreachable host rather than tying up the worker for the full timeout.
     */
    protected const int CONNECT_TIMEOUT = 5;

    /**
     * The API base, as the shared core's `Config::baseUrl()` resolves it, so
     * both SDKs address the same host.
     */
    protected string $apiUrl;

    /**
     * The key sent as the Bearer token, trimmed like the shared core trims it,
     * so a key the `blank()` capture gates let through is sent as the key it
     * holds. Untrimmed, a real key with leading whitespace is refused with a
     * 401, which pauses every feature.
     */
    protected string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $config = CoreConfig::make();

        $this->apiKey = mb_trim($apiKey ?? $config->key());

        $this->apiUrl = $config->baseUrl();
    }

    /**
     * Whether there is a key to send, judged on the trimmed key this client
     * would put in the Bearer header.
     */
    public function hasKey(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * The API base this client sends to, without a trailing slash.
     */
    public function baseUrl(): string
    {
        return $this->apiUrl;
    }

    /**
     * Send one batch of captured items of the given type.
     *
     * The path, the wrapper key, the User-Agent feature and the timeout config
     * key all come from the shared endpoint table, so this SDK cannot address an
     * endpoint differently from `ranetrace/ranetrace-php`.
     *
     * @param  array<int, array>  $items
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When no endpoint is registered for the type.
     */
    public function sendBatchOfType(string $type, array $items): array
    {
        return $this->sendBatch(Endpoints::table()->get($type), $items);
    }

    /**
     * Send a batch of errors to Ranetrace.
     *
     * @param  array<int, array>  $errors
     * @return array<string, mixed>
     */
    public function sendErrorBatch(array $errors): array
    {
        return $this->sendBatchOfType('errors', $errors);
    }

    /**
     * Send a batch of JavaScript errors to Ranetrace.
     *
     * @param  array<int, array>  $errors
     * @return array<string, mixed>
     */
    public function sendJavaScriptErrorBatch(array $errors): array
    {
        return $this->sendBatchOfType('javascript_errors', $errors);
    }

    /**
     * Send a batch of events to Ranetrace.
     *
     * @param  array<int, array>  $events
     * @return array<string, mixed>
     */
    public function sendEventBatch(array $events): array
    {
        return $this->sendBatchOfType('events', $events);
    }

    /**
     * Send a batch of logs to Ranetrace.
     *
     * @param  array<int, array>  $logs
     * @return array<string, mixed>
     */
    public function sendLogBatch(array $logs): array
    {
        return $this->sendBatchOfType('logs', $logs);
    }

    /**
     * Send a batch of page visits to Ranetrace.
     *
     * @param  array<int, array>  $visits
     * @return array<string, mixed>
     */
    public function sendPageVisitBatch(array $visits): array
    {
        return $this->sendBatchOfType('page_visits', $visits);
    }

    /**
     * Tell the API this application's worker ran. The answer comes back in the
     * same shape as a batch's, and the caller decides what it means.
     *
     * @return array<string, mixed>
     */
    public function sendHeartbeat(Heartbeat $heartbeat): array
    {
        if (! $this->hasKey()) {
            return $this->formatErrorResponse('API key not configured');
        }

        return $this->post(Heartbeat::PATH, $heartbeat->userAgent(), Heartbeat::TIMEOUT_SECONDS, $heartbeat->body());
    }

    /**
     * Send one batch to a feature store endpoint. Shared by all five feature
     * batch methods, which differ only in the endpoint they name.
     *
     * @param  array<int, array>  $items
     * @return array<string, mixed>
     */
    protected function sendBatch(Endpoint $endpoint, array $items): array
    {
        if (! $this->hasKey()) {
            return $this->formatErrorResponse('API key not configured');
        }

        if (empty($items)) {
            return $this->formatErrorResponse('Empty batch provided');
        }

        return $this->post(
            $endpoint->path,
            $endpoint->userAgent(Endpoints::SDK),
            (int) config(Endpoints::timeoutKey($endpoint), 10),
            [$endpoint->wrapper => $items],
        );
    }

    /**
     * Format an API response into the package's standard result shape.
     *
     * The body of a failed response is passed on under `data` as the API sent
     * it, `{success: false, error: {message}}`, and its message is read where
     * it is needed, by the shared core's `ResponsePolicy::errorMessage()`.
     * The result's own `error` key is this client's word for a request that
     * got no usable answer, and is never set from a body.
     *
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

    /**
     * POST one request with the five headers the contract names. A request that
     * gets no answer comes back as status 0 rather than throwing.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, string $userAgent, int $timeout, array $body): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => $userAgent,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($timeout)
                ->post($this->apiUrl.$path, $body);

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($this->unreachableCause($e->getMessage()));
        }
    }

    /**
     * The cause alone from Guzzle's message for a request that got no answer,
     * without its `cURL error 7: ` prefix, its libcurl reference link and its
     * trailing ` for <url>`. The shared core reports the same failure with
     * curl's own message, so both SDKs print one line for one cause, and the
     * URL is already in the line the reader sees. A message without these
     * parts passes through as it is.
     */
    private function unreachableCause(string $message): string
    {
        return preg_replace([
            '/ for https?:\/\/\S+$/',
            '/ \(see https?:\/\/curl\.(?:haxx\.)?se\/libcurl\/c\/libcurl-errors\.html\)$/',
            '/^cURL error \d+: /',
        ], '', $message) ?? $message;
    }
}
