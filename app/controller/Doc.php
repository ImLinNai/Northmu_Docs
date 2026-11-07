<?php

namespace app\controller;

use app\BaseController;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;

class Doc extends BaseController
{
    public function index()
    {
        $allCategories = $this->allCategories();
        $publicCategories = $this->publicCategories($allCategories);
        $tree = build_tree($publicCategories);

        $catId = (int) $this->request->get('cat', 0);
        $publicIds = $this->publicCategoryIds($publicCategories);

        if ($catId > 0) {
            $validIds = array_map('intval', array_column($publicCategories, 'id'));
            if (!in_array($catId, $validIds, true)) {
                return $this->denied('该分类不存在或不可访问。', 404);
            }
        }

        $catName = '全部文档';
        $activeCategory = null;
        if ($catId > 0) {
            $catName = $this->categoryName($allCategories, $catId);
            foreach ($allCategories as $category) {
                if ((int) $category['id'] === $catId) {
                    $activeCategory = $category;
                    break;
                }
            }
        }

        $docs = cache_remember('docs:list:' . $catId, cache_ttl(), function () use ($publicIds, $catId, $publicCategories, $allCategories) {
            $query = Db::name('documents')
                ->whereNull('deleted_at')
                ->where('status', 1)
                ->whereIn('category_id', $publicIds);
            if ($catId > 0) {
                $query->whereIn('category_id', $this->descendantIds($publicCategories, $catId));
            }
            $rows = $query->order('is_top', 'desc')
                ->order('top_at', 'desc')
                ->order('published_at', 'desc')
                ->order('id', 'desc')
                ->select()
                ->toArray();
            $rows = array_values(array_filter($rows, function ($doc) use ($allCategories) {
                return document_access($doc, $allCategories)['visibility'] !== 'private';
            }));
            foreach ($rows as &$row) {
                $row['_excerpt'] = document_excerpt($row);
            }
            unset($row);
            return $rows;
        });
        if (!is_array($docs)) {
            $docs = [];
        }
        $this->decorateDocs($docs, $allCategories);

        $seoGlobal = seo_global_config();
        $catVisibility = $catId > 0 ? category_effective_visibility($catId, $allCategories) : 'public';
        $categoryDescription = trim((string) ($activeCategory['description'] ?? ''));
        $description = $categoryDescription !== ''
            ? seo_clean_text($categoryDescription, 300)
            : $seoGlobal['description'];
        $keywords = $seoGlobal['keywords'];
        if ($catId > 0 && $catName !== '未分类') {
            $keywords = seo_clean_keywords($catName . ($keywords !== '' ? ',' . $keywords : ''));
        }
        $canonical = site_base_url() . '/docs' . ($catId > 0 ? '?cat=' . $catId : '');
        $seo = seo_meta([
            'title'       => $catName,
            'description' => $description,
            'keywords'    => $keywords,
            'canonical'   => $canonical,
            'robots'      => $catVisibility === 'public' ? 'index,follow' : 'noindex,follow',
            'type'        => 'website',
        ]);

        $view = $this->baseViewData($catName, 'docs');
        $docMap = $this->documentTreeMap($publicCategories, $allCategories);
        $view += [
            'mode'          => 'list',
            'isSearch'      => false,
            'docs'          => $docs,
            'catTreeHtml'   => render_category_tree($tree, $catId, $docMap, 0),
            'uncategorizedDocs' => $docMap[0] ?? [],
            'activeDocId'   => 0,
            'activeCat'     => $catId,
            'allActive'     => $catId === 0,
            'searchKeyword' => '',
            'listTitle'     => $catName,
            'listSubtitle'  => '共 ' . count($docs) . ' 篇文档',
        ];
        $view = array_merge($view, $seo);

        return view('doc/list', $view);
    }

