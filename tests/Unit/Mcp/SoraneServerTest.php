<?php

declare(strict_types=1);

use Laravel\Mcp\Server;
use Sorane\Laravel\Mcp\SoraneServer;
use Sorane\Laravel\Mcp\Tools\CreateNotesTool;
use Sorane\Laravel\Mcp\Tools\CreateNoteTool;
use Sorane\Laravel\Mcp\Tools\DeleteNoteTool;
use Sorane\Laravel\Mcp\Tools\ErrorStatsTool;
use Sorane\Laravel\Mcp\Tools\GetErrorTool;
use Sorane\Laravel\Mcp\Tools\GetNoteTool;
use Sorane\Laravel\Mcp\Tools\LatestErrorsTool;
use Sorane\Laravel\Mcp\Tools\ListNotesTool;
use Sorane\Laravel\Mcp\Tools\UpdateNoteTool;

beforeEach(function (): void {
    if (! class_exists(Server::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }
});

function getServerProperty(string $property): mixed
{
    return (new ReflectionClass(SoraneServer::class))->getDefaultProperties()[$property];
}

test('extends Laravel MCP Server class', function (): void {
    expect(SoraneServer::class)->toExtend(Server::class);
});

test('has correct name property', function (): void {
    expect(getServerProperty('name'))->toBe('Sorane');
});

test('has correct version property', function (): void {
    expect(getServerProperty('version'))->toBe('1.0.0');
});

test('has instructions property set', function (): void {
    $instructions = getServerProperty('instructions');

    expect($instructions)->toBeString()
        ->not->toBeEmpty()
        ->toContain('Sorane');
});

test('instructions mention notes functionality', function (): void {
    $instructions = getServerProperty('instructions');

    expect($instructions)->toContain('notes');
});

test('registers LatestErrorsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(LatestErrorsTool::class);
});

test('registers GetErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(GetErrorTool::class);
});

test('registers ErrorStatsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(ErrorStatsTool::class);
});

test('registers CreateNoteTool', function (): void {
    expect(getServerProperty('tools'))->toContain(CreateNoteTool::class);
});

test('registers ListNotesTool', function (): void {
    expect(getServerProperty('tools'))->toContain(ListNotesTool::class);
});

test('registers GetNoteTool', function (): void {
    expect(getServerProperty('tools'))->toContain(GetNoteTool::class);
});

test('registers UpdateNoteTool', function (): void {
    expect(getServerProperty('tools'))->toContain(UpdateNoteTool::class);
});

test('registers DeleteNoteTool', function (): void {
    expect(getServerProperty('tools'))->toContain(DeleteNoteTool::class);
});

test('registers CreateNotesTool', function (): void {
    expect(getServerProperty('tools'))->toContain(CreateNotesTool::class);
});

test('registers exactly nine tools', function (): void {
    expect(getServerProperty('tools'))->toHaveCount(9);
});
