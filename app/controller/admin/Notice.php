<?php

namespace app\controller\admin;

use think\facade\Db;
use think\facade\View;

class Notice extends Base
{
    public function index()
    {
        return $this->page(null);
    }

    public function create()
    {
        return $this->page(null, true);
    }

    public function edit(int $id)
    {
        $notice = Db::name('notices')->where('id', $id)->find();
        if (!$notice) {
            admin_flash('公告不存在或已被删除', 'danger');
            return redirect('/admin/notices');
        }
        return $this->page($notice, true);
    }

    protected function page(?array $editing, bool $openForm = false)
    {
        $notices = Db::name('notices')
            ->order('sort', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $levels = notice_levels();
        foreach ($notices as &$row) {
            $row['excerpt'] = mb_strlen((string) $row['content']) > 70
                ? mb_substr((string) $row['content'], 0, 70) . '…'
                : (string) $row['content'];
            $row['level_label'] = $levels[$row['level']]['label'] ?? '普通信息';
            $row['updated_at'] = $row['updated_at'] ?: $row['created_at'];
        }
        unset($row);

        $view = $this->viewData('公告通知', '管理前台横幅与访客弹窗', 'notices') + [
            'notices'   => $notices,
            'editing'   => $editing ?: [],
            'openForm'  => $openForm ? 1 : 0,
            'levels'    => $levels,
            'cooldowns' => notice_cooldowns(),
        ];
        $view['extraScripts'] = '<script src="/static/js/admin-notices.js"></script>';

        return View::fetch('/notices', $view);
    }

    public function save()
    {
        $id = (int) $this->request->post('id', 0);
        $title = trim((string) $this->request->post('title', ''));
        $content = trim((string) $this->request->post('content', ''));
        $backUrl = $id > 0 ? '/admin/notices/edit/' . $id : '/admin/notices/create';

        if ($title === '') {
            admin_flash('公告标题不能为空', 'danger');
            return redirect($backUrl);
        }
        if ($content === '') {
            admin_flash('公告内容不能为空', 'danger');
            return redirect($backUrl);
        }
        $title = mb_substr($title, 0, 100);
        $content = mb_substr($content, 0, 2000);

        $levels = notice_levels();
        $level = (string) $this->request->post('level', 'info');
        if (!isset($levels[$level])) {
            $level = 'info';
        }

        $linkUrl = trim((string) $this->request->post('link_url', ''));
        $linkUrl = mb_substr($linkUrl, 0, 500);
        if ($linkUrl !== '' && !preg_match('#^(https?://|/)#i', $linkUrl)) {
            admin_flash('跳转链接需以 http://、https:// 或 / 开头', 'danger');
            return redirect($backUrl);
        }
        $linkTarget = (string) $this->request->post('link_target', '_blank') === '_self' ? '_self' : '_blank';

        $showBanner = $this->request->post('show_banner') ? 1 : 0;
        $showPopup = $this->request->post('show_popup') ? 1 : 0;
        if (!$showBanner && !$showPopup) {
            admin_flash('请至少选择一种展示方式：前台横幅或访问弹窗', 'danger');
            return redirect($backUrl);
        }

        $cooldowns = notice_cooldowns();
        $cooldown = (int) $this->request->post('popup_cooldown', 24);
        if (!isset($cooldowns[$cooldown])) {
            $cooldown = 24;
        }

        $data = [
            'title'          => $title,
            'content'        => $content,
            'level'          => $level,
            'link_url'       => $linkUrl,
            'link_target'    => $linkTarget,
            'show_banner'    => $showBanner,
            'show_popup'     => $showPopup,
            'scroll'         => 0,
            'popup_cooldown' => $cooldown,
            'is_enabled'     => $this->request->post('is_enabled') ? 1 : 0,
            'sort'           => (int) $this->request->post('sort', 0),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];

        if ($id > 0) {
            $existing = Db::name('notices')->where('id', $id)->find();
            if (!$existing) {
                admin_flash('公告不存在或已被删除', 'danger');
                return redirect('/admin/notices');
            }
            Db::name('notices')->where('id', $id)->update($data);
            audit_log('notice', 'update', '更新公告「' . $title . '」', 'notice', $id);
            admin_flash('公告已更新');
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $id = (int) Db::name('notices')->insertGetId($data);
            audit_log('notice', 'create', '创建公告「' . $title . '」', 'notice', $id);
            admin_flash('公告已创建');
        }

        cache_forget('site:notices');
        return redirect('/admin/notices');
    }

    public function toggle()
    {
        $id = (int) $this->request->post('id', 0);
        $notice = Db::name('notices')->where('id', $id)->find();
        if (!$notice) {
            admin_flash('公告不存在或已被删除', 'danger');
            return redirect('/admin/notices');
        }
        $enabled = empty($notice['is_enabled']) ? 1 : 0;
        Db::name('notices')->where('id', $id)->update([
            'is_enabled' => $enabled,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        audit_log('notice', $enabled ? 'enable' : 'disable', ($enabled ? '启用' : '停用') . '公告「' . $notice['title'] . '」', 'notice', $id);
        admin_flash($enabled ? '公告已启用' : '公告已停用');
        cache_forget('site:notices');
        return redirect('/admin/notices');
    }

    public function delete()
    {
        $id = (int) $this->request->post('id', 0);
        $notice = Db::name('notices')->where('id', $id)->find();
        if (!$notice) {
            admin_flash('公告不存在或已被删除', 'danger');
            return redirect('/admin/notices');
        }
        Db::name('notices')->where('id', $id)->delete();
        audit_log('notice', 'delete', '删除公告「' . $notice['title'] . '」', 'notice', $id);
        admin_flash('公告已删除');
        cache_forget('site:notices');
        return redirect('/admin/notices');
    }
}
