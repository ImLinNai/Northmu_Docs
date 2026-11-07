<?php

namespace app\controller\admin;

use think\facade\Db;
use think\facade\View;

class Appearance extends Base
{
    public function index()
    {
        $settings = [];
        foreach (Db::name('settings')->select() as $row) {
            $settings[$row['name']] = $row['value'];
        }
        $settings += [
            'site_name'       => 'Northmu Docs',
            'site_logo'       => '',
            'site_favicon'    => '',
            'site_brand_image'=> '',
            'site_brand_mode' => 'icon_text',
            'site_nav_style'  => 'top',
            'site_nav_login'  => '1',
            'site_nav_items'  => '',
            'site_desc'       => '个人文档与笔记管理系统',
            'site_home_badge' => '',
            'site_home_title' => '',
            'site_home_intro' => '',
            'site_home_note'  => '',
            'site_home_template' => 'classic',
            'site_home_bg_type'  => 'none',
            'site_home_bg_image' => '',
            'site_home_bg_video' => '',
            'site_home_overlay'  => '45',
            'site_login_title'   => '',
            'site_login_desc'    => '',
            'site_login_icon'    => '',
            'site_login_bg'      => '',
            'site_login_overlay' => '45',
            'site_theme_color'   => 'blue',
            'site_doc_note'      => '',
        ];

        $home = site_home_config();
        $view = $this->viewData('前台设置', '配置品牌、导航、首页与登录页展示', 'appearance') + [
            'settings'     => $settings,
            'navItems'     => site_nav_items(),
            'home'         => $home,
            'homeButtons'  => $home['buttons'],
            'login'        => site_login_config(),
            'loginIconUrl' => preg_match('#^https?://#i', (string) $settings['site_login_icon']) ? (string) $settings['site_login_icon'] : '',
            'loginBgUrl'   => preg_match('#^https?://#i', (string) $settings['site_login_bg']) ? (string) $settings['site_login_bg'] : '',
            'faviconUrl'   => preg_match('#^https?://#i', (string) $settings['site_favicon']) ? (string) $settings['site_favicon'] : '',
            'logoUrl'      => preg_match('#^https?://#i', (string) $settings['site_logo']) ? (string) $settings['site_logo'] : '',
            'brandUrl'     => preg_match('#^https?://#i', (string) $settings['site_brand_image']) ? (string) $settings['site_brand_image'] : '',
            'bgImageUrl'   => preg_match('#^https?://#i', (string) $settings['site_home_bg_image']) ? (string) $settings['site_home_bg_image'] : '',
            'bgVideoUrl'   => preg_match('#^https?://#i', (string) $settings['site_home_bg_video']) ? (string) $settings['site_home_bg_video'] : '',
            'themeColor'   => site_theme_color(),
            'themePresets' => site_theme_presets(),
            'uploadCategories' => upload_categories(),
        ];
        $view['extraScripts'] = '<script src="/static/js/admin-pages.js"></script>';

        return View::fetch('/appearance', $view);
    }

