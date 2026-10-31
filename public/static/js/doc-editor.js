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

    var mode = data.contentType === 'markdown' ? 'markdown' : 'html';
    var editor = null;
    var dirty = false;
    var edits = 0;

    function status(text, cls) {
        saveStatus.textContent = text;
        saveStatus.className = 'small ' + (cls || 'text-secondary');
    }

    function markDirty() {
        dirty = true;
        edits += 1;
        status('有未保存的修改', 'text-warning');
    }

    function currentContent() {
        if (mode === 'markdown') return mdInput.value;
        if (editor) return editor.getHtml();
        return data.content || '';
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
        categorySelect.addEventListener('change', syncCategoryHint);
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
        input.addEventListener('change', markDirty);
    });
    titleInput.addEventListener('input', markDirty);

    if (mode === 'markdown') renderPreview();
    updateModeButtons();

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

    form.addEventListener('submit', function () {
        syncHidden();
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
