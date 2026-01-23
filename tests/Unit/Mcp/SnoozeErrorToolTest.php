<?php

declare(strict_types=1);

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Sorane\Laravel\Mcp\Tools\SnoozeErrorTool;
use Sorane\Laravel\Services\SoraneApiClient;

beforeEach(function (): void {
    if (! class_exists(Laravel\Mcp\Server\Tool::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }

    $this->mockClient = Mockery::mock(SoraneApiClient::class);
    $this->tool = new SnoozeErrorTool($this->mockClient);
});

test('snoozes error with duration successfully', function (): void {
    $this->mockClient->shouldReceive('snoozeError')
        ->once()
        ->with('123', ['duration' => '24h'], 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'error' => [
                    'id' => 'err_123',
                    'state' => 'snoozed',
                    'is_resolved' => false,
                    'is_ignored' => false,
                    'snooze_until' => '2026-01-24T12:34:56+00:00',
                ],
                'activity' => [
                    'id' => 460,
                    'action' => 'snoozed',
                    'performed_at' => '2026-01-23T12:34:56+00:00',
                ],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'duration' => '24h',
    ]));

    expect($response)->toBeInstanceOf(Response::class);
    expect((string) $response->content())
        ->toContain('Error Snoozed Successfully')
        ->toContain('snoozed')
        ->toContain('2026-01-24T12:34:56+00:00');
});

test('snoozes error with until datetime successfully', function (): void {
    $futureDate = (new DateTimeImmutable('+1 day'))->format(DATE_ATOM);

    $this->mockClient->shouldReceive('snoozeError')
        ->once()
        ->with('123', ['until' => $futureDate], 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'error' => [
                    'id' => 'err_123',
                    'state' => 'snoozed',
                    'snooze_until' => $futureDate,
                ],
                'activity' => ['id' => 460, 'action' => 'snoozed'],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'until' => $futureDate,
    ]));

    expect((string) $response->content())->toContain('Error Snoozed Successfully');
});

test('until takes precedence over duration when both provided', function (): void {
    $futureDate = (new DateTimeImmutable('+1 day'))->format(DATE_ATOM);

    $this->mockClient->shouldReceive('snoozeError')
        ->once()
        ->with('123', ['until' => $futureDate], 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['error' => ['id' => 'err_123', 'state' => 'snoozed']],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'duration' => '24h',
        'until' => $futureDate,
    ]));
});

test('normalizes error ID by stripping err_ prefix', function (): void {
    $this->mockClient->shouldReceive('snoozeError')
        ->once()
        ->with('123', ['duration' => '1h'], 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['error' => ['id' => 'err_123', 'state' => 'snoozed']],
        ]);

    $this->tool->handle(new Request([
        'error_id' => 'err_123',
        'duration' => '1h',
    ]));
});

test('passes javascript type correctly', function (): void {
    $this->mockClient->shouldReceive('snoozeError')
        ->once()
        ->with('123', ['duration' => '1h'], 'javascript')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['error' => ['id' => 'err_123', 'state' => 'snoozed']],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'duration' => '1h',
        'type' => 'javascript',
    ]));
});

test('normalizes js type to javascript', function (): void {
    $this->mockClient->shouldReceive('snoozeError')
        ->once()
        ->with('123', ['duration' => '1h'], 'javascript')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['error' => ['id' => 'err_123', 'state' => 'snoozed']],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'duration' => '1h',
        'type' => 'js',
    ]));
});

test('returns error when error_id is missing', function (): void {
    $this->mockClient->shouldNotReceive('snoozeError');

    $response = $this->tool->handle(new Request([
        'duration' => '24h',
    ]));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error when error_id is empty', function (): void {
    $this->mockClient->shouldNotReceive('snoozeError');

    $response = $this->tool->handle(new Request([
        'error_id' => '',
        'duration' => '24h',
    ]));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error when neither duration nor until is provided', function (): void {
    $this->mockClient->shouldNotReceive('snoozeError');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
    ]));

    expect((string) $response->content())->toContain('Either "duration" or "until" is required');
});

test('returns error for invalid duration', function (): void {
    $this->mockClient->shouldNotReceive('snoozeError');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'duration' => '999h',
    ]));

    expect((string) $response->content())
        ->toContain("Invalid duration '999h'")
        ->toContain('1h, 8h, 24h, 7d, 30d');
});

test('accepts all valid durations', function (string $duration): void {
    $this->mockClient->shouldReceive('snoozeError')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['error' => ['id' => 'err_123', 'state' => 'snoozed']],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'duration' => $duration,
    ]));

    expect((string) $response->content())->toContain('Error Snoozed Successfully');
})->with(['1h', '8h', '24h', '7d', '30d']);

test('returns error for invalid datetime format', function (): void {
    $this->mockClient->shouldNotReceive('snoozeError');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'until' => 'not-a-date',
    ]));

    expect((string) $response->content())->toContain('Invalid datetime format');
});

test('returns error for past datetime', function (): void {
    $this->mockClient->shouldNotReceive('snoozeError');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'until' => '2020-01-01T00:00:00+00:00',
    ]));

    expect((string) $response->content())->toContain('must be in the future');
});

test('accepts valid ISO 8601 datetime formats', function (string $datetime): void {
    $this->mockClient->shouldReceive('snoozeError')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['error' => ['id' => 'err_123', 'state' => 'snoozed']],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'until' => $datetime,
    ]));

    expect((string) $response->content())->toContain('Error Snoozed Successfully');
})->with([
    '2030-01-25T09:00:00+00:00',
    '2030-01-25T09:00:00Z',
    '2030-01-25T09:00:00',
]);

test('returns error for 404 status', function (): void {
    $this->mockClient->shouldReceive('snoozeError')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 404,
            'error' => 'Error not found',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '999',
        'duration' => '24h',
    ]));

    expect((string) $response->content())->toContain("Error with ID '999' not found");
});

test('returns error for 403 status', function (): void {
    $this->mockClient->shouldReceive('snoozeError')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 403,
            'error' => 'Access denied',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'duration' => '24h',
    ]));

    expect((string) $response->content())->toContain('Access denied');
});

test('returns error for 422 validation status', function (): void {
    $this->mockClient->shouldReceive('snoozeError')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 422,
            'error' => 'Invalid snooze parameters',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'duration' => '24h',
    ]));

    expect((string) $response->content())->toContain('Validation failed');
});

test('returns generic error for other failures', function (): void {
    $this->mockClient->shouldReceive('snoozeError')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 500,
            'error' => 'Internal server error',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'duration' => '24h',
    ]));

    expect((string) $response->content())
        ->toContain('Failed to snooze error')
        ->toContain('Internal server error');
});

test('has correct description property', function (): void {
    $reflection = new ReflectionProperty($this->tool, 'description');
    $description = $reflection->getValue($this->tool);

    expect($description)->toContain('Temporarily snooze');
    expect($description)->toContain('duration');
    expect($description)->toContain('until');
});
