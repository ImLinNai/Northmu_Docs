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
        initSidebarCollapse();
        initImmersiveNav();
        initCapsuleNav();
        initTocToggle();
        initToc();
        initReadingProgress();
        initLightbox();
        initCategoryCollapse();
        initBackTop();
        initSearchModal();
        initUnlockToggle();
        initContextMenu();
        initCopyProtection();
        if (window.hljs) {
            document.querySelectorAll('[data-article-body] pre code').forEach(function (block) {
                if (!block.classList.contains('hljs')) {
                    try { hljs.highlightElement(block); } catch (e) { }
                }
            });
        }
        initCodeCopy();
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
        var buttons = document.querySelectorAll('[data-toc-toggle]');
        if (!shell || !buttons.length) return;
        var key = 'northmu-toc-' + shell.getAttribute('data-doc-id');
        var stored = null;
        try { stored = localStorage.getItem(key); } catch (e) { }
        if (stored === '0') shell.classList.add('no-toc');
        if (stored === '1') shell.classList.remove('no-toc');

        function sync() {
            var hidden = shell.classList.contains('no-toc');
            buttons.forEach(function (button) {
                button.setAttribute('aria-pressed', hidden ? 'false' : 'true');
                var label = button.querySelector('[data-toc-toggle-text]');
                if (label) label.textContent = hidden ? '显示目录' : '隐藏目录';
                if (button.classList.contains('doc-toc-hide')) {
                    button.setAttribute('title', hidden ? '显示目录' : '隐藏目录');
                    button.setAttribute('aria-label', hidden ? '显示目录' : '隐藏目录');
                }
            });
        }

        sync();
        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var hidden = shell.classList.toggle('no-toc');
                try { localStorage.setItem(key, hidden ? '0' : '1'); } catch (e) { }
                sync();
            });
        });
    }

    function initBackTop() {
        var buttons = document.querySelectorAll('[data-back-top]');
        if (!buttons.length) return;
        var ticking = false;

        function update() {
            var show = window.scrollY > 200;
            buttons.forEach(function (btn) {
                btn.classList.toggle('is-visible', show);
            });
            ticking = false;
        }

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });

        window.addEventListener('scroll', function () {
            if (!ticking) {
                ticking = true;
                window.requestAnimationFrame(update);
            }
        }, { passive: true });
        update();
    }

    function initUnlockToggle() {
        document.querySelectorAll('[data-unlock-toggle]').forEach(function (button) {
            var field = button.closest('.doc-unlock-field');
            var input = field ? field.querySelector('input') : null;
            if (!input) return;
            button.addEventListener('click', function () {
                var reveal = input.type === 'password';
                input.type = reveal ? 'text' : 'password';
                button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
                button.setAttribute('aria-label', reveal ? '隐藏密码' : '显示密码');
                button.setAttribute('title', reveal ? '隐藏密码' : '显示密码');
                var icon = button.querySelector('i');
                if (icon) icon.className = reveal ? 'ti ti-eye-off' : 'ti ti-eye';
                input.focus();
                try {
                    var end = input.value.length;
                    input.setSelectionRange(end, end);
                } catch (e) { }
            });
        });
    }

    function initSearchModal() {
        var modal = document.getElementById('nmSearchModal');
        if (!modal) return;
        var input = modal.querySelector('#nmSearchInput');
        var triggers = document.querySelectorAll('[data-search-open]');
        var closers = modal.querySelectorAll('[data-search-close]');
        var lastFocus = null;

        function isOpen() {
            return modal.classList.contains('is-open');
        }

        function open() {
            lastFocus = document.activeElement;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('nm-search-open');
            if (input) {
                setTimeout(function () {
                    input.focus();
                    var length = input.value.length;
                    try { input.setSelectionRange(length, length); } catch (e) { }
                }, 60);
            }
        }

        function close() {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('nm-search-open');
            if (lastFocus && typeof lastFocus.focus === 'function') {
                lastFocus.focus();
            }
        }

        window.NorthmuSearch = { open: open, close: close };

        triggers.forEach(function (trigger) {
            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                if (isOpen()) close();
                else open();
            });
        });

        closers.forEach(function (closer) {
            closer.addEventListener('click', function (event) {
                event.preventDefault();
                close();
            });
        });

        document.addEventListener('keydown', function (event) {
            var key = event.key || '';
            if ((event.ctrlKey || event.metaKey) && (key === 'k' || key === 'K')) {
                event.preventDefault();
                open();
                return;
            }
            if (!isOpen()) return;
            if (key === 'Escape') {
                event.preventDefault();
                close();
                return;
            }
            if (key === 'Tab') {
                var items = modal.querySelectorAll('input, button, a[href]');
                if (!items.length) return;
                var first = items[0];
                var last = items[items.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });
    }

    function showCopyHint(message, type) {
        if (!message) return;
        var hint = document.getElementById('nmCopyHint');
        if (!hint) {
            hint = document.createElement('div');
            hint.id = 'nmCopyHint';
            hint.className = 'nm-copy-hint';
            hint.setAttribute('role', 'status');
            hint.setAttribute('aria-live', 'polite');
            document.body.appendChild(hint);
        }
        var icons = {
            info: 'ti-info-circle',
            success: 'ti-circle-check',
            warning: 'ti-alert-triangle',
            error: 'ti-circle-x'
        };
        type = icons[type] ? type : 'info';
        hint.className = 'nm-copy-hint is-' + type;
        hint.innerHTML = '';
        var icon = document.createElement('i');
        icon.className = 'ti ' + icons[type];
        var text = document.createElement('span');
        text.textContent = message;
        hint.appendChild(icon);
        hint.appendChild(text);
        void hint.offsetWidth;
        hint.classList.add('show');
        clearTimeout(hint._timer);
        hint._timer = setTimeout(function () {
            hint.classList.remove('show');
        }, 2400);
    }

    function isCopyProtectedDoc() {
        var article = document.querySelector('[data-article-body]');
        return !!(article && article.getAttribute('data-copy-protected') === '1');
    }

    function selectionInside(article) {
        var selection = window.getSelection ? window.getSelection() : null;
        if (!selection || !selection.rangeCount) return false;
        var node = selection.getRangeAt(0).commonAncestorContainer;
        return article.contains(node.nodeType === 3 ? node.parentNode : node);
    }

    function initCopyProtection() {
        var article = document.querySelector('[data-article-body][data-copy-protected="1"]');
        if (!article) return;
        article.classList.add('is-copy-protected');

        var lastShown = 0;
        function block(event) {
            event.preventDefault();
            if (event.stopPropagation) event.stopPropagation();
            var now = Date.now();
            if (now - lastShown > 1200) {
                lastShown = now;
                showCopyHint('本文档已开启复制保护，无法复制内容', 'warning');
            }
        }

        document.addEventListener('copy', function (event) {
            if (selectionInside(article)) block(event);
        }, true);

        document.addEventListener('cut', function (event) {
            if (selectionInside(article) || article.contains(event.target)) block(event);
        }, true);

        document.addEventListener('dragstart', function (event) {
            if (article.contains(event.target)) block(event);
        }, true);

        document.addEventListener('selectstart', function (event) {
            if (article.contains(event.target)) event.preventDefault();
        }, true);

        document.addEventListener('keydown', function (event) {
            if (!(event.ctrlKey || event.metaKey)) return;
            var key = (event.key || '').toLowerCase();
            if (key !== 'c' && key !== 'x' && key !== 'a') return;
            var active = document.activeElement;
            if (active && (/^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName) || active.isContentEditable)) return;
            block(event);
        }, true);
    }

    function initContextMenu() {
        var meta = document.querySelector('meta[name="nm-context-menu"]');
        var mode = meta ? meta.getAttribute('content') : 'default';
        if (mode !== 'disabled' && mode !== 'custom') return;

        if (mode === 'disabled') {
            document.addEventListener('contextmenu', function (event) {
                event.preventDefault();
            });
            return;
        }

        var menu = document.createElement('div');
        menu.className = 'nm-context-menu';
        menu.setAttribute('role', 'menu');
        menu.setAttribute('aria-hidden', 'true');
        document.body.appendChild(menu);
        var isOpen = false;

        function close() {
            if (!isOpen) return;
            isOpen = false;
            menu.classList.remove('is-open');
            menu.setAttribute('aria-hidden', 'true');
        }

        function addItem(icon, label, action, shortcut) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'nm-context-item';
            button.setAttribute('role', 'menuitem');
            var glyph = document.createElement('i');
            glyph.className = 'ti ' + icon;
            var text = document.createElement('span');
            text.textContent = label;
            button.appendChild(glyph);
            button.appendChild(text);
            if (shortcut) {
                var kbd = document.createElement('kbd');
                kbd.textContent = shortcut;
                button.appendChild(kbd);
            }
            button.addEventListener('click', function () {
                close();
                action();
            });
            menu.appendChild(button);
        }

        function addSeparator() {
            var sep = document.createElement('div');
            sep.className = 'nm-context-sep';
            menu.appendChild(sep);
        }

        function openAt(x, y) {
            menu.style.left = '0px';
            menu.style.top = '0px';
            menu.classList.add('is-open');
            menu.setAttribute('aria-hidden', 'false');
            isOpen = true;
            var rect = menu.getBoundingClientRect();
            var left = Math.max(8, Math.min(x, window.innerWidth - rect.width - 8));
            var top = Math.max(8, Math.min(y, window.innerHeight - rect.height - 8));
            menu.style.left = left + 'px';
            menu.style.top = top + 'px';
        }

        function build(event) {
            menu.innerHTML = '';
            var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
            var selection = window.getSelection ? String(window.getSelection()).trim() : '';
            var protectedDoc = isCopyProtectedDoc();

            if (link) {
                addItem('ti-external-link', '在新标签页打开', function () {
                    window.open(link.href, '_blank', 'noopener');
                });
                addItem('ti-copy', '复制链接地址', function () {
                    copyText(link.href, function (ok) {
                        showCopyHint(ok ? '链接已复制' : '复制失败', ok ? 'success' : 'error');
                    });
                });
                addSeparator();
            }

            if (selection !== '' && !protectedDoc) {
                addItem('ti-copy', '复制选中文字', function () {
                    copyText(selection, function (ok) {
                        showCopyHint(ok ? '已复制选中文字' : '复制失败', ok ? 'success' : 'error');
                    });
                });
                addSeparator();
            }

            if (window.history.length > 1 || document.referrer) {
                addItem('ti-arrow-left', '返回上一页', function () {
                    window.history.back();
                });
            }
            addItem('ti-refresh', '刷新页面', function () {
                window.location.reload();
            });
            addItem('ti-copy', '复制页面链接', function () {
                copyText(window.location.href, function (ok) {
                    showCopyHint(ok ? '页面链接已复制' : '复制失败', ok ? 'success' : 'error');
                });
            });
            addSeparator();
            addItem('ti-search', '搜索文档', function () {
                if (window.NorthmuSearch) window.NorthmuSearch.open();
            }, 'Ctrl K');
            addItem('ti-moon', '切换主题', function () {
                if (window.NorthmuTheme) window.NorthmuTheme.toggle();
            });
            if (window.scrollY > 200) {
                addItem('ti-arrow-up', '回到顶部', function () {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                });
            }
        }

        document.addEventListener('contextmenu', function (event) {
            if (event.defaultPrevented) return;
            event.preventDefault();
            build(event);
            openAt(event.clientX, event.clientY);
        });

        document.addEventListener('mousedown', function (event) {
            if (isOpen && !menu.contains(event.target)) close();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') close();
        });

        window.addEventListener('scroll', close, { passive: true });
        window.addEventListener('resize', close);
    }

    function copyText(text, done) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                done(true);
            }, function () {
                fallbackCopy(text, done);
            });
            return;
        }
        fallbackCopy(text, done);
    }

    function fallbackCopy(text, done) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { }
        area.remove();
        done(ok);
    }

    function initCodeCopy() {
        document.querySelectorAll('[data-article-body] pre').forEach(function (pre) {
            if (pre.parentElement && pre.parentElement.classList.contains('nm-code-wrap')) return;

            var wrap = document.createElement('div');
            wrap.className = 'nm-code-wrap';

            var head = document.createElement('div');
            head.className = 'nm-code-head';

            var lang = document.createElement('span');
            lang.className = 'nm-code-lang';
            var code = pre.querySelector('code');
            var match = code ? (code.className || '').match(/(?:language|lang)-([\w+#-]+)/) : null;
            lang.textContent = match ? match[1].toUpperCase() : 'CODE';

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'nm-code-copy';
            var protectedDoc = !!pre.closest('[data-copy-protected="1"]');
            if (protectedDoc) {
                button.classList.add('is-protected');
                button.setAttribute('aria-label', '本文档禁止复制');
                button.innerHTML = '<i class="ti ti-lock"></i><span>禁止复制</span>';
            } else {
                button.setAttribute('aria-label', '复制代码');
                button.innerHTML = '<i class="ti ti-copy"></i><span>复制</span>';
            }
            button.addEventListener('click', function () {
                if (protectedDoc) {
                    showCopyHint('本文档已开启复制保护，无法复制内容', 'warning');
                    return;
                }
                copyText(pre.innerText, function (ok) {
                    button.classList.toggle('is-copied', ok);
                    button.innerHTML = ok
                        ? '<i class="ti ti-check"></i><span>已复制</span>'
                        : '<i class="ti ti-x"></i><span>复制失败</span>';
                    clearTimeout(button._copyTimer);
                    button._copyTimer = setTimeout(function () {
                        button.classList.remove('is-copied');
                        button.innerHTML = '<i class="ti ti-copy"></i><span>复制</span>';
                    }, 1800);
                });
            });

            head.appendChild(lang);
            head.appendChild(button);
            pre.parentNode.insertBefore(wrap, pre);
            wrap.appendChild(head);
            wrap.appendChild(pre);
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

    function initSidebarCollapse() {
        var shell = document.querySelector('.doc-shell');
        var buttons = document.querySelectorAll('[data-sidebar-collapse]');
        if (!shell || !buttons.length) return;
        var key = 'northmu-doc-sidebar-collapsed';
        var desktop = window.matchMedia('(min-width: 768px)');
        var flyouts = [];
        var flyoutLink = null;
        var hideTimer = null;

        function isCollapsed() {
            return shell.classList.contains('sidebar-collapsed');
        }

        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, function (ch) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
            });
        }

        function clearHide() {
            if (hideTimer) {
                clearTimeout(hideTimer);
                hideTimer = null;
            }
        }

        function closeFrom(depth) {
            while (flyouts.length && flyouts[flyouts.length - 1].depth >= depth) {
                var entry = flyouts.pop();
                entry.el.remove();
            }
            if (!flyouts.length && flyoutLink) {
                flyoutLink.classList.remove('is-flyout-open');
                flyoutLink = null;
            }
        }

        function closeFlyout() {
            clearHide();
            closeFrom(0);
        }

        function scheduleHide(depth) {
            clearHide();
            hideTimer = setTimeout(function () { closeFrom(depth); }, 160);
        }

        function positionFlyout(panel, rect) {
            var gap = 8;
            var left = rect.right + gap;
            var width = panel.offsetWidth;
            var height = panel.offsetHeight;
            if (left + width > window.innerWidth - 10) {
                left = Math.max(10, rect.left - width - gap);
            }
            var top = rect.top;
            if (top + height > window.innerHeight - 12) {
                top = Math.max(10, window.innerHeight - height - 12);
            }
            panel.style.left = Math.round(left) + 'px';
            panel.style.top = Math.round(top) + 'px';
        }

        function categoryInfo(item) {
            var link = item.querySelector(':scope > .cat-row .cat-link');
            if (!link) return null;
            var nameEl = link.querySelector('span.text-truncate') || link.querySelector('span');
            return {
                link: link,
                name: nameEl ? nameEl.textContent.trim() : (link.getAttribute('title') || '分类'),
                href: link.getAttribute('href') || '#'
            };
        }

        function createFlyoutItem(tag, className, icon, text, tail) {
            var row = document.createElement(tag);
            row.className = 'nm-cat-flyout-item ' + className;
            row.innerHTML = '<i class="ti ' + icon + '"></i><span>' + escapeHtml(text) + '</span>' + (tail || '');
            return row;
        }

        function openFlyout(item, anchor, depth) {
            if (!isCollapsed() || !desktop.matches) return;
            var info = categoryInfo(item);
            if (!info) return;
            var existing = flyouts[depth];
            if (existing && existing.item === item) {
                clearHide();
                closeFrom(depth + 1);
                return;
            }
            closeFrom(depth);
            clearHide();

            var panel = document.createElement('div');
            panel.className = 'nm-cat-flyout';
            panel.dataset.depth = depth;
            panel.addEventListener('mouseenter', clearHide);
            panel.addEventListener('mouseleave', function () { scheduleHide(depth); });

            var head = document.createElement('a');
            head.className = 'nm-cat-flyout-head';
            head.href = info.href;
            head.innerHTML = '<i class="ti ti-folder"></i><span>' + escapeHtml(info.name) + '</span>';
            panel.appendChild(head);

            var list = document.createElement('div');
            list.className = 'nm-cat-flyout-list';
            var children = item.querySelector(':scope > .cat-children');
            var childNodes = children ? Array.prototype.slice.call(children.children) : [];
            var subItems = childNodes.filter(function (el) { return el.classList.contains('cat-item'); });
            var docItems = childNodes.filter(function (el) { return el.classList.contains('cat-doc-item'); });

            subItems.forEach(function (subItem) {
                var sub = categoryInfo(subItem);
                if (!sub) return;
                var row = createFlyoutItem('a', 'is-sub', 'ti-folder', sub.name, '<i class="ti ti-chevron-right nm-cat-flyout-arrow"></i>');
                row.href = sub.href;
                row.addEventListener('mouseenter', function () { openFlyout(subItem, row, depth + 1); });
                row.addEventListener('focus', function () { openFlyout(subItem, row, depth + 1); });
                list.appendChild(row);
            });

            docItems.forEach(function (docItem) {
                var link = docItem.querySelector('.cat-doc-link');
                if (!link) return;
                var titleEl = link.querySelector('span');
                var title = link.getAttribute('title') || (titleEl ? titleEl.textContent : '未命名文档');
                var locked = link.querySelector('.cat-lock');
                var row = createFlyoutItem('a', 'is-doc', 'ti-file-text', title,
                    locked ? '<i class="ti ti-lock nm-cat-flyout-lock"></i>' : '');
                row.href = link.getAttribute('href') || '#';
                if (link.classList.contains('active')) row.classList.add('active');
                list.appendChild(row);
            });

            if (!list.children.length) {
                var empty = document.createElement('div');
                empty.className = 'nm-cat-flyout-empty';
                empty.textContent = '该分类下暂无内容';
                list.appendChild(empty);
            }

            panel.appendChild(list);
            document.body.appendChild(panel);
            panel.classList.add('show');
            positionFlyout(panel, anchor.getBoundingClientRect());
            flyouts.push({ el: panel, item: item, depth: depth });

            if (depth === 0) {
                if (flyoutLink && flyoutLink !== anchor) flyoutLink.classList.remove('is-flyout-open');
                flyoutLink = anchor;
                anchor.classList.add('is-flyout-open');
            }
        }

        function bindFlyoutItems() {
            document.querySelectorAll('.cat-tree-wrap > .cat-tree > .cat-item').forEach(function (item) {
                if (item.getAttribute('data-flyout-bound') === '1') return;
                var link = item.querySelector(':scope > .cat-row .cat-link');
                item.setAttribute('data-flyout-bound', '1');
                if (!link) return;
                item.addEventListener('mouseenter', function () { openFlyout(item, link, 0); });
                item.addEventListener('mouseleave', function () {
                    if (isCollapsed()) scheduleHide(0);
                });
                link.addEventListener('focus', function () { openFlyout(item, link, 0); });
                link.addEventListener('blur', function () {
                    if (isCollapsed()) scheduleHide(0);
                });
            });
        }

        function sync(collapsed) {
            shell.classList.toggle('sidebar-collapsed', collapsed);
            buttons.forEach(function (button) {
                button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                button.setAttribute('title', collapsed ? '展开分类栏' : '折叠分类栏');
                button.setAttribute('aria-label', collapsed ? '展开分类栏' : '折叠分类栏');
            });
            if (!collapsed) closeFlyout();
        }

        var stored = null;
        try { stored = localStorage.getItem(key); } catch (e) { }
        sync(stored === '1');
        bindFlyoutItems();

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var collapsed = !shell.classList.contains('sidebar-collapsed');
                sync(collapsed);
                try { localStorage.setItem(key, collapsed ? '1' : '0'); } catch (e) { }
            });
        });

        window.addEventListener('scroll', closeFlyout, { passive: true });
        window.addEventListener('resize', function () {
            if (!desktop.matches) closeFlyout();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeFlyout();
        });
    }

    function initImmersiveNav() {
        var nav = document.querySelector('.front-nav.is-immersive');
        if (!nav) return;
        var ticking = false;

        function update() {
            nav.classList.toggle('is-scrolled', window.scrollY > 24);
            ticking = false;
        }

        window.addEventListener('scroll', function () {
            if (!ticking) {
                ticking = true;
                window.requestAnimationFrame(update);
            }
        }, { passive: true });
        update();
    }

    function initCapsuleNav() {
        var linksNav = document.querySelector('.front-nav.is-capsule .front-nav-links');
        var pill = linksNav && linksNav.querySelector('.front-nav-indicator');
        if (!linksNav || !pill) return;
        var links = Array.prototype.slice.call(linksNav.querySelectorAll('.nav-link'));
        if (!links.length) return;
        var active = linksNav.querySelector('.nav-link.active');

        function place(link, opacity) {
            pill.style.width = link.offsetWidth + 'px';
            pill.style.height = link.offsetHeight + 'px';
            pill.style.transform = 'translate(' + link.offsetLeft + 'px, -50%)';
            pill.style.opacity = opacity;
        }

        function rest() {
            if (active && active.offsetWidth > 0) {
                place(active, '.55');
            } else {
                pill.style.opacity = '0';
            }
        }

        links.forEach(function (link) {
            link.addEventListener('pointerenter', function () {
                place(link, '1');
            });
        });
        linksNav.addEventListener('pointerleave', rest);
        window.addEventListener('resize', rest);
        window.addEventListener('load', rest);
        rest();
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
            if (stored === '0' && !target.querySelector('.active')) {
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
            document.querySelectorAll('[data-toc-toggle]').forEach(function (toggle) {
                toggle.classList.add('d-none');
            });
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

    function initReadingProgress() {
        var bar = document.querySelector('[data-reading-progress]');
        var body = document.querySelector('[data-article-body]');
        if (!bar || !body) return;

        var timeEl = document.querySelector('[data-reading-time]');
        var timeText = document.querySelector('[data-reading-time-text]');
        if (timeEl && timeText) {
            var chars = (body.textContent || '').replace(/\s+/g, '').length;
            if (chars > 0) {
                timeText.textContent = '约 ' + Math.max(1, Math.ceil(chars / 350)) + ' 分钟阅读';
                timeEl.hidden = false;
            }
        }

        var ticking = false;
        function update() {
            ticking = false;
            var rect = body.getBoundingClientRect();
            var extent = rect.height - window.innerHeight + 140;
            var ratio = extent <= 0 ? (rect.top < 80 ? 1 : 0) : (80 - rect.top) / extent;
            bar.style.transform = 'scaleX(' + Math.max(0, Math.min(1, ratio)) + ')';
        }
        function request() {
            if (ticking) return;
            ticking = true;
            window.requestAnimationFrame(update);
        }
        window.addEventListener('scroll', request, { passive: true });
        window.addEventListener('resize', request);
        update();
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
