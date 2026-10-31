(function () {
    'use strict';

    var THEME_KEY = 'northmu-theme';
    var themeTimer = null;

    function currentTheme() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    }

    function syncCodeTheme(theme) {
        var light = document.getElementById('hljs-light');
        var dark = document.getElementById('hljs-dark');
        if (light) light.disabled = theme === 'dark';
        if (dark) dark.disabled = theme !== 'dark';
    }

    function applyTheme(theme) {
        theme = theme === 'dark' ? 'dark' : 'light';
        var root = document.documentElement;
        root.classList.add('nm-theme-transition');
        void root.offsetWidth;
        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.setAttribute('data-bs-theme', theme);
        if (document.body) document.body.setAttribute('data-theme', theme);

        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', theme === 'dark' ? '#0f172a' : '#f8fafc');

        try { localStorage.setItem(THEME_KEY, theme); } catch (e) { }
        syncCodeTheme(theme);

        clearTimeout(themeTimer);
        themeTimer = setTimeout(function () {
            root.classList.remove('nm-theme-transition');
        }, 360);

        try {
            document.dispatchEvent(new CustomEvent('northmu:theme', { detail: { theme: theme } }));
        } catch (e) { }
    }

    function toggleTheme() {
        applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-theme-toggle]');
        if (trigger) {
            event.preventDefault();
            toggleTheme();
        }
    });

    window.NorthmuTheme = {
        apply: applyTheme,
        toggle: toggleTheme,
        current: currentTheme
    };

    if (document.body) {
        document.body.setAttribute('data-theme', currentTheme());
    }
    syncCodeTheme(currentTheme());

    var loadingBar = document.getElementById('nmLoadingBar');
    var loadingTimer = null;

    function startLoading() {
        if (!loadingBar) return;
        loadingBar.classList.remove('is-done');
        loadingBar.classList.add('is-loading');
        clearTimeout(loadingTimer);
        loadingTimer = setTimeout(finishLoading, 15000);
    }

    function finishLoading() {
        if (!loadingBar) return;
        clearTimeout(loadingTimer);
        loadingBar.classList.remove('is-loading');
        loadingBar.classList.add('is-done');
    }

    var bootTimer = setTimeout(hideBoot, 5000);
    function hideBoot() {
        clearTimeout(bootTimer);
        var boot = document.getElementById('nmBoot');
        if (!boot) return;
        boot.classList.add('nm-boot-done');
        setTimeout(function () { boot.remove(); }, 450);
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href]');
        if (!link || link.target || link.hasAttribute('download')) return;
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        var href = link.getAttribute('href');
        if (!href || href.charAt(0) === '#') return;
        var url;
        try { url = new URL(link.href, window.location.href); } catch (e) { return; }
        if (url.origin !== window.location.origin) return;
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;
        startLoading();
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.target && form.target !== '_self') return;
        startLoading();
    });

    window.addEventListener('pageshow', finishLoading);
    window.addEventListener('load', hideBoot);

    document.addEventListener('DOMContentLoaded', function () {
        document.body.setAttribute('data-theme', currentTheme());
        initSidebar();
        initTocToggle();
        initToc();
        initLightbox();
        initCategoryCollapse();
        initBackTop();
        if (window.hljs) {
            document.querySelectorAll('[data-article-body] pre code').forEach(function (block) {
                if (!block.classList.contains('hljs')) {
                    try { hljs.highlightElement(block); } catch (e) { }
                }
            });
        }
    });

    if (document.readyState === 'complete') {
        initReveal();
    } else {
        window.addEventListener('load', initReveal);
    }

    function initReveal() {
        var items = document.querySelectorAll('.nm-reveal');
        if (!items.length) return;
        if (!('IntersectionObserver' in window)) {
            items.forEach(function (el) { el.classList.add('is-visible'); });
            return;
        }
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '0px 0px -6% 0px', threshold: 0.04 });
        items.forEach(function (el) {
            if (el.classList.contains('is-visible')) return;
            var rect = el.getBoundingClientRect();
            if (rect.top < window.innerHeight && rect.bottom > 0) {
                el.classList.add('is-visible');
            } else {
                observer.observe(el);
            }
        });
    }

    function initTocToggle() {
        var shell = document.querySelector('.doc-shell[data-doc-id]');
        var button = document.querySelector('[data-toc-toggle]');
        if (!shell || !button) return;
        var key = 'northmu-toc-' + shell.getAttribute('data-doc-id');
        var stored = null;
        try { stored = localStorage.getItem(key); } catch (e) { }
        if (stored === '0') shell.classList.add('no-toc');
        if (stored === '1') shell.classList.remove('no-toc');

        function sync() {
            var hidden = shell.classList.contains('no-toc');
            button.setAttribute('aria-pressed', hidden ? 'false' : 'true');
            var label = button.querySelector('[data-toc-toggle-text]');
            if (label) label.textContent = hidden ? '显示目录' : '隐藏目录';
        }

        sync();
        button.addEventListener('click', function () {
            var hidden = shell.classList.toggle('no-toc');
            try { localStorage.setItem(key, hidden ? '0' : '1'); } catch (e) { }
            sync();
        });
    }

    function initBackTop() {
        document.querySelectorAll('[data-back-top]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
    }

    function initSidebar() {
        var toggles = document.querySelectorAll('[data-sidebar-toggle]');
        var closes = document.querySelectorAll('[data-sidebar-close]');
        var sidebar = document.querySelector('.doc-sidebar');
        var backdrop = document.querySelector('.doc-sidebar-backdrop');
        if (!sidebar) return;

        function close() {
            sidebar.classList.remove('show');
            if (backdrop) backdrop.classList.remove('show');
        }

        toggles.forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                sidebar.classList.toggle('show');
                if (backdrop) backdrop.classList.toggle('show');
            });
        });
        closes.forEach(function (btn) {
            btn.addEventListener('click', close);
        });
        if (backdrop) backdrop.addEventListener('click', close);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
    }

    function initCategoryCollapse() {
        var toggles = document.querySelectorAll('[data-cat-toggle]');
        if (!toggles.length) return;

        toggles.forEach(function (btn) {
            var id = btn.getAttribute('data-cat-toggle');
            var target = document.getElementById('cat-children-' + id);
            if (!target) return;
            var stored = null;
            try { stored = localStorage.getItem('northmu-cat-' + id); } catch (e) { }
            if (stored === '0') {
                target.classList.add('d-none');
                btn.classList.add('collapsed');
            }
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var hidden = target.classList.toggle('d-none');
                btn.classList.toggle('collapsed', hidden);
                try { localStorage.setItem('northmu-cat-' + id, hidden ? '0' : '1'); } catch (err) { }
            });
        });
    }

    function initToc() {
        var tocNav = document.querySelector('[data-doc-toc]');
        var article = document.querySelector('[data-article-body]');
        if (!tocNav || !article) return;

        var headings = article.querySelectorAll('h1, h2, h3, h4');
        var empty = tocNav.querySelector('[data-toc-empty]');
        if (!headings.length) {
            if (empty) empty.classList.remove('d-none');
            var toggle = document.querySelector('[data-toc-toggle]');
            if (toggle) toggle.classList.add('d-none');
            return;
        }
        if (empty) empty.classList.add('d-none');

        var list = tocNav.querySelector('[data-toc-list]');
        if (!list) return;

        headings.forEach(function (heading, index) {
            if (!heading.id) {
                var base = (heading.textContent || 'section').trim().toLowerCase()
                    .replace(/[^\w\u4e00-\u9fa5]+/g, '-')
                    .replace(/^-+|-+$/g, '')
                    .slice(0, 40);
                heading.id = 'toc-' + (base || 'section') + '-' + index;
            }
            var link = document.createElement('a');
            link.href = '#' + heading.id;
            link.className = 'toc-link toc-level-' + heading.tagName.charAt(1);
            link.textContent = heading.textContent;
            link.setAttribute('data-toc-target', heading.id);
            link.addEventListener('click', function (e) {
                e.preventDefault();
                heading.scrollIntoView({ behavior: 'smooth', block: 'start' });
                history.replaceState(null, '', '#' + heading.id);
            });
            list.appendChild(link);
        });

        list.classList.remove('d-none');

        var links = list.querySelectorAll('[data-toc-target]');
        var ticking = false;

        function updateActive() {
            var offset = 110;
            var current = headings[0].id;
            headings.forEach(function (heading) {
                if (heading.getBoundingClientRect().top <= offset) {
                    current = heading.id;
                }
            });
            links.forEach(function (link) {
                link.classList.toggle('active', link.getAttribute('data-toc-target') === current);
            });
            ticking = false;
        }

        window.addEventListener('scroll', function () {
            if (!ticking) {
                ticking = true;
                window.requestAnimationFrame(updateActive);
            }
        }, { passive: true });
        updateActive();
    }

    function initLightbox() {
        var article = document.querySelector('[data-article-body]');
        if (!article) return;

        var box = document.createElement('div');
        box.className = 'img-lightbox';
        box.innerHTML = '<img alt="">';
        document.body.appendChild(box);
        var img = box.querySelector('img');

        article.addEventListener('click', function (e) {
            var target = e.target.closest('img');
            if (!target) return;
            img.src = target.src;
            img.alt = target.alt || '';
            box.classList.add('show');
        });

        box.addEventListener('click', function () {
            box.classList.remove('show');
            img.src = '';
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') box.classList.remove('show');
        });
    }
})();
