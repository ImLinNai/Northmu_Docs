(function () {
    'use strict';

    var dataEl = document.getElementById('docEditorData');
    if (!dataEl) return;

    var data;
    try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }

    var form = document.getElementById('docForm');
    var titleInput = document.getElementById('docTitle');
    var mdInput = document.getElementById('mdInput');
    var mdPreview = document.getElementById('mdPreview');
    var contentField = document.getElementById('contentField');
    var modeField = document.getElementById('contentTypeField');
    var htmlPane = document.getElementById('htmlPane');
    var markdownPane = document.getElementById('markdownPane');
    var saveStatus = document.getElementById('saveStatus');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';

    var seo = data.seo || {};
    var seoGlobal = seo.global || {};
    var seoTitleInput = document.getElementById('docSeoTitle');
    var seoKeywordsInput = document.getElementById('docSeoKeywords');
    var seoDescriptionInput = document.getElementById('docSeoDescription');
    var seoPreviewUrl = document.getElementById('seoPreviewUrl');
    var seoPreviewTitle = document.getElementById('seoPreviewTitle');
    var seoPreviewDesc = document.getElementById('seoPreviewDesc');
    var seoTitleCount = document.getElementById('seoTitleCount');
    var seoDescriptionCount = document.getElementById('seoDescriptionCount');

    var mode = data.contentType === 'markdown' ? 'markdown' : 'html';
    var editor = null;
    var dirty = false;
    var edits = 0;
    var uncategorizedConfirmed = false;

    function status(text, cls) {
        saveStatus.textContent = text;
        saveStatus.className = 'small ' + (cls || 'text-secondary');
    }

    function markDirty() {
        dirty = true;
        edits += 1;
        status('有未保存的修改', 'text-warning');
        form.dispatchEvent(new CustomEvent('nm:dirty', { bubbles: true }));
    }

    function currentContent() {
        if (mode === 'markdown') return mdInput.value;
        if (editor) return editor.getHtml();
        return data.content || '';
    }

    function plainTextContent() {
        var content = currentContent();
        if (mode === 'markdown') {
            try { content = window.marked ? marked.parse(content) : content; } catch (e) { content = ''; }
        } else if (editor && typeof editor.getText === 'function') {
            content = editor.getText();
        }
        var holder = document.createElement('div');
        holder.innerHTML = content || '';
        var text = (holder.textContent || '').replace(/\s+/g, ' ').trim();
        if (!text) return '';
        return text.length > 160 ? text.slice(0, 160) + '…' : text;
    }

    function selectedTagNames() {
        var names = [];
        document.querySelectorAll('.nm-tag-option-input:checked').forEach(function (input) {
            var label = input.parentElement ? input.parentElement.querySelector('.nm-tag-option-label') : null;
            var name = label ? label.textContent.trim() : '';
            if (name && names.indexOf(name) === -1) names.push(name);
        });
        return names.slice(0, 10);
    }

    function selectedCategoryName() {
        var select = document.getElementById('docCategory');
        if (!select) return '';
        var option = select.options[select.selectedIndex];
        var name = option ? (option.getAttribute('data-name') || '') : '';
        return name === '未分类' ? '' : name;
    }

    function seoDefaultTitle() {
        var title = titleInput.value.trim();
        var globalTitle = seoGlobal.title || '';
        if (!title) return globalTitle;
        return globalTitle ? title + ' - ' + globalTitle : title;
    }

    function seoDefaultKeywords() {
        var list = selectedTagNames();
        var category = selectedCategoryName();
        if (category && list.indexOf(category) === -1) list.push(category);
        if (!list.length) return seoGlobal.keywords || seoGlobal.title || '';
        return list.join(',');
    }

    function seoDefaultDescription() {
        return plainTextContent() || seoGlobal.description || '';
    }

    function updateSeoPreview() {
        if (!seoPreviewTitle) return;
        var title = seoTitleInput && seoTitleInput.value.trim() ? seoTitleInput.value.trim() : seoDefaultTitle();
        var description = seoDescriptionInput && seoDescriptionInput.value.trim()
            ? seoDescriptionInput.value.trim()
            : seoDefaultDescription();
        seoPreviewTitle.textContent = title;
        seoPreviewDesc.textContent = description;
        if (seoPreviewUrl) seoPreviewUrl.textContent = seo.url || '';
        if (seoTitleCount) seoTitleCount.textContent = (seoTitleInput ? seoTitleInput.value.length : 0) + '/200';
        if (seoDescriptionCount) seoDescriptionCount.textContent = (seoDescriptionInput ? seoDescriptionInput.value.length : 0) + '/500';
        if (seoKeywordsInput) {
            seoKeywordsInput.setAttribute('placeholder', seoDefaultKeywords());
        }
    }

    function renderPushStatus(status) {
        var wrap = document.getElementById('baiduPushStatus');
        if (!wrap) return;
        while (wrap.firstChild) wrap.removeChild(wrap.firstChild);

        var channels = status && status.channels ? status.channels : {};
        var keys = ['common', 'quick'];
        var hasChannel = keys.some(function (key) { return !!channels[key]; });
        if (!hasChannel) {
            var empty = document.createElement('div');
            empty.className = 'text-secondary small';
            empty.textContent = '尚未提交过百度收录。';
            wrap.appendChild(empty);
            return;
        }

        var meta = document.createElement('div');
        meta.className = 'nm-push-meta';
        var trigger = document.createElement('span');
        trigger.textContent = status.trigger === 'auto' ? '自动提交' : '手动提交';
        var time = document.createElement('span');
        time.textContent = status.at || '';
        meta.appendChild(trigger);
        meta.appendChild(time);
        wrap.appendChild(meta);

        keys.forEach(function (key) {
            var row = channels[key];
            if (!row) return;
            var card = document.createElement('div');
            card.className = 'nm-push-channel';

            var head = document.createElement('div');
            head.className = 'd-flex align-items-center gap-2';
            var dot = document.createElement('span');
            dot.className = 'nm-push-dot ' + (row.ok ? 'is-ok' : 'is-fail');
            var label = document.createElement('span');
            label.className = 'fw-semibold small';
            label.textContent = key === 'quick' ? '快速收录' : '普通收录';
            var badge = document.createElement('span');
            badge.className = 'badge ms-auto ' + (row.ok ? 'bg-green-lt text-green' : 'bg-red-lt text-red');
            badge.textContent = row.ok ? '成功' : '失败';
            head.appendChild(dot);
            head.appendChild(label);
            head.appendChild(badge);

            var message = document.createElement('div');
            message.className = 'nm-push-message small text-secondary mt-1';
            message.textContent = row.message || '';

            card.appendChild(head);
            card.appendChild(message);
            if (row.at) {
                var rowTime = document.createElement('div');
                rowTime.className = 'nm-push-time small text-secondary';
                rowTime.textContent = row.at;
                card.appendChild(rowTime);
            }
            wrap.appendChild(card);
        });
    }

    function syncHidden() {
        contentField.value = currentContent();
        modeField.value = mode;
    }

    function renderPreview() {
        var html = '';
        try {
            html = window.marked ? marked.parse(mdInput.value || '') : (mdInput.value || '');
        } catch (e) { html = ''; }
        mdPreview.innerHTML = html;
        mdPreview.querySelectorAll('pre code').forEach(function (block) {
            var match = /(?:^|\s)language-([\w+-]+)/.exec(block.className || '');
            var lang = match ? match[1] : '';
            block.removeAttribute('class');
            if (!window.hljs) return;
            try {
                var result = lang && hljs.getLanguage(lang)
                    ? hljs.highlight(block.textContent, { language: lang, ignoreIllegals: true })
                    : hljs.highlightAuto(block.textContent);
                block.innerHTML = result.value;
                block.className = 'hljs';
            } catch (e) { }
        });
    }

    var previewTimer = null;
    mdInput.addEventListener('input', function () {
        syncHidden();
        markDirty();
        updateSeoPreview();
        clearTimeout(previewTimer);
        previewTimer = setTimeout(renderPreview, 180);
    });

    function updateModeButtons() {
        document.querySelectorAll('[data-editor-mode]').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-editor-mode') === mode);
        });
        if (htmlPane) htmlPane.classList.toggle('d-none', mode !== 'html');
        if (markdownPane) markdownPane.classList.toggle('d-none', mode !== 'markdown');
    }

    function htmlToMarkdown(html) {
        if (!window.TurndownService) return html;
        var service = new TurndownService({
            headingStyle: 'atx',
            codeBlockStyle: 'fenced',
            bulletListMarker: '-'
        });
        try { return service.turndown(html || ''); } catch (e) { return ''; }
    }

    function setMode(next, convert) {
        if (next !== 'html' && next !== 'markdown') return;
        if (next === mode) { updateModeButtons(); return; }
        if (convert) {
            if (next === 'markdown') {
                mdInput.value = htmlToMarkdown(editor ? editor.getHtml() : '');
                renderPreview();
            } else {
                var html = '';
                try { html = window.marked ? marked.parse(mdInput.value || '') : mdInput.value; } catch (e) { html = ''; }
                if (editor) editor.setHtml(html || '<p><br></p>');
            }
            markDirty();
        }
        mode = next;
        updateModeButtons();
        syncHidden();
        updateSeoPreview();
    }

    document.querySelectorAll('[data-editor-mode]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setMode(btn.getAttribute('data-editor-mode'), true);
        });
    });

    function uploadAttachment(file) {
        if (window.NorthmuAdmin && NorthmuAdmin.checkUpload) {
            var check = NorthmuAdmin.checkUpload(file, 'image');
            if (!check.ok) return Promise.reject(new Error(check.message));
        }
        return new Promise(function (resolve, reject) {
            var fd = new FormData();
            fd.append('file', file);
            fd.append('_token', csrf);
            fetch('/admin/attachments/upload', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                body: fd,
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json();
            }).then(function (res) {
                if (res.code === 0) resolve(res.data);
                else reject(new Error(res.msg || '上传失败'));
            }).catch(reject);
        });
    }

    function pickImageFromLibrary(insertFn) {
        if (!window.NorthmuAttachmentPicker) {
            NorthmuAdmin.toast('附件选择器未加载，请刷新页面后重试', 'error');
            return;
        }
        NorthmuAttachmentPicker.open({
            type: 'image',
            subtitle: '仅显示图片附件，可从附件库选择或上传新图片',
            confirmLabel: '插入所选图片',
            onSelect: function (item) {
                insertFn(item.url, item.name, item.url);
            }
        });
    }

    if (window.wangEditor) {
        try {
            var WE = window.wangEditor;
            editor = WE.createEditor({
                selector: '#wangeditor-container',
                html: data.content || '<p><br></p>',
                config: {
                    placeholder: '开始编写文档，支持粘贴图片与拖拽上传…',
                    MENU_CONF: {
                        uploadImage: {
                            customBrowseAndUpload: function (insertFn) {
                                pickImageFromLibrary(insertFn);
                            },
                            customUpload: function (file, insertFn) {
                                uploadAttachment(file).then(function (info) {
                                    insertFn(info.url, info.name, info.url);
                                }).catch(function (err) {
                                    NorthmuAdmin.toast(err.message || '图片上传失败', 'error');
                                });
                            }
                        }
                    }
                },
                mode: 'default'
            });
            WE.createToolbar({
                editor: editor,
                selector: '#wangeditor-toolbar',
                config: {}
            });
            editor.on('change', function () {
                syncHidden();
                markDirty();
                updateSeoPreview();
            });
        } catch (e) {
            console.error('WangEditor init failed', e);
        }
    }

    var mdImageBtn = document.getElementById('mdImageBtn');
    if (mdImageBtn) {
        mdImageBtn.addEventListener('click', function () {
            var start = mdInput.selectionStart;
            var end = mdInput.selectionEnd;
            pickImageFromLibrary(function (url, name) {
                var snippet = '![' + (name || 'image') + '](' + url + ')';
                mdInput.setRangeText(snippet, start, end, 'end');
                mdInput.dispatchEvent(new Event('input', { bubbles: true }));
                mdInput.focus();
            });
        });
    }

    /* 文档导入：Markdown / 纯文本 / HTML */

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function sanitizeImportedBody(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        doc.querySelectorAll('script, style, iframe, object, embed, link, meta, form, base').forEach(function (node) {
            node.remove();
        });
        doc.body.querySelectorAll('*').forEach(function (node) {
            Array.prototype.slice.call(node.attributes).forEach(function (attr) {
                var name = attr.name.toLowerCase();
                var value = String(attr.value || '').trim().toLowerCase();
                if (name.indexOf('on') === 0) {
                    node.removeAttribute(attr.name);
                } else if ((name === 'href' || name === 'src' || name === 'xlink:href') && value.indexOf('javascript:') === 0) {
                    node.removeAttribute(attr.name);
                }
            });
        });
        return doc.body;
    }

    function imageBaseName(path) {
        var clean = String(path || '').split(/[?#]/)[0];
        try { clean = decodeURIComponent(clean); } catch (e) { }
        var parts = clean.split(/[\\/]/);
        return (parts[parts.length - 1] || '').toLowerCase();
    }

    function isExternalImage(src) {
        var value = String(src || '').trim();
        if (value === '') return false;
        if (/^(https?:)?\/\//i.test(value)) return true;
        if (/^data:/i.test(value)) return true;
        return value.charAt(0) === '/';
    }

    function pickLocalImages() {
        return new Promise(function (resolve) {
            var input = document.getElementById('docImportImages');
            if (!input) {
                resolve([]);
                return;
            }
            var settled = false;
            function finish(files) {
                if (settled) return;
                settled = true;
                input.onchange = null;
                window.removeEventListener('focus', onFocus);
                resolve(files);
            }
            function onFocus() {
                setTimeout(function () {
                    if (!settled) finish([]);
                }, 500);
            }
            input.value = '';
            input.onchange = function () {
                finish(Array.prototype.slice.call(input.files || []));
            };
            window.addEventListener('focus', onFocus);
            input.click();
        });
    }

    function dropEmptyContainer(node, root) {
        var el = node;
        while (el && el !== root && el.nodeType === 1) {
            if (['P', 'DIV', 'SPAN', 'A', 'FIGURE', 'LI'].indexOf(el.tagName) === -1) break;
            if (el.textContent.trim() !== '' || el.querySelector('img, video, iframe, table, pre, code, hr')) break;
            var parent = el.parentNode;
            el.remove();
            el = parent;
        }
    }

    function replaceImageInMarkdown(markdown, src, url) {
        var escaped = String(src).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        var mdPattern = new RegExp('!\\[[^\\]]*\\]\\(\\s*<?' + escaped + '>?(?:\\s+[\'"][^\'"]*[\'"])?\\s*\\)', 'gi');
        var htmlPattern = new RegExp('<img\\b[^>]*\\bsrc=[\'"]' + escaped + '[\'"][^>]*>', 'gi');
        var replacement = url ? function (match, offset, whole) {
            if (match.charAt(0) === '<') return '<img src="' + url + '" alt="">';
            return '![](' + url + ')';
        } : '';
        return markdown.replace(mdPattern, replacement).replace(htmlPattern, replacement);
    }

    function uniquePush(list, value) {
        if (value && list.indexOf(value) === -1) list.push(value);
    }

    function finishImport(sourceText, file, ext) {
        var markdownSource = null;
        var rawHtml = '';
        if (ext === 'html' || ext === 'htm') {
            rawHtml = sanitizeImportedBody(sourceText).innerHTML;
        } else {
            markdownSource = sourceText;
            try {
                rawHtml = window.marked
                    ? marked.parse(sourceText)
                    : '<p>' + escapeHtml(sourceText).replace(/\n{2,}/g, '</p><p>').replace(/\n/g, '<br>') + '</p>';
            } catch (e) {
                rawHtml = '';
            }
            rawHtml = sanitizeImportedBody(rawHtml).innerHTML;
        }

        var holder = document.createElement('div');
        holder.innerHTML = rawHtml;

        var refs = [];
        var localSrcs = [];
        Array.prototype.slice.call(holder.querySelectorAll('img')).forEach(function (img) {
            var src = img.getAttribute('src') || '';
            if (isExternalImage(src)) return;
            var name = imageBaseName(src);
            refs.push({ img: img, src: src, name: name });
            uniquePush(localSrcs, src);
        });

        var uploadedNames = {};
        var uploadedCount = 0;
        var removedCount = 0;

        var chain = Promise.resolve();
        if (refs.length) {
            var missing = refs.map(function (ref) { return ref.name || '未命名图片'; });
            var uniqueMissing = missing.filter(function (name, index) { return missing.indexOf(name) === index; });
            var message = '文档引用了 ' + refs.length + ' 张本地图片（' + uniqueMissing.slice(0, 4).join('、')
                + (uniqueMissing.length > 4 ? ' 等' : '')
                + '），导入后无法显示。是否选择这些图片并上传到附件库？取消将移除这些本地图片。';
            var ask = window.NorthmuUI && NorthmuUI.confirm
                ? NorthmuUI.confirm({
                    anchor: document.getElementById('docImportBtn'),
                    title: '发现本地图片',
                    message: message,
                    confirmText: '选择图片上传'
                })
                : Promise.resolve(window.confirm(message));

            chain = ask.then(function (ok) {
                if (!ok) return;
                return pickLocalImages().then(function (files) {
                    if (!files.length) return;
                    var byName = {};
                    files.forEach(function (f) {
                        byName[f.name.toLowerCase()] = f;
                    });
                    var queue = Promise.resolve();
                    Object.keys(byName).forEach(function (name) {
                        queue = queue.then(function () {
                            return uploadAttachment(byName[name]).then(function (info) {
                                uploadedNames[name] = info.url;
                                uploadedCount += 1;
                            }).catch(function (err) {
                                NorthmuAdmin.toast(byName[name].name + ' 上传失败：' + (err.message || '未知错误'), 'error');
                            });
                        });
                    });
                    return queue;
                });
            });
        }

        chain.then(function () {
            refs.forEach(function (ref) {
                var url = ref.name ? uploadedNames[ref.name] : '';
                if (url) {
                    ref.img.setAttribute('src', url);
                    ref.img.removeAttribute('srcset');
                } else {
                    var parent = ref.img.parentNode;
                    ref.img.remove();
                    removedCount += 1;
                    dropEmptyContainer(parent, holder);
                }
            });

            var finalHtml = holder.innerHTML;
            if (!finalHtml.trim()) finalHtml = '<p><br></p>';

            var mdText = markdownSource;
            if (mdText === null) {
                mdText = htmlToMarkdown(finalHtml);
            } else {
                localSrcs.forEach(function (src) {
                    mdText = replaceImageInMarkdown(mdText, src, uploadedNames[imageBaseName(src)] || '');
                });
            }

            if (editor) editor.setHtml(finalHtml);
            mdInput.value = mdText;
            renderPreview();
            syncHidden();
            updateSeoPreview();

            if (titleInput && !titleInput.value.trim()) {
                var heading = holder.querySelector('h1, h2, h3');
                var fallback = file.name.replace(/\.[^.]+$/, '');
                var title = heading ? heading.textContent.trim() : fallback;
                if (title) titleInput.value = title.slice(0, 200);
            }

            markDirty();
            status('已导入 ' + file.name + '，请检查内容后保存', 'text-success');

            var notes = [];
            if (uploadedCount) notes.push('已上传 ' + uploadedCount + ' 张图片');
            if (removedCount) notes.push('已移除 ' + removedCount + ' 张无法显示的图片');
            NorthmuAdmin.toast('已导入 ' + file.name + (notes.length ? '，' + notes.join('，') : ''), 'success');
        }).catch(function (err) {
            NorthmuAdmin.toast('导入失败：' + (err && err.message ? err.message : '未知错误'), 'error');
        });
    }

    function startImport(file) {
        if (!file) return;
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        if (['md', 'markdown', 'txt', 'html', 'htm'].indexOf(ext) === -1) {
            NorthmuAdmin.toast('暂不支持 .' + ext + ' 文件，可导入 .md / .markdown / .txt / .html / .htm', 'error');
            return;
        }
        var reader = new FileReader();
        reader.onload = function () {
            finishImport(String(reader.result || ''), file, ext);
        };
        reader.onerror = function () {
            NorthmuAdmin.toast('读取文件失败，请重试', 'error');
        };
        reader.readAsText(file);
    }

    var importBtn = document.getElementById('docImportBtn');
    var importFile = document.getElementById('docImportFile');
    if (importBtn && importFile) {
        importBtn.addEventListener('click', function () {
            importFile.value = '';
            importFile.click();
        });
        importFile.addEventListener('change', function () {
            startImport(importFile.files && importFile.files[0]);
        });
    }

    var visibilitySelect = document.getElementById('docVisibility');
    var passwordWrap = document.getElementById('docPasswordWrap');
    var passwordInput = document.getElementById('docPassword');

    function syncVisibilityFields() {
        if (!visibilitySelect || !passwordWrap) return;
        var encrypted = visibilitySelect.value === 'encrypted';
        passwordWrap.classList.toggle('d-none', !encrypted);
        if (passwordInput) {
            passwordInput.required = encrypted && passwordInput.getAttribute('data-has-password') !== '1';
        }
    }

    if (visibilitySelect) {
        visibilitySelect.addEventListener('change', syncVisibilityFields);
        syncVisibilityFields();
    }

    var categorySelect = document.getElementById('docCategory');
    var categoryHint = document.getElementById('categoryVisibilityHint');
    function syncCategoryHint() {
        if (!categorySelect || !categoryHint) return;
        var option = categorySelect.options[categorySelect.selectedIndex];
        var visibility = option ? option.getAttribute('data-visibility') : 'public';
        if (visibility === 'private') {
            categoryHint.textContent = '该分类为私有，访客无法看到此文档。';
        } else if (visibility === 'encrypted') {
            categoryHint.textContent = '该分类为加密，文档会继承加密要求。';
        } else {
            categoryHint.textContent = '该分类为公开，文档可见性按文档自身设置。';
        }
    }
    if (categorySelect) {
        categorySelect.addEventListener('change', function () {
            syncCategoryHint();
            updateSeoPreview();
        });
        syncCategoryHint();
    }

    var editorHeaderInputs = ['docCategory', 'docStatus', 'docVisibility', 'docPassword', 'docShowToc', 'docTop'];
    editorHeaderInputs.forEach(function (id) {
        var input = document.getElementById(id);
        if (input) {
            input.addEventListener('change', markDirty);
            input.addEventListener('input', markDirty);
        }
    });
    document.querySelectorAll('.nm-tag-option-input').forEach(function (input) {
        input.addEventListener('change', function () {
            markDirty();
            updateSeoPreview();
        });
    });
    titleInput.addEventListener('input', function () {
        markDirty();
        updateSeoPreview();
    });

    [seoTitleInput, seoKeywordsInput, seoDescriptionInput].forEach(function (input) {
        if (!input) return;
        input.addEventListener('input', function () {
            markDirty();
            updateSeoPreview();
        });
    });

    var seoResetBtn = document.getElementById('seoResetBtn');
    if (seoResetBtn) {
        seoResetBtn.addEventListener('click', function () {
            [seoTitleInput, seoKeywordsInput, seoDescriptionInput].forEach(function (input) {
                if (input) input.value = '';
            });
            updateSeoPreview();
            markDirty();
        });
    }

    var pushBtn = document.getElementById('baiduPushBtn');
    if (pushBtn) {
        pushBtn.addEventListener('click', function () {
            if (pushBtn.disabled) return;
            var url = pushBtn.getAttribute('data-url');
            if (!url) return;
            pushBtn.disabled = true;
            var original = pushBtn.innerHTML;
            pushBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>提交中…';
            fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json();
            }).then(function (res) {
                if (res && res.code === 0) {
                    NorthmuAdmin.toast(res.msg || '百度收录已提交');
                    if (res.data && res.data.status) renderPushStatus(res.data.status);
                } else {
                    NorthmuAdmin.toast((res && res.msg) || '提交失败', 'error');
                }
            }).catch(function () {
                NorthmuAdmin.toast('提交失败，请检查网络后重试', 'error');
            }).finally(function () {
                pushBtn.disabled = false;
                pushBtn.innerHTML = original;
            });
        });
    }

    if (mode === 'markdown') renderPreview();
    updateModeButtons();
    updateSeoPreview();

    var draft = data.draft;
    var banner = document.getElementById('draftBanner');
    if (draft && banner) {
        var timeEl = document.getElementById('draftTime');
        if (timeEl) timeEl.textContent = '（保存于 ' + (draft.updated_at || '未知时间') + '）';
        banner.classList.remove('d-none');

        document.getElementById('draftRestore').addEventListener('click', function () {
            titleInput.value = draft.title || '';
            var catSelect = document.getElementById('docCategory');
            if (catSelect) catSelect.value = String(draft.category_id || 0);
            syncCategoryHint();
            setMode(draft.content_type === 'markdown' ? 'markdown' : 'html', false);
            if (draft.content_type === 'markdown') {
                mdInput.value = draft.content || '';
                renderPreview();
            } else if (editor) {
                editor.setHtml(draft.content || '<p><br></p>');
            }
            syncHidden();
            markDirty();
            updateSeoPreview();
            banner.classList.add('d-none');
            status('草稿已载入编辑器，请点击保存以生效', 'text-warning');
        });

        document.getElementById('draftDiscard').addEventListener('click', function () {
            NorthmuAdmin.post('/admin/documents/draft/discard', { document_id: data.id }).then(function (res) {
                if (res.code === 0) {
                    banner.classList.add('d-none');
                    NorthmuAdmin.toast('草稿已丢弃');
                } else {
                    NorthmuAdmin.toast(res.msg || '操作失败', 'error');
                }
            }).catch(function () {
                NorthmuAdmin.toast('操作失败，请稍后重试', 'error');
            });
        });
    }

    form.addEventListener('submit', function (event) {
        syncHidden();
        if (!uncategorizedConfirmed) {
            var catSelect = document.getElementById('docCategory');
            var statusSelect = document.getElementById('docStatus');
            var publishing = statusSelect && String(statusSelect.value) === '1';
            if (catSelect && publishing && String(catSelect.value) === '0') {
                event.preventDefault();
                var submitter = event.submitter || form.querySelector('[type="submit"]');
                var proceed = function () {
                    uncategorizedConfirmed = true;
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit(submitter || undefined);
                    } else {
                        form.submit();
                    }
                };
                if (window.NorthmuUI && window.NorthmuUI.confirm) {
                    window.NorthmuUI.confirm({
                        anchor: submitter || catSelect,
                        title: '未选择分类',
                        message: '未分类文档将在前台无法显示，确定继续发布吗？',
                        tone: 'warning',
                        confirmText: '继续发布',
                        cancelText: '选择分类'
                    }).then(function (ok) {
                        if (ok) {
                            proceed();
                        } else {
                            catSelect.focus();
                        }
                    });
                } else if (window.confirm('未分类文档将在前台无法显示，确定继续发布吗？')) {
                    proceed();
                }
                return;
            }
        }
        dirty = false;
    });

    window.addEventListener('beforeunload', function (event) {
        if (!dirty) return;
        event.preventDefault();
        event.returnValue = '';
    });

    setInterval(function () {
        if (!dirty) return;
        var rev = edits;
        var payload = {
            document_id: data.id,
            title: titleInput.value,
            category_id: document.getElementById('docCategory').value,
            content: currentContent(),
            content_type: mode
        };
        NorthmuAdmin.post('/admin/documents/draft', payload).then(function (res) {
            if (res.code === 0) {
                if (edits === rev) {
                    dirty = false;
                    status('草稿已自动保存 ' + ((res.data && res.data.time) || ''), 'text-success');
                }
            }
        }).catch(function () {
            status('自动保存失败，请检查网络', 'text-danger');
        });
    }, 20000);
})();
