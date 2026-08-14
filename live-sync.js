/*
 * live-sync.js - keeps admin.php and index.php current without a manual refresh,
 * so a change made by one role shows up for the other within a few seconds.
 *
 * How it works:
 *   1. Poll live_version.php for a fingerprint per dataset (pets, lost_pets, ...).
 *   2. When a fingerprint the page cares about moves, re-fetch THIS page once.
 *   3. Swap in only the elements marked data-live="<dataset>", matched by id.
 *
 * Re-fetching the page instead of bespoke JSON endpoints is deliberate: the
 * markup for these lists is built inline in PHP, and a second rendering path
 * would drift out of sync with the first. The fingerprint gate keeps the cost
 * down - the full fetch only happens when something actually changed.
 *
 * Mark up a live region with an id and a data-live list:
 *   <tbody id="pets-tbody" data-live="pets">...</tbody>
 *   <div id="chart-data" data-live="pets applications">...</div>
 *
 * After a swap, `live:updated` fires on document with detail.datasets, so pages
 * can re-apply anything that lives outside the swapped HTML (active filters,
 * chart instances).
 */
(function () {
    'use strict';

    var DEFAULT_INTERVAL = 5000;
    var VERSION_ENDPOINT = 'live_version.php';

    var versions = null;      // null until the first poll sets a baseline
    var deferred = {};        // datasets whose swap we postponed, retried next tick
    var inFlight = false;
    var timer = null;

    function liveContainers() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-live]'));
    }

    function datasetsOf(el) {
        return (el.getAttribute('data-live') || '').split(/\s+/).filter(Boolean);
    }

    function watchedDatasets() {
        var watched = {};
        liveContainers().forEach(function (el) {
            datasetsOf(el).forEach(function (name) { watched[name] = true; });
        });
        return watched;
    }

    function anyModalOpen() {
        return Array.prototype.slice.call(document.querySelectorAll('.modal')).some(function (modal) {
            var display = window.getComputedStyle(modal).display;
            return display && display !== 'none';
        });
    }

    // Reasons to leave a region alone this tick rather than swap it.
    function isBusy(el) {
        var active = document.activeElement;
        // Someone is typing or tabbed into this region - never yank it away.
        if (active && active !== document.body && el.contains(active)) return true;
        // An expanded <details> (the "Read My Story" cards) would snap shut.
        if (el.querySelector('details[open]')) return true;
        return false;
    }

    function refresh(changed) {
        var url = new URL(window.location.href);
        // Tells PHP this is a background render, so it doesn't consume one-shot
        // session state (flash messages) meant for a real navigation.
        url.searchParams.set('live', '1');

        return fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { 'X-Live-Sync': '1' }
        })
            .then(function (res) { return res.ok ? res.text() : Promise.reject(res.status); })
            .then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html');
                var applied = {};

                liveContainers().forEach(function (el) {
                    var names = datasetsOf(el);
                    if (!names.some(function (n) { return changed[n]; })) return;

                    // id is how a region is matched against the new render.
                    if (!el.id) return;
                    var incoming = fresh.getElementById(el.id);
                    if (!incoming) return;
                    if (el.innerHTML === incoming.innerHTML) return;

                    if (isBusy(el) || anyModalOpen()) {
                        names.forEach(function (n) { deferred[n] = true; });
                        return;
                    }

                    el.innerHTML = incoming.innerHTML;
                    names.forEach(function (n) { applied[n] = true; });
                });

                var updated = Object.keys(applied);
                if (updated.length) {
                    document.dispatchEvent(new CustomEvent('live:updated', {
                        detail: { datasets: updated }
                    }));
                }
            })
            .catch(function () { /* transient - the next tick retries */ });
    }

    function poll() {
        // Skip while backgrounded; visibilitychange polls immediately on return.
        if (inFlight || document.hidden) return;
        inFlight = true;

        fetch(VERSION_ENDPOINT, { credentials: 'same-origin' })
            .then(function (res) { return res.ok ? res.json() : Promise.reject(res.status); })
            .then(function (data) {
                var watched = watchedDatasets();
                var changed = deferred;
                deferred = {};

                Object.keys(data).forEach(function (name) {
                    if (!watched[name]) return;
                    if (versions && versions[name] !== data[name]) changed[name] = true;
                });

                versions = data;
                if (Object.keys(changed).length) return refresh(changed);
            })
            .catch(function () { /* transient - the next tick retries */ })
            .then(function () { inFlight = false; });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) poll();
    });

    window.LiveSync = {
        start: function (options) {
            var interval = (options && options.interval) || DEFAULT_INTERVAL;
            if (timer) clearInterval(timer);
            poll();
            timer = setInterval(poll, interval);
        },
        poll: poll
    };
})();
