<?php

namespace app\controller\admin;

use think\facade\Db;
use think\facade\View;

class Category extends Base
{
    public function index()
    {
        $categories = Db::name('categories')->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        $flat = flatten_categories($categories);

        $countRows = Db::name('documents')
            ->whereNull('deleted_at')
            ->field('category_id, COUNT(*) AS total')
            ->group('category_id')
            ->select()
            ->toArray();
        $docCounts = [];
        foreach ($countRows as $row) {
            $docCounts[(int) $row['category_id']] = (int) $row['total'];
        }

        foreach ($flat as &$category) {
            $category['indent'] = $category['depth'] * 22;
            $category['option_label'] = category_option_label((string) $category['name'], (int) $category['depth']);
            $category['doc_count'] = $docCounts[(int) $category['id']] ?? 0;
            $category['visibility'] = normalize_visibility($category['visibility'] ?? 'public');
            $category['effective_visibility'] = category_effective_visibility((int) $category['id'], $categories);
            $category['effective_label'] = visibility_label($category['effective_visibility']);
            $category['has_password'] = trim((string) ($category['password_hash'] ?? '')) !== '';
            unset($category['password_hash']);
        }
        unset($category);

        $view = $this->viewData('分类管理', '维护无限级分类、可见性与访问密码', 'categories') + [
            'categories' => $flat,
            'docCounts'  => $docCounts,
        ];
        $view['extraScripts'] = '<script src="/static/js/admin-pages.js"></script>';

        return View::fetch('/categories', $view);
    }

    public function save()
    {
        $id = (int) $this->request->post('id', 0);
        $name = trim((string) $this->request->post('name', ''));
        $description = trim((string) $this->request->post('description', ''));
        $parentId = (int) $this->request->post('parent_id', 0);
        $visibility = normalize_visibility($this->request->post('visibility', 'public'));
        $password = (string) $this->request->post('password', '');
        $sort = (int) $this->request->post('sort', 0);

        if ($name === '') {
            admin_flash('分类名称不能为空', 'danger');
            return redirect('/admin/categories');
        }
        if (mb_strlen($name) > 100) {
            $name = mb_substr($name, 0, 100);
        }
        if (mb_strlen($description) > 255) {
            $description = mb_substr($description, 0, 255);
        }

        if ($parentId > 0) {
            $parent = Db::name('categories')->where('id', $parentId)->find();
            if (!$parent) {
                admin_flash('上级分类不存在', 'danger');
                return redirect('/admin/categories');
            }
            if ($id > 0 && in_array($parentId, $this->categoryDescendantIds($id), true)) {
                admin_flash('不能将分类移动到自身或其子分类下', 'danger');
                return redirect('/admin/categories');
            }
        }

        $existing = $id > 0 ? Db::name('categories')->where('id', $id)->find() : null;
        if ($id > 0 && !$existing) {
            admin_flash('分类不存在', 'danger');
            return redirect('/admin/categories');
        }

        $data = [
            'name'        => $name,
            'description' => $description,
            'parent_id'   => $parentId,
            'visibility'  => $visibility,
            'sort'        => $sort,
            'updated_at'  => date('Y-m-d H:i:s'),
        ];

        if ($visibility === 'encrypted') {
            if ($password !== '') {
                if (mb_strlen($password) < 4 || mb_strlen($password) > 64) {
                    admin_flash('分类访问密码长度需为 4-64 位', 'danger');
                    return redirect('/admin/categories');
                }
                $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            } elseif (!$existing || trim((string) $existing['password_hash']) === '') {
                admin_flash('加密分类需要设置访问密码', 'danger');
                return redirect('/admin/categories');
            }
        } else {
            $data['password_hash'] = '';
        }

        if ($id > 0) {
            Db::name('categories')->where('id', $id)->update($data);
            admin_flash('分类已更新');
            audit_log('category', 'update', '更新分类「' . $name . '」，可见性：' . visibility_label($visibility), 'category', $id);
            cache_forget('site:categories');
            cache_forget_content();
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $id = (int) Db::name('categories')->insertGetId($data);
            admin_flash('分类已创建');
            audit_log('category', 'create', '创建分类「' . $name . '」，可见性：' . visibility_label($visibility), 'category', $id);
            cache_forget('site:categories');
            cache_forget_content();
        }

        return redirect('/admin/categories');
    }

    public function delete()
    {
        $id = (int) $this->request->post('id', 0);
        $category = $id > 0 ? Db::name('categories')->where('id', $id)->find() : null;
        if (!$category) {
            admin_flash('分类不存在', 'danger');
            return redirect('/admin/categories');
        }

        $children = Db::name('categories')->where('parent_id', $id)->count();
        if ($children > 0) {
            admin_flash('该分类下还有子分类，请先移动或删除子分类', 'danger');
            return redirect('/admin/categories');
        }
        $docs = Db::name('documents')->where('category_id', $id)->whereNull('deleted_at')->count();
        if ($docs > 0) {
            admin_flash('该分类下还有 ' . $docs . ' 篇文档，请先移动或删除文档', 'danger');
            return redirect('/admin/categories');
        }

        $trashed = Db::name('documents')->where('category_id', $id)->whereNotNull('deleted_at')->count();
        if ($trashed > 0) {
            admin_flash('回收站中还有 ' . $trashed . ' 篇该分类的文档，请先彻底删除或恢复', 'danger');
            return redirect('/admin/categories');
        }

        Db::name('categories')->where('id', $id)->delete();
        audit_log('category', 'delete', '删除分类「' . $category['name'] . '」', 'category', $id);
        admin_flash('分类已删除');
        cache_forget('site:categories');
        cache_forget_content();
        return redirect('/admin/categories');
    }
}
