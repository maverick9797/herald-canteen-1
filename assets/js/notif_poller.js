/**
 * notif_poller.js — Herald Canteen
 * Live notification toast + badge updater for all customer-facing pages.
 *
 * Include this script on any page where a customer may be logged in.
 * It polls notif_poll.php every 8 s, shows a toast for new notifications,
 * and updates the 🔔 badge count in the navbar in real time.
 *
 * Requires: the page must have elements with class `notif-wrap` containing
 * a `.notif-badge` child for the badge updates to take effect.
 *
 * Usage:
 *   <script src="../assets/js/notif_poller.js"></script>
 *   Call window.initNotifPoller() once the page is ready, or it auto-inits.
 */
(function () {
    'use strict';

    // ── Config ─────────────────────────────────────────────────────────────
    var POLL_INTERVAL_MS  = 8000;   // 8 seconds between polls
    var INITIAL_DELAY_MS  = 4000;   // 4 s grace so the page can settle
    var TOAST_DURATION_MS = 7000;   // how long the toast stays visible
    var POLL_ENDPOINT     = 'notif_poll.php';

    // ── Resolve endpoint relative to current page ──────────────────────────
    function resolveEndpoint() {
        // If the script src gives us a path, use it to locate notif_poll.php
        // Otherwise fall back to a path relative to the current page.
        var scripts = document.getElementsByTagName('script');
        for (var i = 0; i < scripts.length; i++) {
            var src = scripts[i].src || '';
            if (src.indexOf('notif_poller.js') !== -1) {
                return src.replace('notif_poller.js', '').replace('js/', '') + 'pages/' + POLL_ENDPOINT;
            }
        }
        return POLL_ENDPOINT; // fallback: same directory as the current page
    }

    var ENDPOINT = resolveEndpoint();

    // ── Emoji icon map ─────────────────────────────────────────────────────
    function notifIcon(type) {
        var icons = { order: '🛒', payment: '💳', ready: '✅', cancel: '❌', promo: '🎁' };
        return icons[type] || '🔔';
    }

    // ── Inject keyframe animation once ────────────────────────────────────
    function ensureStyles() {
        if (document.getElementById('hc-notif-styles')) return;
        var s = document.createElement('style');
        s.id = 'hc-notif-styles';
        s.textContent = [
            '@keyframes hcNotifIn{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}',
            '@keyframes hcNotifOut{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(16px)}}',
        ].join('');
        document.head.appendChild(s);
    }

    // ── Show toast popup ───────────────────────────────────────────────────
    function showToast(title, message, type) {
        ensureStyles();

        // Remove any existing toast so we don't stack
        var existing = document.getElementById('hc-notif-toast');
        if (existing) { clearTimeout(existing._hcTimer); existing.remove(); }

        var toast = document.createElement('div');
        toast.id = 'hc-notif-toast';
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'polite');
        toast.style.cssText = [
            'position:fixed;bottom:24px;right:24px;z-index:99999',
            'background:#1e1e1e;border:1px solid rgba(77,184,72,0.45)',
            'border-radius:16px;padding:16px 20px',
            'min-width:300px;max-width:380px',
            'display:flex;align-items:flex-start;gap:14px',
            'box-shadow:0 8px 32px rgba(0,0,0,0.55)',
            'animation:hcNotifIn 0.3s ease;cursor:pointer',
            'font-family:inherit',
        ].join(';');

        // Icon
        var iconEl = document.createElement('span');
        iconEl.style.cssText = 'font-size:24px;line-height:1;flex-shrink:0;margin-top:2px';
        iconEl.textContent = notifIcon(type);

        // Body
        var body = document.createElement('div');
        body.style.cssText = 'flex:1;min-width:0';

        var titleEl = document.createElement('div');
        titleEl.style.cssText = 'font-size:14px;font-weight:700;color:#fff;margin-bottom:4px;line-height:1.3';
        titleEl.textContent = title;

        var msgEl = document.createElement('div');
        msgEl.style.cssText = 'font-size:12px;color:rgba(255,255,255,0.55);line-height:1.45';
        msgEl.textContent = message;

        var hint = document.createElement('div');
        hint.style.cssText = 'font-size:11px;color:rgba(77,184,72,0.75);margin-top:6px';
        hint.textContent = 'Tap to view all notifications';

        body.appendChild(titleEl);
        body.appendChild(msgEl);
        body.appendChild(hint);

        // Close button
        var closeBtn = document.createElement('button');
        closeBtn.textContent = '✕';
        closeBtn.setAttribute('aria-label', 'Dismiss notification');
        closeBtn.style.cssText = 'background:none;border:none;color:rgba(255,255,255,0.3);font-size:16px;cursor:pointer;padding:0;line-height:1;flex-shrink:0;align-self:flex-start';
        closeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            dismissToast(toast);
        });

        toast.appendChild(iconEl);
        toast.appendChild(body);
        toast.appendChild(closeBtn);

        // Click → go to notifications page
        toast.addEventListener('click', function () {
            window.location.href = findNotifPageUrl();
        });

        document.body.appendChild(toast);

        // Auto-dismiss after TOAST_DURATION_MS
        toast._hcTimer = setTimeout(function () { dismissToast(toast); }, TOAST_DURATION_MS);
    }

    function dismissToast(toast) {
        if (!toast || !toast.parentNode) return;
        clearTimeout(toast._hcTimer);
        toast.style.animation = 'hcNotifOut 0.3s ease forwards';
        setTimeout(function () { if (toast.parentNode) toast.remove(); }, 300);
    }

    // ── Resolve URL to notifications.php relative to current page ─────────
    function findNotifPageUrl() {
        // Try to find an existing link to notifications.php already on the page
        var links = document.querySelectorAll('a[href*="notifications.php"]');
        if (links.length > 0) return links[0].href;
        return 'notifications.php';
    }

    // ── Update badge count on any 🔔 notification links ───────────────────
    function updateBadge(count) {
        var links = document.querySelectorAll('a[href*="notifications.php"]');
        links.forEach(function (link) {
            var badge = link.querySelector('.notif-badge');
            if (count > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'notif-badge';
                    link.appendChild(badge);
                }
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = '';
            } else if (badge) {
                badge.style.display = 'none';
            }
        });

        // Also update page title badge prefix if unread count changed
        var titleBase = document.title.replace(/^\(\d+\+?\) /, '');
        document.title = count > 0 ? '(' + (count > 99 ? '99+' : count) + ') ' + titleBase : titleBase;
    }

    // ── Polling logic ──────────────────────────────────────────────────────
    var lastPollTs = Math.floor(Date.now() / 1000) - 10;
    var pollTimer  = null;

    function poll() {
        var ts = lastPollTs;
        fetch(ENDPOINT + '?since=' + ts, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (!data) return;
            lastPollTs = data.server_ts || Math.floor(Date.now() / 1000);
            updateBadge(data.count || 0);
            if (data.new_notifs && data.new_notifs.length > 0) {
                var n = data.new_notifs[0];
                showToast(n.title, n.message, n.type);
            }
        })
        .catch(function () { /* silently ignore network errors */ });
    }

    // ── Public init (also called automatically below) ──────────────────────
    function init() {
        if (pollTimer) return; // already running
        setTimeout(function () {
            poll();
            pollTimer = setInterval(poll, POLL_INTERVAL_MS);
        }, INITIAL_DELAY_MS);
    }

    // Expose so pages can call window.initNotifPoller() if they need late init
    window.initNotifPoller = init;

    // Auto-start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
