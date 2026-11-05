<?php

namespace app\controller\admin;

use app\BaseController;
use think\facade\Db;

abstract class Base extends BaseController
{
    protected function viewData(string $title, string $desc, string $active): array
    {
        $siteName = sys_setting('site_name', 'Northmu Docs');
        return [
            'pageTitle'    => $title,
            'pageDesc'     => $desc,
            'active'       => $active,
            'navGroups'    => admin_nav_groups(),
            'navCurrent'   => admin_nav_current($active),
            'siteName'     => $siteName,
            'siteLogo'     => (string) sys_setting('site_logo', ''),
            'themeColor'   => site_theme_color(),
            'admin'        => admin_profile(),
            'copyright'    => sys_setting('site_copyright', '© ' . date('Y') . ' ' . $siteName),
            'flash'        => admin_flash_pull(),
            'uploadLimitsJson' => json_encode(upload_limits_payload(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'extraHead'    => '',
            'extraScripts' => '',
            'pageActions'  => '',
        ];
    }

    protected function ok(string $message = '操作成功', array $data = [])
    {
        return json(['code' => 0, 'msg' => $message, 'data' => $data]);
    }

    protected function fail(string $message, int $status = 200)
    {
        return json(['code' => 1, 'msg' => $message], $status);
    }

    protected function categoryDescendantIds(int $id): array
    {
        $categories = Db::name('categories')->field('id,parent_id')->select()->toArray();
        $ids = [$id];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($categories as $category) {
                $cid = (int) $category['id'];
                if (!in_array($cid, $ids, true) && in_array((int) $category['parent_id'], $ids, true)) {
                    $ids[] = $cid;
                    $changed = true;
                }
            }
        }
        return $ids;
    }

    protected function syncDocumentTags(int $documentId, array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), function ($id) {
            return $id > 0;
        })));
        if ($tagIds) {
            $validIds = Db::name('tags')->whereIn('id', $tagIds)->column('id');
            $tagIds = array_values(array_intersect($tagIds, array_map('intval', $validIds)));
        }

        Db::name('document_tag')->where('document_id', $documentId)->delete();
        foreach (array_slice($tagIds, 0, 20) as $tagId) {
            Db::name('document_tag')->insert([
                'document_id' => $documentId,
                'tag_id'      => $tagId,
            ]);
        }
    }

    protected function recordVersion(array $doc): void
    {
        $max = (int) Db::name('document_versions')->where('document_id', (int) $doc['id'])->max('version_no');
        Db::name('document_versions')->insert([
            'document_id'  => (int) $doc['id'],
            'title'        => (string) $doc['title'],
            'content'      => (string) $doc['content'],
            'content_type' => (string) $doc['content_type'],
            'version_no'   => $max + 1,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    protected function documentTagIds(int $documentId): array
    {
        return array_map('intval', Db::name('document_tag')->where('document_id', $documentId)->column('tag_id'));
    }
}
