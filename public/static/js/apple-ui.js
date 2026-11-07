/* ============================================================
   Northmu Docs · Apple Design Runtime
   按压反馈、锚定浮层、弹簧动画与减弱动态偏好。
   ============================================================ */
(function () {
    'use strict';

    var reduceQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var reduced = !!(reduceQuery && reduceQuery.matches);

    if (reduceQuery) {
        var onReduceChange = function (event) { reduced = event.matches; };
        if (reduceQuery.addEventListener) {
            reduceQuery.addEventListener('change', onReduceChange);
        } else if (reduceQuery.addListener) {
            reduceQuery.addListener(onReduceChange);
        }
    }

    var TAU = Math.PI * 2;

    /**
     * 临界阻尼弹簧。可随时 retarget，并从当前值与当前速度继续。
     * options: from, to, velocity(px/s), damping(1 无回弹), response(秒),
     *          precision, onUpdate(value), onComplete().
     */
    function spring(options) {
        options = options || {};

        var value = Number(options.from) || 0;
        var target = Number(options.to) || 0;
        var velocity = Number(options.velocity) || 0;
        var damping = options.damping == null ? 1 : Math.max(0, Number(options.damping) || 0);
        var response = Math.max(.06, Number(options.response) || .35);
        var precision = Math.max(.0001, Number(options.precision) || .01);
        var onUpdate = options.onUpdate || function () {};
        var onComplete = options.onComplete || null;

        var frame = 0;
        var last = 0;
        var running = false;
        var complete = false;
        var stopped = false;

        function finish() {
            value = target;
            velocity = 0;
            running = false;
            if (frame) {
                cancelAnimationFrame(frame);
                frame = 0;
            }
            onUpdate(value);
            if (!complete) {
                complete = true;
                if (onComplete) onComplete();
            }
        }

        function tick(now) {
            frame = 0;
            if (!running || stopped) return;

            if (!last) last = now;
            var dt = Math.min((now - last) / 1000, 1 / 30);
            last = now;

            var omega = TAU / response;
            var steps = Math.max(1, Math.ceil(dt / (1 / 240)));
            var step = dt / steps;

            for (var i = 0; i < steps; i++) {
                var acceleration = -omega * omega * (value - target) - 2 * damping * omega * velocity;
                velocity += acceleration * step;
                value += velocity * step;
            }

            var settled = Math.abs(value - target) < precision && Math.abs(velocity) < precision * 4;
            if (settled) {
                finish();
                return;
            }

            onUpdate(value);
            frame = requestAnimationFrame(tick);
        }

        function start() {
            if (stopped) return;
            running = true;
            complete = false;
            last = 0;
            if (frame) cancelAnimationFrame(frame);
            if (reduced) {
                finish();
                return;
            }
            frame = requestAnimationFrame(tick);
        }

        var controller = {
            retarget: function (next, nextVelocity) {
                target = Number(next) || 0;
                if (nextVelocity != null) velocity = Number(nextVelocity) || 0;
                start();
            },
            stop: function () {
                stopped = true;
                running = false;
                if (frame) {
                    cancelAnimationFrame(frame);
                    frame = 0;
                }
            },
            finish: finish,
            value: function () { return value; },
            velocity: function () { return velocity; }
        };

        if (reduced) {
            finish();
        } else {
            start();
        }

        return controller;
    }

    /* ---------- 按压反馈：在指针按下的同一帧发生 ---------- */
    var pressSelector = '.btn, .btn-icon, .dropdown-item, .nav-link, .nm-pressable, [role="button"]';
    var pressed = new Set();

    function releasePress(el) {
        if (!el) return;
        el.classList.remove('is-pressed');
        pressed.delete(el);
    }

    function releaseAllPress() {
        pressed.forEach(releasePress);
        pressed.clear();
    }

    document.addEventListener('pointerdown', function (event) {
        if (event.button !== 0 && event.pointerType === 'mouse') return;
        var target = event.target;
        if (!target || !target.closest) return;
        var el = target.closest(pressSelector);
        if (!el || el.disabled || el.getAttribute('aria-disabled') === 'true') return;
        el.classList.add('is-pressed');
        pressed.add(el);
    }, { passive: true });

    window.addEventListener('pointerup', releaseAllPress, { passive: true });
    window.addEventListener('pointercancel', releaseAllPress, { passive: true });
    window.addEventListener('blur', releaseAllPress);
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) releaseAllPress();
    });

    /* ---------- 浮层从触发点生长 ---------- */
    function setOriginFromToggle(menu, toggle) {
        if (!menu || !toggle || !toggle.getBoundingClientRect) return;
        var t = toggle.getBoundingClientRect();
        var m = menu.getBoundingClientRect();
        if (!m.width || !m.height) return;

        var horizontal;
        if (m.right <= t.left + 2) {
            horizontal = 'right';
        } else if (m.left >= t.right - 2) {
            horizontal = 'left';
        } else {
            horizontal = (m.left + m.width / 2) < (t.left + t.width / 2) ? 'left' : 'right';
        }

        var vertical;
        if (m.bottom <= t.top + 2) {
            vertical = 'bottom';
        } else if (m.top >= t.bottom - 2) {
            vertical = 'top';
        } else {
            vertical = (m.top + m.height / 2) < (t.top + t.height / 2) ? 'top' : 'bottom';
        }

        menu.style.transformOrigin = horizontal + ' ' + vertical;
    }

    document.addEventListener('shown.bs.dropdown', function (event) {
        var toggle = event.target;
        if (!toggle || !toggle.closest) return;
        var host = toggle.closest('.dropdown, .dropup, .dropend, .dropstart, .btn-group, .nav-item, .nav');
        var menu = host && host.querySelector('.dropdown-menu');
        if (menu) setOriginFromToggle(menu, toggle);
    });

    document.addEventListener('shown.bs.popover', function (event) {
        var toggle = event.target;
        var popover = document.querySelector('.popover');
        if (popover) setOriginFromToggle(popover, toggle);
    });

    /* ---------- 滚动后的浮层边缘 ---------- */
    function initScrollState() {
        var targets = Array.prototype.slice.call(document.querySelectorAll('.front-nav, .nm-admin-topbar'));
        if (!targets.length) return;

        var ticking = false;

        function update() {
            ticking = false;
            var scrolled = window.scrollY > 8;
            targets.forEach(function (el) {
                el.classList.toggle('is-scrolled', scrolled);
            });
        }

        function request() {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(update);
        }

        window.addEventListener('scroll', request, { passive: true });
        update();
    }

    /* ---------- 手势工具 ---------- */
    function stamp() {
        return window.performance && performance.now ? performance.now() : Date.now();
    }

    function trackSample(samples, event) {
        var t = stamp();
        samples.push({ t: t, x: event.clientX, y: event.clientY });
        while (samples.length > 2 && t - samples[0].t > 120) {
            samples.shift();
        }
    }

    function releaseVelocity(samples, axis) {
        if (samples.length < 2) return 0;
        var first = samples[0];
        var last = samples[samples.length - 1];
        var dt = (last.t - first.t) / 1000;
        if (dt <= 0.001) return 0;
        return (last[axis] - first[axis]) / dt;
    }

    function projectMomentum(velocity, deceleration) {
        deceleration = deceleration || 0.998;
        return (velocity / 1000) * deceleration / (1 - deceleration);
    }

    function rubberband(overshoot, dimension, constant) {
        constant = constant || 0.55;
        return (overshoot * dimension * constant) / (dimension + constant * Math.abs(overshoot));
    }

    function setOpacity(el, value) {
        if (!el) return;
        el.style.opacity = String(Math.max(0, Math.min(1, value)));
    }

    function clearStyles(list) {
        for (var i = 0; i < list.length; i++) {
            var entry = list[i];
            if (entry && entry.el) entry.el.style[entry.prop] = '';
        }
    }

    /* ---------- 移动端分类抽屉：跟手拖动 + 速度接管 ---------- */
    function initDrawerGesture() {
        var drawer = document.querySelector('.doc-sidebar');
        var backdrop = document.querySelector('.doc-sidebar-backdrop');
        if (!drawer) return;

        var media = window.matchMedia('(max-width: 767.98px)');
        var state = null;
        var animation = null;
        var suppressClick = false;

        function cancelAnimation() {
            if (animation) {
                animation.stop();
                animation = null;
            }
        }

        function cleanupDrag() {
            cancelAnimation();
            drawer.classList.remove('is-dragging');
            drawer.style.translate = '';
            if (backdrop) {
                backdrop.classList.remove('is-dragging');
                backdrop.style.opacity = '';
            }
        }

        function syncBackdrop(x) {
            if (!backdrop) return;
            var width = drawer.offsetWidth || 280;
            setOpacity(backdrop, 1 - Math.min(1, Math.max(0, -x / width)) * 0.92);
        }

        function settleOpen(from, velocity) {
            animation = spring({
                from: from,
                to: 0,
                velocity: velocity,
                damping: 0.92,
                response: 0.3,
                precision: 0.5,
                onUpdate: function (x) {
                    drawer.style.translate = x + 'px 0';
                    syncBackdrop(x);
                },
                onComplete: function () {
                    animation = null;
                    drawer.classList.remove('is-dragging');
                    drawer.style.translate = '';
                    if (backdrop) {
                        backdrop.classList.remove('is-dragging');
                        backdrop.style.opacity = '';
                    }
                }
            });
        }

        function closeFromDrag(from, velocity) {
            var width = drawer.offsetWidth || 280;
            animation = spring({
                from: from,
                to: -(width + 16),
                velocity: velocity,
                damping: 0.95,
                response: 0.28,
                precision: 0.5,
                onUpdate: function (x) {
                    drawer.style.translate = x + 'px 0';
                    syncBackdrop(x);
                },
                onComplete: function () {
                    animation = null;
                    drawer.classList.remove('show');
                    drawer.classList.remove('is-dragging');
                    drawer.style.translate = '';
                    if (backdrop) {
                        backdrop.classList.remove('show');
                        backdrop.classList.remove('is-dragging');
                        backdrop.style.opacity = '';
                    }
                }
            });
        }

        drawer.addEventListener('pointerdown', function (event) {
            if (!media.matches || !drawer.classList.contains('show')) return;
            if (event.pointerType === 'mouse' && event.button !== 0) return;
            var target = event.target;
            if (target && target.closest && target.closest('input, textarea, select, [contenteditable="true"]')) return;
            cancelAnimation();
            state = {
                id: event.pointerId,
                startX: event.clientX,
                startY: event.clientY,
                x: 0,
                dragging: false,
                samples: []
            };
            trackSample(state.samples, event);
        });

        window.addEventListener('pointermove', function (event) {
            if (!state || event.pointerId !== state.id) return;
            var dx = event.clientX - state.startX;
            var dy = event.clientY - state.startY;
            trackSample(state.samples, event);

            if (!state.dragging) {
                if (Math.abs(dx) < 8 || Math.abs(dx) <= Math.abs(dy)) return;
                state.dragging = true;
                drawer.classList.add('is-dragging');
                if (backdrop) backdrop.classList.add('is-dragging');
                try { drawer.setPointerCapture(event.pointerId); } catch (error) { }
            }

            var width = drawer.offsetWidth || 280;
            var x = dx < 0 ? dx : rubberband(dx, width, 0.35);
            state.x = x;
            drawer.style.translate = x + 'px 0';
            syncBackdrop(x);
        }, { passive: true });

        function endDrag(event) {
            if (!state || (event.pointerId != null && event.pointerId !== state.id)) return;
            var gesture = state;
            state = null;
            if (!gesture.dragging) return;

            var velocity = releaseVelocity(gesture.samples, 'x');
            var width = drawer.offsetWidth || 280;
            try { drawer.releasePointerCapture(gesture.id); } catch (error) { }
            suppressClick = true;
            setTimeout(function () { suppressClick = false; }, 400);

            if (gesture.x < -width * 0.32 || velocity < -520) {
                closeFromDrag(gesture.x, velocity);
            } else {
                settleOpen(gesture.x, velocity);
            }
        }

        window.addEventListener('pointerup', endDrag);
        window.addEventListener('pointercancel', endDrag);

        document.addEventListener('click', function (event) {
            if (!suppressClick) return;
            suppressClick = false;
            event.preventDefault();
            event.stopPropagation();
        }, true);

        document.addEventListener('click', function (event) {
            var toggle = event.target && event.target.closest && event.target.closest('[data-sidebar-toggle]');
            if (!toggle) return;
            setTimeout(function () {
                if (drawer.classList.contains('show')) cleanupDrag();
            }, 0);
        });

        if (window.MutationObserver) {
            new MutationObserver(function () {
                if (!drawer.classList.contains('show') && state) {
                    state = null;
                    cleanupDrag();
                }
            }).observe(drawer, { attributes: true, attributeFilter: ['class'] });
        }
    }

    /* ---------- 移动端搜索面板：下拉关闭 ---------- */
    function initSearchGesture() {
        var modal = document.getElementById('nmSearchModal');
        if (!modal) return;
        var dialog = modal.querySelector('.nm-search-dialog');
        if (!dialog) return;

        var media = window.matchMedia('(max-width: 767.98px)');
        var state = null;
        var animation = null;

        function cancelAnimation() {
            if (animation) {
                animation.stop();
                animation = null;
            }
        }

        function cleanup() {
            cancelAnimation();
            state = null;
            dialog.classList.remove('is-dragging');
            dialog.style.translate = '';
            modal.style.opacity = '';
        }

        function settleBack(from, velocity) {
            animation = spring({
                from: from,
                to: 0,
                velocity: velocity,
                damping: 0.9,
                response: 0.32,
                precision: 0.5,
                onUpdate: function (y) {
                    dialog.style.translate = '0 ' + y + 'px';
                },
                onComplete: function () {
                    animation = null;
                    dialog.classList.remove('is-dragging');
                    dialog.style.translate = '';
                    modal.style.opacity = '';
                }
            });
        }

        function dismiss(from, velocity) {
            var height = dialog.offsetHeight || 220;
            animation = spring({
                from: from,
                to: height + 80,
                velocity: velocity,
                damping: 0.95,
                response: 0.26,
                precision: 0.5,
                onUpdate: function (y) {
                    dialog.style.translate = '0 ' + Math.max(0, y) + 'px';
                    setOpacity(modal, Math.max(0.35, 1 - y / (height * 1.4)));
                },
                onComplete: function () {
                    animation = null;
                    dialog.classList.remove('is-dragging');
                    dialog.style.translate = '';
                    modal.style.opacity = '';
                    if (window.NorthmuSearch) window.NorthmuSearch.close();
                }
            });
        }

        dialog.addEventListener('pointerdown', function (event) {
            if (!media.matches || !modal.classList.contains('is-open')) return;
            if (event.pointerType === 'mouse' && event.button !== 0) return;
            var target = event.target;
            if (target && target.closest && target.closest('input, textarea, select, button, a, [contenteditable="true"]')) return;
            cancelAnimation();
            state = {
                id: event.pointerId,
                startX: event.clientX,
                startY: event.clientY,
                y: 0,
                dragging: false,
                samples: []
            };
            trackSample(state.samples, event);
        });

        window.addEventListener('pointermove', function (event) {
            if (!state || event.pointerId !== state.id) return;
            var dx = event.clientX - state.startX;
            var dy = event.clientY - state.startY;
            trackSample(state.samples, event);

            if (!state.dragging) {
                if (dy < 8 || dy < Math.abs(dx)) return;
                state.dragging = true;
                dialog.classList.add('is-dragging');
                try { dialog.setPointerCapture(event.pointerId); } catch (error) { }
            }

            var y = dy < 0 ? dy * 0.3 : (dy < 150 ? dy : 150 + rubberband(dy - 150, 120, 0.4));
            state.y = y;
            dialog.style.translate = '0 ' + y + 'px';
            setOpacity(modal, 1 - Math.min(1, Math.max(0, y / 140)) * 0.3);
        }, { passive: true });

        function endDrag(event) {
            if (!state || (event.pointerId != null && event.pointerId !== state.id)) return;
            var gesture = state;
            state = null;
            if (!gesture.dragging) return;

            var velocity = releaseVelocity(gesture.samples, 'y');
            var projected = gesture.y + projectMomentum(velocity);
            try { dialog.releasePointerCapture(gesture.id); } catch (error) { }

            if (gesture.y > 90 || projected > 120 || velocity > 800) {
                dismiss(gesture.y, velocity);
            } else {
                settleBack(gesture.y, velocity);
            }
        }

        window.addEventListener('pointerup', endDrag);
        window.addEventListener('pointercancel', endDrag);

        function cleanupIfClosed() {
            if (!modal.classList.contains('is-open')) cleanup();
        }

        document.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || !target.closest) return;
            if (target.closest('[data-search-open], [data-search-close]')) {
                setTimeout(cleanupIfClosed, 0);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') cleanupIfClosed();
        });

        if (window.MutationObserver) {
            new MutationObserver(cleanupIfClosed).observe(modal, {
                attributes: true,
                attributeFilter: ['class']
            });
        }
    }

    function init() {
        initScrollState();
        initDrawerGesture();
        initSearchGesture();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.NM = window.NM || {};
    window.NM.motion = {
        spring: spring,
        isReduced: function () { return reduced; },
        ease: {
            enter: 'cubic-bezier(.2,.8,.2,1)',
            exit: 'cubic-bezier(.8,.2,.8,1)',
            standard: 'cubic-bezier(.32,.72,0,1)'
        }
    };
})();
