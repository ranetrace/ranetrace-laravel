<?php

declare(strict_types=1);

test('mcp config defaults to enabled', function (): void {
    // The default value should be true when not explicitly set
    expect(config('ranetrace.mcp.enabled'))->toBeTrue();
});

test('mcp config can be disabled via config', function (): void {
    config(['ranetrace.mcp.enabled' => false]);

    expect(config('ranetrace.mcp.enabled'))->toBeFalse();
});

test('mcp config key exists in ranetrace config', function (): void {
    $config = config('ranetrace');

    expect($config)->toHaveKey('mcp');
    expect($config['mcp'])->toHaveKey('enabled');
});