    public function search()
    {
        $keyword = trim((string) $this->request->get('q', ''));
        if ($keyword === '') {
            return redirect('/docs');
        }
        if (mb_strlen($keyword) > 100) {
            $keyword = mb_substr($keyword, 0, 100);
        }

        $allCategories = $this->allCategories();
        $publicCategories = $this->publicCategories($allCategories);
        $tree = build_tree($publicCategories);
        $publicIds = $this->publicCategoryIds($publicCategories);

        $like = '%' . $keyword . '%';
        $docs = cache_remember('docs:search:' . md5(mb_strtolower($keyword)), min(cache_ttl(), 1800), function () use ($like, $publicIds, $allCategories) {
            $rows = Db::name('documents')
                ->whereNull('deleted_at')
                ->where('status', 1)
                ->whereIn('category_id', $publicIds)
                ->where(function ($query) use ($like) {
                    $query->whereLike('title', $like)->whereOr('content', 'like', $like);
                })
                ->order('is_top', 'desc')
                ->order('top_at', 'desc')
                ->order('published_at', 'desc')
                ->order('id', 'desc')
                ->limit(200)
                ->select()
                ->toArray();
            $rows = array_values(array_filter($rows, function ($doc) use ($allCategories) {
                return document_access($doc, $allCategories)['visibility'] !== 'private';
            }));
            foreach ($rows as &$row) {
                $row['_excerpt'] = document_excerpt($row);
            }
            unset($row);
            return $rows;
        });
        if (!is_array($docs)) {
            $docs = [];
        }
        $docs = array_values(array_filter($docs, function ($doc) use ($allCategories, $keyword) {
            $access = document_access($doc, $allCategories);
            if ($access['visibility'] === 'private') {
                return false;
            }
            if ($access['visibility'] === 'encrypted' && !$this->docUnlocked((int) $doc['id'], $access)) {
                return mb_stripos((string) $doc['title'], $keyword) !== false;
            }
            return true;
        }));
        $this->decorateDocs($docs, $allCategories);

        $view = $this->baseViewData('搜索：' . $keyword, 'docs');
        $docMap = $this->documentTreeMap($publicCategories, $allCategories);
        $view += [
            'mode'          => 'list',
            'isSearch'      => true,
            'docs'          => $docs,
            'catTreeHtml'   => render_category_tree($tree, 0, $docMap, 0),
            'uncategorizedDocs' => $docMap[0] ?? [],
            'activeDocId'   => 0,
            'activeCat'     => 0,
            'allActive'     => false,
            'searchKeyword' => $keyword,
            'listTitle'     => '搜索：' . $keyword,
            'listSubtitle'  => '找到 ' . count($docs) . ' 篇文档',
        ];
        $seoGlobal = seo_global_config();
        $view = array_merge($view, seo_meta([
            'title'       => '搜索：' . $keyword,
            'description' => $seoGlobal['description'],
            'keywords'    => $seoGlobal['keywords'],
            'canonical'   => '',
            'robots'      => 'noindex,follow',
            'type'        => 'website',
        ]));

        return view('doc/list', $view);
    }

