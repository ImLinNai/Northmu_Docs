<?php

namespace app\controller\admin;

use app\model\Admin;
use think\facade\Db;
use think\facade\View;

class Settings extends Base
{
    public function index()
    {
        $settings = [];
        foreach (Db::name('settings')->select() as $row) {
            $settings[$row['name']] = $row['value'];
        }
        $settings += [
            'site_name'           => 'Northmu Docs',
            'site_desc'           => '个人文档与笔记管理系统',
            'site_url'            => '',
            'site_logo'           => '',
            'site_icp'            => '',
            'site_copyright'      => '',
            'site_seo_title'      => '',
            'site_seo_keywords'   => '',
            'site_seo_description'=> '',
            'baidu_auto_push'     => '0',
            'baidu_common_push'   => '1',
            'baidu_quick_push'    => '0',
            'baidu_token'         => '',
            'context_menu_mode'   => 'default',
            'copy_guard'          => '0',
            'memorial_enabled'    => '0',
            'memorial_scope'      => 'all',
            'memorial_start'      => '',
            'memorial_end'        => '',
            'captcha_on'          => '1',
            'login_fail_limit'    => '5',
            'login_lock_minutes'  => '10',
            'admin_session_hours' => '24',
            'maintenance_enabled' => '0',
            'maintenance_template'=> 'macos',
            'maintenance_reason'  => '',
            'maintenance_until'   => '',
        ];
        $uploadCategories = upload_categories();
        foreach ($uploadCategories as $key => $category) {
            $settings['upload_' . $key . '_ext'] = $category['ext'];
            $settings['upload_' . $key . '_max_mb'] = (string) $category['max_mb'];
        }

        $untilRaw = trim((string) ($settings['maintenance_until'] ?? ''));
        $untilTs = $untilRaw !== '' ? (int) strtotime($untilRaw) : 0;
        $maintenanceExpired = (string) ($settings['maintenance_enabled'] ?? '0') === '1'
            && $untilTs > 0 && $untilTs <= time();
        $maintenanceUntilInput = $untilTs > 0 && !$maintenanceExpired ? date('Y-m-d\TH:i', $untilTs) : '';

        $memorial = memorial_state();
        $memorialStartInput = $memorial['start'] > 0 ? date('Y-m-d\TH:i', $memorial['start']) : '';
        $memorialEndInput = $memorial['end'] > 0 ? date('Y-m-d\TH:i', $memorial['end']) : '';

        $view = $this->viewData('系统设置', '站点信息、SEO 优化、上传限制、登录安全与管理员资料', 'settings') + [
            'settings'         => $settings,
            'siteLogo'         => (string) ($settings['site_logo'] ?? ''),
            'siteUrl'          => (string) ($settings['site_url'] ?? ''),
            'siteSeoTitle'     => (string) ($settings['site_seo_title'] ?? ''),
            'siteSeoKeywords'  => (string) ($settings['site_seo_keywords'] ?? ''),
            'siteSeoDescription' => (string) ($settings['site_seo_description'] ?? ''),
            'baiduToken'       => (string) ($settings['baidu_token'] ?? ''),
            'baiduConfigured'  => baidu_seo_config()['configured'],
            'auditCount'       => Db::name('audit_logs')->count(),
            'uploadCategories' => $uploadCategories,
            'phpUploadMax'     => (string) ini_get('upload_max_filesize'),
            'maintenanceTemplates'   => maintenance_templates(),
            'maintenanceUntilInput'  => $maintenanceUntilInput,
            'maintenanceExpired'     => $maintenanceExpired,
            'memorialState'          => $memorial,
            'memorialStartInput'     => $memorialStartInput,
            'memorialEndInput'       => $memorialEndInput,
        ];
        $view['seoPreview'] = [
            'url'         => ($view['siteUrl'] !== '' ? $view['siteUrl'] : site_base_url()) . '/',
            'title'       => $view['siteSeoTitle'] !== '' ? $view['siteSeoTitle'] : $settings['site_name'],
            'description' => $view['siteSeoDescription'] !== '' ? $view['siteSeoDescription'] : (string) $settings['site_desc'],
        ];
        $view['logoUrl'] = preg_match('#^https?://#i', (string) ($settings['site_logo'] ?? '')) ? (string) $settings['site_logo'] : '';
        $view['avatarUrl'] = preg_match('#^https?://#i', (string) ($view['admin']['avatar'] ?? '')) ? (string) $view['admin']['avatar'] : '';

        return View::fetch('/settings', $view);
    }

