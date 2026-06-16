/**
 * Ranetrace diagnostics dashboard — client script (no build step).
 * Served by AssetController (CSP-clean, no inline JS).
 *
 * Auto-refresh poller: every `data-refresh` seconds it fetches the server-
 * rendered panels fragment from `data-panels-url` and swaps #rt-panels. Blade
 * stays the only renderer — there is no JSON-to-DOM rebuild here. Polling pauses
 * while the tab is hidden and skips overlapping requests; a failed poll is
 * ignored and retried on the next tick (the page never breaks on a blip).
 */
(function () {
    'use strict';

    var app = document.getElementById('rt-app');
    if (!app) {
        return;
    }

    var url = app.getAttribute('data-panels-url');
    var refresh = parseInt(app.getAttribute('data-refresh'), 10) || 0;
    var container = document.getElementById('rt-panels');

    if (!url || !container || refresh <= 0) {
        return;
    }

    var inFlight = false;

    function poll() {
        if (inFlight || document.hidden) {
            return;
        }

        inFlight = true;

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (response) {
                return response.ok ? response.text() : Promise.reject(response.status);
            })
            .then(function (html) {
                container.innerHTML = html;
            })
            .catch(function () {
                // Transient failure — leave the current panels in place and retry.
            })
            .finally(function () {
                inFlight = false;
            });
    }

    setInterval(poll, refresh * 1000);
})();
