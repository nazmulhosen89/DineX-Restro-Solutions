/* QRRS live refresh: updates the list in place; never reloads the page */
(function () {
    'use strict';
    var cfg = window.qrrsLiveCfg;
    if (!cfg || typeof jQuery === 'undefined') return;

    var tab = new URLSearchParams(window.location.search).get('tab') || '';
    var live = ['', 'orders', 'billing', 'kitchen', 'waiter'];
    if (live.indexOf(tab) === -1) return;

    var last = null;
    var banner = null;
    var knownReady = null;
    var readySound = cfg.sound ? new Audio(cfg.sound) : null;

    // Waiter terminal: a sound when one of today's orders becomes ready
    function checkReady() {
        if (!readySound || !document.querySelector('.floor-grid')) return;
        jQuery.post(cfg.ajax, { action: 'qrrs_live_ready', security: cfg.nonce }, function (res) {
            if (!res || !res.success) return;
            var ids = res.data.ids || [];
            if (knownReady === null) { knownReady = ids; return; }   // first load: silent
            var fresh = ids.some(function (id) { return knownReady.indexOf(id) === -1; });
            knownReady = ids;
            if (fresh) {
                readySound.currentTime = 0;
                readySound.play().catch(function () {});
            }
        });
    }

    // Kitchen and Orders have their own loaders: call them, no page reload
    function refreshInPlace() {
        var done = false;
        if (typeof window.loadKitchenOrders === 'function') { window.loadKitchenOrders(); done = true; }
        if (typeof window.loadAllOrders === 'function') { window.loadAllOrders(); done = true; }
        return done;
    }

    // Waiter has no loader: show a small notice, the waiter refreshes when ready
    function showNotice() {
        if (banner) return;
        banner = document.createElement('div');
        banner.textContent = 'Updates available · tap to refresh';
        banner.style.cssText = 'position:fixed; top:14px; right:14px; z-index:99999; background:#0f172a; color:#fff; padding:10px 16px; border-radius:10px; font-size:13px; font-weight:600; cursor:pointer; box-shadow:0 8px 24px rgba(0,0,0,.25);';
        banner.onclick = function () { window.location.reload(); };
        document.body.appendChild(banner);
    }

    function check() {
        if (document.hidden) return;
        jQuery.post(cfg.ajax, { action: 'qrrs_live_signature', security: cfg.nonce }, function (res) {
            if (!res || !res.success) return;
            var sig = res.data.sig;
            if (last === null) { last = sig; return; }
            if (sig !== last) {
                last = sig;
                if (!refreshInPlace()) { showNotice(); }
            }
        });
    }

    setInterval(function () { check(); checkReady(); }, cfg.every || 5000);
    check();
    checkReady();
})();