    public function save()
    {
        $siteName = trim((string) $this->request->post('site_name', ''));
        $siteDesc = trim((string) $this->request->post('site_desc', ''));
        $siteUrl = normalize_site_url((string) $this->request->post('site_url', ''));
        $siteIcp = trim((string) $this->request->post('site_icp', ''));
        $siteCopyright = trim((string) $this->request->post('site_copyright', ''));
        $seoTitle = seo_clean_text((string) $this->request->post('site_seo_title', ''), 120);
        $seoKeywords = seo_clean_keywords((string) $this->request->post('site_seo_keywords', ''));
        $seoDescription = seo_clean_text((string) $this->request->post('site_seo_description', ''), 300);
        $baiduAuto = (int) $this->request->post('baidu_auto_push', 0) === 1 ? '1' : '0';
        $baiduCommon = (int) $this->request->post('baidu_common_push', 0) === 1 ? '1' : '0';
        $baiduQuick = (int) $this->request->post('baidu_quick_push', 0) === 1 ? '1' : '0';
        $baiduToken = trim((string) $this->request->post('baidu_token', ''));
        $contextMenuMode = (string) $this->request->post('context_menu_mode', 'default');
        if (!in_array($contextMenuMode, ['default', 'disabled', 'custom'], true)) {
            $contextMenuMode = 'default';
        }
        $copyGuard = (int) $this->request->post('copy_guard', 0) === 1 ? '1' : '0';
        $memorialEnabled = (int) $this->request->post('memorial_enabled', 0) === 1 ? '1' : '0';
        $memorialScope = (string) $this->request->post('memorial_scope', 'all');
        if (!in_array($memorialScope, ['all', 'home'], true)) {
            $memorialScope = 'all';
        }
        $memorialStartRaw = trim((string) $this->request->post('memorial_start', ''));
        $memorialEndRaw = trim((string) $this->request->post('memorial_end', ''));
        $memorialStart = '';
        $memorialEnd = '';
        $captchaOn = (int) $this->request->post('captcha_on', 0) === 1 ? '1' : '0';
        $failLimit = (int) $this->request->post('login_fail_limit', 5);
        $lockMinutes = (int) $this->request->post('login_lock_minutes', 10);
        $sessionHours = (int) $this->request->post('admin_session_hours', 24);
        if (!in_array($sessionHours, [12, 24, 168], true)) {
            $sessionHours = 24;
        }
        $maintenanceEnabled = (int) $this->request->post('maintenance_enabled', 0) === 1 ? '1' : '0';
        $maintenanceTemplate = (string) $this->request->post('maintenance_template', 'macos');
        if (!array_key_exists($maintenanceTemplate, maintenance_templates())) {
            $maintenanceTemplate = 'macos';
        }
        $maintenanceReason = trim((string) $this->request->post('maintenance_reason', ''));
        $maintenanceUntilRaw = trim((string) $this->request->post('maintenance_until', ''));
        $maintenanceUntil = '';
        $removeLogo = (int) $this->request->post('logo_remove', 0) === 1;
        $logoUrl = normalize_media_url((string) $this->request->post('logo_url', ''));

        if ($siteName === '') {
            admin_flash('站点名称不能为空', 'danger');
            return redirect('/admin/settings');
        }
        if ($siteUrl === null) {
            admin_flash('站点地址格式不正确，请填写 https://docs.example.com 形式的站点根地址', 'danger');
            return redirect('/admin/settings');
        }
        if ($baiduToken !== '' && !preg_match('/^[A-Za-z0-9_-]{6,100}$/', $baiduToken)) {
            admin_flash('百度准入密钥 Token 格式不正确，请从百度搜索资源平台复制', 'danger');
            return redirect('/admin/settings');
        }
        if ($baiduAuto === '1') {
            if ($baiduToken === '') {
                admin_flash('启用全站自动提交前请先填写百度准入密钥 Token', 'danger');
                return redirect('/admin/settings');
            }
            if ($baiduCommon !== '1' && $baiduQuick !== '1') {
                admin_flash('启用全站自动提交时，请至少开启普通收录或快速收录其中一项', 'danger');
                return redirect('/admin/settings');
            }
            if ($siteUrl === '') {
                admin_flash('启用百度自动收录前请先填写站点地址，用于生成提交链接', 'danger');
                return redirect('/admin/settings');
            }
        }
        if ($logoUrl === null) {
            admin_flash('Logo 在线链接格式不正确，请填写 http(s) 链接或以 /uploads/ 开头的站内路径', 'danger');
            return redirect('/admin/settings');
        }
        if (mb_strlen($maintenanceReason) > 500) {
            admin_flash('维护说明不能超过 500 个字符', 'danger');
            return redirect('/admin/settings');
        }
        if ($maintenanceUntilRaw !== '') {
            $untilTs = strtotime(str_replace('T', ' ', $maintenanceUntilRaw));
            if ($untilTs === false) {
                admin_flash('维护结束时间格式不正确，请重新选择', 'danger');
                return redirect('/admin/settings');
            }
            if ($untilTs <= time()) {
                admin_flash('维护结束时间需要晚于当前时间，留空表示不限时', 'danger');
                return redirect('/admin/settings');
            }
            $maintenanceUntil = date('Y-m-d H:i:s', $untilTs);
        }
        if ($memorialStartRaw !== '') {
            $startTs = strtotime(str_replace('T', ' ', $memorialStartRaw));
            if ($startTs === false) {
                admin_flash('纪念日自动开始时间格式不正确，请重新选择', 'danger');
                return redirect('/admin/settings');
            }
            $memorialStart = date('Y-m-d H:i:s', $startTs);
        }
        if ($memorialEndRaw !== '') {
            $endTs = strtotime(str_replace('T', ' ', $memorialEndRaw));
            if ($endTs === false) {
                admin_flash('纪念日自动结束时间格式不正确，请重新选择', 'danger');
                return redirect('/admin/settings');
            }
            $memorialEnd = date('Y-m-d H:i:s', $endTs);
        }
        if ($memorialStart !== '' && $memorialEnd !== '' && strtotime($memorialStart) >= strtotime($memorialEnd)) {
            admin_flash('纪念日结束时间需要晚于开始时间', 'danger');
            return redirect('/admin/settings');
        }

        $siteName = mb_substr($siteName, 0, 50);
        $siteDesc = mb_substr($siteDesc, 0, 200);
        $siteIcp = mb_substr($siteIcp, 0, 50);
        $siteCopyright = mb_substr($siteCopyright, 0, 100);
        $failLimit = max(1, min(50, $failLimit));
        $lockMinutes = max(1, min(1440, $lockMinutes));

        $uploadCategories = upload_categories();
        $uploadData = [];
        foreach ($uploadCategories as $key => $category) {
            $ext = normalize_upload_extensions((string) $this->request->post('upload_' . $key . '_ext', ''));
            if ($ext === '') {
                admin_flash($category['label'] . '分类请至少保留一个允许的扩展名', 'danger');
                return redirect('/admin/settings');
            }
            $maxMb = max(1, min(500, (int) $this->request->post('upload_' . $key . '_max_mb', $category['max_mb'])));
            $uploadData['upload_' . $key . '_ext'] = $ext;
            $uploadData['upload_' . $key . '_max_mb'] = (string) $maxMb;
        }
        $imageCategory = $uploadCategories['image'];

        $oldLogo = (string) sys_setting('site_logo', '');
        $newLogo = null;

        try {
            $file = $this->request->file('logo');
        } catch (\Throwable $e) {
            $file = null;
        }

        if ($file) {
            $extension = strtolower(pathinfo((string) $file->getOriginalName(), PATHINFO_EXTENSION));
            if (!in_array($extension, $imageCategory['ext_list'], true)) {
                admin_flash('Logo 仅支持 ' . $imageCategory['ext'] . ' 格式，可在「上传设置」中调整图片扩展名', 'danger');
                return redirect('/admin/settings');
            }
            if ((int) $file->getSize() > $imageCategory['max_bytes']) {
                admin_flash('Logo 文件不能超过 ' . $imageCategory['max_mb'] . ' MB', 'danger');
                return redirect('/admin/settings');
            }
            $imageInfo = @getimagesize($file->getPathname());
            if (!$imageInfo || !in_array($imageInfo[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
                admin_flash('Logo 文件不是有效的图片', 'danger');
                return redirect('/admin/settings');
            }

            $relativeDir = 'uploads/site';
            $targetDir = public_path() . $relativeDir;
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                admin_flash('无法创建 Logo 目录，请检查 public/uploads 写入权限', 'danger');
                return redirect('/admin/settings');
            }
            ensure_uploads_protection();

            $filename = 'logo_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
            try {
                $file->move($targetDir, $filename);
                $newLogo = '/' . $relativeDir . '/' . $filename;
            } catch (\Throwable $e) {
                admin_flash('Logo 保存失败：' . $e->getMessage(), 'danger');
                return redirect('/admin/settings');
            }
        }

        $data = [
            'site_name'           => $siteName,
            'site_desc'           => $siteDesc,
            'site_url'            => $siteUrl,
            'site_icp'            => $siteIcp,
            'site_copyright'      => $siteCopyright,
            'site_seo_title'      => $seoTitle,
            'site_seo_keywords'   => mb_substr($seoKeywords, 0, 255),
            'site_seo_description'=> mb_substr($seoDescription, 0, 300),
            'baidu_auto_push'     => $baiduAuto,
            'baidu_common_push'   => $baiduCommon,
            'baidu_quick_push'    => $baiduQuick,
            'baidu_token'         => $baiduToken,
            'context_menu_mode'   => $contextMenuMode,
            'copy_guard'          => $copyGuard,
            'memorial_enabled'    => $memorialEnabled,
            'memorial_scope'      => $memorialScope,
            'memorial_start'      => $memorialStart,
            'memorial_end'        => $memorialEnd,
            'captcha_on'          => $captchaOn,
            'login_fail_limit'    => (string) $failLimit,
            'login_lock_minutes'  => (string) $lockMinutes,
            'admin_session_hours' => (string) $sessionHours,
            'maintenance_enabled' => $maintenanceEnabled,
            'maintenance_template'=> $maintenanceTemplate,
            'maintenance_reason'  => mb_substr($maintenanceReason, 0, 500),
            'maintenance_until'   => $maintenanceUntil,
        ] + $uploadData;

        if ($newLogo !== null) {
            $data['site_logo'] = $newLogo;
        } elseif ($removeLogo) {
            $data['site_logo'] = '';
        } elseif ($logoUrl !== '') {
            $data['site_logo'] = $logoUrl;
        } elseif ($oldLogo !== '' && preg_match('#^https?://#i', $oldLogo)) {
            $data['site_logo'] = '';
        }

        foreach ($data as $name => $value) {
            save_setting($name, $value);
        }
        cache_forget('site:settings');
        cache_forget('site:sitemap');

        if ($oldLogo !== (string) ($data['site_logo'] ?? $oldLogo)) {
            $this->deleteLogo($oldLogo);
        }

        audit_log('setting', 'update', '更新系统设置（站点信息/SEO/百度收录/前台交互/纪念日/上传设置/登录安全/维护模式）');
        admin_flash('系统设置已保存');
        return redirect('/admin/settings');
    }

    public function profile()
    {
        $admin = Admin::find((int) current_admin()['id']);
        if (!$admin) {
            admin_flash('管理员账号不存在', 'danger');
            return redirect('/admin/settings');
        }

        $nickname = trim((string) $this->request->post('nickname', ''));
        if (mb_strlen($nickname) > 30) {
            admin_flash('昵称不能超过 30 个字符', 'danger');
            return redirect('/admin/settings');
        }

        $oldAvatar = (string) $admin->avatar;
        $newAvatar = null;
        $removeAvatar = (int) $this->request->post('avatar_remove', 0) === 1;
        $avatarUrl = normalize_media_url((string) $this->request->post('avatar_url', ''));
        if ($avatarUrl === null) {
            admin_flash('头像在线链接格式不正确，请填写 http(s) 链接或以 /uploads/ 开头的站内路径', 'danger');
            return redirect('/admin/settings');
        }

        try {
            $file = $this->request->file('avatar');
        } catch (\Throwable $e) {
            $file = null;
        }

        if ($file) {
            $imageCategory = upload_categories()['image'];
            $extension = strtolower(pathinfo((string) $file->getOriginalName(), PATHINFO_EXTENSION));
            if (!in_array($extension, $imageCategory['ext_list'], true)) {
                admin_flash('头像仅支持 ' . $imageCategory['ext'] . ' 格式，可在「上传设置」中调整图片扩展名', 'danger');
                return redirect('/admin/settings');
            }
            if ((int) $file->getSize() > $imageCategory['max_bytes']) {
                admin_flash('头像文件不能超过 ' . $imageCategory['max_mb'] . ' MB', 'danger');
                return redirect('/admin/settings');
            }
            $imageInfo = @getimagesize($file->getPathname());
            if (!$imageInfo || !in_array($imageInfo[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
                admin_flash('头像文件不是有效的图片', 'danger');
                return redirect('/admin/settings');
            }

            $relativeDir = 'uploads/avatar';
            $targetDir = public_path() . $relativeDir;
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                admin_flash('无法创建头像目录，请检查 public/uploads 写入权限', 'danger');
                return redirect('/admin/settings');
            }
            ensure_uploads_protection();

            $filename = 'avatar_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
            try {
                $file->move($targetDir, $filename);
                $newAvatar = '/' . $relativeDir . '/' . $filename;
            } catch (\Throwable $e) {
                admin_flash('头像保存失败：' . $e->getMessage(), 'danger');
                return redirect('/admin/settings');
            }
        }

        $admin->nickname = $nickname;
        if ($newAvatar !== null) {
            $admin->avatar = $newAvatar;
        } elseif ($removeAvatar) {
            $admin->avatar = '';
        } elseif ($avatarUrl !== '') {
            $admin->avatar = $avatarUrl;
        } elseif ($oldAvatar !== '' && preg_match('#^https?://#i', $oldAvatar)) {
            $admin->avatar = '';
        }
        $admin->save();

        if ($oldAvatar !== (string) $admin->avatar) {
            $this->deleteAvatar($oldAvatar);
        }

        audit_log('profile', 'update', $nickname !== '' ? '更新管理员昵称与头像' : '更新管理员头像');
        cache_forget('site:author');
        admin_flash('管理员资料已更新');
        return redirect('/admin/settings');
    }

    public function password()
    {
        $admin = Admin::find((int) current_admin()['id']);
        if (!$admin) {
            admin_flash('管理员账号不存在', 'danger');
            return redirect('/admin/settings');
        }

        $current = (string) $this->request->post('current_password', '');
        $new = (string) $this->request->post('new_password', '');
        $confirm = (string) $this->request->post('confirm_password', '');

        if (!password_verify($current, (string) $admin->password_hash)) {
            admin_flash('当前密码不正确', 'danger');
            return redirect('/admin/settings');
        }
        if (mb_strlen($new) < 8) {
            admin_flash('新密码至少需要 8 个字符', 'danger');
            return redirect('/admin/settings');
        }
        if ($new !== $confirm) {
            admin_flash('两次输入的新密码不一致', 'danger');
            return redirect('/admin/settings');
        }
        if (password_verify($new, (string) $admin->password_hash)) {
            admin_flash('新密码不能与当前密码相同', 'danger');
            return redirect('/admin/settings');
        }

        $admin->password_hash = password_hash($new, PASSWORD_DEFAULT);
        $admin->save();

        audit_log('profile', 'password', '修改管理员登录密码');
        admin_flash('管理员密码已更新');
        return redirect('/admin/settings');
    }

    protected function deleteLogo(string $path): void
    {
        if ($path === '' || !str_starts_with($path, '/uploads/site/') || str_contains($path, '..')) {
            return;
        }
        $absolute = public_path() . ltrim($path, '/');
        $root = realpath(public_path() . 'uploads/site');
        $real = realpath($absolute);
        if ($real && $root && str_starts_with($real, $root) && is_file($real)) {
            @unlink($real);
        }
    }

    protected function deleteAvatar(string $path): void
    {
        if ($path === '' || !str_starts_with($path, '/uploads/avatar/') || str_contains($path, '..')) {
            return;
        }
        $absolute = public_path() . ltrim($path, '/');
        $root = realpath(public_path() . 'uploads/avatar');
        $real = realpath($absolute);
        if ($real && $root && str_starts_with($real, $root) && is_file($real)) {
            @unlink($real);
        }
    }
}
