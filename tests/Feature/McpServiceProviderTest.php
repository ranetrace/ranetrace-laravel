<?php

declare(strict_types=1);

test('mcp config defaults to enabled', function (): void {
    // The default value should be true when not explicitly set
    expect(config('sorane.mcp.enabled'))->toBeTrue();
});

test('mcp config can be disabled via config', function (): void {
    config(['sorane.mcp.enabled' => false]);

    expect(config('sorane.mcp.enabled'))->toBeFalse();
});

test('mcp config key exists in sorane config', function (): void {
    $config = config('sorane');

    expect($config)->toHaveKey('mcp');
    expect($config['mcp'])->toHaveKey('enabled');
});
