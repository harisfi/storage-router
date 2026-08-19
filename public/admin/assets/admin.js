// Localize timestamps rendered as RFC3339 UTC on <time class="local-time datetime="...">">
// No-JS fallback: the server-side UTC text remains until JS replaces it.
(function () {
    'use strict';

    function localize() {
        var elts = document.querySelectorAll('time.local-time[datetime]');
        var noop = /^invalid/i;
        var formatter = new Intl.DateTimeFormat(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });

        for (var i = 0; i < elts.length; i++) {
            var el = elts[i];
            var parsed = new Date(el.getAttribute('datetime'));
            if (isNaN(parsed.getTime())) {
                continue; // keep the original text if unparseable
            }
            el.textContent = formatter.format(parsed);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', localize);
    } else {
        localize();
    }
})();