    public function save()
    {
        $siteName = trim((string) $this->request->post('site_name', ''));
        if ($siteName === '' || mb_strlen($siteName) > 50) {
            admin_flash('站点名称不能为空且不能超过 50 个字符', 'warning');
            return redirect('/admin/appearance');
        }

        $brandMode = (string) $this->request->post('site_brand_mode', 'icon_text');
        if (!in_array($brandMode, ['icon_text', 'image'], true)) {
            $brandMode = 'icon_text';
        }
        $navStyle = (string) $this->request->post('site_nav_style', 'top');
        if (!in_array($navStyle, ['top', 'floating', 'capsule'], true)) {
            $navStyle = 'top';
        }
        $navLogin = (int) $this->request->post('site_nav_login', 0) === 1 ? '1' : '0';

        $navItems = $this->normalizeNavItems($this->request->post('nav', []));
        if ($navItems === null) {
            admin_flash('导航菜单格式不正确，请检查标题与链接', 'warning');
            return redirect('/admin/appearance');
        }

        $homeBadge = trim((string) $this->request->post('home_badge', ''));
        $homeTitle = trim((string) $this->request->post('home_title', ''));
        $homeIntro = trim((string) $this->request->post('home_intro', ''));
        $homeNote = trim((string) $this->request->post('home_note', ''));
        if (mb_strlen($homeBadge) > 30 || mb_strlen($homeTitle) > 60 || mb_strlen($homeIntro) > 400 || mb_strlen($homeNote) > 100) {
            admin_flash('首页文字超出长度限制（标签 30 / 标题 60 / 介绍 400 / 底部说明 100 字）', 'warning');
            return redirect('/admin/appearance');
        }

        $homeTemplate = (string) $this->request->post('home_template', 'classic');
        if (!in_array($homeTemplate, ['classic', 'split', 'minimal'], true)) {
            $homeTemplate = 'classic';
        }
        $homeBgType = (string) $this->request->post('home_bg_type', 'none');
        if (!in_array($homeBgType, ['none', 'image', 'video'], true)) {
            $homeBgType = 'none';
        }
        $homeOverlay = max(0, min(95, (int) $this->request->post('home_overlay', 45)));

        $homeButtons = $this->normalizeHomeButtons($this->request->post('home_buttons', []));
        if ($homeButtons === null) {
            admin_flash('首页按钮格式不正确，请检查按钮文字与链接', 'warning');
            return redirect('/admin/appearance');
        }

        $loginTitle = trim((string) $this->request->post('login_title', ''));
        $loginDesc = trim((string) $this->request->post('login_desc', ''));
        if (mb_strlen($loginTitle) > 40 || mb_strlen($loginDesc) > 100) {
            admin_flash('登录页标题不能超过 40 字，介绍不能超过 100 字', 'warning');
            return redirect('/admin/appearance');
        }
        $loginOverlay = max(0, min(95, (int) $this->request->post('login_overlay', 45)));

        $loginIconUrl = normalize_media_url((string) $this->request->post('login_icon_url', ''));
        $loginBgUrl = normalize_media_url((string) $this->request->post('login_bg_url', ''));
        $faviconUrl = normalize_media_url((string) $this->request->post('favicon_url', ''));
        $logoUrl = normalize_media_url((string) $this->request->post('logo_url', ''));
        $brandUrl = normalize_media_url((string) $this->request->post('brand_url', ''));
        $bgImageUrl = normalize_media_url((string) $this->request->post('bg_image_url', ''));
        $bgVideoUrl = normalize_media_url((string) $this->request->post('bg_video_url', ''));
        if ($loginIconUrl === null || $loginBgUrl === null || $faviconUrl === null || $logoUrl === null || $brandUrl === null || $bgImageUrl === null || $bgVideoUrl === null) {
            admin_flash('图片链接格式不正确，请填写 http(s) 链接或以 /uploads/ 开头的站内路径', 'warning');
            return redirect('/admin/appearance');
        }

        $themeColor = (string) $this->request->post('site_theme_color', 'blue');
        if (!array_key_exists($themeColor, site_theme_presets())) {
            $themeColor = 'blue';
        }

        $docNote = trim((string) $this->request->post('doc_note', ''));
        if (mb_strlen($docNote) > 60) {
            admin_flash('文档页侧栏底注不能超过 60 个字符', 'warning');
            return redirect('/admin/appearance');
        }

        $data = [
            'site_name'       => $siteName,
            'site_brand_mode' => $brandMode,
            'site_nav_style'  => $navStyle,
            'site_nav_login'  => $navLogin,
            'site_nav_items'  => json_encode($navItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'site_home_badge'   => $homeBadge,
            'site_home_title'   => $homeTitle,
            'site_home_intro'   => $homeIntro,
            'site_home_note'    => $homeNote,
            'site_home_template'=> $homeTemplate,
            'site_home_bg_type' => $homeBgType,
            'site_home_overlay' => (string) $homeOverlay,
            'site_home_buttons' => json_encode($homeButtons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'site_login_title'  => $loginTitle,
            'site_login_desc'   => $loginDesc,
            'site_login_overlay'=> (string) $loginOverlay,
            'site_theme_color'  => $themeColor,
            'site_doc_note'     => $docNote,
        ];

        $oldLogo = (string) sys_setting('site_logo', '');
        $oldFavicon = (string) sys_setting('site_favicon', '');
        $oldBrand = (string) sys_setting('site_brand_image', '');
        $oldBgImage = (string) sys_setting('site_home_bg_image', '');
        $oldBgVideo = (string) sys_setting('site_home_bg_video', '');
        $oldLoginIcon = (string) sys_setting('site_login_icon', '');
        $oldLoginBg = (string) sys_setting('site_login_bg', '');

        try {
            $logoFile = $this->request->file('logo');
        } catch (\Throwable $e) {
            $logoFile = null;
        }
        try {
            $brandFile = $this->request->file('brand_image');
        } catch (\Throwable $e) {
            $brandFile = null;
        }
        try {
            $bgImageFile = $this->request->file('bg_image');
        } catch (\Throwable $e) {
            $bgImageFile = null;
        }
        try {
            $bgVideoFile = $this->request->file('bg_video');
        } catch (\Throwable $e) {
            $bgVideoFile = null;
        }
        try {
            $loginIconFile = $this->request->file('login_icon');
        } catch (\Throwable $e) {
            $loginIconFile = null;
        }
        try {
            $loginBgFile = $this->request->file('login_bg');
        } catch (\Throwable $e) {
            $loginBgFile = null;
        }
        try {
            $faviconFile = $this->request->file('favicon');
        } catch (\Throwable $e) {
            $faviconFile = null;
        }

        $removeLogo = (int) $this->request->post('logo_remove', 0) === 1;
        $removeFavicon = (int) $this->request->post('favicon_remove', 0) === 1;
        $removeBrand = (int) $this->request->post('brand_remove', 0) === 1;
        $removeBgImage = (int) $this->request->post('bg_image_remove', 0) === 1;
        $removeBgVideo = (int) $this->request->post('bg_video_remove', 0) === 1;
        $removeLoginIcon = (int) $this->request->post('login_icon_remove', 0) === 1;
        $removeLoginBg = (int) $this->request->post('login_bg_remove', 0) === 1;

        $newLogo = null;
        $newBrand = null;
        $newBgImage = null;
        $newBgVideo = null;
        $newLoginIcon = null;
        $newLoginBg = null;
        $newFavicon = null;
        try {
            if ($logoFile) {
                $newLogo = $this->storeImage($logoFile, 'logo');
                $data['site_logo'] = $newLogo;
            } elseif ($removeLogo) {
                $data['site_logo'] = '';
            } elseif ($logoUrl !== '') {
                $data['site_logo'] = $logoUrl;
            } elseif ($oldLogo !== '' && preg_match('#^https?://#i', $oldLogo)) {
                $data['site_logo'] = '';
            }

            if ($faviconFile) {
                $newFavicon = $this->storeFavicon($faviconFile);
                $data['site_favicon'] = $newFavicon;
            } elseif ($removeFavicon) {
                $data['site_favicon'] = '';
            } elseif ($faviconUrl !== '') {
                $data['site_favicon'] = $faviconUrl;
            } elseif ($oldFavicon !== '' && preg_match('#^https?://#i', $oldFavicon)) {
                $data['site_favicon'] = '';
            }

            if ($brandFile) {
                $newBrand = $this->storeImage($brandFile, 'brand');
                $data['site_brand_image'] = $newBrand;
            } elseif ($removeBrand) {
                $data['site_brand_image'] = '';
            } elseif ($brandUrl !== '') {
                $data['site_brand_image'] = $brandUrl;
            } elseif ($oldBrand !== '' && preg_match('#^https?://#i', $oldBrand)) {
                $data['site_brand_image'] = '';
            }

            if ($bgImageFile) {
                $newBgImage = $this->storeImage($bgImageFile, 'home-bg', 'uploads/home');
                $data['site_home_bg_image'] = $newBgImage;
            } elseif ($removeBgImage) {
                $data['site_home_bg_image'] = '';
            } elseif ($bgImageUrl !== '') {
                $data['site_home_bg_image'] = $bgImageUrl;
            } elseif ($oldBgImage !== '' && preg_match('#^https?://#i', $oldBgImage)) {
                $data['site_home_bg_image'] = '';
            }

            if ($bgVideoFile) {
                $newBgVideo = $this->storeVideo($bgVideoFile, 'home-bg');
                $data['site_home_bg_video'] = $newBgVideo;
            } elseif ($removeBgVideo) {
                $data['site_home_bg_video'] = '';
            } elseif ($bgVideoUrl !== '') {
                $data['site_home_bg_video'] = $bgVideoUrl;
            } elseif ($oldBgVideo !== '' && preg_match('#^https?://#i', $oldBgVideo)) {
                $data['site_home_bg_video'] = '';
            }

            if ($loginIconFile) {
                $newLoginIcon = $this->storeImage($loginIconFile, 'login-icon');
                $data['site_login_icon'] = $newLoginIcon;
            } elseif ($removeLoginIcon) {
                $data['site_login_icon'] = '';
            } elseif ($loginIconUrl !== '') {
                $data['site_login_icon'] = $loginIconUrl;
            } elseif ($oldLoginIcon !== '' && preg_match('#^https?://#i', $oldLoginIcon)) {
                $data['site_login_icon'] = '';
            }

            if ($loginBgFile) {
                $newLoginBg = $this->storeImage($loginBgFile, 'login-bg', 'uploads/site');
                $data['site_login_bg'] = $newLoginBg;
            } elseif ($removeLoginBg) {
                $data['site_login_bg'] = '';
            } elseif ($loginBgUrl !== '') {
                $data['site_login_bg'] = $loginBgUrl;
            } elseif ($oldLoginBg !== '' && preg_match('#^https?://#i', $oldLoginBg)) {
                $data['site_login_bg'] = '';
            }
        } catch (\RuntimeException $e) {
            foreach ([$newLogo, $newFavicon, $newBrand, $newBgImage, $newBgVideo, $newLoginIcon, $newLoginBg] as $stored) {
                $this->deleteStoredFile((string) $stored);
            }
            admin_flash($e->getMessage(), 'warning');
            return redirect('/admin/appearance');
        }

        $effectiveBgImage = array_key_exists('site_home_bg_image', $data) ? $data['site_home_bg_image'] : $oldBgImage;
        $effectiveBgVideo = array_key_exists('site_home_bg_video', $data) ? $data['site_home_bg_video'] : $oldBgVideo;
        if (($homeBgType === 'image' && $effectiveBgImage === '') || ($homeBgType === 'video' && $effectiveBgVideo === '')) {
            $homeBgType = 'none';
        }
        $data['site_home_bg_type'] = $homeBgType;

        foreach ($data as $name => $value) {
            save_setting($name, $value);
        }
        cache_forget('site:settings');

        if ($oldLogo !== (string) ($data['site_logo'] ?? $oldLogo)) {
            $this->deleteStoredFile($oldLogo);
        }
        if ($oldFavicon !== (string) ($data['site_favicon'] ?? $oldFavicon)) {
            $this->deleteStoredFile($oldFavicon);
        }
        if ($oldBrand !== (string) ($data['site_brand_image'] ?? $oldBrand)) {
            $this->deleteStoredFile($oldBrand);
        }
        if ($oldBgImage !== (string) ($data['site_home_bg_image'] ?? $oldBgImage)) {
            $this->deleteStoredFile($oldBgImage);
        }
        if ($oldBgVideo !== (string) ($data['site_home_bg_video'] ?? $oldBgVideo)) {
            $this->deleteStoredFile($oldBgVideo);
        }
        if ($oldLoginIcon !== (string) ($data['site_login_icon'] ?? $oldLoginIcon)) {
            $this->deleteStoredFile($oldLoginIcon);
        }
        if ($oldLoginBg !== (string) ($data['site_login_bg'] ?? $oldLoginBg)) {
            $this->deleteStoredFile($oldLoginBg);
        }

        audit_log('appearance', 'update', '更新前台品牌、导航、首页/登录页展示与主题色');
        admin_flash('前台设置已保存');
        return redirect('/admin/appearance');
    }

    protected function normalizeNavItems($raw): ?array
    {
        if (!is_array($raw)) {
            return [];
        }
        $titles = $raw['title'] ?? [];
        $urls = $raw['url'] ?? [];
        $targets = $raw['target'] ?? [];
        if (!is_array($titles) || !is_array($urls)) {
            return null;
        }

        $items = [];
        foreach ($titles as $index => $title) {
            $title = trim((string) $title);
            $url = trim((string) ($urls[$index] ?? ''));
            if ($title === '' && $url === '') {
                continue;
            }
            if ($title === '' || mb_strlen($title) > 20) {
                return null;
            }
            if (!$this->safeNavUrl($url)) {
                return null;
            }
            $target = (string) ($targets[$index] ?? '_self');
            $items[] = [
                'title'  => $title,
                'url'    => $url,
                'target' => $target === '_blank' ? '_blank' : '_self',
            ];
            if (count($items) >= 12) {
                break;
            }
        }
        return $items;
    }

    protected function normalizeHomeButtons($raw): ?array
    {
        if (!is_array($raw)) {
            return [];
        }
        $labels = $raw['label'] ?? [];
        $urls = $raw['url'] ?? [];
        $styles = $raw['style'] ?? [];
        if (!is_array($labels) || !is_array($urls)) {
            return null;
        }

        $buttons = [];
        foreach ($labels as $index => $label) {
            $label = trim((string) $label);
            $url = trim((string) ($urls[$index] ?? ''));
            if ($label === '' && $url === '') {
                continue;
            }
            if ($label === '' || mb_strlen($label) > 20) {
                return null;
            }
            if (!$this->safeNavUrl($url)) {
                return null;
            }
            $style = (string) ($styles[$index] ?? 'primary');
            $buttons[] = [
                'label' => $label,
                'url'   => $url,
                'style' => $style === 'outline' ? 'outline' : 'primary',
            ];
            if (count($buttons) >= 4) {
                break;
            }
        }
        return $buttons;
    }

    protected function safeNavUrl(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > 300) {
            return false;
        }
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return !str_starts_with($url, '//');
        }
        return (bool) preg_match('#^(https?://|mailto:|tel:)#i', $url);
    }

