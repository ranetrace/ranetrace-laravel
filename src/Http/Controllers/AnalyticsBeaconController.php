<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Laravel\Support\VisitVerificationMark;
use Throwable;

/**
 * The endpoint the human-verification beacon posts to.
 *
 * A post proves a real browser executed JavaScript and had the page visible,
 * which is the one thing no amount of server-side header reading can establish.
 * All this endpoint does is leave a mark for the token it was given: the page
 * visit job dispatched for that same view reads the mark a few seconds later
 * and reports `verified_human` accordingly. Nothing is sent from here, so a
 * flood of beacons costs cache writes and nothing else.
 *
 * The mark outlives the job's wait window by a margin, because a busy queue
 * runs the delayed job a little late and an expired mark reads as unverified.
 *
 * A well-formed token always answers 200 with the same body, whether or not any
 * visit is waiting on it. Writing the mark is idempotent, so a second beacon
 * for the same view changes nothing, and an answer that varied with whether the
 * token was known would let a prober learn which tokens are live.
 *
 * @see VisitVerificationMark
 */
class AnalyticsBeaconController extends Controller
{
    public function verify(Request $request): JsonResponse
    {
        // The beacon endpoint is part of the capture path and must never throw
        // uncaught into the host app, even if the cache store is broken.
        // (Failure-isolation Core Rule.)
        try {
            return $this->process($request);
        } catch (Throwable $e) {
            InternalLogger::error('Failed to process analytics beacon', [
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process beacon',
            ], 500);
        }
    }

    private function process(Request $request): JsonResponse
    {
        if (! config('ranetrace.enabled', true) || ! config('ranetrace.website_analytics.enabled', false)) {
            return response()->json([
                'success' => false,
                'message' => 'Website analytics is not enabled',
            ], 403);
        }

        // The route is mounted whenever analytics is on, so that a beacon from
        // a page cached before the flag was turned off gets a clean 403 rather
        // than a 404 that reads as a broken install.
        if (! config('ranetrace.website_analytics.beacon.enabled', false)) {
            return response()->json([
                'success' => false,
                'message' => 'Verification beacon is not enabled',
            ], 403);
        }

        // The token is a UUID this application minted. Validating the shape
        // here is what lets the view print it back into a script tag, and it
        // keeps an arbitrary attacker-chosen string out of the cache key.
        $validator = Validator::make($request->all(), [
            'token' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
            ], 422);
        }

        VisitVerificationMark::put((string) $request->input('token'));

        return response()->json([
            'success' => true,
            'message' => 'Beacon received',
        ], 200);
    }
}
