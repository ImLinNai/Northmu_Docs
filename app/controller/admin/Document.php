<?php

namespace app\controller\admin;

use think\facade\Db;
use think\facade\View;

class Document extends Base
{
    public function index()
    {
        $keyword = trim((string) $this->request->get('keyword', ''));
        $cat = (string) $this->request->get('cat', '');
        $status = (string) $this->request->get('status', '');
        $visibility = (string) $this->request->get('visibility', '');
        if (!in_array($visibility, ['public', 'encrypted', 'private'], true)) {
            $visibility = '';
        }

        $query = Db::name('documents')->whereNull('deleted_at');
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->whereLike('title', $like)->whereOr('content', 'like', $like);
            });
        }
        if ($cat !== '') {
            $query->where('category_id', (int) $cat);
        }
        if ($status !== '') {
            $query->where('status', (int) $status);
        }
        if ($visibility !== '') {
            $query->where('visibility', $visibility);
        }

        $paginator = $query
            ->order('is_top', 'desc')
            ->order('top_at', 'desc')
            ->order('published_at', 'desc')
            ->order('id', 'desc')
            ->paginate(['list_rows' => 12, 'query' => $this->request->get()]);

        $docs = $paginator->items();
        $this->decorateDocs($docs);

        $categories = Db::name('categories')->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        $flat = flatten_categories($categories);
        foreach ($flat as &$category) {
            $category['option_label'] = category_option_label((string) $category['name'], (int) $category['depth']);
            $category['effective_visibility'] = category_effective_visibility((int) $category['id'], $categories);
            unset($category['password_hash']);
        }
        unset($category);

        $view = $this->viewData('文档管理', '创建、编辑、发布与整理你的文档', 'documents') + [
            'docs'       => $docs,
            'paginator'  => $paginator,
            'categories' => $flat,
            'filters'    => [
                'keyword'    => $keyword,
                'cat'        => $cat,
                'status'     => $status,
                'visibility' => $visibility,
            ],
            'totalCount'    => Db::name('documents')->whereNull('deleted_at')->count(),
            'draftCount'    => Db::name('documents')->whereNull('deleted_at')->where('status', 0)->count(),
            'privateCount'  => Db::name('documents')->whereNull('deleted_at')->where('visibility', 'private')->count(),
            'encryptedCount'=> Db::name('documents')->whereNull('deleted_at')->where('visibility', 'encrypted')->count(),
            'trashCount'    => Db::name('documents')->whereNotNull('deleted_at')->count(),
        ];
        $view['extraScripts'] = '<script src="/static/js/admin-pages.js"></script>';

        return View::fetch('/documents/index', $view);
    }

    public function export()
    {
        $raw = trim((string) $this->request->get('ids', ''));
        if ($raw === '') {
            $raw = (string) $this->request->get('id', '');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), function ($id) {
            return $id > 0;
        })));
        $ids = array_slice($ids, 0, 100);

        if (!$ids) {
            admin_flash('请选择要导出的文档', 'warning');
            return redirect('/admin/documents');
        }

        $docs = Db::name('documents')
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if (!$docs) {
            admin_flash('所选文档不存在或已被删除', 'warning');
            return redirect('/admin/documents');
        }

        if (count($docs) === 1) {
            $doc = $docs[0];
            return download($this->markdownForDocument($doc), $this->markdownFilename($doc) . '.md', true)
                ->mimeType('text/markdown; charset=utf-8');
        }

        $zipPath = tempnam(runtime_path(), 'doc-export-');
        $zip = new \ZipArchive();
        if ($zipPath === false || $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            admin_flash('导出失败，请稍后重试', 'danger');
            return redirect('/admin/documents');
        }

        $used = [];
        foreach ($docs as $doc) {
            $name = $this->markdownFilename($doc);
            if (isset($used[$name])) {
                $name .= '-' . (int) $doc['id'];
            }
            $used[$name] = true;
            $zip->addFromString($name . '.md', $this->markdownForDocument($doc));
        }
        $zip->close();

        $content = (string) file_get_contents($zipPath);
        @unlink($zipPath);

        return download($content, 'Northmu_Docs_' . date('Ymd_His') . '.zip', true)
            ->mimeType('application/zip');
    }

    protected function markdownForDocument(array $doc): string
    {
        $body = (string) ($doc['content'] ?? '');
        if (($doc['content_type'] ?? 'html') !== 'markdown') {
            $body = html_to_markdown($body);
        }
        $body = trim($body);
        $title = trim((string) ($doc['title'] ?? ''));

        if ($title === '') {
            return $body . "\n";
        }
        if ($body === '') {
            return '# ' . $title . "\n";
        }
        if (!preg_match('/^#\s+/u', $body)) {
            $body = '# ' . $title . "\n\n" . $body;
        }

        return $body . "\n";
    }

    protected function markdownFilename(array $doc): string
    {
        $title = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', (string) ($doc['title'] ?? '')) ?? '';
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if ($title === '') {
            $title = 'document-' . (int) ($doc['id'] ?? 0);
        }
        return mb_substr($title, 0, 60);
    }

    public function create()
    {
        return $this->renderEditor([
            'id'              => 0,
            'title'           => '',
            'category_id'     => 0,
            'content'         => '',
            'content_type'    => 'html',
            'status'          => 1,
            'visibility'      => 'public',
            'password_hash'   => '',
            'seo_title'       => '',
            'seo_keywords'    => '',
            'seo_description' => '',
            'baidu_push'      => null,
            'show_toc'        => 1,
            'copy_protected'  => 0,
            'is_top'          => 0,
            'top_at'          => null,
            'published_at'    => null,
        ], true);
    }

    public function edit(int $id)
    {
        $doc = Db::name('documents')->where('id', $id)->find();
        if (!$doc) {
            admin_flash('文档不存在', 'danger');
            return redirect('/admin/documents');
        }
        if ($doc['deleted_at']) {
            admin_flash('该文档在回收站中，请先恢复后再编辑', 'warning');
            return redirect('/admin/documents/trash');
        }
        return $this->renderEditor($doc, false);
    }

    protected function renderEditor(array $doc, bool $isNew)
    {
        $categories = Db::name('categories')->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        $flat = flatten_categories($categories);
        foreach ($flat as &$category) {
            $category['option_label'] = category_option_label((string) $category['name'], (int) $category['depth']);
            $category['effective_visibility'] = category_effective_visibility((int) $category['id'], $categories);
            unset($category['password_hash']);
        }
        unset($category);

        $allTags = Db::name('tags')->order('name', 'asc')->field('id,name')->select()->toArray();
        $selectedTagIds = $isNew ? [] : $this->documentTagIds((int) $doc['id']);
        foreach ($allTags as &$tag) {
            $tag['checked'] = in_array((int) $tag['id'], $selectedTagIds, true);
        }
        unset($tag);
        $hasPassword = trim((string) ($doc['password_hash'] ?? '')) !== '';
        unset($doc['password_hash']);

        $selectedTagNames = [];
        foreach ($allTags as $tag) {
            if ($tag['checked']) {
                $selectedTagNames[] = (string) $tag['name'];
            }
        }
        $categoryName = '';
        foreach ($flat as $category) {
            if ((int) $category['id'] === (int) $doc['category_id']) {
                $categoryName = (string) $category['name'];
                break;
            }
        }
        $seoGlobal = seo_global_config();
        $seoDefaults = document_seo_defaults($doc, $selectedTagNames, $categoryName);
        $customSeo = [
            'title'       => (string) ($doc['seo_title'] ?? ''),
            'keywords'    => (string) ($doc['seo_keywords'] ?? ''),
            'description' => (string) ($doc['seo_description'] ?? ''),
        ];
        $seoPreview = [
            'title'       => $customSeo['title'] !== '' ? $customSeo['title'] : $seoDefaults['title'],
            'keywords'    => $customSeo['keywords'] !== '' ? $customSeo['keywords'] : $seoDefaults['keywords'],
            'description' => $customSeo['description'] !== '' ? $customSeo['description'] : $seoDefaults['description'],
            'url'         => $isNew ? site_base_url() . '/doc/1.html' : document_url((int) $doc['id'], true),
        ];
        $docAccess = document_access($doc, $categories);
        $docPublic = $docAccess['visibility'] === 'public';
        $docPublished = (int) $doc['status'] === 1;
        $canPush = !$isNew && $docPublic && $docPublished;
        $baiduConfig = baidu_seo_config();
        $pushStatus = baidu_push_status($doc['baidu_push'] ?? null);
        $pushRows = [];
        foreach (['common' => '普通收录', 'quick' => '快速收录'] as $channelKey => $channelLabel) {
            if (isset($pushStatus['channels'][$channelKey])) {
                $row = $pushStatus['channels'][$channelKey];
                $pushRows[] = [
                    'key'     => $channelKey,
                    'label'   => $channelLabel,
                    'ok'      => (bool) $row['ok'],
                    'message' => (string) $row['message'],
                    'at'      => (string) $row['at'],
                ];
            }
        }
        $pushTriggerLabel = $pushStatus['trigger'] === 'auto' ? '自动提交' : '手动提交';

        $adminId = (int) current_admin()['id'];
        $draft = Db::name('document_drafts')
            ->where('admin_id', $adminId)
            ->where('document_id', (int) $doc['id'])
            ->find();

        $editorData = [
            'id'           => (int) $doc['id'],
            'isNew'        => $isNew,
            'content'      => (string) $doc['content'],
            'contentType'  => (string) $doc['content_type'],
            'uploadUrl'    => '/admin/attachments/upload',
            'seo'          => [
                'defaults' => $seoDefaults,
                'custom'   => $customSeo,
                'global'   => [
                    'title'       => $seoGlobal['title'],
                    'keywords'    => $seoGlobal['keywords'],
                    'description' => $seoGlobal['description'],
                ],
                'url'      => $seoPreview['url'],
                'baidu'    => [
                    'configured' => $baiduConfig['configured'],
                    'auto'       => $baiduConfig['auto'],
                    'common'     => $baiduConfig['common'],
                    'quick'      => $baiduConfig['quick'],
                    'status'     => $pushStatus,
                    'pushUrl'    => $isNew ? '' : '/admin/documents/push/' . (int) $doc['id'],
                    'canPush'    => $canPush,
                ],
            ],
            'draft'        => $draft ? [
                'title'        => (string) $draft['title'],
                'category_id'  => (int) $draft['category_id'],
                'content'      => (string) $draft['content'],
                'content_type' => (string) $draft['content_type'],
                'updated_at'   => (string) $draft['updated_at'],
            ] : null,
            'versionCount' => $isNew ? 0 : Db::name('document_versions')->where('document_id', (int) $doc['id'])->count(),
        ];

        $view = $this->viewData(
            $isNew ? '新建文档' : '编辑文档',
            $isNew ? '支持富文本与 Markdown 双模式编辑' : '修改内容、可见性、分类与标签',
            'documents'
        ) + [
            'doc'             => $doc,
            'isNew'           => $isNew,
            'categories'      => $flat,
            'allTags'         => $allTags,
            'selectedTagIds'  => $selectedTagIds,
            'hasPassword'     => $hasPassword,
            'hasDraft'        => (bool) $draft,
            'editorData'      => $editorData,
            'seoDefaults'     => $seoDefaults,
            'seoPreview'      => $seoPreview,
            'baiduConfigured' => $baiduConfig['configured'],
            'baiduAuto'       => $baiduConfig['auto'],
            'baiduStatus'     => $pushStatus,
            'pushRows'        => $pushRows,
            'pushTriggerLabel'=> $pushTriggerLabel,
            'docPublic'       => $docPublic,
            'docPublished'    => $docPublished,
            'canPush'         => $canPush,
        ];
        $view['editorDataJson'] = json_encode(
            $editorData,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        $view['extraHead'] = '<link rel="stylesheet" href="/static/vendor/wangeditor/style.css">'
            . '<link id="hljs-light" rel="stylesheet" href="/static/vendor/highlight/styles/github.min.css">'
            . '<link id="hljs-dark" rel="stylesheet" href="/static/vendor/highlight/styles/github-dark.min.css" disabled>';
        $view['extraScripts'] = '<script src="/static/vendor/wangeditor/index.js"></script>'
            . '<script src="/static/vendor/marked/marked.umd.js"></script>'
            . '<script src="/static/vendor/turndown/turndown.js"></script>'
            . '<script src="/static/vendor/highlight/highlight.min.js"></script>'
            . '<script src="/static/js/doc-editor.js"></script>';

        return View::fetch('/documents/edit', $view);
    }

    public function save()
    {
        $id = (int) $this->request->post('id', 0);
        $title = trim((string) $this->request->post('title', ''));
        $categoryId = (int) $this->request->post('category_id', 0);
        $content = (string) $this->request->post('content', '');
        $contentType = (string) $this->request->post('content_type', 'html');
        $status = (int) $this->request->post('status', 1) === 1 ? 1 : 0;
        $visibility = normalize_visibility($this->request->post('visibility', 'public'));
        $password = (string) $this->request->post('password', '');
        $seoTitle = seo_clean_text((string) $this->request->post('seo_title', ''), 200);
        $seoKeywords = seo_clean_keywords((string) $this->request->post('seo_keywords', ''));
        $seoDescription = seo_clean_text((string) $this->request->post('seo_description', ''), 500);
        $showToc = (int) $this->request->post('show_toc', 0) === 1 ? 1 : 0;
        $copyProtected = (int) $this->request->post('copy_protected', 0) === 1 ? 1 : 0;
        $isTop = (int) $this->request->post('is_top', 0) === 1 ? 1 : 0;
        $tagIds = (array) $this->request->post('tag_ids/a', []);
        $after = (string) $this->request->post('after', 'edit');

        $back = $id > 0 ? '/admin/documents/edit/' . $id : '/admin/documents/create';

        if ($title === '') {
            admin_flash('文档标题不能为空', 'danger');
            return redirect($back);
        }
        if (mb_strlen($title) > 200) {
            $title = mb_substr($title, 0, 200);
        }
        if (!in_array($contentType, ['html', 'markdown'], true)) {
            $contentType = 'html';
        }
        if ($categoryId > 0 && !Db::name('categories')->where('id', $categoryId)->find()) {
            admin_flash('所选分类不存在', 'danger');
            return redirect($back);
        }

        $existing = null;
        if ($id > 0) {
            $existing = Db::name('documents')->where('id', $id)->whereNull('deleted_at')->find();
            if (!$existing) {
                admin_flash('文档不存在或已在回收站中', 'danger');
                return redirect('/admin/documents');
            }
        }

        $now = date('Y-m-d H:i:s');
        $adminId = (int) current_admin()['id'];
        $data = [
            'title'           => $title,
            'category_id'     => $categoryId,
            'content'         => $content,
            'content_type'    => $contentType,
            'status'          => $status,
            'visibility'      => $visibility,
            'seo_title'       => $seoTitle,
            'seo_keywords'    => mb_substr($seoKeywords, 0, 255),
            'seo_description' => mb_substr($seoDescription, 0, 500),
            'show_toc'        => $showToc,
            'copy_protected'  => $copyProtected,
            'is_top'          => $isTop,
            'updated_at'      => $now,
        ];

        if ($visibility === 'encrypted') {
            if ($password !== '') {
                if (mb_strlen($password) < 4 || mb_strlen($password) > 64) {
                    admin_flash('文档访问密码长度需为 4-64 位', 'danger');
                    return redirect($back);
                }
                $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            } elseif (!$existing || trim((string) $existing['password_hash']) === '') {
                admin_flash('加密文档需要设置访问密码', 'danger');
                return redirect($back);
            }
        } else {
            $data['password_hash'] = '';
        }

        if ($isTop) {
            if (!$existing || (int) $existing['is_top'] !== 1 || empty($existing['top_at'])) {
                $data['top_at'] = $now;
            }
        } else {
            $data['top_at'] = null;
        }

        if ($status === 1) {
            if (!$existing || empty($existing['published_at'])) {
                $data['published_at'] = $now;
            }
        } elseif (!$existing) {
            $data['published_at'] = null;
        }

        if ($id > 0) {
            Db::name('documents')->where('id', $id)->update($data);
            $changed = $existing['title'] !== $title
                || $existing['content'] !== $content
                || $existing['content_type'] !== $contentType;
            if ($changed) {
                $this->recordVersion(array_merge($existing, $data, ['id' => $id]));
            }
            audit_log('document', 'update', '修改文档「' . $title . '」，可见性：' . visibility_label($visibility), 'document', $id);
            admin_flash('文档已保存');
        } else {
            $data['views'] = 0;
            $data['created_at'] = $now;
            $id = (int) Db::name('documents')->insertGetId($data);
            $this->recordVersion(array_merge($data, ['id' => $id]));
            audit_log('document', 'create', '创建文档「' . $title . '」，可见性：' . visibility_label($visibility), 'document', $id);
        }

        $this->syncDocumentTags($id, $tagIds);
        Db::name('document_drafts')->where('admin_id', $adminId)->whereIn('document_id', [0, $id])->delete();

        cache_forget_content();

        if ($existing === null) {
            $pushMessage = '';
            $pushType = 'success';
            $pushConfig = baidu_seo_config();
            if ($pushConfig['auto']) {
                if (!$pushConfig['configured']) {
                    $pushMessage = '百度收录未推送：请先在系统设置中配置 Token 与收录通道';
                    $pushType = 'warning';
                } elseif ($status === 1) {
                    $savedDoc = Db::name('documents')->where('id', $id)->find();
                    $access = document_access((array) $savedDoc);
                    if ($access['visibility'] === 'public') {
                        $pushResult = baidu_push_document((array) $savedDoc, 'auto');
                        if (!empty($pushResult['payload'])) {
                            Db::name('documents')->where('id', $id)->update([
                                'baidu_push' => baidu_push_store_json($pushResult['payload']),
                            ]);
                        }
                        $pushMessage = ($pushResult['ok'] ? '百度收录已提交：' : '百度收录提交未成功：') . $pushResult['message'];
                        $pushType = $pushResult['ok'] ? 'success' : 'warning';
                    }
                }
            }
            admin_flash($pushMessage !== '' ? '文档已创建；' . $pushMessage : '文档已创建', $pushType);
        }

        if ($after === 'list') {
            return redirect('/admin/documents');
        }
        return redirect('/admin/documents/edit/' . $id);
    }

    public function push(int $id)
    {
        $doc = Db::name('documents')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$doc) {
            return $this->fail('文档不存在或已在回收站中');
        }
        $config = baidu_seo_config();
        if (!$config['configured']) {
            return $this->fail('百度收录尚未配置，请先在系统设置中填写 Token 并启用收录通道');
        }
        if ((int) $doc['status'] !== 1) {
            return $this->fail('文档尚未发布，无法提交百度收录');
        }
        $access = document_access($doc);
        if ($access['visibility'] !== 'public') {
            return $this->fail('仅公开文档可以提交百度收录');
        }

        $result = baidu_push_document($doc, 'manual');
        if (empty($result['payload'])) {
            return $this->fail($result['message'] !== '' ? $result['message'] : '百度收录提交失败');
        }
        $raw = baidu_push_store_json($result['payload']);
        Db::name('documents')->where('id', $id)->update(['baidu_push' => $raw]);
        audit_log('document', 'push', '向百度提交文档「' . $doc['title'] . '」收录', 'document', $id);

        return $this->ok($result['message'], ['status' => baidu_push_status($raw)]);
    }

    public function delete()
    {
        $id = (int) $this->request->post('id', 0);
        $doc = $id > 0 ? Db::name('documents')->where('id', $id)->whereNull('deleted_at')->find() : null;
        if (!$doc) {
            admin_flash('文档不存在或已删除', 'danger');
            return redirect('/admin/documents');
        }
        Db::name('documents')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        Db::name('document_drafts')->where('document_id', $id)->delete();
        cache_forget_content();
        audit_log('document', 'delete', '将文档「' . $doc['title'] . '」移入回收站', 'document', $id);
        admin_flash('文档已移入回收站');
        return redirect('/admin/documents');
    }

    public function batch()
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->request->post('ids/a', [])), function ($id) {
            return $id > 0;
        })));
        $action = (string) $this->request->post('action', '');

        if (!$ids) {
            admin_flash('请先选择文档', 'warning');
            return redirect('/admin/documents');
        }

        $now = date('Y-m-d H:i:s');
        $count = 0;
        cache_forget_content();

        switch ($action) {
            case 'delete':
                $count = Db::name('documents')->whereIn('id', $ids)->whereNull('deleted_at')->update(['deleted_at' => $now]);
                Db::name('document_drafts')->whereIn('document_id', $ids)->delete();
                audit_log('document', 'delete', '批量将 ' . $count . ' 篇文档移入回收站');
                admin_flash('已将 ' . $count . ' 篇文档移入回收站');
                return redirect('/admin/documents');
            case 'restore':
                $count = Db::name('documents')->whereIn('id', $ids)->whereNotNull('deleted_at')->update(['deleted_at' => null]);
                audit_log('document', 'restore', '批量恢复 ' . $count . ' 篇文档');
                admin_flash('已恢复 ' . $count . ' 篇文档');
                return redirect('/admin/documents/trash');
            case 'purge':
                foreach ($ids as $id) {
                    $this->purgeDocument($id);
                }
                audit_log('document', 'purge', '批量彻底删除 ' . count($ids) . ' 篇文档');
                admin_flash('已彻底删除所选文档');
                return redirect('/admin/documents/trash');
            case 'publish':
                Db::name('documents')->whereIn('id', $ids)->whereNull('deleted_at')->whereNull('published_at')->update(['published_at' => $now]);
                $count = Db::name('documents')->whereIn('id', $ids)->whereNull('deleted_at')->update(['status' => 1, 'updated_at' => $now]);
                audit_log('document', 'publish', '批量发布 ' . $count . ' 篇文档');
                admin_flash('已发布 ' . $count . ' 篇文档');
                break;
            case 'unpublish':
                $count = Db::name('documents')->whereIn('id', $ids)->whereNull('deleted_at')->update(['status' => 0, 'updated_at' => $now]);
                audit_log('document', 'unpublish', '批量取消发布 ' . $count . ' 篇文档');
                admin_flash('已取消发布 ' . $count . ' 篇文档');
                break;
            case 'top':
                $count = 0;
                foreach ($ids as $docId) {
                    $doc = Db::name('documents')->where('id', $docId)->whereNull('deleted_at')->find();
                    if (!$doc) {
                        continue;
                    }
                    $data = ['is_top' => 1];
                    if ((int) $doc['is_top'] !== 1 || empty($doc['top_at'])) {
                        $data['top_at'] = $now;
                    }
                    Db::name('documents')->where('id', $docId)->update($data);
                    $count++;
                }
                audit_log('document', 'top', '批量置顶 ' . $count . ' 篇文档');
                admin_flash('已置顶 ' . $count . ' 篇文档');
                break;
            case 'untop':
                $count = Db::name('documents')->whereIn('id', $ids)->whereNull('deleted_at')->update(['is_top' => 0, 'top_at' => null]);
                audit_log('document', 'untop', '批量取消置顶 ' . $count . ' 篇文档');
                admin_flash('已取消置顶 ' . $count . ' 篇文档');
                break;
            default:
                admin_flash('未知的批量操作', 'danger');
        }

        return redirect('/admin/documents');
    }

    public function trash()
    {
        $paginator = Db::name('documents')
            ->whereNotNull('deleted_at')
            ->order('deleted_at', 'desc')
            ->paginate(['list_rows' => 12, 'query' => $this->request->get()]);
        $docs = $paginator->items();
        $this->decorateDocs($docs);

        $view = $this->viewData('回收站', '恢复或彻底删除已删除的文档', 'documents') + [
            'docs'      => $docs,
            'paginator' => $paginator,
        ];
        $view['extraScripts'] = '<script src="/static/js/admin-pages.js"></script>';

        return View::fetch('/documents/trash', $view);
    }

    public function restore()
    {
        $id = (int) $this->request->post('id', 0);
        $doc = Db::name('documents')->where('id', $id)->whereNotNull('deleted_at')->find();
        $count = Db::name('documents')->where('id', $id)->whereNotNull('deleted_at')->update(['deleted_at' => null]);
        cache_forget_content();
        if ($count > 0) {
            audit_log('document', 'restore', '从回收站恢复文档「' . ($doc['title'] ?? '#' . $id) . '」', 'document', $id);
        }
        admin_flash($count > 0 ? '文档已恢复' : '文档不在回收站中', $count > 0 ? 'success' : 'warning');
        return redirect('/admin/documents/trash');
    }

    public function purge()
    {
        $id = (int) $this->request->post('id', 0);
        $doc = Db::name('documents')->where('id', $id)->whereNotNull('deleted_at')->find();
        $this->purgeDocument($id);
        cache_forget_content();
        if ($doc) {
            audit_log('document', 'purge', '彻底删除文档「' . $doc['title'] . '」', 'document', $id);
        }
        admin_flash('文档已彻底删除');
        return redirect('/admin/documents/trash');
    }

    protected function purgeDocument(int $id): void
    {
        $doc = Db::name('documents')->where('id', $id)->whereNotNull('deleted_at')->find();
        if (!$doc) {
            return;
        }
        Db::name('document_tag')->where('document_id', $id)->delete();
        Db::name('document_versions')->where('document_id', $id)->delete();
        Db::name('document_drafts')->where('document_id', $id)->delete();
        Db::name('documents')->where('id', $id)->delete();
    }

    public function versions(int $id)
    {
        $doc = Db::name('documents')->where('id', $id)->find();
        if (!$doc) {
            admin_flash('文档不存在', 'danger');
            return redirect('/admin/documents');
        }

        $versions = Db::name('document_versions')
            ->where('document_id', $id)
            ->order('version_no', 'desc')
            ->select()
            ->toArray();
        $latestNo = $versions ? (int) $versions[0]['version_no'] : 0;
        foreach ($versions as &$version) {
            $version['size_text'] = format_size(strlen((string) $version['content']));
            $version['is_latest'] = (int) $version['version_no'] === $latestNo;
        }
        unset($version);

        $versionData = [
            'versions' => array_map(function ($version) {
                return [
                    'id'           => (int) $version['id'],
                    'version_no'   => (int) $version['version_no'],
                    'title'        => (string) $version['title'],
                    'content'      => (string) $version['content'],
                    'content_type' => (string) $version['content_type'],
                    'created_at'   => (string) $version['created_at'],
                ];
            }, $versions),
            'current' => [
                'version_no'   => '当前',
                'title'        => (string) $doc['title'],
                'content'      => (string) $doc['content'],
                'content_type' => (string) $doc['content_type'],
                'created_at'   => (string) $doc['updated_at'],
            ],
        ];

        $view = $this->viewData('版本历史：' . $doc['title'], '查看历史版本、对比差异并回滚', 'documents') + [
            'doc'         => $doc,
            'versions'    => $versions,
            'versionData' => $versionData,
        ];
        $view['versionDataJson'] = json_encode(
            $versionData,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        $view['extraHead'] = '<link rel="stylesheet" href="/static/vendor/diff2html/diff2html.min.css">';
        $view['extraScripts'] = '<script src="/static/vendor/diff/diff.min.js"></script>'
            . '<script src="/static/vendor/diff2html/diff2html.min.js"></script>'
            . '<script src="/static/js/admin-pages.js"></script>';

        return View::fetch('/documents/versions', $view);
    }

    public function rollback()
    {
        $documentId = (int) $this->request->post('document_id', 0);
        $versionId = (int) $this->request->post('version_id', 0);
        $doc = $documentId > 0 ? Db::name('documents')->where('id', $documentId)->whereNull('deleted_at')->find() : null;
        $version = $versionId > 0 ? Db::name('document_versions')->where('id', $versionId)->where('document_id', $documentId)->find() : null;

        if (!$doc || !$version) {
            admin_flash('文档或版本不存在', 'danger');
            return redirect('/admin/documents');
        }

        $data = [
            'title'        => (string) $version['title'],
            'content'      => (string) $version['content'],
            'content_type' => (string) $version['content_type'],
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        Db::name('documents')->where('id', $documentId)->update($data);
        $this->recordVersion(array_merge($doc, $data, ['id' => $documentId]));
        cache_forget_content();
        audit_log('document', 'rollback', '将文档「' . $doc['title'] . '」回滚到版本 v' . $version['version_no'], 'document', $documentId);
        admin_flash('已回滚到版本 v' . $version['version_no'] . '，并生成新的版本记录');

        return redirect('/admin/documents/edit/' . $documentId);
    }

    public function saveDraft()
    {
        $documentId = (int) $this->request->post('document_id', 0);
        $title = mb_substr(trim((string) $this->request->post('title', '')), 0, 200);
        $categoryId = (int) $this->request->post('category_id', 0);
        $content = (string) $this->request->post('content', '');
        $contentType = (string) $this->request->post('content_type', 'html');
        if (!in_array($contentType, ['html', 'markdown'], true)) {
            $contentType = 'html';
        }

        if ($title === '' && trim($content) === '') {
            return $this->ok('内容为空，已跳过自动保存');
        }

        $adminId = (int) current_admin()['id'];
        Db::name('document_drafts')
            ->where('admin_id', $adminId)
            ->where('document_id', $documentId)
            ->delete();
        Db::name('document_drafts')->insert([
            'document_id'  => $documentId,
            'admin_id'     => $adminId,
            'title'        => $title,
            'category_id'  => $categoryId,
            'content'      => $content,
            'content_type' => $contentType,
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        return $this->ok('草稿已自动保存', ['time' => date('H:i:s')]);
    }

    public function discardDraft()
    {
        $documentId = (int) $this->request->post('document_id', 0);
        Db::name('document_drafts')
            ->where('admin_id', (int) current_admin()['id'])
            ->where('document_id', $documentId)
            ->delete();
        return $this->ok('草稿已丢弃');
    }

    protected function decorateDocs(array &$docs): void
    {
        if (!$docs) {
            return;
        }
        $ids = array_column($docs, 'id');
        $tagRows = Db::name('document_tag')
            ->alias('dt')
            ->join('tags t', 't.id = dt.tag_id')
            ->whereIn('dt.document_id', $ids)
            ->field('dt.document_id, t.name')
            ->select()
            ->toArray();
        $tagMap = [];
        foreach ($tagRows as $row) {
            $tagMap[(int) $row['document_id']][] = (string) $row['name'];
        }
        $categories = Db::name('categories')->select()->toArray();
        $categoryMap = [];
        $categoryVisibility = [];
        foreach ($categories as $category) {
            $categoryMap[(int) $category['id']] = (string) $category['name'];
            $categoryVisibility[(int) $category['id']] = category_effective_visibility((int) $category['id'], $categories);
        }

        foreach ($docs as &$doc) {
            $categoryId = (int) $doc['category_id'];
            $access = document_access($doc, $categories);
            $doc['tags'] = $tagMap[(int) $doc['id']] ?? [];
            $doc['category_name'] = $categoryMap[$categoryId] ?? '未分类';
            $doc['category_private'] = isset($categoryVisibility[$categoryId]) && $categoryVisibility[$categoryId] === 'private';
            $doc['category_visibility'] = $categoryVisibility[$categoryId] ?? 'public';
            $doc['effective_visibility'] = $access['visibility'];
            $doc['visibility_label'] = visibility_label($access['visibility']);
            $doc['has_password'] = trim((string) ($doc['password_hash'] ?? '')) !== '';
            unset($doc['password_hash']);
            $doc['updated_text'] = $doc['updated_at'] ? date('Y-m-d H:i', strtotime((string) $doc['updated_at'])) : '-';
            $doc['published_text'] = !empty($doc['published_at']) ? date('Y-m-d H:i', strtotime((string) $doc['published_at'])) : '未发布';
            $doc['top_text'] = !empty($doc['top_at']) ? date('Y-m-d H:i', strtotime((string) $doc['top_at'])) : '';
            $doc['deleted_text'] = $doc['deleted_at'] ? date('Y-m-d H:i', strtotime((string) $doc['deleted_at'])) : '';
        }
        unset($doc);
    }
}