    protected function storeImage($file, string $prefix, string $relativeDir = 'uploads/site'): string
    {
        $category = upload_categories()['image'];
        $extension = strtolower(pathinfo((string) $file->getOriginalName(), PATHINFO_EXTENSION));
        if (!in_array($extension, $category['ext_list'], true)) {
            throw new \RuntimeException('图片仅支持 ' . $category['ext'] . ' 格式，可在「上传设置」中调整图片扩展名');
        }
        if ((int) $file->getSize() > $category['max_bytes']) {
            throw new \RuntimeException('图片不能超过 ' . $category['max_mb'] . ' MB');
        }
        if ($extension === 'ico') {
            $header = @file_get_contents($file->getPathname(), false, null, 0, 4);
            if ($header !== "\x00\x00\x01\x00") {
                throw new \RuntimeException('上传的 ico 文件无效');
            }
        } else {
            $imageInfo = @getimagesize($file->getPathname());
            if (!$imageInfo || !in_array($imageInfo[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
                throw new \RuntimeException('上传的文件不是有效的图片');
            }
        }

        $targetDir = public_path() . $relativeDir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('无法创建图片目录，请检查 public/uploads 写入权限');
        }
        ensure_uploads_protection();

        $filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        try {
            $file->move($targetDir, $filename);
        } catch (\Throwable $e) {
            throw new \RuntimeException('图片保存失败：' . $e->getMessage());
        }
        return '/' . $relativeDir . '/' . $filename;
    }

    protected function storeFavicon($file): string
    {
        $category = upload_categories()['image'];
        $extension = strtolower(pathinfo((string) $file->getOriginalName(), PATHINFO_EXTENSION));
        if (!in_array($extension, $category['ext_list'], true)) {
            throw new \RuntimeException('浏览器标签图标仅支持 ' . $category['ext'] . ' 格式，可在「上传设置」中调整图片扩展名');
        }
        if ((int) $file->getSize() > $category['max_bytes']) {
            throw new \RuntimeException('浏览器标签图标不能超过 ' . $category['max_mb'] . ' MB');
        }
        if ($extension === 'ico') {
            $header = @file_get_contents($file->getPathname(), false, null, 0, 4);
            if ($header !== "\x00\x00\x01\x00") {
                throw new \RuntimeException('上传的 ico 文件无效');
            }
        } else {
            $imageInfo = @getimagesize($file->getPathname());
            if (!$imageInfo || !in_array($imageInfo[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
                throw new \RuntimeException('上传的文件不是有效的图片');
            }
        }

        $relativeDir = 'uploads/site';
        $targetDir = public_path() . $relativeDir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('无法创建图标目录，请检查 public/uploads 写入权限');
        }
        ensure_uploads_protection();

        $filename = 'favicon_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        try {
            $file->move($targetDir, $filename);
        } catch (\Throwable $e) {
            throw new \RuntimeException('图标保存失败：' . $e->getMessage());
        }
        return '/' . $relativeDir . '/' . $filename;
    }

    protected function storeVideo($file, string $prefix): string
    {
        $category = upload_categories()['video'];
        $extension = strtolower(pathinfo((string) $file->getOriginalName(), PATHINFO_EXTENSION));
        if (!in_array($extension, $category['ext_list'], true)) {
            throw new \RuntimeException('背景视频仅支持 ' . $category['ext'] . ' 格式，可在「上传设置」中调整视频扩展名');
        }
        if ((int) $file->getSize() > $category['max_bytes']) {
            throw new \RuntimeException('背景视频不能超过 ' . $category['max_mb'] . ' MB');
        }

        $relativeDir = 'uploads/home';
        $targetDir = public_path() . $relativeDir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('无法创建视频目录，请检查 public/uploads 写入权限');
        }
        ensure_uploads_protection();

        $filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        try {
            $file->move($targetDir, $filename);
        } catch (\Throwable $e) {
            throw new \RuntimeException('视频保存失败：' . $e->getMessage());
        }
        return '/' . $relativeDir . '/' . $filename;
    }

    protected function deleteStoredFile(string $path): void
    {
        if ($path === '' || str_contains($path, '..')) {
            return;
        }
        foreach (['uploads/site/', 'uploads/home/'] as $prefix) {
            if (!str_starts_with($path, '/' . $prefix)) {
                continue;
            }
            $absolute = public_path() . ltrim($path, '/');
            $root = realpath(public_path() . $prefix);
            $real = realpath($absolute);
            if ($real && $root && str_starts_with($real, $root) && is_file($real)) {
                @unlink($real);
            }
            return;
        }
    }
}
