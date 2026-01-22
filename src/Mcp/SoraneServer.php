<?php

declare(strict_types=1);

namespace Sorane\Laravel\Mcp;

use Laravel\Mcp\Server;
use Sorane\Laravel\Mcp\Tools\ErrorStatsTool;
use Sorane\Laravel\Mcp\Tools\GetErrorTool;
use Sorane\Laravel\Mcp\Tools\LatestErrorsTool;

class SoraneServer extends Server
{
    /**
     * The MCP server's name.
     */
    protected string $name = 'Sorane';

    /**
     * The MCP server's version.
     */
    protected string $version = '1.0.0';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = 'Sorane MCP server for fetching application errors and issues. Use these tools to investigate errors, get error details, and view error statistics from your Sorane-monitored application.';

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Server\Tool>>
     */
    protected array $tools = [
        LatestErrorsTool::class,
        GetErrorTool::class,
        ErrorStatsTool::class,
    ];
}
