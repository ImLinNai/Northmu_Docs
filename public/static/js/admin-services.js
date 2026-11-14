(function () {
    'use strict';

    var dataEl = document.getElementById('nmServiceData');
    var grid = document.querySelector('[data-service-grid]');
    if (!dataEl || !grid) return;

    var services = [];
    try {
        services = JSON.parse(dataEl.textContent || '[]');
    } catch (e) {
        services = [];
    }

    var VIEW_KEY = 'northmu-service-view';
    var POST_URL = '/admin/services/save';

    function findService(id) {
        for (var i = 0; i < services.length; i++) {
            if (services[i].id === id) return services[i];
        }
        return null;
    }

    function toast(message, type) {
        if (window.NorthmuAdmin && window.NorthmuAdmin.toast) {
            window.NorthmuAdmin.toast(message, type || 'success');
        }
    }

    function post(data) {
        if (window.NorthmuUI && window.NorthmuUI.post) {
            return window.NorthmuUI.post(POST_URL, data);
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        var body = new URLSearchParams();
        Object.keys(data || {}).forEach(function (key) {
            if (data[key] !== null && data[key] !== undefined) body.append(key, data[key]);
        });
        return fetch(POST_URL, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': meta ? meta.getAttribute('content') : '',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        });
    }

    // ---------- 视图切换 ----------
    var viewGroup = document.querySelector('[data-service-view-switch]');
    var viewButtons = viewGroup ? Array.prototype.slice.call(viewGroup.querySelectorAll('[data-service-view-btn]')) : [];

    function applyView(view) {
        var next = view === 'list' ? 'list' : 'grid';
        grid.setAttribute('data-service-view', next);
        viewButtons.forEach(function (btn) {
            btn.setAttribute('aria-pressed', btn.getAttribute('data-service-view-btn') === next ? 'true' : 'false');
        });
    }

    try {
        applyView(window.localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'grid');
    } catch (e) {
        applyView('grid');
    }

    viewButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var view = btn.getAttribute('data-service-view-btn') === 'list' ? 'list' : 'grid';
            applyView(view);
            try {
                window.localStorage.setItem(VIEW_KEY, view);
            } catch (e) { }
        });
    });

    // ---------- 卡片状态 ----------
    function cardOf(id) {
        return grid.querySelector('[data-service-card][data-service-id="' + id + '"]');
    }

    function syncCard(card, service) {
        if (!card || !service) return;
        card.classList.toggle('is-enabled', service.status === 'running');
        card.classList.toggle('is-pending', service.status === 'pending');
        var state = card.querySelector('[data-service-state]');
        if (state) state.textContent = service.statusLabel;
        var input = card.querySelector('[data-service-switch] input');
        if (input) input.checked = !!service.enabled;
    }

    function applySnapshot(snapshot) {
        var service = findService(snapshot.id);
        if (!service) return;
        service.enabled = !!snapshot.enabled;
        service.configured = !!snapshot.configured;
        service.status = snapshot.status;
        service.statusLabel = snapshot.statusLabel;
        syncCard(cardOf(service.id), service);
    }

    // ---------- 启用 / 停用 ----------
    function setSwitchBusy(input, busy) {
        if (!input) return;
        input.disabled = busy;
        var card = input.closest('[data-service-card]');
        if (card) card.classList.toggle('is-busy', busy);
    }

    grid.addEventListener('change', function (event) {
        var input = event.target.closest('[data-service-switch] input');
        if (!input) return;
        var card = input.closest('[data-service-card]');
        if (!card) return;
        var service = findService(card.getAttribute('data-service-id'));
        if (!service) return;

        var next = input.checked;

        if (next && service.requiresConfig && !service.configured) {
            input.checked = false;
            openModal(service.id, { enableOnSave: true });
            return;
        }

        setSwitchBusy(input, true);
        post({ id: service.id, enabled: next ? 1 : 0 }).then(function (res) {
            setSwitchBusy(input, false);
            if (!res || res.code !== 0) {
                input.checked = !next;
                toast((res && res.msg) || '操作失败，请稍后重试', 'error');
                return;
            }
            applySnapshot(res.data.service);
            toast(res.msg || '设置已保存', next ? 'success' : 'info');
        }).catch(function () {
            setSwitchBusy(input, false);
            input.checked = !next;
            toast('网络异常，请稍后重试', 'error');
        });
    });

    // ---------- 配置弹窗 ----------
    var modalEl = document.getElementById('serviceConfigModal');
    var modal = modalEl && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
    var form = document.getElementById('serviceConfigForm');
    var saveBtn = document.getElementById('serviceConfigSave');
    var linksEl = modalEl ? modalEl.querySelector('[data-service-modal-links]') : null;
    var modalIcon = modalEl ? modalEl.querySelector('[data-service-modal-icon]') : null;
    var currentId = null;
    var enableOnSave = false;

    if (modalIcon) {
        modalIcon.addEventListener('error', function () {
            modalIcon.hidden = true;
            modalIcon.removeAttribute('src');
        });
        modalIcon.addEventListener('load', function () {
            modalIcon.hidden = false;
        });
    }

    function renderLinks(service) {
        if (!linksEl) return;
        linksEl.innerHTML = '';
        var links = service.links || [];
        if (!links.length) {
            linksEl.hidden = true;
            return;
        }
        var label = document.createElement('span');
        label.className = 'nm-service-modal-links-label';
        label.textContent = '相关链接';
        linksEl.appendChild(label);
        links.forEach(function (link) {
            var a = document.createElement('a');
            a.href = link.url;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.textContent = link.label + ' ↗';
            linksEl.appendChild(a);
        });
        linksEl.hidden = false;
    }

    function renderField(container, service, field, index) {
        var saved = service.config || {};
        var inputId = 'serviceField-' + service.id + '-' + index;
        var value = saved[field.name];
        if (value === undefined || value === null) value = '';

        if (field.type === 'checkbox') {
            var checkCol = document.createElement('div');
            checkCol.className = 'col-md-6';
            var checkWrap = document.createElement('label');
            checkWrap.className = 'form-check form-switch nm-service-field-switch';
            checkWrap.setAttribute('for', inputId);

            var check = document.createElement('input');
            check.type = 'checkbox';
            check.className = 'form-check-input';
            check.id = inputId;
            check.checked = String(value) === '1';
            check.setAttribute('data-service-field', field.name);
            check.setAttribute('data-field-type', 'checkbox');

            var checkLabel = document.createElement('span');
            checkLabel.className = 'form-check-label';
            checkLabel.textContent = field.label || field.name;

            checkWrap.appendChild(check);
            checkWrap.appendChild(checkLabel);
            checkCol.appendChild(checkWrap);
            if (field.hint) {
                var checkHint = document.createElement('div');
                checkHint.className = 'form-hint';
                checkHint.textContent = field.hint;
                checkCol.appendChild(checkHint);
            }
            container.appendChild(checkCol);
            return;
        }

        var col = document.createElement('div');
        col.className = 'col-md-6';

        var label = document.createElement('label');
        label.className = 'form-label';
        label.setAttribute('for', inputId);
        label.textContent = field.label || field.name;

        var input;
        if (field.type === 'select') {
            input = document.createElement('select');
            input.className = 'form-select';
            input.setAttribute('data-service-field', field.name);
            input.setAttribute('data-field-type', 'select');
            (field.options || []).forEach(function (option) {
                var opt = document.createElement('option');
                opt.value = option.value;
                opt.textContent = option.label;
                if (String(value) === String(option.value)) opt.selected = true;
                input.appendChild(opt);
            });
        } else {
            input = document.createElement('input');
            input.type = field.type === 'password' ? 'password' : 'text';
            input.className = 'form-control';
            input.placeholder = field.placeholder || '';
            input.value = String(value);
            input.setAttribute('data-service-field', field.name);
            input.setAttribute('data-field-type', input.type);
            if (input.type === 'password') input.autocomplete = 'new-password';
        }
        input.id = inputId;

        col.appendChild(label);
        col.appendChild(input);

        if (field.hint) {
            var hint = document.createElement('div');
            hint.className = 'form-hint';
            hint.textContent = field.hint;
            col.appendChild(hint);
        }
        container.appendChild(col);
    }

    function buildFields(service) {
        form.innerHTML = '';
        var fields = service.fields || [];
        var sections = service.sections || [];
        if (!fields.length && !sections.length) {
            var empty = document.createElement('div');
            empty.className = 'col-12';
            empty.innerHTML = '<p class="text-secondary mb-0">该服务暂未提供可配置项。</p>';
            form.appendChild(empty);
            return;
        }

        fields.forEach(function (field, index) {
            renderField(form, service, field, 'base-' + index);
        });

        sections.forEach(function (section, sectionIndex) {
            var wrap = document.createElement('div');
            wrap.className = 'col-12';
            var box = document.createElement('section');
            box.className = 'nm-service-section';

            var head = document.createElement('div');
            head.className = 'nm-service-section-head';
            var title = document.createElement('h6');
            title.className = 'nm-service-section-title';
            title.textContent = section.name || '子服务';
            var tag = document.createElement('span');
            tag.className = 'nm-service-section-tag';
            tag.textContent = '子服务';
            head.appendChild(title);
            head.appendChild(tag);
            box.appendChild(head);

            if (section.desc) {
                var desc = document.createElement('p');
                desc.className = 'nm-service-section-desc';
                desc.textContent = section.desc;
                box.appendChild(desc);
            }

            var row = document.createElement('div');
            row.className = 'row g-3';
            (section.fields || []).forEach(function (field, fieldIndex) {
                renderField(row, service, field, 's' + sectionIndex + '-' + fieldIndex);
            });
            box.appendChild(row);
            wrap.appendChild(box);
            form.appendChild(wrap);
        });
    }

    function syncSaveLabel(service) {
        if (!saveBtn) return;
        var enabling = enableOnSave || !service.enabled;
        saveBtn.innerHTML = '<i class="ti ti-device-floppy me-1"></i>' + (enabling ? '保存并启用' : '保存配置');
    }

    function openModal(id, options) {
        var service = findService(id);
        if (!service || !modal) return;
        currentId = id;
        enableOnSave = !!(options && options.enableOnSave) || !service.enabled;

        var title = modalEl.querySelector('[data-service-modal-title]');
        var meta = modalEl.querySelector('[data-service-modal-meta]');
        var desc = modalEl.querySelector('[data-service-modal-desc]');
        if (title) title.textContent = service.name;
        if (meta) meta.textContent = [service.tag, service.statusLabel].filter(Boolean).join(' · ');
        if (desc) desc.textContent = service.desc || '';

        if (modalIcon) {
            modalIcon.hidden = true;
            modalIcon.removeAttribute('src');
            if (service.icon) modalIcon.src = service.icon;
        }

        buildFields(service);
        renderLinks(service);
        syncSaveLabel(service);
        modal.show();
    }

    grid.addEventListener('click', function (event) {
        if (event.target.closest('[data-service-switch]')) return;
        var card = event.target.closest('[data-service-card]');
        if (!card) return;
        openModal(card.getAttribute('data-service-id'));
    });

    grid.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        if (!event.target.matches('[data-service-card]')) return;
        if (event.target.closest('[data-service-switch]')) return;
        event.preventDefault();
        openModal(event.target.getAttribute('data-service-id'));
    });

    function collectConfig() {
        var payload = {};
        form.querySelectorAll('[data-service-field]').forEach(function (input) {
            var name = input.getAttribute('data-service-field');
            payload['config[' + name + ']'] = input.getAttribute('data-field-type') === 'checkbox'
                ? (input.checked ? '1' : '0')
                : input.value.trim();
        });
        return payload;
    }

    function readConfigValues() {
        var values = {};
        form.querySelectorAll('[data-service-field]').forEach(function (input) {
            var name = input.getAttribute('data-service-field');
            values[name] = input.getAttribute('data-field-type') === 'checkbox'
                ? (input.checked ? '1' : '0')
                : input.value.trim();
        });
        return values;
    }

    if (saveBtn && form) {
        saveBtn.addEventListener('click', function () {
            if (!currentId || saveBtn.disabled) return;
            var service = findService(currentId);
            if (!service) return;

            var payload = { id: service.id, enabled: 1 };
            var config = collectConfig();
            Object.keys(config).forEach(function (key) {
                payload[key] = config[key];
            });

            saveBtn.disabled = true;
            var originalHtml = saveBtn.innerHTML;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>保存中…';

            post(payload).then(function (res) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalHtml;
                if (!res || res.code !== 0) {
                    toast((res && res.msg) || '保存失败，请检查配置', 'error');
                    return;
                }
                service.config = readConfigValues();
                applySnapshot(res.data.service);
                if (modal) modal.hide();
                toast(res.msg || '服务配置已保存', 'success');
            }).catch(function () {
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalHtml;
                toast('网络异常，请稍后重试', 'error');
            });
        });
    }

    if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function () {
            currentId = null;
            enableOnSave = false;
        });
    }

    // ---------- 图标加载失败回退 ----------
    grid.querySelectorAll('[data-service-icon]').forEach(function (img) {
        if (img.complete && img.naturalWidth === 0) {
            img.remove();
            return;
        }
        img.addEventListener('error', function () {
            img.remove();
        }, { once: true });
    });
})();
