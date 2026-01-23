<?php

declare(strict_types=1);

namespace Sorane\Laravel\Mcp;

use Laravel\Mcp\Server;
use Sorane\Laravel\Mcp\Tools\BulkDeleteErrorsTool;
use Sorane\Laravel\Mcp\Tools\BulkIgnoreErrorsTool;
use Sorane\Laravel\Mcp\Tools\BulkResolveErrorsTool;
use Sorane\Laravel\Mcp\Tools\BulkRestoreErrorsTool;
use Sorane\Laravel\Mcp\Tools\CreateNotesTool;
use Sorane\Laravel\Mcp\Tools\CreateNoteTool;
use Sorane\Laravel\Mcp\Tools\DeleteErrorTool;
use Sorane\Laravel\Mcp\Tools\DeleteNoteTool;
use Sorane\Laravel\Mcp\Tools\ErrorStatsTool;
use Sorane\Laravel\Mcp\Tools\GetErrorActivityTool;
use Sorane\Laravel\Mcp\Tools\GetErrorTool;
use Sorane\Laravel\Mcp\Tools\GetNoteTool;
use Sorane\Laravel\Mcp\Tools\IgnoreErrorTool;
use Sorane\Laravel\Mcp\Tools\LatestErrorsTool;
use Sorane\Laravel\Mcp\Tools\ListNotesTool;
use Sorane\Laravel\Mcp\Tools\ReopenErrorTool;
use Sorane\Laravel\Mcp\Tools\ResolveErrorTool;
use Sorane\Laravel\Mcp\Tools\RestoreErrorTool;
use Sorane\Laravel\Mcp\Tools\SearchErrorsTool;
use Sorane\Laravel\Mcp\Tools\SnoozeErrorTool;
use Sorane\Laravel\Mcp\Tools\UnignoreErrorTool;
use Sorane\Laravel\Mcp\Tools\UnsnoozeErrorTool;
use Sorane\Laravel\Mcp\Tools\UpdateNoteTool;

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
    protected string $instructions = 'Sorane MCP server for fetching application errors and issues. Use these tools to search and investigate errors with advanced filtering, get error details, view error statistics, manage investigation notes, and control error states (resolve, ignore, snooze, delete, restore) from your Sorane-monitored application.';

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Server\Tool>>
     */
    protected array $tools = [
        LatestErrorsTool::class,
        SearchErrorsTool::class,
        GetErrorTool::class,
        ErrorStatsTool::class,
        CreateNoteTool::class,
        ListNotesTool::class,
        GetNoteTool::class,
        UpdateNoteTool::class,
        DeleteNoteTool::class,
        CreateNotesTool::class,
        ResolveErrorTool::class,
        ReopenErrorTool::class,
        IgnoreErrorTool::class,
        UnignoreErrorTool::class,
        SnoozeErrorTool::class,
        UnsnoozeErrorTool::class,
        DeleteErrorTool::class,
        RestoreErrorTool::class,
        GetErrorActivityTool::class,
        BulkResolveErrorsTool::class,
        BulkIgnoreErrorsTool::class,
        BulkDeleteErrorsTool::class,
        BulkRestoreErrorsTool::class,
    ];
}
