<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Ranetrace\Laravel\Http\Controllers\AssetController;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The dashboard's only guard.
 *
 * Mirrors Horizon/Pulse/Telescope: the package appends this middleware to its
 * own route group and never trusts the host's `web` group to authorize. The
 * `viewRanetrace` gate is the single decision point — default-deny everywhere
 * except `local` until the host explicitly grants access.
 *
 * On denial it returns a friendly, CSP-clean page explaining how to grant
 * access (define `viewRanetrace` in AppServiceProvider::boot()) rather than a
 * bare 403. This leaks nothing — it's the same advice as the docs.
 */
class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Gate::check('viewRanetrace', [$request->user()])) {
            return $next($request);
        }

        try {
            $html = view('ranetrace::dashboard.denied', [
                'assetVersion' => AssetController::version(),
            ])->render();

            return response($html, 403);
        } catch (Throwable) {
            // Never let the denial path itself error into the host app.
            abort(403);
        }
    }
}
