(function () {
    'use strict';

    var modalEl = document.getElementById('nmAttachmentModal');
    if (!modalEl) return;

    var grid = document.getElementById('nmAttachGrid');
    var empty = document.getElementById('nmAttachEmpty');
    var emptyText = document.getElementById('nmAttachEmptyText');
    var loading = document.getElementById('nmAttachLoading');
    var moreBtn = document.getElementById('nmAttachMore');
    var searchInput = document.getElementById('nmAttachSearch');
    var uploadBtn = document.getElementById('nmAttachUploadBtn');
    var fileInput = document.getElementById('nmAttachFileInput');
    var statusEl = document.getElementById('nmAttachStatus');
    var progressEl = document.getElementById('nmAttachProgress');
    var progressBar = progressEl ? progressEl.querySelector('.progress-bar') : null;
    var countEl = document.getElementById('nmAttachCount');
    var confirmBtn = document.getElementById('nmAttachConfirm');
    var subtitleEl = document.getElementById('nmAttachSubtitle');
    var previewEmpty = document.getElementById('nmAttachPreviewEmpty');
    var previewBody = document.getElementById('nmAttachPreviewBody');
    var previewMedia = document.getElementById('nmAttachPreviewMedia');
    var previewName = document.getElementById('nmAttachPreviewName');
    var previewMeta = document.getElementById('nmAttachPreviewMeta');
    var previewUrl = document.getElementById('nmAttachPreviewUrl');
    var copyBtn = document.getElementById('nmAttachCopy');

    var TYPE_META = {
        image: {
            label: '图片',
            upload: '上传图片',
            subtitle: '仅显示图片附件，可从附件库选择或上传新图片',
            empty: '附件库中还没有图片，点击「上传图片」添加。'
        },
        video: {
            label: '视频',
            upload: '上传视频',
            subtitle: '仅显示视频附件，可从附件库选择或上传新视频',
            empty: '附件库中还没有视频，点击「上传视频」添加。'
        },
        file: {
            label: '文件',
            upload: '上传文件',
            subtitle: '仅显示文档、压缩包等文件附件',
            empty: '附件库中还没有文件，点击「上传文件」添加。'
        },
        all: {
            label: '附件',
            upload: '上传附件',
            subtitle: '显示全部附件，可选择已有附件或上传新附件',
            empty: '附件库中还没有附件，点击「上传附件」添加。'
        }
    };

    var modal = window.bootstrap && bootstrap.Modal ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
    var options = {};
    var items = [];
    var selected = null;
    var page = 1;
    var hasMore = false;
    var keyword = '';
    var loadingList = false;
    var uploading = false;
    var searchTimer = null;
    var lastUploaded = null;
    var currentType = 'image';

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function toast(message, type) {
        if (window.NorthmuAdmin && NorthmuAdmin.toast) NorthmuAdmin.toast(message, type || 'success');
    }

    function meta() {
        return TYPE_META[currentType] || TYPE_META.image;
    }

    function acceptForType(type) {
        var limits = window.NorthmuUploadLimits || {};
        var keys = type === 'all' ? ['image', 'video', 'file'] : [type];
        var exts = [];
        keys.forEach(function (key) {
            if (limits[key] && Array.isArray(limits[key].ext)) {
                limits[key].ext.forEach(function (ext) {
                    if (exts.indexOf(ext) === -1) exts.push(ext);
                });
            }
        });
        return exts.map(function (ext) { return '.' + ext; }).join(',');
    }

    function applyTypeMeta() {
        var info = meta();
        if (subtitleEl) subtitleEl.textContent = options.subtitle || info.subtitle;
        if (uploadBtn) uploadBtn.innerHTML = '<i class="ti ti-cloud-upload me-1"></i>' + info.upload;
        if (fileInput) fileInput.setAttribute('accept', acceptForType(currentType));
        if (emptyText) emptyText.textContent = info.empty;
    }

    function setStatus(text, visible) {
        if (!statusEl) return;
        statusEl.textContent = text || '';
        statusEl.classList.toggle('d-none', !visible);
    }

    function setProgress(percent, visible) {
        if (!progressEl) return;
        progressEl.classList.toggle('d-none', !visible);
        if (progressBar) progressBar.style.width = Math.max(0, Math.min(100, percent)) + '%';
    }

    function formatSize(bytes) {
        bytes = Number(bytes) || 0;
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1).replace(/\.0$/, '') + ' KB';
        return (bytes / 1024 / 1024).toFixed(1).replace(/\.0$/, '') + ' MB';
    }

    function fullUrl(path) {
        if (!path) return '';
        if (/^https?:\/\//i.test(path)) return path;
        return window.location.origin + path;
    }

    function makeThumb(item) {
        var wrap = document.createElement('span');
        wrap.className = 'nm-attach-card-thumb';
        if (item.is_image) {
            var img = document.createElement('img');
            img.src = item.url;
            img.alt = item.name || '附件';
            img.loading = 'lazy';
            img.addEventListener('error', function () {
                wrap.textContent = '';
                var icon = document.createElement('i');
                icon.className = 'ti ti-photo-off';
                wrap.appendChild(icon);
            });
            wrap.appendChild(img);
        } else {
            var icon = document.createElement('i');
            icon.className = item.is_video ? 'ti ti-video' : 'ti ti-file';
            wrap.appendChild(icon);
            if (item.extension) {
                var badge = document.createElement('span');
                badge.className = 'nm-attach-card-ext';
                badge.textContent = item.extension.toUpperCase();
                wrap.appendChild(badge);
            }
        }
        return wrap;
    }

    function makeCard(item) {
        var card = document.createElement('button');
        card.type = 'button';
        card.className = 'nm-attach-card';
        card.setAttribute('data-id', String(item.id));
        card.title = item.name || '';
        card.appendChild(makeThumb(item));

        var name = document.createElement('span');
        name.className = 'nm-attach-card-name';
        name.textContent = item.name || '未命名附件';
        card.appendChild(name);

        card.addEventListener('click', function () { select(item); });
        card.addEventListener('dblclick', function () {
            select(item);
            confirmSelection();
        });
        return card;
    }

    function render() {
        grid.textContent = '';
        items.forEach(function (item) {
            grid.appendChild(makeCard(item));
        });
        empty.classList.toggle('d-none', loadingList || items.length > 0);
        moreBtn.classList.toggle('d-none', !hasMore || loadingList);
        countEl.textContent = items.length ? '共 ' + items.length + ' 个' + meta().label + '附件' : '';
        markSelected();
    }

    function markSelected() {
        Array.prototype.forEach.call(grid.querySelectorAll('.nm-attach-card'), function (card) {
            card.classList.toggle('is-selected', !!selected && card.getAttribute('data-id') === String(selected.id));
        });
    }

    function select(item) {
        selected = item;
        markSelected();
        confirmBtn.disabled = false;

        previewEmpty.classList.add('d-none');
        previewBody.classList.remove('d-none');
        previewMedia.textContent = '';
        previewName.textContent = item.name || '未命名附件';
        previewMeta.textContent = [item.size_text || formatSize(item.size), item.created_at || ''].filter(Boolean).join(' · ');
        previewUrl.value = fullUrl(item.url);

        if (item.is_image) {
            var img = document.createElement('img');
            img.src = item.url;
            img.alt = item.name || '附件预览';
            previewMedia.appendChild(img);
        } else if (item.is_video) {
            var video = document.createElement('video');
            video.src = item.url;
            video.controls = true;
            video.muted = true;
            video.playsInline = true;
            video.preload = 'metadata';
            previewMedia.appendChild(video);
        } else {
            var icon = document.createElement('i');
            icon.className = 'ti ti-file nm-attach-preview-file';
            previewMedia.appendChild(icon);
        }
    }

    function clearSelection() {
        selected = null;
        confirmBtn.disabled = true;
        markSelected();
        previewBody.classList.add('d-none');
        previewEmpty.classList.remove('d-none');
        previewMedia.textContent = '';
    }

    function fetchList(reset) {
        if (loadingList) return Promise.resolve();
        loadingList = true;
        if (reset) {
            page = 1;
            items = [];
            hasMore = false;
            grid.textContent = '';
            clearSelection();
        }
        loading.classList.remove('d-none');
        empty.classList.add('d-none');
        moreBtn.classList.add('d-none');

        var query = new URLSearchParams();
        query.set('type', currentType);
        query.set('page', String(page));
        query.set('limit', '24');
        if (keyword) query.set('keyword', keyword);

        return fetch('/admin/attachments/picker?' + query.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        }).then(function (res) {
            if (!res || res.code !== 0) throw new Error((res && res.msg) || '附件加载失败');
            var data = res.data || {};
            var list = data.items || [];
            items = items.concat(list);
            hasMore = !!data.has_more;
            if (lastUploaded) {
                var exists = items.some(function (item) { return Number(item.id) === Number(lastUploaded.id); });
                if (!exists) items.unshift(lastUploaded);
                select(lastUploaded);
                lastUploaded = null;
            }
            render();
            return data;
        }).catch(function (err) {
            empty.classList.remove('d-none');
            if (emptyText) emptyText.textContent = err.message || '附件加载失败，请稍后重试';
            toast(err.message || '附件加载失败', 'error');
        }).then(function () {
            loading.classList.add('d-none');
            loadingList = false;
            empty.classList.toggle('d-none', items.length > 0);
        });
    }

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
            if (!res || res.code !== 0) throw new Error((res && res.msg) || '上传失败');
            return res.data;
        });
    }

    function uploadFiles(files) {
        var list = Array.prototype.slice.call(files || []);
        if (!list.length) return;

        var expected = currentType === 'all' ? null : currentType;
        var checked = window.NorthmuAdmin && NorthmuAdmin.validateUploads
            ? NorthmuAdmin.validateUploads(list, expected)
            : { valid: list.map(function (file) { return { file: file }; }), invalid: [] };

        checked.invalid.forEach(function (item) {
            toast(item.file.name + '：' + item.message, 'error');
        });

        list = checked.valid.map(function (item) { return item.file; });
        if (!list.length) {
            fileInput.value = '';
            return;
        }
        if (uploading) return;

        uploading = true;
        uploadBtn.disabled = true;
        fileInput.value = '';
        var done = 0;
        var failed = 0;
        var lastInfo = null;
        setProgress(0, false);
        setProgress(4, true);
        setStatus('准备上传 ' + list.length + ' 个文件…', true);

        var chain = Promise.resolve();
        list.forEach(function (file, index) {
            chain = chain.then(function () {
                setStatus('正在上传 ' + (index + 1) + '/' + list.length + '：' + file.name, true);
                return uploadOne(file).then(function (info) {
                    done++;
                    lastInfo = info;
                }).catch(function (err) {
                    failed++;
                    toast(file.name + '：' + err.message, 'error');
                }).then(function () {
                    setProgress(Math.round((done + failed) / list.length * 100), true);
                });
            });
        });

        chain.then(function () {
            uploading = false;
            uploadBtn.disabled = false;
            var summary = failed === 0
                ? '已上传 ' + done + ' 个文件，列表已刷新'
                : '上传完成：成功 ' + done + ' 个，失败 ' + failed + ' 个';
            setStatus(summary, true);
            toast(summary, failed === 0 ? 'success' : 'warning');

            if (done > 0) {
                if (lastInfo) {
                    lastUploaded = {
                        id: lastInfo.id,
                        name: lastInfo.name || '新上传附件',
                        url: lastInfo.url,
                        is_image: !!lastInfo.is_image,
                        is_video: !!lastInfo.is_video,
                        extension: (lastInfo.name || '').split('.').pop().toLowerCase(),
                        category: lastInfo.category || 'file',
                        size: lastInfo.size || 0,
                        size_text: formatSize(lastInfo.size || 0),
                        created_at: ''
                    };
                }
                fetchList(true).then(function () {
                    setTimeout(function () { setProgress(0, false); }, 600);
                });
            } else {
                setProgress(0, false);
            }
        }).catch(function () {
            uploading = false;
            uploadBtn.disabled = false;
            setProgress(0, false);
        });
    }

    function confirmSelection() {
        if (!selected) return;
        var item = selected;
        if (typeof options.onSelect === 'function') {
            options.onSelect(item);
        }
        if (modal) modal.hide();
    }

    function open(opts) {
        options = opts || {};
        currentType = options.type && TYPE_META[options.type] ? options.type : 'image';
        applyTypeMeta();
        if (confirmBtn) confirmBtn.innerHTML = '<i class="ti ti-check me-1"></i>' + (options.confirmLabel || '使用所选附件');
        keyword = '';
        searchInput.value = '';
        items = [];
        selected = null;
        lastUploaded = null;
        setStatus('', false);
        setProgress(0, false);
        confirmBtn.disabled = true;
        if (!modal) return;
        modal.show();
        fetchList(true);
    }

    uploadBtn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
        uploadFiles(fileInput.files);
    });

    moreBtn.addEventListener('click', function () {
        if (loadingList || !hasMore) return;
        page += 1;
        fetchList(false);
    });

    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            keyword = searchInput.value.trim();
            fetchList(true);
        }, 260);
    });

    confirmBtn.addEventListener('click', confirmSelection);

    copyBtn.addEventListener('click', function () {
        var url = previewUrl.value;
        if (!url) return;
        var done = function () { toast('链接已复制'); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done).catch(function () { window.prompt('复制链接', url); });
        } else {
            window.prompt('复制链接', url);
        }
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        clearTimeout(searchTimer);
    });

    function applyLibraryPreview(holder, item, type) {
        if (!holder) return;
        var existing = holder.querySelector('img, video');
        var mediaClass = existing && existing.className ? existing.className : 'settings-logo-preview';
        holder.textContent = '';
        if (type === 'video') {
            var video = document.createElement('video');
            video.className = 'settings-video-preview';
            video.src = item.url;
            video.muted = true;
            video.loop = true;
            video.playsInline = true;
            video.preload = 'metadata';
            holder.appendChild(video);
        } else {
            var img = document.createElement('img');
            img.className = mediaClass;
            img.src = item.url;
            img.alt = item.name || '已选附件';
            holder.appendChild(img);
        }
    }

    document.querySelectorAll('[data-nm-picker]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var type = btn.getAttribute('data-nm-picker') === 'video' ? 'video' : 'image';
            var target = document.getElementById(btn.getAttribute('data-target') || '');
            var preview = document.getElementById(btn.getAttribute('data-preview') || '');
            var fileInput = document.getElementById(btn.getAttribute('data-file') || '');
            var removeName = btn.getAttribute('data-remove') || '';
            var form = btn.closest('form');
            var removeInput = removeName && form
                ? form.querySelector('input[type="checkbox"][name="' + removeName + '"]')
                : null;

            open({
                type: type,
                subtitle: type === 'video'
                    ? '仅显示视频附件，可从附件库选择或上传新视频'
                    : '仅显示图片附件，可从附件库选择或上传新图片',
                confirmLabel: '使用所选附件',
                onSelect: function (item) {
                    if (target) target.value = item.url;
                    if (fileInput) {
                        fileInput.value = '';
                        var local = fileInput.nextElementSibling;
                        if (local && local.classList.contains('nm-local-preview')) local.remove();
                    }
                    if (removeInput) removeInput.checked = false;
                    applyLibraryPreview(preview, item, type);
                    toast('已选择' + (type === 'video' ? '视频' : '图片') + '：' + (item.name || ''));
                }
            });
        });
    });

    window.NorthmuAttachmentPicker = {
        open: open
    };
})();
