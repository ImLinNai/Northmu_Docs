(function () {
    'use strict';

    function toast(message, type) {
        if (window.NorthmuUI && NorthmuUI.toast) {
            NorthmuUI.toast(message, type || 'info');
        }
    }

    function show(el, on) {
        if (el) el.classList.toggle('d-none', !on);
    }

    /* ---------- 密码显示切换（创建表单与恢复弹窗共用） ---------- */
    document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-password-toggle'));
            if (!input) return;
            var visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            btn.setAttribute('aria-pressed', visible ? 'true' : 'false');
            btn.setAttribute('aria-label', visible ? '隐藏密码' : '显示密码');
            btn.innerHTML = '<i class="ti ' + (visible ? 'ti-eye-off' : 'ti-eye') + '"></i>';
        });
    });

    /* ---------- 创建表单：两次密码一致性校验 ---------- */
    var pwd = document.getElementById('backupPassword');
    var pwd2 = document.getElementById('backupPasswordConfirm');
    var pwdHint = document.getElementById('backupPasswordHint');
    if (pwd && pwd2) {
        var syncPassword = function () {
            var has = pwd.value !== '';
            pwd2.required = has;
            pwd2.setCustomValidity(has && pwd2.value !== pwd.value ? '两次输入的备份密码不一致' : '');
            if (pwdHint) {
                pwdHint.textContent = has
                    ? '将使用 AES-256 加密备份包，恢复时必须输入同一密码。'
                    : '设置密码后备份包使用 AES-256 加密，恢复时必须输入同一密码。';
            }
        };
        pwd.addEventListener('input', syncPassword);
        pwd2.addEventListener('input', syncPassword);
        syncPassword();
    }

    /* ---------- 恢复弹窗 ---------- */
    var modalEl = document.getElementById('backupRestoreModal');
    if (!modalEl || !window.NorthmuUI || !window.bootstrap) return;

    var els = {
        file: document.getElementById('backupRestoreFile'),
        fileMeta: document.getElementById('backupRestoreFileMeta'),
        badges: document.getElementById('backupRestoreBadges'),
        alert: document.getElementById('backupRestoreAlert'),
        loading: document.getElementById('backupRestoreLoading'),
        passwordWrap: document.getElementById('backupRestorePasswordWrap'),
        password: document.getElementById('backupRestorePassword'),
        meta: document.getElementById('backupRestoreMeta'),
        conflict: document.getElementById('backupRestoreConflict'),
        conflictText: document.getElementById('backupRestoreConflictText'),
        done: document.getElementById('backupRestoreDone'),
        doneText: document.getElementById('backupRestoreDoneText'),
        errors: document.getElementById('backupRestoreErrors'),
        errorList: document.getElementById('backupRestoreErrorList'),
        progress: document.getElementById('backupRestoreProgress'),
        progressText: document.getElementById('backupRestoreProgressText'),
        cancel: document.getElementById('backupRestoreCancel'),
        inspect: document.getElementById('backupRestoreInspect'),
        start: document.getElementById('backupRestoreStart'),
        reload: document.getElementById('backupRestoreReload')
    };

    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    var radios = modalEl.querySelectorAll('input[name="backupRestoreMode"]');

    var file = '';
    var encrypted = false;
    var password = '';
    var inspected = false;
    var restoring = false;
    var finished = false;
    var lastConflicts = null;

    function badge(text, cls) {
        var el = document.createElement('span');
        el.className = 'badge ' + cls;
        el.textContent = text;
        return el;
    }

    function setAlert(message, type) {
        if (!els.alert) return;
        els.alert.className = 'alert alert-' + type;
        els.alert.textContent = message;
        show(els.alert, true);
    }

    function clearAlert() {
        if (!els.alert) return;
        els.alert.textContent = '';
        show(els.alert, false);
    }

    function currentMode() {
        var checked = modalEl.querySelector('input[name="backupRestoreMode"]:checked');
        return checked ? checked.value : 'skip';
    }

    function syncModeTone() {
        var overwrite = currentMode() === 'overwrite';
        els.start.classList.toggle('btn-danger', overwrite);
        els.start.classList.toggle('btn-primary', !overwrite);
    }

    function resetState() {
        inspected = false;
        restoring = false;
        finished = false;
        password = '';
        lastConflicts = null;

        if (els.password) {
            els.password.value = '';
            els.password.type = 'password';
        }
        clearAlert();
        if (els.fileMeta) els.fileMeta.textContent = '';
        if (els.badges) els.badges.innerHTML = '';
        if (els.errorList) els.errorList.innerHTML = '';
        if (els.doneText) els.doneText.textContent = '';
        if (els.conflictText) els.conflictText.textContent = '';

        show(els.loading, false);
        show(els.meta, false);
        show(els.conflict, false);
        show(els.done, false);
        show(els.errors, false);
        show(els.progress, false);
        show(els.passwordWrap, false);
        show(els.start, false);
        show(els.reload, false);
        show(els.inspect, false);
        show(els.cancel, true);

        els.inspect.textContent = '检测数据包';
        els.cancel.textContent = '取消';
        els.start.className = 'btn btn-primary d-none';
        els.start.disabled = false;
        els.start.innerHTML = '<i class="ti ti-restore me-1"></i>开始恢复';

        radios.forEach(function (radio) {
            radio.checked = radio.value === 'skip';
        });
    }

    function renderMeta(meta) {
        var items = {
            site_name: meta.site_name || '-',
            app_version: meta.app_version ? 'v' + meta.app_version : '-',
            created_at: meta.created_at || '-',
            db_name: meta.db_name || '-',
            tables: meta.table_count + ' 张表 · ' + meta.row_count + ' 条记录',
            uploads: meta.upload_count + ' 个 · ' + (meta.upload_size || '0 B'),
            documents: meta.documents + ' 篇 / ' + meta.drafts + ' 份草稿',
            size: meta.size_text || '-'
        };
        Object.keys(items).forEach(function (key) {
            var el = els.meta.querySelector('[data-meta="' + key + '"]');
            if (el) el.textContent = items[key];
        });
    }

    function renderConflict(conflicts) {
        lastConflicts = conflicts;
        if (!conflicts || !conflicts.has_conflict) {
            setAlert('完整性校验通过，未检测到与现有数据重叠的内容，将直接写入备份数据。', 'info');
            show(els.conflict, false);
            return;
        }

        var parts = [];
        var tables = conflicts.tables || [];
        if (tables.length) {
            parts.push(tables.length + ' 个数据表共 ' + (conflicts.table_rows || 0) + ' 条记录');
        }
        if (conflicts.uploads > 0) {
            parts.push(conflicts.uploads + ' 个附件文件');
        }
        var names = tables.slice(0, 4).map(function (item) {
            return item.name + '（现有 ' + item.current_rows + ' 行 / 备份 ' + item.backup_rows + ' 行）';
        });
        els.conflictText.textContent = '检测到与现有数据重叠：' + parts.join('、') + '。'
            + (names.length ? '涉及 ' + names.join('、') + (tables.length > 6 ? ' 等' : '') + '。' : '')
            + '请选择恢复方式后继续。';
        show(els.conflict, true);
        syncModeTone();
    }

    function failInspect(message) {
        inspected = false;
        setAlert(message, 'danger');
        show(els.passwordWrap, encrypted);
        show(els.inspect, true);
        els.inspect.textContent = '重新检测';
        if (encrypted) els.password.focus();
    }

    function inspect() {
        if (!file) return;
        var typed = els.password ? els.password.value : '';
        if (encrypted && typed === '') {
            setAlert('该备份已设置密码，请输入备份密码后再检测。', 'warning');
            if (els.password) els.password.focus();
            return;
        }

        clearAlert();
        show(els.meta, false);
        show(els.conflict, false);
        show(els.done, false);
        show(els.errors, false);
        show(els.progress, false);
        show(els.start, false);
        show(els.reload, false);
        show(els.inspect, false);
        show(els.loading, true);

        NorthmuUI.post('/admin/backup/inspect', {
            file: file,
            password: encrypted ? typed : ''
        }).then(function (res) {
            show(els.loading, false);
            if (!res || res.code !== 0) {
                failInspect((res && res.msg) || '检测失败，请稍后重试。');
                return;
            }
            password = encrypted ? typed : '';
            inspected = true;
            renderMeta(res.data && res.data.meta ? res.data.meta : {});
            show(els.meta, true);
            show(els.passwordWrap, false);
            renderConflict(res.data ? res.data.conflicts : null);
            show(els.start, true);
            els.start.focus();
        }).catch(function () {
            show(els.loading, false);
            failInspect('检测请求失败，请检查网络或服务器日志后重试。');
        });
    }

    function openRestore(btn) {
        resetState();
        file = btn.getAttribute('data-file') || '';
        encrypted = btn.getAttribute('data-encrypted') === '1';

        els.file.textContent = file;
        els.fileMeta.textContent = encrypted ? '加密备份包 · AES-256' : '未加密备份包';
        els.badges.appendChild(badge(encrypted ? '已加密' : '未加密', encrypted ? 'bg-purple-lt' : 'bg-secondary-lt'));

        modal.show();

        if (encrypted) {
            show(els.passwordWrap, true);
            show(els.inspect, true);
            window.setTimeout(function () { els.password.focus(); }, 180);
        } else {
            inspect();
        }
    }

    function renderDone(result, mode) {
        var parts = [
            '恢复模式：' + (mode === 'overwrite' ? '覆盖' : '跳过重复'),
            '处理 ' + (result.tables || 0) + ' 张表 · 新增 ' + (result.rows || 0) + ' 条记录',
            '写入附件 ' + (result.uploads || 0) + ' 个'
        ];
        if (result.skipped_uploads) {
            parts.push('跳过已有附件 ' + result.skipped_uploads + ' 个');
        }
        els.doneText.textContent = parts.join(' · ') + '。';
        show(els.done, true);

        if (result.errors && result.errors.length) {
            els.errorList.innerHTML = '';
            result.errors.forEach(function (message) {
                var li = document.createElement('li');
                li.textContent = message;
                els.errorList.appendChild(li);
            });
            show(els.errors, true);
        }
    }

    function runRestore(mode) {
        restoring = true;
        clearAlert();
        show(els.passwordWrap, false);
        show(els.meta, false);
        show(els.conflict, false);
        show(els.start, false);
        show(els.inspect, false);
        show(els.cancel, false);
        show(els.reload, false);
        show(els.done, false);
        show(els.errors, false);
        show(els.progress, true);
        els.progressText.textContent = mode === 'overwrite'
            ? '正在覆盖数据表并写入备份内容，请勿关闭或刷新页面…'
            : '正在写入备份数据，请勿关闭或刷新页面…';

        NorthmuUI.post('/admin/backup/restore', {
            file: file,
            password: password,
            mode: mode
        }).then(function (res) {
            restoring = false;
            show(els.progress, false);
            if (!res || res.code !== 0) {
                setAlert((res && res.msg) || '恢复失败，请稍后重试。', 'danger');
                show(els.meta, true);
                if (lastConflicts && lastConflicts.has_conflict) show(els.conflict, true);
                show(els.cancel, true);
                show(els.start, true);
                return;
            }
            finished = true;
            renderDone(res.data || {}, mode);
            show(els.reload, true);
            show(els.cancel, true);
            els.cancel.textContent = '关闭';
        }).catch(function () {
            restoring = false;
            show(els.progress, false);
            setAlert('恢复请求失败，请查看服务器日志后重试。', 'danger');
            show(els.meta, true);
            if (lastConflicts && lastConflicts.has_conflict) show(els.conflict, true);
            show(els.cancel, true);
            show(els.start, true);
        });
    }

    document.querySelectorAll('[data-restore]').forEach(function (btn) {
        btn.addEventListener('click', function () { openRestore(btn); });
    });

    /* ---------- 删除备份 ---------- */
    document.querySelectorAll('[data-delete-file]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var name = btn.getAttribute('data-delete-file');
            if (!name) return;
            NorthmuUI.confirm({
                anchor: btn,
                title: '删除备份',
                message: '确定删除备份「' + (btn.getAttribute('data-delete-name') || name) + '」？删除后无法找回。',
                tone: 'danger',
                confirmText: '删除'
            }).then(function (ok) {
                if (!ok) return;
                NorthmuUI.busy(btn, true, '删除中…');
                NorthmuUI.post('/admin/backup/delete', { file: name }).then(function (res) {
                    if (res && res.code === 0) {
                        toast('备份已删除', 'success');
                        var row = btn.closest('tr');
                        if (row) row.remove();
                        if (!document.querySelector('[data-delete-file]')) {
                            window.location.reload();
                        }
                        return;
                    }
                    NorthmuUI.busy(btn, false);
                    toast((res && res.msg) || '删除失败', 'error');
                }).catch(function () {
                    NorthmuUI.busy(btn, false);
                    toast('删除请求失败，请稍后重试', 'error');
                });
            });
        });
    });

    radios.forEach(function (radio) {
        radio.addEventListener('change', syncModeTone);
    });

    els.inspect.addEventListener('click', inspect);

    els.start.addEventListener('click', function () {
        if (!inspected || restoring || finished) return;
        var mode = currentMode();
        var overwrite = mode === 'overwrite';
        NorthmuUI.confirm({
            anchor: els.start,
            title: overwrite ? '确认覆盖恢复' : '确认开始恢复',
            message: overwrite
                ? '将删除备份涉及的同名数据表并替换为备份内容，现有数据会丢失且无法撤销。确定继续？'
                : '将写入备份中不存在的数据；已存在的表和记录会被跳过。恢复期间请勿关闭页面。',
            tone: overwrite ? 'danger' : 'warning',
            confirmText: overwrite ? '覆盖并恢复' : '开始恢复'
        }).then(function (ok) {
            if (ok) runRestore(mode);
        });
    });

    els.reload.addEventListener('click', function () {
        window.location.reload();
    });

    els.password.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            inspect();
        }
    });

    modalEl.addEventListener('hide.bs.modal', function (event) {
        if (restoring) {
            event.preventDefault();
            toast('恢复正在进行，请等待完成后再关闭。', 'warning');
        }
    });
})();
