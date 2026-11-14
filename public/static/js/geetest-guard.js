(function () {
    'use strict';

    var dataEl = document.getElementById('nmGeetestConfig');
    if (!dataEl) return;

    var config = {};
    try {
        config = JSON.parse(dataEl.textContent || '{}');
    } catch (e) {
        return;
    }
    if (!config.enabled || !config.captchaId) return;

    var SCRIPT_URL = 'https://static.geetest.com/v4/gt4.js';
    var VERIFY_URL = '/security/geetest/verify';
    var scriptPromise = null;
    var instancePromise = null;
    var pendingResolve = null;
    var busy = false;

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function toast(message, type) {
        if (window.NorthmuAdmin && window.NorthmuAdmin.toast) {
            window.NorthmuAdmin.toast(message, type || 'error');
            return;
        }
        var host = document.getElementById('nmGeetestToast');
        if (!host) {
            host = document.createElement('div');
            host.id = 'nmGeetestToast';
            host.className = 'nm-gt-toast';
            host.setAttribute('role', 'status');
            document.body.appendChild(host);
        }
        host.textContent = message;
        host.classList.toggle('is-error', (type || 'error') === 'error');
        host.classList.add('is-visible');
        window.clearTimeout(host._timer);
        host._timer = window.setTimeout(function () {
            host.classList.remove('is-visible');
        }, 3200);
    }

    function loadScript() {
        if (scriptPromise) return scriptPromise;
        scriptPromise = new Promise(function (resolve, reject) {
            if (typeof window.initGeetest4 === 'function') {
                resolve();
                return;
            }
            var existing = document.querySelector('script[data-nm-geetest]');
            if (existing) {
                existing.addEventListener('load', function () { resolve(); });
                existing.addEventListener('error', function () { reject(new Error('load failed')); });
                return;
            }
            var script = document.createElement('script');
            script.src = SCRIPT_URL;
            script.async = true;
            script.setAttribute('data-nm-geetest', '1');
            script.onload = function () { resolve(); };
            script.onerror = function () { reject(new Error('load failed')); };
            document.head.appendChild(script);
        });
        return scriptPromise;
    }

    function settle(validate) {
        if (!pendingResolve) return;
        var resolve = pendingResolve;
        pendingResolve = null;
        resolve(validate || null);
    }

    function ensureInstance() {
        if (instancePromise) return instancePromise;
        instancePromise = loadScript().then(function () {
            return new Promise(function (resolve, reject) {
                if (typeof window.initGeetest4 !== 'function') {
                    reject(new Error('initGeetest4 missing'));
                    return;
                }
                window.initGeetest4({
                    captchaId: config.captchaId,
                    product: config.product === 'popup' ? 'popup' : 'float',
                    language: 'zh'
                }, function (captcha) {
                    captcha.onSuccess(function () {
                        var validate = captcha.getValidate();
                        settle(validate && validate.lot_number ? validate : null);
                    });
                    captcha.onError(function () {
                        settle(null);
                    });
                    captcha.onClose(function () {
                        settle(null);
                    });
                    resolve(captcha);
                });
            });
        });
        return instancePromise;
    }

    function requestValidate() {
        return ensureInstance().then(function (captcha) {
            return new Promise(function (resolve) {
                pendingResolve = resolve;
                try {
                    captcha.showCaptcha();
                } catch (e) {
                    pendingResolve = null;
                    resolve(null);
                }
            });
        }).then(function (validate) {
            if (validate) {
                instancePromise.then(function (captcha) {
                    try { captcha.reset(); } catch (e) { }
                });
            }
            return validate;
        });
    }

    function requestGrant(scene) {
        if (busy) return Promise.resolve(null);
        busy = true;
        return requestValidate().then(function (validate) {
            if (!validate) return null;
            var body = new URLSearchParams();
            body.append('scene', scene);
            body.append('lot_number', validate.lot_number);
            body.append('captcha_output', validate.captcha_output);
            body.append('pass_token', validate.pass_token);
            body.append('gen_time', validate.gen_time);
            return fetch(VERIFY_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken()
                },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json();
            }).then(function (res) {
                if (!res || res.code !== 0 || !res.data || !res.data.grant) {
                    toast((res && res.msg) || '行为验证未通过，请重试', 'error');
                    return null;
                }
                return res.data.grant;
            });
        }).catch(function () {
            toast('行为验证组件加载失败，请检查网络后重试', 'error');
            return null;
        }).then(function (grant) {
            busy = false;
            return grant;
        });
    }

    function sceneForForm(form) {
        var scene = (form.getAttribute('data-gt-scene') || '').trim();
        if (!scene) return '';

        if (scene === 'batch') {
            var actionSelect = form.querySelector('select[name="action"]');
            var action = actionSelect ? actionSelect.value : '';
            if (action === 'publish') return 'publish';
            if (action === 'delete' || action === 'purge') return 'delete';
            return '';
        }

        if (scene === 'publish') {
            var statusSelect = form.querySelector('select[name="status"]');
            if (!statusSelect || statusSelect.value !== '1') return '';
            var isNew = form.getAttribute('data-doc-new') === '1';
            var wasPublished = form.getAttribute('data-doc-status') === '1';
            return isNew || !wasPublished ? 'publish' : '';
        }

        return scene === 'login' || scene === 'delete' ? scene : '';
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') return;
        if (form.dataset.gtGranted === '1') return;

        var scene = sceneForForm(form);
        if (!scene) return;

        event.preventDefault();
        event.stopImmediatePropagation();

        var submitter = event.submitter || null;
        form.classList.add('is-gt-busy');

        requestGrant(scene).then(function (grant) {
            form.classList.remove('is-gt-busy');
            if (!grant) return;

            var input = form.querySelector('input[name="geetest_grant"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'geetest_grant';
                form.appendChild(input);
            }
            input.value = grant;
            form.dataset.gtGranted = '1';

            var submit = function () {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(submitter || undefined);
                } else {
                    form.submit();
                }
            };

            // admin-ui.js 已接管确认弹窗；再次标记避免重复弹出确认
            form.dataset.nmConfirmed = '1';
            try {
                submit();
            } catch (e) {
                form.submit();
            }
        });
    }, true);
})();
