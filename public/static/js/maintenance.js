(function () {
    var root = document.body;
    if (!root || !root.classList.contains('nm-maint')) {
        return;
    }

    var until = parseInt(root.getAttribute('data-maintenance-until') || '0', 10) || 0;
    var countdowns = document.querySelectorAll('[data-countdown]');
    var clock = document.querySelector('[data-clock]');

    function pad(value) {
        return (value < 10 ? '0' : '') + value;
    }

    function tickClock() {
        if (!clock) {
            return;
        }
        var now = new Date();
        clock.textContent = pad(now.getHours()) + ':' + pad(now.getMinutes());
    }

    function tickCountdown() {
        if (!until || !countdowns.length) {
            return;
        }
        var left = until - Math.floor(Date.now() / 1000);
        if (left <= 0) {
            countdowns.forEach(function (node) {
                node.textContent = '00:00';
            });
            return;
        }
        var hours = Math.floor(left / 3600);
        var minutes = Math.floor((left % 3600) / 60);
        var seconds = left % 60;
        var text = (hours > 0 ? hours + ':' : '') + pad(minutes) + ':' + pad(seconds);
        countdowns.forEach(function (node) {
            node.textContent = text;
        });
    }

    tickClock();
    tickCountdown();

    if (clock) {
        setInterval(tickClock, 15000);
    }
    if (until && countdowns.length) {
        setInterval(tickCountdown, 1000);
    }
})();
