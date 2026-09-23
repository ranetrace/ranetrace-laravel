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
        'delayMs' => (int) config('ranetrace.website_analytics.beacon.delay_ms', 0),
    ];
@endphp
{{--
    Ranetrace human-verification beacon.

    Confirms that a real browser executed JavaScript and had this page visible,
    so the page visit already dispatched for this view can be reported as
    verified. HTTP clients and headless fetchers never reach this code at all,
    and a hidden prerender is filtered by the visibility checks below.

    It starts at once rather than on `load`. The directive sits before
    `</body>`, so the document is parsed by the time this runs, and waiting for
    every image and font to arrive was a minimum time on page: a visitor who
    left before `load` plus the delay was reported unverified, and the app
    drops unverified visits from every number. The same reasoning is why a
    hidden page is waited on rather than given up on, and why leaving the page
    posts too, once it has been seen: a configured delay must never be what
    decides whether a real visitor counts.

    Known gap, by design: a page opened in a background tab and first looked at
    after `wait_seconds` is still reported unverified, because the visit job
    has already run by then. Nothing here can change that; the page has not
    been seen while the visit was held.

    Best effort throughout: a failure here must never disturb the host page, and
    a visit whose beacon never arrives is still counted, just not verified.

    Every note inside the script below is a Blade comment for the same reason
    this one is: the compiler removes them before anything renders, so they stay
    in the source and leave the page. This script ships to every visitor of
    every site that installs the package, so its bytes are paid for by all of
    them.
--}}
<script @if($ranetraceNonce ?? null) nonce="{{ $ranetraceNonce }}" @endif>
(function () {
    'use strict';

    {{-- Printed through Blade's json directive, which escapes the angle
         brackets: a value here can never close this script element from inside
         the string literal it landed in. --}}
    var config = @json($ranetraceBeaconConfig);

    var fired = false;
    var seen = false;

    {{-- The one post. keepalive lets it outlive the page, which the pagehide
         path depends on; sendBeacon would too, but cannot set the CSRF header. --}}
    function post() {
        if (fired) {
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

    function fire() {
        if (fired) {
            return;
        }

        {{-- A background tab or a prerender is not a human looking at the page
             yet. Wait for it to be shown, then start over; the listener is
             one-shot, so a page hidden again re-arms it from here. --}}
        if (document.visibilityState !== 'visible') {
            try {
                document.addEventListener('visibilitychange', schedule, { once: true });
            } catch (e) {}

            return;
        }

        post();
    }

    {{-- requestAnimationFrame proves a frame was painted, and does not run in a
         hidden tab or a prerender, which is what makes `seen` trustworthy. --}}
    function painted() {
        if (document.visibilityState === 'visible') {
            seen = true;
        }

        window.setTimeout(fire, config.delayMs);
    }

    function schedule() {
        try {
            if (typeof requestAnimationFrame === 'function') {
                requestAnimationFrame(painted);
            } else {
                painted();
            }
        } catch (e) {}
    }

    try {
        {{-- By pagehide the page usually reports itself hidden already, so the
             visibility check is replaced by `seen`: it was visible at a
             painted frame, which is the same proof the timer path asks for. --}}
        window.addEventListener('pagehide', function () {
            if (seen) {
                post();
            }
        });

        schedule();
    } catch (e) {}
})();
</script>
@endif
