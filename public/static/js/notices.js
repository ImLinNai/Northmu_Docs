(function () {
    var reduceMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

    function setupBanners() {
        var banners = document.querySelectorAll('.nm-notice-banner');
        Array.prototype.forEach.call(banners, function (banner) {
            var track = banner.querySelector('.nm-notice-banner-track');
            var text = banner.querySelector('[data-notice-text]');
            if (!track || !text) {
                return;
            }

            var marquee = track.querySelector('[data-notice-marquee]');
            if (!marquee) {
                marquee = document.createElement('span');
                marquee.className = 'nm-notice-banner-marquee';
                marquee.setAttribute('data-notice-marquee', '');
                track.appendChild(marquee);
                marquee.appendChild(text);
                var clone = text.cloneNode(true);
                clone.setAttribute('aria-hidden', 'true');
                marquee.appendChild(clone);
            }

            banner.classList.remove('is-scrolling');
            var probe = document.createElement('span');
            probe.textContent = text.textContent;
            probe.style.cssText = 'position:absolute;left:-9999px;visibility:hidden;white-space:nowrap;';
            probe.style.font = window.getComputedStyle(text).font;
            track.appendChild(probe);
            var width = probe.getBoundingClientRect().width;
            probe.remove();
            if (width <= track.clientWidth - 8) {
                return;
            }
            if (reduceMotion && reduceMotion.matches) {
                return;
            }
            banner.style.setProperty('--nm-notice-duration', Math.max(14, Math.round(width / 60)) + 's');
            banner.classList.add('is-scrolling');
        });
    }

    setupBanners();
    var resizeTimer = 0;
    window.addEventListener('resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(setupBanners, 200);
    });
    if (reduceMotion) {
        var onMotionChange = function () { setupBanners(); };
        if (reduceMotion.addEventListener) {
            reduceMotion.addEventListener('change', onMotionChange);
        } else if (reduceMotion.addListener) {
            reduceMotion.addListener(onMotionChange);
        }
    }

    var modal = document.getElementById('nmNoticeModal');
    if (!modal) {
        return;
    }

    var items = Array.prototype.slice.call(modal.querySelectorAll('[data-notice-popup]'));
    var index = 0;
    var current = null;
    var lastFocus = null;

    function storageKey(id) {
        return 'nm-notice-seen-' + id;
    }

    function isDue(item) {
        var hours = parseFloat(item.getAttribute('data-cooldown')) || 24;
        try {
            var seen = parseInt(window.localStorage.getItem(storageKey(item.getAttribute('data-id'))) || '0', 10);
            return !seen || Date.now() - seen >= hours * 3600 * 1000;
        } catch (e) {
            return true;
        }
    }

    function markSeen(item) {
        try {
            window.localStorage.setItem(storageKey(item.getAttribute('data-id')), String(Date.now()));
        } catch (e) { }
    }

    function nextItem() {
        while (index < items.length && !isDue(items[index])) {
            index++;
        }
        return index < items.length ? items[index] : null;
    }

    function show(item) {
        Array.prototype.forEach.call(items, function (node) {
            node.hidden = node !== item;
        });
        current = item;
        modal.hidden = false;
        window.requestAnimationFrame(function () {
            modal.classList.add('is-open');
        });
        document.body.classList.add('nm-notice-lock');
        var button = item.querySelector('[data-notice-dismiss].btn');
        if (button) {
            window.setTimeout(function () { button.focus({ preventScroll: true }); }, 120);
        }
    }

    function closeAll() {
        modal.classList.remove('is-open');
        document.body.classList.remove('nm-notice-lock');
        window.setTimeout(function () {
            if (!modal.classList.contains('is-open')) {
                modal.hidden = true;
            }
        }, 220);
        if (lastFocus && lastFocus.focus) {
            lastFocus.focus({ preventScroll: true });
        }
        lastFocus = null;
        current = null;
    }

    function advance() {
        index++;
        var item = nextItem();
        if (item) {
            show(item);
        } else {
            closeAll();
        }
    }

    var first = nextItem();
    if (!first) {
        modal.remove();
        return;
    }

    lastFocus = document.activeElement;
    show(first);

    modal.addEventListener('click', function (event) {
        var dismiss = event.target.closest('[data-notice-dismiss]');
        var link = event.target.closest('[data-notice-link]');

        if (link && current) {
            markSeen(current);
            return;
        }
        if (!dismiss || !current) {
            return;
        }

        markSeen(current);
        if (dismiss.classList.contains('btn')) {
            advance();
        } else {
            closeAll();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || modal.hidden || !current) {
            return;
        }
        markSeen(current);
        closeAll();
    });
})();
