(function () {
    'use strict';

    var ICONS = {
        success: 'ti-circle-check',
        error: 'ti-circle-x',
        warning: 'ti-alert-triangle',
        info: 'ti-info-circle'
    };

    function normalizeType(type) {
        type = String(type || 'success').toLowerCase();
        if (type === 'danger' || type === 'fail' || type === 'failed' || type === 'failure') return 'error';
        if (type === 'warn') return 'warning';
        if (type === 'message' || type === 'notice') return 'info';
        return ICONS[type] ? type : 'success';
    }

    /* ---------- Toast（页面底部居中） ---------- */
    function toastWrap() {
        var wrap = document.getElementById('nmToastWrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = 'nmToastWrap';
            wrap.className = 'nm-toast-wrap';
            wrap.setAttribute('role', 'status');
            wrap.setAttribute('aria-live', 'polite');
            document.body.appendChild(wrap);
        }
        return wrap;
    }

    function toast(message, type, options) {
        if (!message) return null;
        options = options || {};
        type = normalizeType(type);
        var duration = Math.max(1600, Number(options.duration) || 4200);
        var wrap = toastWrap();
        var existing = wrap.children.length;
        if (existing >= 4) {
            wrap.removeChild(wrap.firstElementChild);
        }

        var el = document.createElement('div');
        el.className = 'nm-toast nm-toast-' + type;
        el.style.setProperty('--nm-toast-duration', duration + 'ms');

        var tile = document.createElement('span');
        tile.className = 'nm-toast-icon-tile';
        tile.innerHTML = '<i class="ti ' + ICONS[type] + '"></i>';

        var body = document.createElement('div');
        body.className = 'nm-toast-message';
        body.textContent = message;

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'nm-toast-close';
        close.setAttribute('aria-label', '关闭提示');
        close.innerHTML = '<i class="ti ti-x"></i>';

        var progress = document.createElement('span');
        progress.className = 'nm-toast-progress';

        var timer = null;
        function remove() {
            if (!el.parentNode) return;
            clearTimeout(timer);
            el.classList.remove('show');
            el.classList.add('hide');
            setTimeout(function () { el.remove(); }, 240);
        }

        close.addEventListener('click', remove);

        el.appendChild(tile);
        el.appendChild(body);
        el.appendChild(close);
        el.appendChild(progress);
        wrap.appendChild(el);
        window.requestAnimationFrame(function () { el.classList.add('show'); });
        timer = setTimeout(remove, duration);
        return remove;
    }

    /* ---------- Popconfirm（气泡确认框） ---------- */
    var activeConfirm = null;
    var lastPointer = null;

    document.addEventListener('pointerdown', function (event) {
        lastPointer = { x: event.clientX, y: event.clientY };
    }, true);

    function closeConfirm(result) {
        if (!activeConfirm) return;
        var current = activeConfirm;
        activeConfirm = null;
        document.removeEventListener('mousedown', current.onOutside, true);
        document.removeEventListener('keydown', current.onKey, true);
        window.removeEventListener('resize', current.onReposition);
        window.removeEventListener('scroll', current.onReposition, true);
        current.el.remove();
        if (current.restoreFocus) {
            try { current.anchor.focus({ preventScroll: true }); } catch (e) { }
        }
        current.resolve(result);
    }

    function positionConfirm(el, anchor) {
        var pad = 8;
        var width = el.offsetWidth;
        var height = el.offsetHeight;
        var left;
        var top;
        var rect = anchor && anchor.getBoundingClientRect ? anchor.getBoundingClientRect() : null;
        var anchored = rect && rect.width > 0 && rect.height > 0 &&
            rect.bottom > 0 && rect.top < window.innerHeight;
        if (anchored) {
            left = rect.left + rect.width / 2 - width / 2;
            top = rect.top - height - 10;
            if (top < pad) top = rect.bottom + 10;
        } else if (lastPointer) {
            left = lastPointer.x - width / 2;
            top = lastPointer.y - height - 12;
            if (top < pad) top = lastPointer.y + 14;
        } else if (rect) {
            left = rect.left + rect.width / 2 - width / 2;
            top = rect.top - height - 10;
        } else {
            return;
        }
        left = Math.max(pad, Math.min(left, window.innerWidth - width - pad));
        top = Math.max(pad, Math.min(top, window.innerHeight - height - pad));
        el.style.left = Math.round(left) + 'px';
        el.style.top = Math.round(top) + 'px';
    }

    function confirmAction(options) {
        options = options || {};
        var anchor = options.anchor || document.activeElement;
        if (!anchor || !anchor.getBoundingClientRect) {
            return Promise.resolve(window.confirm(options.message || '确定继续？'));
        }
        closeConfirm(false);

        return new Promise(function (resolve) {
            var tone = options.tone === 'danger' ? 'danger' : 'warning';
            var el = document.createElement('div');
            el.className = 'nm-popconfirm is-' + tone;
            el.setAttribute('role', 'alertdialog');
            el.setAttribute('aria-modal', 'false');

            var title = document.createElement('div');
            title.className = 'nm-popconfirm-title';
            title.innerHTML = '<i class="ti ' + (tone === 'danger' ? 'ti-alert-hexagon' : 'ti-help-circle') + '"></i>';
            var titleText = document.createElement('span');
            titleText.textContent = options.title || (tone === 'danger' ? '确认删除' : '请确认操作');
            title.appendChild(titleText);

            var message = document.createElement('div');
            message.className = 'nm-popconfirm-message';
            message.textContent = options.message || '确定继续执行该操作吗？';

            var actions = document.createElement('div');
            actions.className = 'nm-popconfirm-actions';

            var cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'btn btn-sm btn-ghost-secondary';
            cancel.textContent = options.cancelText || '取消';

            var ok = document.createElement('button');
            ok.type = 'button';
            ok.className = 'btn btn-sm ' + (tone === 'danger' ? 'btn-danger' : 'btn-primary');
            ok.textContent = options.confirmText || '确定';

            actions.appendChild(cancel);
            actions.appendChild(ok);
            el.appendChild(title);
            el.appendChild(message);
            el.appendChild(actions);
            document.body.appendChild(el);

            var onOutside = function (event) {
                if (!el.contains(event.target) && !anchor.contains(event.target)) {
                    closeConfirm(false);
                }
            };
            var onKey = function (event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeConfirm(false);
                } else if (event.key === 'Tab') {
                    var focusables = el.querySelectorAll('button');
                    if (!focusables.length) return;
                    var first = focusables[0];
                    var last = focusables[focusables.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                }
            };
            var onReposition = function () { positionConfirm(el, anchor); };

            activeConfirm = {
                el: el,
                anchor: anchor,
                resolve: resolve,
                onOutside: onOutside,
                onKey: onKey,
                onReposition: onReposition,
                restoreFocus: options.restoreFocus !== false
            };

            cancel.addEventListener('click', function () { closeConfirm(false); });
            ok.addEventListener('click', function () { closeConfirm(true); });

            positionConfirm(el, anchor);
            document.addEventListener('mousedown', onOutside, true);
            document.addEventListener('keydown', onKey, true);
            window.addEventListener('resize', onReposition);
            window.addEventListener('scroll', onReposition, true);
            window.requestAnimationFrame(function () { ok.focus({ preventScroll: true }); });
        });
    }

    /* ---------- 提交拦截：data-confirm / data-confirm-batch ---------- */
    var CONFIRM_ATTRS = ['data-confirm', 'data-nm-confirm', 'data-confirm-batch', 'data-nm-confirm-batch'];

    function hasConfirm(form) {
        return CONFIRM_ATTRS.some(function (attr) { return form.hasAttribute(attr); });
    }

    function submitWithConfirm(form, submitter) {
        var batch = form.getAttribute('data-confirm-batch') || form.getAttribute('data-nm-confirm-batch');
        if (batch !== null && batch !== undefined) {
            var checked = form.querySelectorAll('[data-check-item]:checked').length;
            if (checked === 0) {
                toast('请先选择要操作的项目', 'warning');
                return;
            }
        }
        var message = form.getAttribute('data-confirm') || form.getAttribute('data-nm-confirm') ||
            form.getAttribute('data-confirm-batch') || form.getAttribute('data-nm-confirm-batch') || '确定执行该操作吗？';
        var tone = form.getAttribute('data-confirm-tone') || (form.querySelector('[data-confirm-danger], .btn-danger') ? 'danger' : 'warning');
        confirmAction({
            anchor: submitter || form.querySelector('[type="submit"]') || form,
            title: form.getAttribute('data-confirm-title') || (tone === 'danger' ? '确认执行' : '请确认操作'),
            message: message,
            tone: tone,
            confirmText: form.getAttribute('data-confirm-ok') || '确定'
        }).then(function (ok) {
            if (!ok) return;
            form.dataset.nmConfirmed = '1';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter || undefined);
            } else {
                form.submit();
            }
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') return;
        if (form.dataset.nmConfirmed === '1') {
            delete form.dataset.nmConfirmed;
            return;
        }
        if (!hasConfirm(form)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        submitWithConfirm(form, event.submitter);
    }, true);

    /* ---------- 链接/按钮确认（delegated） ---------- */
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-nm-confirm-click]');
        if (!trigger) return;
        if (trigger.dataset.nmConfirmed === '1') {
            delete trigger.dataset.nmConfirmed;
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        var tone = trigger.getAttribute('data-confirm-tone') || 'danger';
        confirmAction({
            anchor: trigger,
            title: trigger.getAttribute('data-confirm-title') || '请确认操作',
            message: trigger.getAttribute('data-nm-confirm-click') || '确定继续吗？',
            tone: tone,
            confirmText: trigger.getAttribute('data-confirm-ok') || '确定'
        }).then(function (ok) {
            if (!ok) return;
            trigger.dataset.nmConfirmed = '1';
            var href = trigger.getAttribute('href');
            if (trigger.tagName === 'A' && href && href !== '#') {
                window.location.href = href;
            } else if (typeof trigger.click === 'function') {
                trigger.click();
            }
        });
    }, true);

    /* ---------- Tooltip / Popover ---------- */
    function initTooltips(root) {
        if (!window.bootstrap || !bootstrap.Tooltip) return;
        root = root || document;
        root.querySelectorAll('[data-nm-tip], [data-bs-toggle="tooltip"]').forEach(function (el) {
            var text = el.getAttribute('data-nm-tip');
            var toggle = el.getAttribute('data-bs-toggle');
            if (toggle && toggle !== 'tooltip') {
                if (text && !el.getAttribute('title')) {
                    el.setAttribute('title', text);
                }
                return;
            }
            if (text && !el.getAttribute('title')) {
                el.setAttribute('title', text);
            }
            if (!bootstrap.Tooltip.getInstance(el)) {
                new bootstrap.Tooltip(el, {
                    trigger: el.getAttribute('data-nm-tip') ? 'hover focus' : undefined,
                    placement: el.getAttribute('data-tip-placement') || 'top',
                    container: 'body',
                    delay: { show: 180, hide: 80 }
                });
            }
        });
    }

    function initPopovers(root) {
        if (!window.bootstrap || !bootstrap.Popover) return;
        root = root || document;
        root.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
            if (!bootstrap.Popover.getInstance(el)) {
                new bootstrap.Popover(el, { container: 'body', trigger: 'click', html: false });
            }
        });
    }

    /* ---------- Modal / Drawer 便捷方法 ---------- */
    function component(instanceName, id) {
        var el = typeof id === 'string' ? document.getElementById(id) : id;
        if (!el || !window.bootstrap) return null;
        var Instance = bootstrap[instanceName];
        return Instance ? Instance.getOrCreateInstance(el) : null;
    }

    function openDrawer(id) {
        var drawer = component('Offcanvas', id);
        if (drawer) drawer.show();
        return drawer;
    }

    function closeDrawer(id) {
        var drawer = component('Offcanvas', id);
        if (drawer) drawer.hide();
        return drawer;
    }

    function openModal(id) {
        var modal = component('Modal', id);
        if (modal) modal.show();
        return modal;
    }

    function closeModal(id) {
        var modal = component('Modal', id);
        if (modal) modal.hide();
        return modal;
    }

    /* ---------- 其他工具 ---------- */
    function copy(text, successMessage) {
        var done = function () { toast(successMessage || '已复制到剪贴板', 'success'); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).then(done).catch(function () {
                window.prompt('复制内容', text);
            });
        }
        window.prompt('复制内容', text);
        return Promise.resolve();
    }

    function busy(button, isBusy, busyText) {
        if (!button) return;
        if (isBusy) {
            if (button.dataset.nmBusy === '1') return;
            button.dataset.nmBusy = '1';
            button.dataset.nmOriginalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>' + (busyText || '处理中…');
        } else {
            if (button.dataset.nmBusy !== '1') return;
            delete button.dataset.nmBusy;
            button.disabled = false;
            if (button.dataset.nmOriginalHtml) {
                button.innerHTML = button.dataset.nmOriginalHtml;
                delete button.dataset.nmOriginalHtml;
            }
        }
    }

    function post(url, data) {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? meta.getAttribute('content') : '';
        var body = new URLSearchParams();
        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (value !== null && value !== undefined) body.append(key, value);
        });
        return fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': token,
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (response) {
            var type = response.headers.get('content-type') || '';
            if (type.indexOf('application/json') === -1) {
                throw new Error('服务器返回了非 JSON 响应');
            }
            return response.json();
        });
    }

    function init() {
        initTooltips(document);
        initPopovers(document);
        document.querySelectorAll('.toast, .alert').forEach(function (el) {
            if (el.classList.contains('alert-dismissible') && !el.querySelector('.btn-close')) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn-close';
                btn.setAttribute('data-bs-dismiss', 'alert');
                btn.setAttribute('aria-label', '关闭');
                el.appendChild(btn);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.NorthmuUI = {
        toast: toast,
        confirm: confirmAction,
        tooltip: initTooltips,
        popover: initPopovers,
        openModal: openModal,
        closeModal: closeModal,
        openDrawer: openDrawer,
        closeDrawer: closeDrawer,
        copy: copy,
        busy: busy,
        post: post
    };
})();
