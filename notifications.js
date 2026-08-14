/*
 * notifications.js - the notification bell, shared by the admin panel and the
 * public site so both behave identically.
 *
 * Which alerts count as "new" is per-viewer, and the underlying tables have no
 * read flag, so the browser keeps a high-water mark of the highest id already
 * seen per kind. Ids only ever grow, so anything above the mark is new. That
 * avoids a per-user read-state table entirely.
 *
 * Expected markup (ids are configurable):
 *   <span id="...-bell">          the clickable bell, badge nested inside
 *   <span id="...-badge">         unread count
 *   <div  id="...-dropdown">      toggled with .open
 *   <div  id="...-body" data-live="...">   the list; each row .notif-link
 *                                          with data-kind and data-id
 *
 * The body is a live-sync region, so its children are replaced wholesale.
 * Everything here is either bound to the container or delegated.
 */
(function () {
    'use strict';

    function create(opts) {
        var bell = document.getElementById(opts.bellId);
        var dropdown = document.getElementById(opts.dropdownId);
        var body = document.getElementById(opts.bodyId);
        var badge = document.getElementById(opts.badgeId);
        var markAll = opts.markAllId ? document.getElementById(opts.markAllId) : null;

        // Signed-out visitors have no bell at all.
        if (!bell || !dropdown || !body || !badge) return null;

        function readSeen() {
            try {
                var raw = localStorage.getItem(opts.storageKey);
                return raw ? JSON.parse(raw) : null;
            } catch (e) {
                return null;
            }
        }

        function writeSeen(seen) {
            try { localStorage.setItem(opts.storageKey, JSON.stringify(seen)); } catch (e) { /* not fatal */ }
        }

        function links() {
            return Array.prototype.slice.call(body.querySelectorAll('.notif-link'));
        }

        function currentMaxIds() {
            var max = {};
            links().forEach(function (el) {
                var kind = el.getAttribute('data-kind');
                var id = parseInt(el.getAttribute('data-id'), 10);
                if (kind && !isNaN(id)) max[kind] = Math.max(max[kind] || 0, id);
            });
            return max;
        }

        function refresh() {
            var seen = readSeen() || {};
            var unread = 0;

            links().forEach(function (el) {
                var id = parseInt(el.getAttribute('data-id'), 10);
                var isNew = !isNaN(id) && id > (seen[el.getAttribute('data-kind')] || 0);
                el.classList.toggle('is-new', isNew);
                if (isNew) unread++;
            });

            badge.textContent = unread;
            badge.style.display = unread > 0 ? 'flex' : 'none';
        }

        // clearHighlights: the explicit "Mark all read" button wipes the amber
        // rows immediately; merely opening the bell clears the count but leaves
        // them marked so you can still see which ones were new.
        function markRead(clearHighlights) {
            var seen = readSeen() || {};
            var max = currentMaxIds();
            Object.keys(max).forEach(function (kind) {
                seen[kind] = Math.max(seen[kind] || 0, max[kind]);
            });
            writeSeen(seen);

            badge.style.display = 'none';
            if (clearHighlights) {
                links().forEach(function (el) { el.classList.remove('is-new'); });
            }
        }

        bell.addEventListener('click', function (event) {
            event.stopPropagation();
            var opening = !dropdown.classList.contains('open');
            dropdown.classList.toggle('open');
            if (opening) markRead(false);
        });

        document.addEventListener('click', function (event) {
            if (!dropdown.classList.contains('open')) return;
            if (dropdown.contains(event.target) || bell.contains(event.target)) return;
            dropdown.classList.remove('open');
        });

        if (markAll) {
            markAll.addEventListener('click', function (event) {
                event.stopPropagation();
                markRead(true);
            });
        }

        // Delegated on the container, which survives live-sync swapping its
        // children.
        if (opts.onSelect) {
            body.addEventListener('click', function (event) {
                // Rows can contain their own actions (a dismiss form); those
                // handle their own clicks.
                if (event.target.closest('form')) return;
                var link = event.target.closest('.notif-link');
                if (!link || !link.getAttribute('data-goto')) return;
                dropdown.classList.remove('open');
                opts.onSelect(link);
            });
        }

        document.addEventListener('live:updated', function (event) {
            var changed = event.detail.datasets;
            if (!opts.datasets || opts.datasets.some(function (d) { return changed.indexOf(d) !== -1; })) {
                refresh();
            }
        });

        // First run in this browser starts quiet: whatever is already on the
        // page counts as seen, so the bell only speaks up for what arrives from
        // here on rather than dumping the whole backlog.
        if (readSeen() === null) writeSeen(currentMaxIds());
        refresh();

        return { refresh: refresh, markRead: markRead };
    }

    window.NotificationBell = { create: create };
})();
