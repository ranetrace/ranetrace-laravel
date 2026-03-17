<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools;

use DateTimeImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Ranetrace\Laravel\Mcp\Tools\Concerns\NormalizesIds;
use Ranetrace\Laravel\Services\RanetraceApiClient;

class SnoozeErrorTool extends Tool
{
    use NormalizesIds;

    protected const VALID_DURATIONS = ['1h', '8h', '24h', '7d', '30d'];

    /**
     * The tool's description.
     */
    protected string $description = 'Temporarily snooze an error. Snoozed errors will not trigger notifications until the snooze period expires. Use "duration" for preset durations or "until" for a specific datetime.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $errorId = $this->normalizeErrorId($request->get('error_id'));
        $type = $this->normalizeType($request->get('type'));
        $duration = $request->get('duration');
        $until = $request->get('until');

        if (empty($errorId)) {
            return Response::error('Error ID is required.');
        }

        if (empty($duration) && empty($until)) {
            return Response::error('Either "duration" or "until" is required.');
        }

        $data = [];

        if ($until !== null) {
            $validationError = $this->validateUntil($until);
            if ($validationError !== null) {
                return Response::error($validationError);
            }
            $data['until'] = $until;
        } elseif ($duration !== null) {
            if (! in_array($duration, self::VALID_DURATIONS, true)) {
                $validDurations = implode(', ', self::VALID_DURATIONS);

                return Response::error("Invalid duration '{$duration}'. Valid options: {$validDurations}.");
            }
            $data['duration'] = $duration;
        }

        $result = $this->client->snoozeError($errorId, $data, $type);

        if (! $result['success']) {
            return $this->handleErrorResponse($result, $errorId);
        }

        return Response::text($this->formatResponse($result['data'], $errorId));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'error_id' => $schema->string()
                ->description('The error ID (with or without err_ prefix).')
                ->required(),
            'duration' => $schema->string()
                ->description('Preset snooze duration: "1h", "8h", "24h", "7d", or "30d".')
                ->enum(self::VALID_DURATIONS),
            'until' => $schema->string()
                ->description('ISO 8601 datetime to snooze until (must be in the future).'),
            'type' => $schema->string()
                ->description('The error type: "php" (default), "javascript", or "js".')
                ->enum(['php', 'javascript', 'js']),
        ];
    }

    /**
     * Normalize the error type parameter.
     */
    protected function normalizeType(?string $type): string
    {
        if ($type === null) {
            return 'php';
        }

        return $type === 'js' ? 'javascript' : $type;
    }

    /**
     * Validate the "until" parameter.
     */
    protected function validateUntil(string $until): ?string
    {
        $datetime = DateTimeImmutable::createFromFormat(DATE_ATOM, $until);

        if ($datetime === false) {
            $datetime = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $until);
        }

        if ($datetime === false) {
            $datetime = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $until);
        }

        if ($datetime === false) {
            return 'Invalid datetime format. Use ISO 8601 format (e.g., "2026-01-25T09:00:00+00:00").';
        }

        if ($datetime <= new DateTimeImmutable) {
            return 'The "until" datetime must be in the future.';
        }

        return null;
    }

    /**
     * Handle error responses from the API.
     *
     * @param  array<string, mixed>  $result
     */
    protected function handleErrorResponse(array $result, string $errorId): Response
    {
        $errorMessage = $result['error'] ?? 'Unknown error occurred';

        return match ($result['status']) {
            404 => Response::error("Error with ID '{$errorId}' not found."),
            403 => Response::error("Access denied: {$errorMessage}"),
            422 => Response::error("Validation failed: {$errorMessage}"),
            default => Response::error("Failed to snooze error: {$errorMessage}"),
        };
    }

    /**
     * Format the success response.
     *
     * @param  array<string, mixed>  $data
     */
    protected function formatResponse(array $data, string $errorId): string
    {
        $error = $data['error'] ?? [];
        $activity = $data['activity'] ?? [];

        $id = $error['id'] ?? "err_{$errorId}";
        $state = $error['state'] ?? 'snoozed';
        $isResolved = ($error['is_resolved'] ?? false) ? 'Yes' : 'No';
        $isIgnored = ($error['is_ignored'] ?? false) ? 'Yes' : 'No';
        $snoozeUntil = $error['snooze_until'] ?? 'Unknown';

        $activityId = $activity['id'] ?? 'N/A';
        $activityAction = $activity['action'] ?? 'snoozed';
        $performedAt = $activity['performed_at'] ?? 'N/A';

        return <<<RESPONSE
        # Error Snoozed Successfully

        ## Error State
        - **Error ID:** {$id}
        - **State:** {$state}
        - **Resolved:** {$isResolved}
        - **Ignored:** {$isIgnored}
        - **Snoozed Until:** {$snoozeUntil}

        ## Activity Log Entry
        - **Activity ID:** {$activityId}
        - **Action:** {$activityAction}
        - **Performed At:** {$performedAt}
        RESPONSE;
    }
}
