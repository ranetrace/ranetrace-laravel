{{--
    The human-verification beacon, rendered by the ranetraceErrorTracking
    directive next to the JavaScript error script and gated on its own flag,
    `website_analytics.beacon.enabled`.

    It posts one opaque token and nothing else. The token is minted by
    `TrackPageVisit` for this one view and left on the request; without it there
    is no visit waiting to be verified, so nothing is rendered.

    It stays in its own file rather than inside `error-tracker.blade.php`
    because that view is a wrapper around the shared capture script and is
    guarded, in `ErrorTrackerViewRenderTest`, against carrying any browser code
    of its own.
--}}
@php
    // Both PHP blocks in this file use the block form deliberately. Blade
    // matches a raw PHP block by its opening and closing directives, so the
    // one-expression inline form here would swallow everything down to the
    // close of the block below it.
    $ranetraceViewToken = request()->attributes->get('ranetrace_view_token');
@endphp
@if(config('ranetrace.website_analytics.beacon.enabled') && $ranetraceViewToken)
@php
    $ranetraceBeaconConfig = [
        'endpoint' => route('ranetrace.analytics.verify'),
        'token' => $ranetraceViewToken,
        // The route sits behind the `web` group's CSRF middleware, so the
        // beacon is given a token and sends it as `X-CSRF-TOKEN`.
        'csrfToken' => csrf_token(),
        'delayMs' => (int) config('ranetrace.website_analytics.beacon.delay_ms', 1500),
    ];
@endphp
<script @if($ranetraceNonce ?? null) nonce="{{ $ranetraceNonce }}" @endif>
/**
 * Ranetrace human-verification beacon.
 *
 * Confirms that a real browser executed JavaScript and had this page visible,
 * so the page visit already dispatched for this view can be reported as
 * verified. HTTP clients and headless fetchers never reach this code at all,
 * and a hidden prerender is filtered by the visibility check below.
 *
 * Best effort throughout: a failure here must never disturb the host page, and
 * a visit whose beacon never arrives is still counted, just not verified.
 */
(function () {
    'use strict';

    // Printed through Blade's json directive, which escapes the angle
    // brackets: a value here can never close this script element from inside
    // the string literal it landed in.
    var config = @json($ranetraceBeaconConfig);

    var fired = false;

    function fire() {
        if (fired) {
            return;
        }

        // A background tab or a prerender is not a human looking at the page.
        if (document.visibilityState !== 'visible') {
            return;
        }

        fired = true;

        try {
            fetch(config.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ token: config.token }),
                keepalive: true
            }).catch(function () {});
        } catch (e) {}
    }

    function schedule() {
        // requestAnimationFrame proves at least one frame was painted; the
        // delay that follows filters instant bounces and prerenders.
        try {
            if (typeof requestAnimationFrame === 'function') {
                requestAnimationFrame(function () {
                    window.setTimeout(fire, config.delayMs);
                });
            } else {
                window.setTimeout(fire, config.delayMs);
            }
        } catch (e) {}
    }

    try {
        if (document.readyState === 'complete') {
            schedule();
        } else {
            window.addEventListener('load', schedule, { once: true });
        }
    } catch (e) {}
})();
</script>
@endif
