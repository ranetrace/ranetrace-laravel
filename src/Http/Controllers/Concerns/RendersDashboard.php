<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Http\Controllers\Concerns;

use Ranetrace\Laravel\Dashboard\DashboardData;

/**
 * Shared view-data assembly for the dashboard shell and its refresh fragment,
 * so a full page load and a poll render the exact same panels from the exact
 * same data. The shell adds its own asset version on top of this.
 */
trait RendersDashboard
{
    /**
     * @return array<string, mixed>
     */
    protected function panelData(DashboardData $data): array
    {
        return [
            ...$data->collect(),
            'refresh' => (int) config('ranetrace.dashboard.refresh', 10),
            'hostedUrl' => (string) config('ranetrace.dashboard.hosted_url', 'https://ranetrace.com'),
        ];
    }
}