    public function read()
    {
        $id = (int) $this->request->param('id', 0);
        $requestPath = (string) parse_url((string) $this->request->server('REQUEST_URI', ''), PHP_URL_PATH);
        if ($id > 0 && !str_ends_with(strtolower($requestPath), '.html')) {
            return redirect('/doc/' . $id . '.html', 301);
        }

        $doc = $id > 0 ? cache_remember('doc:' . $id, cache_ttl(), function () use ($id) {
            $row = Db::name('documents')->where('id', $id)->whereNull('deleted_at')->find();
            if (!$row) {
                return null;
            }
            $row['_content_html'] = document_content_html($row);
            return $row;
        }) : null;
        if (!is_array($doc) || (int) $doc['status'] !== 1) {
            return $this->denied('文档不存在或已被删除。', 404);
        }

        $allCategories = $this->allCategories();
        $access = document_access($doc, $allCategories);
        if ($access['visibility'] === 'private') {
            return $this->denied('文档不存在或已被删除。', 404);
        }

        if ($access['visibility'] === 'encrypted' && !$this->docUnlocked($id, $access)) {
            return $this->renderUnlock($doc, $allCategories);
        }

        Db::name('documents')->where('id', $id)->inc('views')->update();
        $doc['views'] = (int) $doc['views'] + 1;
        cache_put('doc:' . $id, $doc, cache_ttl());

        $publicCategories = $this->publicCategories($allCategories);
        $tree = build_tree($publicCategories);
        $docs = [$doc];
        $this->decorateDocs($docs, $allCategories);
        $doc = $docs[0];
        $doc['updated_full'] = $doc['updated_at'] ? date('Y-m-d H:i', strtotime((string) $doc['updated_at'])) : '-';

        $catPath = category_breadcrumb((int) $doc['category_id'], $allCategories);
        $contentHtml = document_content_html($doc);
        $canonical = document_url((int) $doc['id'], true);
        $robots = $access['visibility'] === 'public' ? 'index,follow' : 'noindex,nofollow';
        $seo = document_seo_meta($doc, $doc['tags'], $doc['category_name'], $canonical, $robots);

        $view = $this->baseViewData((string) $doc['title'], 'docs');
        $view['pageTitle'] = $seo['pageTitle'];
        $view['extraHead'] = '<link id="hljs-light" rel="stylesheet" href="/static/vendor/highlight/styles/github.min.css">'
            . '<link id="hljs-dark" rel="stylesheet" href="/static/vendor/highlight/styles/github-dark.min.css" disabled>';
        $view['extraScripts'] = '<script src="/static/vendor/highlight/highlight.min.js"></script>';
        $docMap = $this->documentTreeMap($publicCategories, $allCategories);
        $view += [
            'mode'          => 'read',
            'isLocked'      => false,
            'doc'           => $doc,
            'contentHtml'   => $contentHtml,
            'catTreeHtml'   => render_category_tree($tree, (int) $doc['category_id'], $docMap, (int) $doc['id']),
            'uncategorizedDocs' => $docMap[0] ?? [],
            'activeDocId'   => (int) $doc['id'],
            'catPath'       => $catPath,
            'activeCat'     => (int) $doc['category_id'],
            'allActive'     => false,
            'searchKeyword' => '',
        ];
        $view = array_merge($view, $seo);

        return view('doc/read', $view);
    }

    public function unlock()
    {
        $id = (int) $this->request->param('id', 0);
        $doc = $id > 0 ? Db::name('documents')->where('id', $id)->whereNull('deleted_at')->find() : null;
        if (!$doc || (int) $doc['status'] !== 1) {
            return $this->denied('文档不存在或已被删除。', 404);
        }

        $allCategories = $this->allCategories();
        $access = document_access($doc, $allCategories);
        if ($access['visibility'] === 'private') {
            return $this->denied('文档不存在或已被删除。', 404);
        }
        if ($access['visibility'] !== 'encrypted') {
            return redirect('/doc/' . $id . '.html');
        }
        if ($this->docUnlocked($id, $access)) {
            return redirect('/doc/' . $id . '.html');
        }

        $password = (string) $this->request->post('password', '');
        $ip = (string) $this->request->ip();
        $failKey = 'doc_unlock_fail_' . md5($ip . '|' . $id);
        $fails = (int) Cache::get($failKey, 0);

        if ($fails >= 8) {
            return $this->renderUnlock($doc, $allCategories, '尝试次数过多，请 10 分钟后再试。');
        }
        if ($password === '') {
            return $this->renderUnlock($doc, $allCategories, '请输入访问密码。');
        }
        if ($access['password_hash'] === '' || !password_verify($password, $access['password_hash'])) {
            Cache::set($failKey, $fails + 1, 600);
            return $this->renderUnlock($doc, $allCategories, '密码不正确，请重试。');
        }

        Cache::delete($failKey);
        Session::set($this->unlockSessionKey($id), $this->unlockFingerprint($access['password_hash']));
        return redirect('/doc/' . $id . '.html');
    }

