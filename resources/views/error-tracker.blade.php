{{--
    One directive, two scripts, each behind its own flag:

    - the JavaScript error capture script below, gated on
      `javascript_errors.enabled`;
    - the analytics human-verification beacon, gated on
      `website_analytics.beacon.enabled` and on this view having been handed a
      token, in `analytics-beacon.blade.php`.

    The beacon rides this directive instead of getting one of its own so that
    installing it stays a single line in a layout: a layout that already carries
    the directive needs nothing but the env var.

    The browser capture script is NOT in this file. It lives once, in
    `ranetrace/ranetrace-php` at `resources/js/error-tracker.js`, and both SDKs
    render it from there. It used to live here too, inline and byte-identical for
    some 370 lines, and because each SDK's relay validates exactly what its own
    copy sent, a fix applied to one copy silently stranded the other.

    What stays here is what only Laravel can supply: the route the relay is
    mounted on, the values out of `config/ranetrace.php`, the CSRF token the
    `web` middleware group will check, and the Vite CSP nonce. The nonce is
    resolved once, here, and handed to both scripts.
--}}
@php($ranetraceNonce = \Illuminate\Support\Facades\Vite::cspNonce())
@if(config('ranetrace.javascript_errors.enabled'))
<script @if($ranetraceNonce) nonce="{{ $ranetraceNonce }}" @endif>
{!! \Ranetrace\Php\JavaScript\CaptureScript::withConfig([
    'endpoint' => route('ranetrace.javascript-errors.store'),
    // The conditional above this script is the enabled gate; the script re-reads
    // the flag because the framework-agnostic host has no such wrapper.
    'enabled' => true,
    'sampleRate' => (float) config('ranetrace.javascript_errors.sample_rate', 1.0),
    'captureConsoleErrors' => (bool) config('ranetrace.javascript_errors.capture_console_errors'),
    'maxBreadcrumbs' => (int) config('ranetrace.javascript_errors.max_breadcrumbs', 20),
    'ignoredErrors' => array_values((array) config(
        'ranetrace.javascript_errors.ignored_errors',
        \Ranetrace\Laravel\Http\Controllers\JavaScriptErrorController::DEFAULT_IGNORED_ERRORS,
    )),
    // This relay sits behind the `web` group's CSRF middleware, so the script is
    // given a token and sends `X-CSRF-TOKEN`. A host that configures no token
    // (the framework-agnostic SDK, whose relay checks Origin/Referer instead)
    // gets a script that never sends the header.
    'csrfToken' => csrf_token(),
]) !!}
</script>
@endif
@include('ranetrace::analytics-beacon', ['ranetraceNonce' => $ranetraceNonce])
