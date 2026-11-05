(function () {
    'use strict';

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function toast(message, type) {
        if (window.NorthmuAdmin && window.NorthmuAdmin.toast) {
            window.NorthmuAdmin.toast(message, type || 'info');
        }
    }

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        var hero = document.getElementById('nmUpdateHero');
        var icon = document.getElementById('nmUpdateIcon');
        var title = document.getElementById('nmUpdateTitle');
        var meta = document.getElementById('nmUpdateMeta');
        var sourceBadge = document.getElementById('nmUpdateSource');
        var hint = document.getElementById('nmUpdateHint');
        var checkButton = document.getElementById('nmUpdateCheck');
        var downloadButton = document.getElementById('nmUpdateDownload');
        var skipButton = document.getElementById('nmUpdateSkip');
        var releaseLink = document.getElementById('nmUpdateRelease');
        var notesCard = document.getElementById('nmUpdateNotesCard');
        var notesVersion = document.getElementById('nmUpdateNotesVersion');
        var notesBody = document.getElementById('nmUpdateNotes');
        var skippedLabel = document.getElementById('nmSkippedVersion');

        if (!hero || !checkButton) return;

        var latestVersion = '';

        function setIcon(kind) {
            if (!icon) return;
            if (kind === 'loading') {
                icon.innerHTML = '<span class="nm-update-spinner"></span>';
            } else if (kind === 'latest') {
                icon.innerHTML = '<i class="ti ti-circle-check"></i>';
            } else if (kind === 'update') {
                icon.innerHTML = '<i class="ti ti-rocket"></i>';
            } else if (kind === 'skipped') {
                icon.innerHTML = '<i class="ti ti-history"></i>';
            } else if (kind === 'error') {
                icon.innerHTML = '<i class="ti ti-alert-circle"></i>';
            } else {
                icon.innerHTML = '<i class="ti ti-cloud-download"></i>';
            }
        }

        function setHeroState(kind) {
            hero.classList.remove('is-latest', 'is-error', 'is-skipped');
            if (kind === 'latest') hero.classList.add('is-latest');
            if (kind === 'error') hero.classList.add('is-error');
            if (kind === 'skipped') hero.classList.add('is-skipped');
        }

        function setSource(label, fromGitee) {
            if (!sourceBadge) return;
            if (!label) {
                sourceBadge.classList.add('d-none');
                return;
            }
            sourceBadge.classList.remove('d-none');
            sourceBadge.textContent = '来源：' + label;
        }

        function showActions(state) {
            var hasUpdate = state === 'update';
            downloadButton.classList.toggle('d-none', !hasUpdate);
            skipButton.classList.toggle('d-none', !hasUpdate);
        }

        function formatSize(bytes) {
            bytes = Number(bytes) || 0;
            if (bytes <= 0) return '';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        function formatDate(value) {
            if (!value) return '';
            var date = new Date(value);
            if (isNaN(date.getTime())) return String(value);
            return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-'
                + String(date.getDate()).padStart(2, '0') + ' ' + String(date.getHours()).padStart(2, '0') + ':'
                + String(date.getMinutes()).padStart(2, '0');
        }

        function stripInlineMarkdown(text) {
            return text
                .replace(/\*\*([^*]+)\*\*/g, '$1')
                .replace(/`([^`]+)`/g, '$1')
                .replace(/\[([^\]]+)\]\((https?:[^)]+)\)/g, '$1');
        }

        function renderNotesText(notes) {
            notesBody.textContent = '';
            notes.replace(/\r\n/g, '\n').split('\n').forEach(function (line) {
                var text = line.trim();
                if (!text) {
                    var gap = document.createElement('div');
                    gap.className = 'nm-update-notes-gap';
                    notesBody.appendChild(gap);
                    return;
                }
                var heading = text.match(/^#{1,6}\s+(.+)$/);
                if (heading) {
                    var head = document.createElement('div');
                    head.className = 'nm-update-notes-heading';
                    head.textContent = stripInlineMarkdown(heading[1]);
                    notesBody.appendChild(head);
                    return;
                }
                var bullet = text.match(/^[-*]\s+(.+)$/);
                var numbered = text.match(/^(\d+)\.\s+(.+)$/);
                var row = document.createElement('div');
                if (bullet) {
                    row.className = 'nm-update-notes-item';
                    row.textContent = '• ' + stripInlineMarkdown(bullet[1]);
                } else if (numbered) {
                    row.className = 'nm-update-notes-item';
                    row.textContent = numbered[1] + '. ' + stripInlineMarkdown(numbered[2]);
                } else {
                    row.className = 'nm-update-notes-line';
                    row.textContent = stripInlineMarkdown(text);
                }
                notesBody.appendChild(row);
            });
        }

        function renderNotes(data) {
            if (!notesCard || !notesBody) return;
            var notes = (data.notes || '').trim();
            if (!notes) {
                notesCard.classList.add('d-none');
                return;
            }
            renderNotesText(notes);
            if (notesVersion) notesVersion.textContent = 'v' + data.latest;
            notesCard.classList.remove('d-none');
        }

        function render(data) {
            latestVersion = data.latest || '';
            setHeroState('');
            setIcon('update');
            showActions('update');
            setSource(data.source_label, data.source === 'gitee');

            if (data.has_update) {
                if (data.skipped) {
                    setHeroState('skipped');
                    setIcon('skipped');
                    title.textContent = '已跳过 v' + data.latest;
                    meta.textContent = '当前 v' + data.current + '，该版本已跳过，后续新版本仍会提示';
                    hint.textContent = '已跳过此版本；如需升级仍可点击「立即下载」手动获取安装包。';
                    showActions('update');
                } else {
                    title.textContent = '发现新版本 v' + data.latest;
                    meta.textContent = '当前 v' + data.current + (data.published_at ? ' · 发布于 ' + formatDate(data.published_at) : '');
                    hint.textContent = '可下载安装包后按发行版说明升级；建议先备份数据库与上传目录。';
                    showActions('update');
                }

                if (data.download_url) {
                    downloadButton.href = data.download_url;
                    downloadButton.removeAttribute('aria-disabled');
                    downloadButton.classList.remove('d-none');
                    var size = formatSize(data.download_size);
                    downloadButton.innerHTML = '<i class="ti ti-download me-1"></i>立即下载'
                        + (size ? '（' + size + '）' : '');
                } else {
                    downloadButton.classList.add('d-none');
                }
                skipButton.classList.toggle('d-none', !!data.skipped);
                renderNotes(data);
            } else {
                setHeroState('latest');
                setIcon('latest');
                showActions('idle');
                title.textContent = '已是最新版本 v' + data.current;
                meta.textContent = data.published_at
                    ? '最新发行版 v' + data.latest + ' · 发布于 ' + formatDate(data.published_at)
                    : '最新发行版 v' + data.latest;
                hint.textContent = '暂无可用更新，可稍后再次检测。';
                if (notesCard) notesCard.classList.add('d-none');
            }

            if (data.release_url) {
                releaseLink.href = data.release_url;
            }
            if (skippedLabel) {
                skippedLabel.textContent = data.skipped_version || '无';
            }
        }

        function showError(message) {
            setHeroState('error');
            setIcon('error');
            showActions('idle');
            setSource('');
            title.textContent = '检测更新失败';
            meta.textContent = message || '无法获取发行版信息';
            hint.textContent = '请检查服务器网络或稍后重试；GitHub 不可用时会自动尝试 Gitee。';
            if (notesCard) notesCard.classList.add('d-none');
        }

        function check(force) {
            checkButton.disabled = true;
            setHeroState('');
            setIcon('loading');
            showActions('idle');
            setSource('');
            title.textContent = '正在检测更新…';
            meta.textContent = force
                ? '正在重新获取 GitHub / Gitee 最新发行版'
                : '正在通过 GitHub 获取最新发行版，失败时自动切换 Gitee';
            hint.textContent = '检测通常在数秒内完成。';

            fetch('/admin/update/check?force=' + (force ? '1' : '0'), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.json().then(function (payload) {
                    return { ok: response.ok, payload: payload };
                });
            }).then(function (result) {
                var payload = result.payload || {};
                if (!result.ok || payload.code !== 0) {
                    showError(payload.msg || '检测更新失败');
                    return;
                }
                render(payload.data || {});
                if (payload.data && payload.data.fallback_reason) {
                    hint.textContent = payload.data.fallback_reason + (payload.data.checked_at ? ' · 检测于 ' + payload.data.checked_at : '');
                }
            }).catch(function () {
                showError('网络请求失败，请检查网络后重试');
            }).finally(function () {
                checkButton.disabled = false;
            });
        }

        checkButton.addEventListener('click', function () {
            check(true);
        });

        if (skipButton) {
            skipButton.addEventListener('click', function () {
                if (!latestVersion) return;
                var message = '确定跳过 v' + latestVersion + '？跳过只影响该版本，后续新版本仍会提示。';
                var run = function () {
                    skipButton.disabled = true;
                    fetch('/admin/update/skip', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf(),
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: 'version=' + encodeURIComponent(latestVersion)
                    }).then(function (response) {
                        return response.json();
                    }).then(function (payload) {
                        if (!payload || payload.code !== 0) {
                            throw new Error((payload && payload.msg) || '操作失败');
                        }
                        setHeroState('skipped');
                        setIcon('skipped');
                        title.textContent = '已跳过 v' + latestVersion;
                        meta.textContent = '该版本已跳过，后续新版本仍会提示';
                        hint.textContent = '已跳过此版本；如需升级仍可点击「立即下载」手动获取安装包。';
                        skipButton.classList.add('d-none');
                        if (skippedLabel) skippedLabel.textContent = latestVersion;
                        toast(payload.msg || '已跳过该版本', 'success');
                    }).catch(function (error) {
                        toast(error && error.message ? error.message : '操作失败', 'danger');
                    }).finally(function () {
                        skipButton.disabled = false;
                    });
                };

                if (window.NorthmuUI && window.NorthmuUI.confirm) {
                    window.NorthmuUI.confirm({
                        anchor: skipButton,
                        title: '跳过此版本',
                        message: message,
                        tone: 'warning',
                        confirmText: '跳过',
                        cancelText: '取消'
                    }).then(function (ok) {
                        if (ok) run();
                    });
                    return;
                }
                if (window.confirm(message)) {
                    run();
                }
            });
        }

        check(false);
    });
})();