    protected function renderUnlock(array $doc, array $allCategories, string $error = '')
    {
        $catPath = category_breadcrumb((int) $doc['category_id'], $allCategories);
        $publicCategories = $this->publicCategories($allCategories);
        $tree = build_tree($publicCategories);
        $docMap = $this->documentTreeMap($publicCategories, $allCategories);
        $docs = [$doc];
        $this->decorateDocs($docs, $allCategories);
        $doc = $docs[0];
        $doc['updated_full'] = $doc['updated_at'] ? date('Y-m-d H:i', strtotime((string) $doc['updated_at'])) : '-';

        $view = $this->baseViewData('输入密码 - ' . $doc['title'], 'docs');
        $view += [
            'mode'          => 'read',
            'isLocked'      => true,
            'unlockError'   => $error,
            'unlockId'      => (int) $doc['id'],
            'doc'           => $doc,
            'contentHtml'   => '',
            'catPath'       => $catPath,
            'catTreeHtml'   => render_category_tree($tree, (int) $doc['category_id'], $docMap, (int) $doc['id']),
            'uncategorizedDocs' => $docMap[0] ?? [],
            'activeDocId'   => (int) $doc['id'],
            'activeCat'     => (int) $doc['category_id'],
            'allActive'     => false,
            'searchKeyword' => '',
        ];
        $seoGlobal = seo_global_config();
        $view = array_merge($view, seo_meta([
            'title'       => '输入密码 - ' . (string) $doc['title'],
            'description' => $seoGlobal['description'],
            'keywords'    => '',
            'canonical'   => '',
            'robots'      => 'noindex,nofollow',
            'type'        => 'article',
        ]));
        return view('doc/read', $view);
    }

    protected function documentTreeMap(array $publicCategories, array $allCategories): array
    {
        $rows = cache_remember('docs:tree-links', cache_ttl(), function () {
            return Db::name('documents')
                ->whereNull('deleted_at')
                ->where('status', 1)
                ->field('id,title,category_id,visibility,password_hash,is_top,published_at')
                ->order('is_top', 'desc')
                ->order('top_at', 'desc')
                ->order('published_at', 'desc')
                ->order('id', 'desc')
                ->select()
                ->toArray();
        });
        if (!is_array($rows)) {
            return [];
        }

        $publicIds = array_flip(array_map('intval', array_column($publicCategories, 'id')));
        $map = [];
        foreach ($rows as $row) {
            $categoryId = (int) $row['category_id'];
            if ($categoryId !== 0 && !isset($publicIds[$categoryId])) {
                continue;
            }
            $access = document_access($row, $allCategories);
            if ($access['visibility'] === 'private') {
                continue;
            }
            $row['is_encrypted'] = $access['visibility'] === 'encrypted';
            unset($row['password_hash']);
            $map[$categoryId][] = $row;
        }
        return $map;
    }

    protected function allCategories(): array
    {
        return site_categories();
    }

    protected function publicCategories(array $allCategories): array
    {
        $public = [];
        foreach ($allCategories as $category) {
            if (category_effective_visibility((int) $category['id'], $allCategories) !== 'private') {
                $category['effective_visibility'] = category_effective_visibility((int) $category['id'], $allCategories);
                $public[] = $category;
            }
        }
        return $public;
    }

    protected function publicCategoryIds(array $publicCategories): array
    {
        $ids = array_map('intval', array_column($publicCategories, 'id'));
        $ids[] = 0;
        return array_values(array_unique($ids));
    }

