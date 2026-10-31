<?php

namespace app\controller\admin;

use think\facade\Db;
use think\facade\View;

class Tag extends Base
{
    public function index()
    {
        $tags = Db::name('tags')
            ->alias('t')
            ->leftJoin('document_tag dt', 'dt.tag_id = t.id')
            ->field('t.id, t.name, t.created_at, COUNT(dt.document_id) AS doc_count')
            ->group('t.id')
            ->order('t.id', 'asc')
            ->select()
            ->toArray();

        $view = $this->viewData('标签管理', '整理标签，合并重复项', 'tags') + [
            'tags' => $tags,
        ];
        $view['extraScripts'] = '<script src="/static/js/admin-pages.js"></script>';

        return View::fetch('/tags', $view);
    }

    public function save()
    {
        $id = (int) $this->request->post('id', 0);
        $name = trim((string) $this->request->post('name', ''));
        if ($name === '') {
            admin_flash('标签名称不能为空', 'danger');
            return redirect('/admin/tags');
        }
        $name = mb_substr($name, 0, 50);

        $exists = Db::name('tags')->where('name', $name)->where('id', '<>', $id)->find();
        if ($exists) {
            admin_flash('标签「' . $name . '」已存在', 'danger');
            return redirect('/admin/tags');
        }

        if ($id > 0) {
            $existing = Db::name('tags')->where('id', $id)->find();
            if (!$existing) {
                admin_flash('标签不存在', 'danger');
                return redirect('/admin/tags');
            }
            Db::name('tags')->where('id', $id)->update(['name' => $name]);
            audit_log('tag', 'update', '将标签「' . $existing['name'] . '」重命名为「' . $name . '」', 'tag', $id);
            admin_flash('标签已更新');
            cache_forget_content();
        } else {
            $id = (int) Db::name('tags')->insertGetId([
                'name'       => $name,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            audit_log('tag', 'create', '创建标签「' . $name . '」', 'tag', $id);
            admin_flash('标签已创建');
            cache_forget_content();
        }

        return redirect('/admin/tags');
    }

    public function delete()
    {
        $id = (int) $this->request->post('id', 0);
        $tag = Db::name('tags')->where('id', $id)->find();
        if (!$tag) {
            admin_flash('标签不存在', 'danger');
            return redirect('/admin/tags');
        }
        Db::name('document_tag')->where('tag_id', $id)->delete();
        Db::name('tags')->where('id', $id)->delete();
        audit_log('tag', 'delete', '删除标签「' . $tag['name'] . '」', 'tag', $id);
        admin_flash('标签已删除');
        cache_forget_content();
        return redirect('/admin/tags');
    }

    public function merge()
    {
        $sourceId = (int) $this->request->post('source_id', 0);
        $targetId = (int) $this->request->post('target_id', 0);

        if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
            admin_flash('请选择两个不同的标签进行合并', 'danger');
            return redirect('/admin/tags');
        }
        $source = Db::name('tags')->where('id', $sourceId)->find();
        $target = Db::name('tags')->where('id', $targetId)->find();
        if (!$source || !$target) {
            admin_flash('标签不存在', 'danger');
            return redirect('/admin/tags');
        }

        $docIds = Db::name('document_tag')->where('tag_id', $sourceId)->column('document_id');
        $moved = 0;
        foreach ($docIds as $docId) {
            $linked = Db::name('document_tag')
                ->where('document_id', $docId)
                ->where('tag_id', $targetId)
                ->find();
            if (!$linked) {
                Db::name('document_tag')->insert([
                    'document_id' => $docId,
                    'tag_id'      => $targetId,
                ]);
                $moved++;
            }
        }
        Db::name('document_tag')->where('tag_id', $sourceId)->delete();
        Db::name('tags')->where('id', $sourceId)->delete();

        audit_log('tag', 'merge', '将标签「' . $source['name'] . '」合并到「' . $target['name'] . '」，关联 ' . $moved . ' 篇文档', 'tag', $targetId);
        admin_flash('已将「' . $source['name'] . '」合并到「' . $target['name'] . '」，关联 ' . $moved . ' 篇文档');
        cache_forget_content();
        return redirect('/admin/tags');
    }
}
