<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ranetrace\Laravel\Http\Controllers\DashboardController;
use Ranetrace\Laravel\Http\Controllers\DashboardFragmentController;

/*
|--------------------------------------------------------------------------
| Ranetrace Dashboard Routes
|--------------------------------------------------------------------------
|
| Loaded by RanetraceServiceProvider inside a route group that applies the
| configured path/domain/middleware plus the package's Authorize middleware.
| Every route here is therefore behind the `viewRanetrace` gate.
|
*/

Route::get('/', [DashboardController::class, 'index'])->name('ranetrace.dashboard');
Route::get('panels', [DashboardFragmentController::class, 'index'])->name('ranetrace.dashboard.panels');
