<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Ranetrace\Laravel\Http\Controllers\Concerns\RendersDashboard;

class DashboardFragmentController extends Controller
{
    use RendersDashboard;

    /**
     * Render just the panels fragment (no shell) for the auto-refresh poller.
     *
     * Same view + same data as the shell's panel block, so a poll swaps the
     * container with byte-identical markup to the first paint — no JSON-to-DOM
     * rebuild. Stays behind the gate like every other data route.
     */
    public function index(DashboardData $data): View
    {
        return view('ranetrace::dashboard.panels', $this->panelData($data));
    }
}
