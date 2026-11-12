(function () {
    'use strict';

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function b64uToBuf(value) {
        var base64 = String(value || '').replace(/-/g, '+').replace(/_/g, '/');
        while (base64.length % 4) base64 += '=';
        var raw = window.atob(base64);
        var bytes = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
        return bytes.buffer;
    }

    function bufToB64u(buffer) {
        var bytes = new Uint8Array(buffer);
        var raw = '';
        for (var i = 0; i < bytes.length; i++) raw += String.fromCharCode(bytes[i]);
        return window.btoa(raw).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function request(url, data, method) {
        var headers = {
            'X-CSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest'
        };
        var options = { method: method || 'GET', headers: headers, credentials: 'same-origin' };
        if (data) {
            var body = new URLSearchParams();
            Object.keys(data).forEach(function (key) {
                if (data[key] !== null && data[key] !== undefined) body.append(key, data[key]);
            });
            headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
            options.body = body.toString();
        }
        return fetch(url, options).then(function (response) {
            var type = response.headers.get('content-type') || '';
            if (type.indexOf('application/json') === -1) {
                throw new Error('服务器返回了非 JSON 响应');
            }
            return response.json();
        });
    }

    function describeError(error) {
        var name = error && error.name ? error.name : '';
        var messages = {
            NotAllowedError: '操作已取消，或没有匹配的通行密钥',
            InvalidStateError: '该设备已经注册过通行密钥',
            SecurityError: '当前环境不支持通行密钥，请使用 HTTPS 或 localhost 访问',
            AbortError: '操作已取消，请重试',
            NotSupportedError: '当前浏览器或设备不支持通行密钥'
        };
        if (name && messages[name]) return messages[name];
        return (error && error.message) ? error.message : '通行密钥操作失败';
    }

    function toast(message, type) {
        if (window.NorthmuAdmin && window.NorthmuAdmin.toast) {
            window.NorthmuAdmin.toast(message, type);
        } else if (window.NorthmuUI && window.NorthmuUI.toast) {
            window.NorthmuUI.toast(message, type);
        } else {
            window.alert(message);
        }
    }

    function supported() {
        return !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create && window.isSecureContext);
    }

    /* ---------- 登录页：通行密钥断言 ---------- */
    function initLogin(button) {
        if (!supported()) {
            button.hidden = true;
            return;
        }
        var label = button.querySelector('.nm-auth-passkey-title');
        var defaultLabel = label ? label.textContent : '';

        function busy(state) {
            button.disabled = state;
            button.classList.toggle('is-busy', state);
            if (label) label.textContent = state ? '正在验证通行密钥…' : defaultLabel;
        }

        button.addEventListener('click', function () {
            if (button.disabled) return;
            busy(true);
            request(button.getAttribute('data-options-url'))
                .then(function (json) {
                    if (!json || json.code !== 0) throw new Error((json && json.msg) || '无法获取通行密钥选项');
                    var data = json.data || {};
                    var allow = (data.allowCredentials || []).map(function (item) {
                        return {
                            type: 'public-key',
                            id: b64uToBuf(item.id),
                            transports: item.transports || []
                        };
                    });
                    return navigator.credentials.get({
                        publicKey: {
                            challenge: b64uToBuf(data.challenge),
                            rpId: data.rpId,
                            timeout: data.timeout || 60000,
                            userVerification: data.userVerification || 'preferred',
                            allowCredentials: allow
                        }
                    });
                })
                .then(function (credential) {
                    if (!credential) throw new Error('未获取到通行密钥');
                    var response = credential.response;
                    return request(button.getAttribute('data-login-url'), {
                        id: credential.id,
                        rawId: bufToB64u(credential.rawId),
                        clientDataJSON: bufToB64u(response.clientDataJSON),
                        authenticatorData: bufToB64u(response.authenticatorData),
                        signature: bufToB64u(response.signature),
                        userHandle: response.userHandle ? bufToB64u(response.userHandle) : ''
                    }, 'POST');
                })
                .then(function (json) {
                    if (!json || json.code !== 0) throw new Error((json && json.msg) || '通行密钥登录失败');
                    window.location.href = (json.data && json.data.redirect) || '/admin';
                })
                .catch(function (error) {
                    busy(false);
                    toast(describeError(error), 'error');
                });
        });
    }

    /* ---------- 后台设置：注册通行密钥 ---------- */
    function initRegister(button) {
        if (!supported()) {
            button.disabled = true;
            button.setAttribute('title', '当前环境不支持通行密钥，请使用 HTTPS 或 localhost 访问');
            return;
        }
        var optionsUrl = button.getAttribute('data-options-url');
        var registerUrl = button.getAttribute('data-register-url');
        var defaultHtml = button.innerHTML;

        button.addEventListener('click', function () {
            if (button.disabled) return;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>正在等待设备验证…';

            request(optionsUrl, {}, 'POST')
                .then(function (json) {
                    if (!json || json.code !== 0) throw new Error((json && json.msg) || '无法获取注册选项');
                    var data = json.data || {};
                    var exclude = (data.excludeCredentials || []).map(function (item) {
                        return { type: 'public-key', id: b64uToBuf(item.id) };
                    });
                    return navigator.credentials.create({
                        publicKey: {
                            challenge: b64uToBuf(data.challenge),
                            rp: { id: data.rpId, name: data.rpName || 'Northmu Docs' },
                            user: {
                                id: b64uToBuf(data.userId),
                                name: data.userName || 'admin',
                                displayName: data.userDisplayName || data.userName || 'admin'
                            },
                            pubKeyCredParams: [
                                { type: 'public-key', alg: -7 },
                                { type: 'public-key', alg: -257 }
                            ],
                            timeout: data.timeout || 60000,
                            attestation: 'none',
                            excludeCredentials: exclude,
                            authenticatorSelection: data.authenticatorSelection || {
                                residentKey: 'preferred',
                                requireResidentKey: false,
                                userVerification: 'preferred'
                            }
                        }
                    });
                })
                .then(function (credential) {
                    if (!credential) throw new Error('未创建通行密钥');
                    var response = credential.response;
                    return request(registerUrl, {
                        id: credential.id,
                        rawId: bufToB64u(credential.rawId),
                        clientDataJSON: bufToB64u(response.clientDataJSON),
                        attestationObject: bufToB64u(response.attestationObject)
                    }, 'POST');
                })
                .then(function (json) {
                    if (!json || json.code !== 0) throw new Error((json && json.msg) || '通行密钥注册失败');
                    toast(json.msg || '通行密钥已添加', 'success');
                    setTimeout(function () { window.location.reload(); }, 600);
                })
                .catch(function (error) {
                    button.disabled = false;
                    button.innerHTML = defaultHtml;
                    toast(describeError(error), 'error');
                });
        });
    }

    /* ---------- 后台设置：删除通行密钥 ---------- */
    function initDelete(button) {
        var url = button.getAttribute('data-delete-url') || '/admin/passkey/delete';
        var id = button.getAttribute('data-passkey-id') || button.getAttribute('data-passkey-delete');
        var name = button.getAttribute('data-passkey-label') || '这枚通行密钥';
        button.addEventListener('click', function () {
            if (button.disabled) return;
            var run = function () {
                button.disabled = true;
                request(url, { id: id }, 'POST')
                    .then(function (json) {
                        if (!json || json.code !== 0) throw new Error((json && json.msg) || '删除失败');
                        toast(json.msg || '通行密钥已删除', 'success');
                        setTimeout(function () { window.location.reload(); }, 500);
                    })
                    .catch(function (error) {
                        button.disabled = false;
                        toast(describeError(error), 'error');
                    });
            };
            if (window.NorthmuUI && window.NorthmuUI.confirm) {
                window.NorthmuUI.confirm({
                    anchor: button,
                    title: '删除通行密钥？',
                    message: '删除后，' + name + ' 将无法再用于登录后台。',
                    confirmText: '删除',
                    tone: 'danger'
                }).then(function (ok) {
                    if (ok) run();
                });
            } else if (window.confirm('确定删除' + name + '？删除后该设备将无法用于通行密钥登录。')) {
                run();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var loginBtn = document.getElementById('passkeyLoginBtn');
        if (loginBtn) initLogin(loginBtn);
        var registerBtn = document.getElementById('passkeyRegisterBtn');
        if (registerBtn) initRegister(registerBtn);
        document.querySelectorAll('[data-passkey-delete]').forEach(initDelete);
    });
})();
