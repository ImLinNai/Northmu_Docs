(function () {
    'use strict';

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function toast(message, type) {
        if (window.NorthmuAdmin) {
            NorthmuAdmin.toast(message, type);
        } else {
            window.alert(message);
        }
    }

    document.querySelectorAll('[data-check-all]').forEach(function (all) {
        var table = all.closest('table');
        if (!table) return;
        var items = Array.prototype.slice.call(table.querySelectorAll('[data-check-item]'));

        function updateCount() {
            var count = items.filter(function (item) { return item.checked; }).length;
            document.querySelectorAll('[data-check-count]').forEach(function (el) {
                el.textContent = String(count);
            });
            all.checked = count > 0 && count === items.length;
        }

        all.addEventListener('change', function () {
            items.forEach(function (item) { item.checked = all.checked; });
            updateCount();
        });
        items.forEach(function (item) {
            item.addEventListener('change', updateCount);
        });
        updateCount();
    });

    var categoryPanel = document.getElementById('categoryDrawer') || document.getElementById('categoryModal');
    if (categoryPanel) {
        var catVisibility = document.getElementById('catVisibility');
        var catPasswordWrap = document.getElementById('catPasswordWrap');
        var catPassword = document.getElementById('catPassword');
        var catHasPassword = false;

        function syncCategoryPassword() {
            var encrypted = catVisibility.value === 'encrypted';
            catPasswordWrap.classList.toggle('d-none', !encrypted);
            catPassword.required = encrypted && !catHasPassword;
            catPassword.placeholder = encrypted && catHasPassword ? '留空则保持原密码' : '设置分类访问密码';
        }

        catVisibility.addEventListener('change', syncCategoryPassword);

        var categoryEvent = categoryPanel.classList.contains('offcanvas') ? 'show.bs.offcanvas' : 'show.bs.modal';
        categoryPanel.addEventListener(categoryEvent, function (event) {
            var btn = event.relatedTarget;
            var isEdit = !!(btn && btn.hasAttribute('data-category-edit'));
            var id = isEdit ? btn.getAttribute('data-id') : '0';
            var hasPassword = isEdit && btn.getAttribute('data-has-password') === '1';
            catHasPassword = hasPassword;
            var parentSelect = document.getElementById('catParent');

            document.getElementById('catId').value = id;
            document.getElementById('catName').value = isEdit ? btn.getAttribute('data-name') : '';
            document.getElementById('catDescription').value = isEdit ? btn.getAttribute('data-description') : '';
            catVisibility.value = isEdit ? btn.getAttribute('data-visibility') : 'public';
            catPassword.value = '';
            document.getElementById('catSort').value = isEdit ? btn.getAttribute('data-sort') : '0';
            document.getElementById('catModalTitle').textContent = isEdit ? '编辑分类' : '新建分类';
            syncCategoryPassword();

            Array.prototype.forEach.call(parentSelect.options, function (option) {
                option.disabled = isEdit && option.value === id;
            });
            parentSelect.value = isEdit ? btn.getAttribute('data-parent') : '0';
            if (parentSelect.value === '' || (isEdit && parentSelect.value === id)) {
                parentSelect.value = '0';
            }
        });
    }

    var tagEditModal = document.getElementById('tagEditModal');
    if (tagEditModal) {
        tagEditModal.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget;
            if (!btn) return;
            document.getElementById('tagEditId').value = btn.getAttribute('data-id');
            document.getElementById('tagEditName').value = btn.getAttribute('data-name');
        });
    }

    var tagMergeModal = document.getElementById('tagMergeModal');
    if (tagMergeModal) {
        tagMergeModal.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget;
            if (!btn) return;
            var sourceId = btn.getAttribute('data-id');
            document.getElementById('mergeSourceId').value = sourceId;
            document.getElementById('mergeSourceName').value = btn.getAttribute('data-name');

            var target = document.getElementById('mergeTargetId');
            Array.prototype.forEach.call(target.options, function (option) {
                option.disabled = option.value === sourceId;
            });
            target.value = '';
        });
    }

    var uploadBtn = document.getElementById('attachmentUploadBtn');
    var uploadInput = document.getElementById('attachmentInput');
    var progress = document.getElementById('attachmentProgress');

    function uploadOne(file) {
        var fd = new FormData();
        fd.append('file', file);
        fd.append('_token', csrf());
        return fetch('/admin/attachments/upload', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        }).then(function (res) {
            if (res.code !== 0) throw new Error(res.msg || '上传失败');
            return res.data;
        });
    }

    if (uploadBtn && uploadInput) {
        uploadBtn.addEventListener('click', function () { uploadInput.click(); });
        var dropZone = document.getElementById('attachmentDropZone');
        if (dropZone) {
            ['dragenter', 'dragover'].forEach(function (name) {
                dropZone.addEventListener(name, function (event) {
                    event.preventDefault();
                    dropZone.classList.add('is-dragover');
                });
            });
            ['dragleave', 'drop'].forEach(function (name) {
                dropZone.addEventListener(name, function (event) {
                    event.preventDefault();
                    if (name === 'dragleave' && dropZone.contains(event.relatedTarget)) return;
                    dropZone.classList.remove('is-dragover');
                });
            });
            dropZone.addEventListener('drop', function (event) {
                var files = event.dataTransfer && event.dataTransfer.files;
                if (!files || !files.length) return;
                try {
                    var transfer = new DataTransfer();
                    Array.prototype.forEach.call(files, function (file) { transfer.items.add(file); });
                    uploadInput.files = transfer.files;
                    uploadInput.dispatchEvent(new Event('change', { bubbles: true }));
                } catch (e) {
                    toast('当前浏览器不支持拖拽上传，请点击按钮选择文件', 'warning');
                }
            });
        }
        uploadInput.addEventListener('change', function () {
            var files = Array.prototype.slice.call(uploadInput.files || []);
            if (!files.length) return;

            if (window.NorthmuAdmin && NorthmuAdmin.validateUploads) {
                var checked = NorthmuAdmin.validateUploads(files, null);
                checked.invalid.forEach(function (item) {
                    toast(item.file.name + '：' + item.message, 'error');
                });
                files = checked.valid.map(function (item) { return item.file; });
                if (!files.length) {
                    uploadInput.value = '';
                    return;
                }
            }

            if (window.NorthmuAdmin) NorthmuAdmin.toast('正在上传 ' + files.length + ' 个文件…', 'info');

            var bar = progress ? progress.querySelector('.progress-bar') : null;
            if (progress) progress.classList.remove('d-none');
            var done = 0;
            var failed = 0;

            var chain = Promise.resolve();
            files.forEach(function (file) {
                chain = chain.then(function () {
                    return uploadOne(file).then(function () {
                        done++;
                    }).catch(function (err) {
                        failed++;
                        toast(file.name + '：' + err.message, 'error');
                    }).then(function () {
                        if (bar) bar.style.width = Math.round((done + failed) / files.length * 100) + '%';
                    });
                });
            });

            chain.then(function () {
                if (done > 0) {
                    window.location.reload();
                } else if (progress) {
                    progress.classList.add('d-none');
                }
                uploadInput.value = '';
            });
        });
    }

    document.querySelectorAll('[data-copy-url]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var url = window.location.origin + btn.getAttribute('data-copy-url');
            if (window.NorthmuUI) {
                NorthmuUI.copy(url, '附件链接已复制');
            } else if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () { toast('链接已复制'); });
            } else {
                window.prompt('复制链接', url);
            }
        });
    });

    document.querySelectorAll('[data-delete-attachment]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var ask = window.NorthmuUI
                ? NorthmuUI.confirm({
                    anchor: btn,
                    title: '删除附件',
                    message: '删除后文件将从附件库移除；仍被文档引用时无法删除。',
                    tone: 'danger',
                    confirmText: '删除'
                })
                : Promise.resolve(window.confirm('确定删除该附件？仍被文档引用时无法删除。'));
            ask.then(function (ok) {
                if (!ok) return;
                NorthmuAdmin.post('/admin/attachments/delete', { id: btn.getAttribute('data-delete-attachment') }).then(function (res) {
                    if (res.code === 0) {
                        toast('附件已删除', 'success');
                        window.location.reload();
                    } else {
                        toast(res.msg || '删除失败', 'error');
                    }
                }).catch(function () {
                    toast('删除失败，请稍后重试', 'error');
                });
            });
        });
    });

    var cleanupBtn = document.getElementById('attachmentCleanupBtn');
    if (cleanupBtn) {
        cleanupBtn.addEventListener('click', function () {
            var ask = window.NorthmuUI
                ? NorthmuUI.confirm({
                    anchor: cleanupBtn,
                    title: '清理未引用文件',
                    message: '将删除所有未被文档引用的附件记录，以及上传目录中未登记的孤立文件。此操作不可撤销。',
                    tone: 'danger',
                    confirmText: '开始清理'
                })
                : Promise.resolve(window.confirm('将删除所有未被文档引用的附件记录，以及上传目录中未登记的孤立文件。确定继续？'));
            ask.then(function (ok) {
                if (!ok) return;
                if (window.NorthmuUI) NorthmuUI.busy(cleanupBtn, true, '清理中…');
                else cleanupBtn.disabled = true;
                NorthmuAdmin.post('/admin/attachments/cleanup', {}).then(function (res) {
                    toast(res.msg || '清理完成');
                    setTimeout(function () { window.location.reload(); }, 800);
                }).catch(function () {
                    if (window.NorthmuUI) NorthmuUI.busy(cleanupBtn, false);
                    else cleanupBtn.disabled = false;
                    toast('清理失败，请稍后重试', 'error');
                });
            });
        });
    }

    var cacheTestBtn = document.getElementById('cacheTestBtn');
    if (cacheTestBtn) {
        cacheTestBtn.addEventListener('click', function () {
            var result = document.getElementById('cacheTestResult');
            var form = cacheTestBtn.closest('form');
            if (!form || !result) return;
            var body = new URLSearchParams(new FormData(form));
            result.className = 'small mt-2 text-secondary';
            result.textContent = '正在测试连接…';
            cacheTestBtn.disabled = true;
            fetch(cacheTestBtn.getAttribute('data-test-url') || '/admin/cache/test', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf(),
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json();
            }).then(function (res) {
                if (res.code === 0) {
                    result.className = 'small mt-2 text-success';
                    result.textContent = '连接成功：Redis ' + ((res.data && res.data.version) || '') + ((res.data && res.data.memory) ? '，已用内存 ' + res.data.memory : '');
                } else {
                    result.className = 'small mt-2 text-danger';
                    result.textContent = res.msg || '连接失败';
                }
            }).catch(function () {
                result.className = 'small mt-2 text-danger';
                result.textContent = '连接测试请求失败';
            }).then(function () {
                cacheTestBtn.disabled = false;
            });
        });
    }

    var versionEl = document.getElementById('versionData');
    if (versionEl && window.Diff && window.Diff2Html) {
        var versionData = null;
        try { versionData = JSON.parse(versionEl.textContent); } catch (e) { versionData = null; }
        if (versionData) {
            var diffModalEl = document.getElementById('diffModal');
            var diffModal = diffModalEl && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(diffModalEl) : null;

            document.querySelectorAll('[data-compare-version]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = parseInt(btn.getAttribute('data-compare-version'), 10);
                    var version = versionData.versions.filter(function (item) { return item.id === id; })[0];
                    if (!version || !diffModal) return;

                    var patch = Diff.createTwoFilesPatch(
                        '版本 v' + version.version_no,
                        '当前版本',
                        version.content || '',
                        versionData.current.content || '',
                        version.created_at || '',
                        versionData.current.created_at || '',
                        { context: 4 }
                    );
                    var html = Diff2Html.html(patch, {
                        drawFileList: false,
                        matching: 'lines',
                        outputFormat: 'line-by-line'
                    });
                    document.getElementById('diffOutput').innerHTML = html;
                    document.getElementById('diffTitle').textContent = '对比：v' + version.version_no + ' → 当前版本';
                    diffModal.show();
                });
            });
        }
    }

    var navItemsBody = document.getElementById('navItemsBody');
    var navItemAdd = document.getElementById('navItemAdd');
    var navItemTemplate = document.getElementById('navItemTemplate');
    var navItemCount = document.getElementById('navItemCount');
    if (navItemsBody && navItemAdd && navItemTemplate) {
        var maxNavItems = 12;
        var updateNavCount = function () {
            var count = navItemsBody.querySelectorAll('.nav-item-row').length;
            if (navItemCount) {
                navItemCount.textContent = count + ' 个菜单';
            }
            navItemAdd.disabled = count >= maxNavItems;
        };
        navItemAdd.addEventListener('click', function () {
            if (navItemsBody.querySelectorAll('.nav-item-row').length >= maxNavItems) {
                return;
            }
            navItemsBody.appendChild(navItemTemplate.content.firstElementChild.cloneNode(true));
            updateNavCount();
            var firstInput = navItemsBody.querySelector('.nav-item-row:last-child input');
            if (firstInput) {
                firstInput.focus();
            }
        });
        navItemsBody.addEventListener('click', function (event) {
            var removeBtn = event.target.closest('.nav-item-remove');
            if (!removeBtn) {
                return;
            }
            var row = removeBtn.closest('.nav-item-row');
            if (row) {
                row.remove();
            }
            updateNavCount();
        });
        updateNavCount();
    }

    var homeButtonsBody = document.getElementById('homeButtonsBody');
    var homeButtonAdd = document.getElementById('homeButtonAdd');
    var homeButtonTemplate = document.getElementById('homeButtonTemplate');
    var homeButtonsEmpty = document.getElementById('homeButtonsEmpty');
    if (homeButtonsBody && homeButtonAdd && homeButtonTemplate) {
        var maxHomeButtons = 4;

        var homeRows = function () {
            return Array.prototype.slice.call(homeButtonsBody.querySelectorAll('.home-button-row'));
        };

        var escapeHomeText = function (text) {
            var span = document.createElement('span');
            span.textContent = text;
            return span.innerHTML;
        };

        var syncHomeButtonRow = function (row) {
            var rows = homeRows();
            var index = rows.indexOf(row);
            if (index < 0) {
                return;
            }
            row.querySelectorAll('[data-home-btn-icononly]').forEach(function (input) {
                input.name = 'home_buttons[icon_only][' + index + ']';
            });
            var indexLabel = row.querySelector('.nm-home-btn-index');
            if (indexLabel) {
                indexLabel.textContent = '按钮 ' + (index + 1);
            }

            var labelInput = row.querySelector('[data-home-btn-label]');
            var urlInput = row.querySelector('[data-home-btn-url]');
            var styleSelect = row.querySelector('[data-home-btn-style]');
            var iconSelect = row.querySelector('[data-home-btn-icon]');
            var colorValue = row.querySelector('[data-home-btn-color-value]');
            var colorInput = row.querySelector('[data-home-color-input]');
            var onlyCheckbox = row.querySelector('input[type="checkbox"][data-home-btn-icononly]');

            var icon = iconSelect ? iconSelect.value : '';
            var label = labelInput ? labelInput.value.trim() : '';
            var url = urlInput ? urlInput.value.trim() : '';
            var style = styleSelect ? styleSelect.value : 'primary';
            var color = colorValue ? colorValue.value : '';
            var only = !!(onlyCheckbox && onlyCheckbox.checked && icon);

            if (onlyCheckbox) {
                onlyCheckbox.disabled = icon === '';
                if (icon === '' && onlyCheckbox.checked) {
                    onlyCheckbox.checked = false;
                    onlyCheckbox.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            var presetActive = false;
            row.querySelectorAll('[data-home-color]').forEach(function (dot) {
                var match = dot.getAttribute('data-home-color') === color;
                dot.classList.toggle('is-active', match);
                if (match && color !== '') {
                    presetActive = true;
                }
            });
            var customLabel = row.querySelector('.nm-color-custom');
            if (customLabel) {
                customLabel.classList.toggle('is-active', color !== '' && !presetActive);
            }
            if (colorInput && color) {
                colorInput.value = color;
            }

            var preview = row.querySelector('[data-home-btn-preview]');
            if (preview) {
                preview.classList.toggle('is-outline', style === 'outline');
                preview.classList.toggle('is-custom', color !== '');
                preview.classList.toggle('is-icon-only', !!only);
                preview.style.removeProperty('--home-btn-color');
                if (color) {
                    preview.style.setProperty('--home-btn-color', color);
                }
                var option = iconSelect && iconSelect.selectedOptions ? iconSelect.selectedOptions[0] : null;
                var svg = option ? option.getAttribute('data-icon-svg') || '' : '';
                var fallbackLabel = label || url || '按钮';
                if (icon && svg) {
                    if (!only) {
                        preview.innerHTML = '<span class="nm-home-btn-preview-icon">' + svg + '</span><span>' + escapeHomeText(fallbackLabel) + '</span>';
                        preview.removeAttribute('title');
                    } else {
                        preview.innerHTML = '<span class="nm-home-btn-preview-icon">' + svg + '</span>';
                        preview.setAttribute('title', fallbackLabel);
                    }
                } else {
                    preview.innerHTML = '<span>' + escapeHomeText(fallbackLabel) + '</span>';
                    preview.removeAttribute('title');
                }
            }

            homeButtonAdd.disabled = rows.length >= maxHomeButtons;
            if (homeButtonsEmpty) {
                homeButtonsEmpty.hidden = rows.length > 0;
            }
        };

        var refreshHomeButtons = function () {
            homeRows().forEach(syncHomeButtonRow);
        };

        homeButtonAdd.addEventListener('click', function () {
            if (homeRows().length >= maxHomeButtons) {
                return;
            }
            homeButtonsBody.appendChild(homeButtonTemplate.content.firstElementChild.cloneNode(true));
            refreshHomeButtons();
            var lastRow = homeRows()[homeRows().length - 1];
            var firstInput = lastRow ? lastRow.querySelector('[data-home-btn-label]') : null;
            if (firstInput) {
                firstInput.focus();
            }
        });

        homeButtonsBody.addEventListener('click', function (event) {
            var colorDot = event.target.closest('[data-home-color]');
            if (colorDot) {
                var dotRow = colorDot.closest('.home-button-row');
                var colorValue = dotRow ? dotRow.querySelector('[data-home-btn-color-value]') : null;
                if (colorValue) {
                    colorValue.value = colorDot.getAttribute('data-home-color') || '';
                    colorValue.dispatchEvent(new Event('input', { bubbles: true }));
                }
                if (dotRow) {
                    syncHomeButtonRow(dotRow);
                }
                return;
            }
            var removeBtn = event.target.closest('.home-button-remove');
            if (!removeBtn) {
                return;
            }
            var row = removeBtn.closest('.home-button-row');
            if (row) {
                row.remove();
            }
            refreshHomeButtons();
        });

        homeButtonsBody.addEventListener('input', function (event) {
            var row = event.target.closest('.home-button-row');
            if (!row) {
                return;
            }
            if (event.target.matches('[data-home-color-input]')) {
                var colorValue = row.querySelector('[data-home-btn-color-value]');
                if (colorValue) {
                    colorValue.value = event.target.value;
                    colorValue.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
            syncHomeButtonRow(row);
        });

        homeButtonsBody.addEventListener('change', function (event) {
            var row = event.target.closest('.home-button-row');
            if (row) {
                syncHomeButtonRow(row);
            }
        });

        refreshHomeButtons();
    }

    [['homeOverlay', 'homeOverlayValue'], ['loginOverlay', 'loginOverlayValue']].forEach(function (pair) {
        var overlayInput = document.getElementById(pair[0]);
        var overlayValue = document.getElementById(pair[1]);
        if (!overlayInput || !overlayValue) {
            return;
        }
        var syncOverlayLabel = function () {
            overlayValue.textContent = overlayInput.value + '%';
        };
        overlayInput.addEventListener('input', syncOverlayLabel);
        syncOverlayLabel();
    });

    document.querySelectorAll('.theme-swatch input[name="site_theme_color"]').forEach(function (input) {
        input.addEventListener('change', function () {
            document.querySelectorAll('.theme-swatch').forEach(function (swatch) {
                swatch.classList.remove('is-active');
            });
            var label = input.closest('.theme-swatch');
            if (label) {
                label.classList.add('is-active');
            }
        });
    });
})();