    protected function descendantIds(array $categories, int $rootId): array
    {
        $ids = [$rootId];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($categories as $category) {
                $id = (int) $category['id'];
                if (!in_array($id, $ids, true) && in_array((int) $category['parent_id'], $ids, true)) {
                    $ids[] = $id;
                    $changed = true;
                }
            }
        }
        return $ids;
    }

    protected function categoryName(array $categories, int $id): string
    {
        foreach ($categories as $category) {
            if ((int) $category['id'] === $id) {
                return (string) $category['name'];
            }
        }
        return '未分类';
    }

    protected function decorateDocs(array &$docs, array $allCategories): void
    {
        if (!$docs) {
            return;
        }
        $tagMap = site_document_tags();
        $categoryMap = [];
        foreach ($allCategories as $category) {
            $categoryMap[(int) $category['id']] = (string) $category['name'];
        }

        foreach ($docs as &$doc) {
            $access = document_access($doc, $allCategories);
            $unlocked = $this->docUnlocked((int) $doc['id'], $access);
            $doc['tags'] = $tagMap[(int) $doc['id']] ?? [];
            $doc['category_name'] = $categoryMap[(int) $doc['category_id']] ?? '未分类';
            $doc['updated_date'] = $doc['updated_at'] ? date('Y-m-d', strtotime((string) $doc['updated_at'])) : '-';
            $doc['published_date'] = $doc['published_at'] ? date('Y-m-d', strtotime((string) $doc['published_at'])) : '-';
            $doc['views'] = (int) $doc['views'];
            $doc['access_visibility'] = $access['visibility'];
            $doc['is_encrypted'] = $access['visibility'] === 'encrypted';
            $doc['is_locked'] = $doc['is_encrypted'] && !$unlocked;

            if ($doc['is_locked']) {
                $doc['excerpt'] = '该文档已加密，输入访问密码后查看全文。';
                $doc['tags'] = [];
            } else {
                $doc['excerpt'] = document_excerpt($doc);
            }

            unset($doc['password_hash']);
        }
        unset($doc);
    }

    protected function docUnlocked(int $id, array $access): bool
    {
        if (($access['visibility'] ?? '') !== 'encrypted' || $access['password_hash'] === '') {
            return false;
        }
        return (string) Session::get($this->unlockSessionKey($id)) === $this->unlockFingerprint($access['password_hash']);
    }

    protected function unlockSessionKey(int $id): string
    {
        return 'doc_unlock_' . $id;
    }

    protected function unlockFingerprint(string $hash): string
    {
        return $hash === '' ? '' : substr(hash('sha256', $hash), 0, 20);
    }

    protected function baseViewData(string $title, string $navActive = 'docs'): array
    {
        $siteName = sys_setting('site_name', 'Northmu Docs');
        $seoGlobal = seo_global_config();
        $front = site_front_config();
        $sidebarNote = trim((string) sys_setting('site_doc_note', ''));
        return [
            'pageTitle'   => $title === '' ? $seoGlobal['title'] : $title . ' - ' . $seoGlobal['title'],
            'navActive'   => $navActive,
            'siteName'    => $siteName,
            'docSidebarNote' => $sidebarNote !== '' ? $sidebarNote : $siteName,
            'siteLogo'    => (string) sys_setting('site_logo', ''),
            'siteDesc'    => sys_setting('site_desc', '个人文档与笔记管理系统'),
            'siteIcp'     => sys_setting('site_icp', ''),
            'copyright'   => sys_setting('site_copyright', '© ' . date('Y') . ' ' . $siteName),
            'navItems'    => front_nav_items(),
            'siteBrandMode'  => $front['brandMode'],
            'siteBrandImage' => $front['brandImage'],
            'siteNavStyle'   => $front['navStyle'],
            'siteNavLogin'   => $front['navLogin'],
            'contextMenuMode'=> site_context_menu_mode(),
            'themeColor'  => site_theme_color(),
            'adminLogged' => admin_logged_in(),
            'adminProfile'=> admin_logged_in() ? admin_profile() : null,
            'maintenanceBanner' => admin_logged_in() ? maintenance_state() : null,
            'noticeBanner' => front_notices('banner'),
            'noticePopups' => front_notices('popup'),
            'noticePlacement' => 'doc',
            'author'      => site_author(),
            'extraHead'   => '',
            'extraScripts'=> '',
        ] + seo_meta([
            'title'       => $title,
            'description' => $seoGlobal['description'],
            'keywords'    => $seoGlobal['keywords'],
            'canonical'   => '',
            'robots'      => 'index,follow',
        ]);
    }

    protected function denied(string $message, int $statusCode)
    {
        $view = $this->baseViewData($statusCode === 404 ? '页面不存在' : '无权访问', 'docs');
        $view['statusCode'] = $statusCode;
        $view['message'] = $message;
        $seoGlobal = seo_global_config();
        $view = array_merge($view, seo_meta([
            'title'       => $statusCode === 404 ? '页面不存在' : '无权访问',
            'description' => $seoGlobal['description'],
            'keywords'    => '',
            'canonical'   => '',
            'robots'      => 'noindex,nofollow',
        ]));
        return view('doc/denied', $view, $statusCode);
    }
}
