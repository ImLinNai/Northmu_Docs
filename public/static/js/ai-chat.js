(function () {
    'use strict';

    var root = document.getElementById('nmAiChat');
    if (!root) return;

    var fab = root.querySelector('[data-ai-chat-open]');
    var panel = root.querySelector('.nm-ai-chat-panel');
    var list = root.querySelector('[data-ai-chat-messages]');
    var form = root.querySelector('[data-ai-chat-form]');
    var input = root.querySelector('[data-ai-chat-input]');
    var sendBtn = root.querySelector('[data-ai-chat-send]');
    if (!fab || !panel || !list || !form || !input || !sendBtn) return;

    var endpoint = root.getAttribute('data-endpoint') || '/ai/chat';
    var token = root.getAttribute('data-token') || '';
    var greeting = root.getAttribute('data-greeting') || '你好，我是 AI 助手，有什么可以帮你？';
    var mobileQuery = window.matchMedia('(max-width: 575.98px)');
    var greeted = false;
    var busy = false;

    function scrollToEnd() {
        window.requestAnimationFrame(function () {
            list.scrollTop = list.scrollHeight;
        });
    }

    function addMessage(role, text) {
        var row = document.createElement('div');
        row.className = 'nm-ai-chat-msg is-' + role;

        if (role !== 'user') {
            var avatar = document.createElement('span');
            avatar.className = 'nm-ai-chat-avatar';
            avatar.setAttribute('aria-hidden', 'true');
            var icon = document.createElement('i');
            icon.className = role === 'error' ? 'ti ti-alert-triangle' : 'ti ti-sparkles';
            avatar.appendChild(icon);
            row.appendChild(avatar);
        }

        var bubble = document.createElement('div');
        bubble.className = 'nm-ai-chat-bubble';
        bubble.textContent = text;
        row.appendChild(bubble);
        list.appendChild(row);
        scrollToEnd();
        return row;
    }

    function showTyping() {
        var row = document.createElement('div');
        row.className = 'nm-ai-chat-msg is-assistant';
        row.setAttribute('data-ai-chat-typing', '');

        var avatar = document.createElement('span');
        avatar.className = 'nm-ai-chat-avatar';
        avatar.setAttribute('aria-hidden', 'true');
        var icon = document.createElement('i');
        icon.className = 'ti ti-sparkles';
        avatar.appendChild(icon);

        var bubble = document.createElement('div');
        bubble.className = 'nm-ai-chat-bubble';
        var dots = document.createElement('span');
        dots.className = 'nm-ai-chat-typing';
        dots.setAttribute('role', 'status');
        dots.setAttribute('aria-label', 'AI 正在输入');
        for (var i = 0; i < 3; i++) {
            dots.appendChild(document.createElement('span'));
        }
        bubble.appendChild(dots);
        row.appendChild(avatar);
        row.appendChild(bubble);
        list.appendChild(row);
        scrollToEnd();
        return row;
    }

    function setBusy(next) {
        busy = next;
        sendBtn.disabled = next || input.value.trim() === '';
    }

    function resizeInput() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 112) + 'px';
    }

    function setOpen(open) {
        root.classList.toggle('is-open', open);
        fab.setAttribute('aria-expanded', open ? 'true' : 'false');
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');

        if (open) {
            if (!greeted) {
                addMessage('assistant', greeting);
                greeted = true;
            }
            if (mobileQuery.matches) {
                document.body.classList.add('nm-ai-chat-locked');
            }
            window.setTimeout(function () {
                input.focus();
                scrollToEnd();
            }, 180);
        } else {
            document.body.classList.remove('nm-ai-chat-locked');
            fab.focus();
        }
    }

    function send() {
        var text = input.value.trim();
        if (text === '' || busy) return;

        addMessage('user', text);
        input.value = '';
        resizeInput();
        setBusy(true);
        var typing = showTyping();

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'msg=' + encodeURIComponent(text),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error('服务返回异常，请稍后重试');
            });
        }).then(function (res) {
            if (typing && typing.parentNode) typing.parentNode.removeChild(typing);
            if (!res || res.code !== 0) {
                throw new Error((res && res.msg) || 'AI 暂时无法回复，请稍后再试');
            }
            var content = res.data && res.data.content ? String(res.data.content) : '';
            if (content === '') {
                throw new Error('AI 暂时无法回复，请稍后再试');
            }
            addMessage('assistant', content);
            setBusy(false);
        }).catch(function (err) {
            if (typing && typing.parentNode) typing.parentNode.removeChild(typing);
            addMessage('error', err && err.message ? err.message : '网络异常，请稍后重试');
            setBusy(false);
        });
    }

    fab.addEventListener('click', function () {
        setOpen(true);
    });

    root.querySelectorAll('[data-ai-chat-close]').forEach(function (el) {
        el.addEventListener('click', function () {
            setOpen(false);
        });
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        send();
    });

    input.addEventListener('input', function () {
        resizeInput();
        sendBtn.disabled = busy || input.value.trim() === '';
    });

    input.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
        event.preventDefault();
        send();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (!root.classList.contains('is-open')) return;
        setOpen(false);
    });

    if (mobileQuery.addEventListener) {
        mobileQuery.addEventListener('change', function (event) {
            if (!event.matches) document.body.classList.remove('nm-ai-chat-locked');
        });
    }
})();
