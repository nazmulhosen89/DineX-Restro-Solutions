/* QRRS live cooking countdown. Elements: <span class="qrrs-cd" data-due="epoch" data-now="server epoch"> */
(function () {
    'use strict';
    var style = document.createElement('style');
    style.textContent = '.qrrs-cd{font-weight:800;font-variant-numeric:tabular-nums}' +
        '.qrrs-cd-late{animation:qrrsBlink 1s steps(2,start) infinite}' +
        '@keyframes qrrsBlink{to{visibility:hidden}}';
    document.head.appendChild(style);

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function fmt(sec) { var m = Math.floor(sec / 60), s = sec % 60; return pad(m) + ':' + pad(s); }

    function tick() {
        var clientNow = Date.now() / 1000;
        var els = document.querySelectorAll('.qrrs-cd[data-due]');
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            if (el.dataset.skew === undefined && el.dataset.now !== undefined) {
                el.dataset.skew = String(parseFloat(el.dataset.now) - clientNow);
            }
            var skew = parseFloat(el.dataset.skew || '0');
            var left = Math.floor(parseFloat(el.dataset.due) - (clientNow + skew));
            if (left >= 0) {
                el.textContent = 'Ready in ' + fmt(left);
                el.style.color = '#1d4ed8';
                el.classList.remove('qrrs-cd-late');
            } else {
                el.textContent = 'Overdue ' + fmt(-left);
                el.style.color = '#dc2626';
                el.classList.add('qrrs-cd-late');
            }
        }
    }
    tick();
    setInterval(tick, 1000);
    window.qrrsCountdownTick = tick;
})();