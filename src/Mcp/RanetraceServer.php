<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp;

use Laravel\Mcp\Server;
use Ranetrace\Laravel\Mcp\Tools\BulkDeleteErrorsTool;
use Ranetrace\Laravel\Mcp\Tools\BulkIgnoreErrorsTool;
use Ranetrace\Laravel\Mcp\Tools\BulkReopenErrorsTool;
use Ranetrace\Laravel\Mcp\Tools\BulkResolveErrorsTool;
use Ranetrace\Laravel\Mcp\Tools\BulkRestoreErrorsTool;
use Ranetrace\Laravel\Mcp\Tools\CreateNotesTool;
use Ranetrace\Laravel\Mcp\Tools\CreateNoteTool;
use Ranetrace\Laravel\Mcp\Tools\DeleteErrorTool;
use Ranetrace\Laravel\Mcp\Tools\DeleteNoteTool;
use Ranetrace\Laravel\Mcp\Tools\ErrorStatsTool;
use Ranetrace\Laravel\Mcp\Tools\GetErrorActivityTool;
use Ranetrace\Laravel\Mcp\Tools\GetErrorTool;
use Ranetrace\Laravel\Mcp\Tools\GetNoteTool;
use Ranetrace\Laravel\Mcp\Tools\IgnoreErrorTool;
use Ranetrace\Laravel\Mcp\Tools\LatestErrorsTool;
use Ranetrace\Laravel\Mcp\Tools\ListNotesTool;
use Ranetrace\Laravel\Mcp\Tools\ReopenErrorTool;
use Ranetrace\Laravel\Mcp\Tools\ResolveErrorTool;
use Ranetrace\Laravel\Mcp\Tools\RestoreErrorTool;
use Ranetrace\Laravel\Mcp\Tools\SearchErrorsTool;
use Ranetrace\Laravel\Mcp\Tools\SnoozeErrorTool;
use Ranetrace\Laravel\Mcp\Tools\UnignoreErrorTool;
use Ranetrace\Laravel\Mcp\Tools\UnsnoozeErrorTool;
use Ranetrace\Laravel\Mcp\Tools\UpdateNoteTool;

class RanetraceServer extends Server
{
    /**
     * The MCP server's name.
     */
    protected string $name = 'Ranetrace';

    /**
     * The MCP server's version.
     */
    protected string $version = '1.0.0';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = 'Ranetrace MCP server for fetching application errors and issues. Use these tools to search and investigate errors with advanced filtering, get error details, view error statistics, manage investigation notes, and control error states (resolve, ignore, snooze, delete, restore) from your Ranetrace-monitored application.';

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
        BulkReopenErrorsTool::class,
        BulkIgnoreErrorsTool::class,
        BulkDeleteErrorsTool::class,
        BulkRestoreErrorsTool::class,
    ];
}
