<?php

namespace app\controller\admin;

use think\facade\Db;
use think\facade\View;

class Index extends Base
{
    public function index()
    {
        $stats = cache_remember('admin:dashboard', 300, function () {
            $recentDocs = Db::name('documents')
                ->whereNull('deleted_at')
                ->order('updated_at', 'desc')
                ->limit(6)
                ->field('id,title,status,visibility,password_hash,views,updated_at,category_id')
                ->select()
                ->toArray();

            $categories = Db::name('categories')->select()->toArray();
            $categoryMap = [];
            foreach ($categories as $category) {
                $categoryMap[(int) $category['id']] = (string) $category['name'];
            }
            $badgeMap = [
                'public'    => 'bg-azure-lt text-azure',
                'encrypted' => 'bg-yellow-lt text-yellow',
                'private'   => 'bg-red-lt text-red',
            ];
            foreach ($recentDocs as &$doc) {
                $doc['category_name'] = $categoryMap[(int) $doc['category_id']] ?? '未分类';
                $effective = document_access($doc, $categories)['visibility'];
                $doc['visibility_label'] = visibility_label($effective);
                $doc['visibility_class'] = $badgeMap[$effective] ?? 'bg-secondary-lt';
            }
            unset($doc);

            return [
                'docCount'   => Db::name('documents')->whereNull('deleted_at')->count(),
                'catCount'   => Db::name('categories')->count(),
                'tagCount'   => Db::name('tags')->count(),
                'totalViews' => (int) Db::name('documents')->whereNull('deleted_at')->sum('views'),
                'recentDocs' => $recentDocs,
            ];
        });
        if (!is_array($stats)) {
            $stats = [];
        }
        $docCount = (int) ($stats['docCount'] ?? 0);
        $catCount = (int) ($stats['catCount'] ?? 0);
        $tagCount = (int) ($stats['tagCount'] ?? 0);
        $totalViews = (int) ($stats['totalViews'] ?? 0);
        $recentDocs = $stats['recentDocs'] ?? [];

        try {
            $mysqlVersion = Db::query('SELECT VERSION() AS version')[0]['version'] ?? '-';
        } catch (\Throwable $e) {
            $mysqlVersion = '-';
        }

        return View::fetch('/index', $this->viewData('仪表盘', '站点运行概览与系统环境信息', 'dashboard') + [
            'docCount'     => $docCount,
            'catCount'     => $catCount,
            'tagCount'     => $tagCount,
            'totalViews'   => $totalViews,
            'recentDocs'   => $recentDocs,
            'phpVersion'   => PHP_VERSION,
            'mysqlVersion' => $mysqlVersion,
            'tpVersion'    => \think\facade\App::version(),
            'serverSoft'   => (string) $this->request->server('SERVER_SOFTWARE', '-'),
            'uploadMax'    => ini_get('upload_max_filesize'),
            'osInfo'       => PHP_OS,
        ]);
    }
}
