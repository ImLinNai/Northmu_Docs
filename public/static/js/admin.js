(function () {
    'use strict';

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function post(url, data) {
        var body = new URLSearchParams();
        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (value !== null && value !== undefined) {
                body.append(key, value);
            }
        });
        return fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf(),
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

    var TOAST_TYPES = {
        success: { icon: 'ti-circle-check' },
        failure: { icon: 'ti-circle-x' },
        warning: { icon: 'ti-alert-triangle' },
        error: { icon: 'ti-alert-octagon' }
    };

    function normalizeType(type) {
        type = String(type || 'success').toLowerCase();
        if (type === 'danger' || type === 'fail' || type === 'failed') return 'error';
        if (type === 'info') return 'success';
        return TOAST_TYPES[type] ? type : 'success';
    }

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

    function toast(message, type) {
        if (!message) return;
        type = normalizeType(type);
        var wrap = toastWrap();
        var el = document.createElement('div');
        el.className = 'nm-toast nm-toast-' + type;

        var icon = document.createElement('i');
        icon.className = 'ti ' + TOAST_TYPES[type].icon + ' nm-toast-icon';

        var body = document.createElement('div');
        body.className = 'nm-toast-message';
        body.textContent = message;

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'nm-toast-close';
        close.setAttribute('aria-label', '关闭');
        close.innerHTML = '<i class="ti ti-x"></i>';

        var timer = null;
        function remove() {
            clearTimeout(timer);
            el.classList.remove('show');
            el.classList.add('hide');
            setTimeout(function () { el.remove(); }, 240);
        }
        close.addEventListener('click', remove);

        el.appendChild(icon);
        el.appendChild(body);
        el.appendChild(close);
        wrap.appendChild(el);
        window.requestAnimationFrame(function () { el.classList.add('show'); });
        timer = setTimeout(remove, 4000);
    }

    var loadingBar = document.getElementById('nmLoadingBar');
    var loadingTimer = null;

    function startLoading() {
        if (!loadingBar) return;
        loadingBar.classList.remove('is-done');
        loadingBar.classList.add('is-loading');
        clearTimeout(loadingTimer);
        loadingTimer = setTimeout(finishLoading, 15000);
    }

    function finishLoading() {
        if (!loadingBar) return;
        clearTimeout(loadingTimer);
        loadingBar.classList.remove('is-loading');
        loadingBar.classList.add('is-done');
    }

    var bootTimer = setTimeout(hideBoot, 5000);
    function hideBoot() {
        clearTimeout(bootTimer);
        var boot = document.getElementById('nmBoot');
        if (!boot) return;
        boot.classList.add('nm-boot-done');
        setTimeout(function () { boot.remove(); }, 450);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var flash = document.getElementById('nmFlash');
        if (flash) {
            toast(flash.getAttribute('data-message'), flash.getAttribute('data-type'));
        }
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href]');
        if (!link || link.target || link.hasAttribute('download')) return;
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        var href = link.getAttribute('href');
        if (!href || href.charAt(0) === '#') return;
        var url;
        try { url = new URL(link.href, window.location.href); } catch (e) { return; }
        if (url.origin !== window.location.origin) return;
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;
        startLoading();
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.target && form.target !== '_self') return;
        startLoading();
    });

    window.addEventListener('pageshow', finishLoading);
    window.addEventListener('load', hideBoot);

    function uploadLimits() {
        return window.NorthmuUploadLimits || {};
    }

    function fileExtension(name) {
        var parts = String(name || '').split('.');
        return parts.length > 1 ? parts.pop().toLowerCase() : '';
    }

    function formatFileSize(bytes) {
        bytes = Number(bytes) || 0;
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1).replace(/\.0$/, '') + ' KB';
        return (bytes / 1048576).toFixed(1).replace(/\.0$/, '') + ' MB';
    }

    function detectCategory(ext) {
        var limits = uploadLimits();
        var order = ['image', 'video', 'file'];
        for (var i = 0; i < order.length; i++) {
            var category = limits[order[i]];
            if (category && category.ext && category.ext.indexOf(ext) !== -1) return order[i];
        }
        return null;
    }

    /**
     * 选择文件后立即按类别校验扩展名与大小
     * expected 为空时按扩展名自动归类
     */
    function checkUpload(file, expected) {
        var limits = uploadLimits();
        if (!file) return { ok: false, message: '请选择要上传的文件' };
        if (!limits.file && !limits.image && !limits.video) return { ok: true, category: null };

        var ext = fileExtension(file.name);
        if (ext === '') {
            return { ok: false, message: '无法识别文件扩展名：' + file.name };
        }

        var detected = detectCategory(ext);
        if (expected === 'file' && detected !== null && detected !== 'file') {
            return { ok: false, message: '文件分类仅支持文档、压缩包等文件，当前 .' + ext + ' 属于' + (limits[detected] ? limits[detected].label : '其他') + '分类' };
        }
        var categoryKey = expected && limits[expected] ? expected : detected;
        if (!categoryKey) {
            return { ok: false, message: '不支持 .' + ext + ' 文件：该扩展名未包含在上传设置中' };
        }

        var category = limits[categoryKey];
        if (expected && category.ext.indexOf(ext) === -1) {
            return {
                ok: false,
                message: category.label + '仅支持 ' + category.ext.join('、') + ' 格式，当前文件为 .' + ext
            };
        }
        if (file.size > category.maxMb * 1048576) {
            return {
                ok: false,
                category: categoryKey,
                message: category.label + '大小不能超过 ' + category.maxMb + ' MB，当前文件为 ' + formatFileSize(file.size)
            };
        }
        return { ok: true, category: categoryKey, label: category.label };
    }

    function validateUploads(files, expected) {
        var valid = [];
        var invalid = [];
        Array.prototype.forEach.call(files || [], function (file) {
            var result = checkUpload(file, expected || null);
            if (result.ok) {
                valid.push({ file: file, category: result.category });
            } else {
                invalid.push({ file: file, message: result.message });
            }
        });
        return { valid: valid, invalid: invalid };
    }

    function inputCategory(input) {
        var explicit = input.getAttribute('data-upload-category');
        if (explicit) return explicit;
        var accept = (input.getAttribute('accept') || '').toLowerCase();
        if (accept.indexOf('image/') !== -1 || accept.indexOf('.ico') !== -1) return 'image';
        if (accept.indexOf('video/') !== -1) return 'video';
        return '';
    }

    function initLocalUploadPreviews() {
        document.querySelectorAll('input[type="file"].form-control').forEach(function (input) {
            var expected = inputCategory(input);
            var holder = null;
            var objectUrl = null;

            function clearPreview() {
                if (objectUrl) {
                    URL.revokeObjectURL(objectUrl);
                    objectUrl = null;
                }
                if (holder) {
                    holder.remove();
                    holder = null;
                }
            }

            input.addEventListener('change', function () {
                clearPreview();
                var file = input.files && input.files[0];
                if (!file) return;

                var result = checkUpload(file, expected || null);
                if (!result.ok) {
                    toast(result.message, 'error');
                    input.value = '';
                    return;
                }

                var isImage = (result.category === 'image');
                holder = document.createElement('div');
                holder.className = 'nm-local-preview';
                if (isImage) {
                    var img = document.createElement('img');
                    objectUrl = URL.createObjectURL(file);
                    img.src = objectUrl;
                    img.alt = '待上传图片预览';
                    img.onload = function () {
                        if (objectUrl) {
                            URL.revokeObjectURL(objectUrl);
                            objectUrl = null;
                        }
                    };
                    holder.appendChild(img);
                } else {
                    var icon = document.createElement('i');
                    icon.className = result.category === 'video' ? 'ti ti-video' : 'ti ti-file';
                    icon.style.fontSize = '1.4rem';
                    holder.appendChild(icon);
                }
                var meta = document.createElement('span');
                meta.textContent = '待保存：' + file.name + '（' + formatFileSize(file.size) + '）';
                holder.appendChild(meta);
                input.insertAdjacentElement('afterend', holder);
            });
        });
    }

    initLocalUploadPreviews();

    window.NorthmuAdmin = {
        csrf: csrf,
        post: post,
        toast: toast,
        uploadLimits: uploadLimits,
        fileExtension: fileExtension,
        formatFileSize: formatFileSize,
        detectCategory: detectCategory,
        checkUpload: checkUpload,
        validateUploads: validateUploads
    };
})();
