<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Ranetrace\Laravel\Http\Controllers\Concerns\RendersDashboard;

class DashboardController extends Controller
{
    use RendersDashboard;

    /**
     * Render the diagnostics dashboard shell (full first paint).
     *
     * Data comes from the shared DashboardData service, so the page and the
     * `ranetrace:status` CLI can never disagree. Every read inside the service
     * degrades to a safe default, so the panels render even when the cache or
     * database is unavailable rather than 500-ing the host app.
     */
    public function index(DashboardData $data): View
    {
        return view('ranetrace::dashboard.index', [
            ...$this->panelData($data),
            'assetVersion' => AssetController::version(),
        ]);
    }
}
