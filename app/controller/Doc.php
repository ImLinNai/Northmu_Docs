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
        if ($catId > 0) {
            $catName = $this->categoryName($allCategories, $catId);
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

        $view = $this->baseViewData($catName, 'docs');
        $view += [
            'mode'          => 'list',
            'isSearch'      => false,
            'docs'          => $docs,
            'catTreeHtml'   => render_category_tree($tree, $catId),
            'activeCat'     => $catId,
            'allActive'     => $catId === 0,
            'searchKeyword' => '',
            'listTitle'     => $catName,
            'listSubtitle'  => '共 ' . count($docs) . ' 篇文档',
        ];

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
        $view += [
            'mode'          => 'list',
            'isSearch'      => true,
            'docs'          => $docs,
            'catTreeHtml'   => render_category_tree($tree, 0),
            'activeCat'     => 0,
            'allActive'     => false,
            'searchKeyword' => $keyword,
            'listTitle'     => '搜索：' . $keyword,
            'listSubtitle'  => '找到 ' . count($docs) . ' 篇文档',
        ];

        return view('doc/list', $view);
    }

    public function read()
    {
        $id = (int) $this->request->param('id', 0);
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

        $view = $this->baseViewData((string) $doc['title'], 'docs');
        $view['extraHead'] = '<link id="hljs-light" rel="stylesheet" href="/static/vendor/highlight/styles/github.min.css">'
            . '<link id="hljs-dark" rel="stylesheet" href="/static/vendor/highlight/styles/github-dark.min.css" disabled>';
        $view['extraScripts'] = '<script src="/static/vendor/highlight/highlight.min.js"></script>';
        $view += [
            'mode'          => 'read',
            'doc'           => $doc,
            'contentHtml'   => $contentHtml,
            'catTreeHtml'   => render_category_tree($tree, (int) $doc['category_id']),
            'catPath'       => $catPath,
            'activeCat'     => (int) $doc['category_id'],
            'allActive'     => false,
            'searchKeyword' => '',
        ];

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
            return redirect('/doc/' . $id);
        }
        if ($this->docUnlocked($id, $access)) {
            return redirect('/doc/' . $id);
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
        return redirect('/doc/' . $id);
    }

    protected function renderUnlock(array $doc, array $allCategories, string $error = '')
    {
        $catPath = category_breadcrumb((int) $doc['category_id'], $allCategories);
        $categoryName = '未分类';
        if ($catPath) {
            $last = end($catPath);
            $categoryName = (string) $last['name'];
        }
        $publicCategories = $this->publicCategories($allCategories);
        $tree = build_tree($publicCategories);
        $view = $this->baseViewData('输入密码 - ' . $doc['title'], 'docs');
        $view += [
            'doc'           => $doc,
            'catPath'       => $catPath,
            'categoryName'  => $categoryName,
            'publishedText' => !empty($doc['published_at']) ? date('Y-m-d', strtotime((string) $doc['published_at'])) : '-',
            'error'         => $error,
            'unlockId'      => (int) $doc['id'],
            'catTreeHtml'   => render_category_tree($tree, (int) $doc['category_id']),
            'activeCat'     => (int) $doc['category_id'],
            'allActive'     => false,
            'searchKeyword' => '',
        ];
        return view('doc/unlock', $view);
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
        $front = site_front_config();
        return [
            'pageTitle'   => $title === '' ? $siteName : $title . ' - ' . $siteName,
            'navActive'   => $navActive,
            'siteName'    => $siteName,
            'siteLogo'    => (string) sys_setting('site_logo', ''),
            'siteDesc'    => sys_setting('site_desc', '个人文档与笔记管理系统'),
            'siteIcp'     => sys_setting('site_icp', ''),
            'copyright'   => sys_setting('site_copyright', '© ' . date('Y') . ' ' . $siteName),
            'navItems'    => front_nav_items(),
            'siteBrandMode'  => $front['brandMode'],
            'siteBrandImage' => $front['brandImage'],
            'siteNavStyle'   => $front['navStyle'],
            'siteNavLogin'   => $front['navLogin'],
            'themeColor'  => site_theme_color(),
            'adminLogged' => admin_logged_in(),
            'adminProfile'=> admin_logged_in() ? admin_profile() : null,
            'author'      => site_author(),
            'extraHead'   => '',
            'extraScripts'=> '',
        ];
    }

    protected function denied(string $message, int $statusCode)
    {
        $view = $this->baseViewData($statusCode === 404 ? '页面不存在' : '无权访问', 'docs');
        $view['statusCode'] = $statusCode;
        $view['message'] = $message;
        return view('doc/denied', $view, $statusCode);
    }
}
