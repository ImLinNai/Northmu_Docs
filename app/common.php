<?php

use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;

/**
 * Redis 配置（data/redis.php），未安装或未启用时返回默认配置
 */
function redis_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'enabled'  => false,
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'password' => '',
        'database' => 0,
        'prefix'   => 'northmu:',
        'ttl'      => 3600,
    ];

    $file = app()->getRootPath() . 'data' . DIRECTORY_SEPARATOR . 'redis.php';
    if (is_file($file)) {
        try {
            $loaded = include $file;
            if (is_array($loaded)) {
                $config = array_merge($config, $loaded);
            }
        } catch (\Throwable $e) {
            // 配置损坏时按未启用处理
        }
    }

    $config['enabled'] = !empty($config['enabled']);
    $config['port'] = max(1, min(65535, (int) $config['port']));
    $config['database'] = max(0, min(15, (int) $config['database']));
    $config['ttl'] = max(60, min(86400, (int) $config['ttl']));
    $config['prefix'] = trim((string) $config['prefix']) ?: 'northmu:';
    if (!str_ends_with($config['prefix'], ':')) {
        $config['prefix'] .= ':';
    }

    return $config;
}

function redis_supported(): bool
{
    return extension_loaded('redis') || class_exists('\Predis\Client');
}

/**
 * 获取 Redis 缓存驱动；未启用或连接失败时返回 null，调用方应回退到数据库
 */
function redis_driver(): ?\think\cache\Driver
{
    static $driver = null;
    static $resolved = false;

    if ($resolved) {
        return $driver;
    }
    $resolved = true;

    $config = redis_config();
    if (!$config['enabled'] || !redis_supported()) {
        return null;
    }

    try {
        $driver = Cache::store('redis');
        $client = $driver->handler();
        // 旧版本使用 v1:/v2: 代际键，首次运行新逻辑时清理掉
        if ((string) $driver->get('cache_schema', '') !== '2') {
            $prefix = $config['prefix'];
            $legacy = [];
            foreach (redis_keys_by_pattern($client, $prefix . '*') as $key) {
                $short = substr((string) $key, strlen($prefix));
                if ($short === 'cache_version' || preg_match('/^v\d+:/', $short)) {
                    $legacy[] = $key;
                }
            }
            if ($legacy) {
                redis_delete_keys($client, $legacy);
            }
            $driver->set('cache_schema', '2', 0);
        }
    } catch (\Throwable $e) {
        $driver = null;
    }

    return $driver;
}

function redis_keys_by_pattern($client, string $pattern): array
{
    try {
        if ($client instanceof \Redis) {
            $keys = $client->keys($pattern);
        } else {
            $keys = $client->executeRaw(['KEYS', $pattern]);
        }
        return is_array($keys) ? array_values($keys) : [];
    } catch (\Throwable $e) {
        return [];
    }
}

function redis_delete_keys($client, array $keys): int
{
    $deleted = 0;
    foreach (array_chunk($keys, 200) as $chunk) {
        try {
            if ($client instanceof \Redis) {
                $deleted += (int) $client->del($chunk);
            } else {
                $deleted += (int) $client->executeRaw(array_merge(['DEL'], array_values($chunk)));
            }
        } catch (\Throwable $e) {
            // 忽略单个批次失败
        }
    }
    return $deleted;
}

/**
 * 缓存有效期跟随后台 Redis 配置
 */
function cache_ttl(): int
{
    return max(60, (int) redis_config()['ttl']);
}

function cache_remember(string $name, int $ttl, callable $resolver)
{
    $driver = redis_driver();
    if (!$driver) {
        return $resolver();
    }

    try {
        if ($driver->has($name)) {
            return $driver->get($name);
        }
    } catch (\Throwable $e) {
        return $resolver();
    }

    $value = $resolver();
    if ($value !== null) {
        cache_put($name, $value, $ttl);
    }
    return $value;
}

function cache_put(string $name, $value, int $ttl = 0): void
{
    $driver = redis_driver();
    if (!$driver || $value === null) {
        return;
    }
    try {
        $driver->set($name, $value, max(1, $ttl > 0 ? $ttl : cache_ttl()));
    } catch (\Throwable $e) {
        // 缓存写入失败不影响业务
    }
}

function cache_forget(string ...$names): int
{
    $driver = redis_driver();
    if (!$driver) {
        return 0;
    }
    $deleted = 0;
    foreach ($names as $name) {
        try {
            if ($driver->delete($name)) {
                $deleted++;
            }
        } catch (\Throwable $e) {
            // 单个键删除失败不影响其余缓存
        }
    }
    return $deleted;
}

/**
 * 按逻辑键前缀删除，如 site: / doc / admin:
 */
function cache_forget_prefix(string $prefix): int
{
    $driver = redis_driver();
    if (!$driver) {
        return 0;
    }
    try {
        $client = $driver->handler();
        $pattern = redis_config()['prefix'] . $prefix . '*';
        return redis_delete_keys($client, redis_keys_by_pattern($client, $pattern));
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * 清空本站全部缓存（不含同库其他应用）
 */
function cache_forget_all(): bool
{
    return redis_driver() !== null && cache_forget_prefix('') >= 0;
}

/**
 * 文档、分类、标签变更后失效前台文档缓存
 */
function cache_forget_content(): void
{
    cache_forget_prefix('doc');
    cache_forget_prefix('site:document_tags');
    cache_forget_prefix('admin:');
}

/**
 * 读取系统设置，首次调用时一次性载入全部设置项
 */
function sys_setting(?string $name = null, $default = null)
{
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        try {
            $loaded = cache_remember('site:settings', cache_ttl(), function () {
                $rows = [];
                foreach (Db::name('settings')->select() as $row) {
                    $rows[$row['name']] = $row['value'];
                }
                return $rows;
            });
            if (is_array($loaded)) {
                $settings = $loaded;
            }
        } catch (\Throwable $e) {
            $settings = [];
        }
    }
    if ($name === null) {
        return $settings;
    }
    return array_key_exists($name, $settings) ? $settings[$name] : $default;
}

/**
 * 前台公共分类（含继承后的有效可见性），带 Redis 缓存
 */
function site_categories(): array
{
    $rows = cache_remember('site:categories', cache_ttl(), function () {
        return Db::name('categories')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    });
    return is_array($rows) ? $rows : [];
}

/**
 * 文档与标签的关联映射，供前台列表统一取用
 */
function site_document_tags(): array
{
    $map = cache_remember('site:document_tags', cache_ttl(), function () {
        $rows = Db::name('document_tag')
            ->alias('dt')
            ->join('tags t', 't.id = dt.tag_id')
            ->field('dt.document_id, t.name')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['document_id']][] = (string) $row['name'];
        }
        return $map;
    });
    return is_array($map) ? $map : [];
}

/**
 * 前台导航项，未在后台配置时使用默认导航
 */
function site_nav_items(): array
{
    $stored = (string) sys_setting('site_nav_items', '');
    if ($stored !== '') {
        $decoded = json_decode($stored, true);
        if (is_array($decoded)) {
            $items = [];
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? ''));
                $url = trim((string) ($row['url'] ?? ''));
                if ($title === '' || $url === '' || mb_strlen($title) > 20) {
                    continue;
                }
                $items[] = [
                    'title'  => $title,
                    'url'    => $url,
                    'target' => (($row['target'] ?? '_self') === '_blank') ? '_blank' : '_self',
                ];
            }
            return array_slice($items, 0, 12);
        }
    }

    return [
        ['title' => '首页', 'url' => '/', 'target' => '_self'],
        ['title' => '浏览文档', 'url' => '/docs', 'target' => '_self'],
    ];
}

/**
 * 前台导航项并计算当前高亮
 */
function front_nav_items(): array
{
    $currentPath = '/' . ltrim((string) request()->pathinfo(), '/');
    $items = site_nav_items();
    foreach ($items as &$item) {
        $item['active'] = false;
        if (!str_starts_with($item['url'], '/')) {
            continue;
        }
        $path = parse_url($item['url'], PHP_URL_PATH);
        $path = is_string($path) ? $path : '';
        if ($path === '') {
            continue;
        }
        $norm = '/' . trim($path, '/');
        $item['active'] = $norm === '/'
            ? $currentPath === '/'
            : ($currentPath === $norm || str_starts_with($currentPath, $norm . '/'));
    }
    unset($item);
    return $items;
}

/**
 * 前台品牌与导航布局配置
 */
function site_front_config(): array
{
    $brandMode = (string) sys_setting('site_brand_mode', 'icon_text');
    $navStyle = (string) sys_setting('site_nav_style', 'top');
    return [
        'brandMode'  => in_array($brandMode, ['icon_text', 'image'], true) ? $brandMode : 'icon_text',
        'brandImage' => (string) sys_setting('site_brand_image', ''),
        'navStyle'   => in_array($navStyle, ['top', 'floating'], true) ? $navStyle : 'top',
        'navLogin'   => (string) sys_setting('site_nav_login', '1') === '1',
    ];
}

/**
 * 全局主题色预设
 */
function site_theme_presets(): array
{
    return [
        'blue'   => ['label' => '经典蓝', 'color' => '#066fd1'],
        'indigo' => ['label' => '靛青', 'color' => '#4f46e5'],
        'violet' => ['label' => '紫罗兰', 'color' => '#7c3aed'],
        'teal'   => ['label' => '青绿', 'color' => '#0d9488'],
        'orange' => ['label' => '琥珀橙', 'color' => '#ea580c'],
        'rose'   => ['label' => '玫瑰红', 'color' => '#e11d48'],
    ];
}

function site_theme_color(): string
{
    $key = (string) sys_setting('site_theme_color', 'blue');
    return array_key_exists($key, site_theme_presets()) ? $key : 'blue';
}

/**
 * 首页展示配置（内容、按钮、背景与模板）
 */
function site_home_config(): array
{
    $siteName = (string) sys_setting('site_name', 'Northmu Docs');
    $siteDesc = (string) sys_setting('site_desc', '个人文档与笔记管理系统');

    $template = (string) sys_setting('site_home_template', 'classic');
    if (!in_array($template, ['classic', 'split', 'minimal'], true)) {
        $template = 'classic';
    }

    $buttons = [];
    $buttonsRaw = (string) sys_setting('site_home_buttons', '');
    if ($buttonsRaw !== '') {
        $decoded = json_decode($buttonsRaw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $label = trim((string) ($row['label'] ?? ''));
                $url = trim((string) ($row['url'] ?? ''));
                if ($label === '' || $url === '') {
                    continue;
                }
                $buttons[] = [
                    'label'    => $label,
                    'url'      => $url,
                    'style'    => (($row['style'] ?? 'primary') === 'outline') ? 'outline' : 'primary',
                    'external' => (bool) preg_match('#^https?://#i', $url),
                ];
                if (count($buttons) >= 4) {
                    break;
                }
            }
        }
    } else {
        $buttons[] = ['label' => '浏览文档', 'url' => '/docs', 'style' => 'primary', 'external' => false];
    }

    $bgType = (string) sys_setting('site_home_bg_type', 'none');
    if (!in_array($bgType, ['none', 'image', 'video'], true)) {
        $bgType = 'none';
    }
    $bgImage = trim((string) sys_setting('site_home_bg_image', ''));
    $bgVideo = trim((string) sys_setting('site_home_bg_video', ''));
    if (($bgType === 'image' && $bgImage === '') || ($bgType === 'video' && $bgVideo === '')) {
        $bgType = 'none';
    }

    $overlay = max(0, min(95, (int) sys_setting('site_home_overlay', '45')));
    $badge = trim((string) sys_setting('site_home_badge', ''));
    $title = trim((string) sys_setting('site_home_title', ''));
    $intro = trim((string) sys_setting('site_home_intro', ''));
    $note = trim((string) sys_setting('site_home_note', ''));

    return [
        'template'     => $template,
        'badge'        => $badge !== '' ? $badge : $siteDesc,
        'title'        => $title !== '' ? $title : $siteName,
        'intro'        => $intro !== '' ? $intro : '这里沉淀我的项目文档与学习笔记，按分类整理、随时检索、持续更新。公开内容欢迎浏览，加密内容输入密码后即可阅读，私密内容仅管理员可见。',
        'note'         => $note !== '' ? $note : '公开 · 加密 · 私有，三种可见性按需设置',
        'buttons'      => $buttons,
        'bgType'       => $bgType,
        'bgImage'      => $bgImage,
        'bgVideo'      => $bgVideo,
        'overlay'      => $overlay,
        'overlayAlpha' => number_format($overlay / 100, 2, '.', ''),
        'defaults'     => [
            'badge' => $siteDesc,
            'title' => $siteName,
            'intro' => '这里沉淀我的项目文档与学习笔记，按分类整理、随时检索、持续更新。公开内容欢迎浏览，加密内容输入密码后即可阅读，私密内容仅管理员可见。',
            'note'  => '公开 · 加密 · 私有，三种可见性按需设置',
        ],
    ];
}


function save_setting(string $name, $value): void
{
    if (Db::name('settings')->where('name', $name)->find()) {
        Db::name('settings')->where('name', $name)->update(['value' => (string) $value]);
    } else {
        Db::name('settings')->insert(['name' => $name, 'value' => (string) $value]);
    }
}

/**
 * 校验图片/媒体地址：站内 /uploads 路径或 http(s) 在线链接
 * 返回空字符串表示未填写，null 表示格式不合法
 */
function normalize_media_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (mb_strlen($url) > 500) {
        return null;
    }
    if (str_starts_with($url, '/')) {
        if (str_starts_with($url, '//')) {
            return null;
        }
        return preg_match('#^/uploads/[A-Za-z0-9_./-]+$#', $url) ? $url : null;
    }
    if (!preg_match('#^https?://#i', $url)) {
        return null;
    }
    return preg_match('#^https?://[A-Za-z0-9\-._~:/?\#\[\]@!$&()*+,;=%]+$#i', $url) ? $url : null;
}

/**
 * 浏览器标签图标地址
 */
function site_favicon_url(): string
{
    $favicon = trim((string) sys_setting('site_favicon', ''));
    return $favicon !== '' ? $favicon : '/favicon.svg';
}

/**
 * 登录页外观配置
 */
function site_login_config(): array
{
    $title = trim((string) sys_setting('site_login_title', ''));
    $desc = trim((string) sys_setting('site_login_desc', ''));
    $overlay = max(0, min(95, (int) sys_setting('site_login_overlay', '45')));

    return [
        'title'        => $title !== '' ? $title : '登录后台',
        'desc'         => $desc !== '' ? $desc : '仅限超级管理员访问',
        'icon'         => trim((string) sys_setting('site_login_icon', '')),
        'bg'           => trim((string) sys_setting('site_login_bg', '')),
        'overlay'      => $overlay,
        'overlayAlpha' => number_format($overlay / 100, 2, '.', ''),
    ];
}

/**
 * 全局上传分类默认值：文件、图片、视频
 */
function upload_category_defaults(): array
{
    return [
        'file'  => [
            'label'  => '文件',
            'icon'   => 'file-text',
            'ext'    => 'pdf,zip,rar,7z,txt,md,doc,docx,xls,xlsx,ppt,pptx',
            'max_mb' => 10,
        ],
        'image' => [
            'label'  => '图片',
            'icon'   => 'photo',
            'ext'    => 'jpg,jpeg,png,gif,webp,ico',
            'max_mb' => 8,
        ],
        'video' => [
            'label'  => '视频',
            'icon'   => 'video',
            'ext'    => 'mp4,webm,ogg',
            'max_mb' => 30,
        ],
    ];
}

/**
 * 清洗扩展名列表：仅保留字母数字扩展名，剔除可执行与脚本类型
 */
function normalize_upload_extensions(string $input): string
{
    $parts = preg_split('/[,，\s]+/u', strtolower($input)) ?: [];
    $blocked = ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'htaccess', 'htpasswd', 'ini', 'js', 'html', 'htm', 'svg', 'cgi', 'pl', 'shtml'];
    $extensions = [];
    foreach ($parts as $part) {
        $part = trim($part, ". \t\n\r\0\x0B");
        if ($part === '' || !preg_match('/^[a-z0-9]{1,10}$/', $part) || in_array($part, $blocked, true)) {
            continue;
        }
        $extensions[$part] = true;
        if (count($extensions) >= 30) {
            break;
        }
    }
    return implode(',', array_keys($extensions));
}

/**
 * 当前生效的上传分类配置；旧版仅有 upload_ext / upload_max_mb 时自动兼容
 */
function upload_categories(): array
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $defaults = upload_category_defaults();
    $legacyRaw = sys_setting('upload_ext', null);
    $legacyMaxRaw = sys_setting('upload_max_mb', null);
    $legacyList = [];
    if ($legacyRaw !== null && $legacyRaw !== '') {
        $legacyList = array_values(array_filter(explode(',', strtolower((string) $legacyRaw))));
    }
    $imageCore = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $videoCore = ['mp4', 'webm', 'ogg'];
    $legacyMax = $legacyMaxRaw !== null ? max(1, min(500, (int) $legacyMaxRaw)) : null;

    $resolved = [];
    foreach ($defaults as $key => $default) {
        $storedExt = sys_setting('upload_' . $key . '_ext', null);
        if ($storedExt !== null) {
            $ext = normalize_upload_extensions((string) $storedExt);
        } elseif ($legacyList) {
            if ($key === 'image') {
                $ext = implode(',', array_values(array_intersect($legacyList, array_merge($imageCore, ['ico']))));
            } elseif ($key === 'video') {
                $ext = implode(',', array_values(array_intersect($legacyList, $videoCore)));
            } else {
                $ext = implode(',', array_values(array_diff($legacyList, array_merge($imageCore, $videoCore, ['ico']))));
            }
        } else {
            $ext = $default['ext'];
        }
        if ($ext === '') {
            $ext = $default['ext'];
        }

        $storedMax = sys_setting('upload_' . $key . '_max_mb', null);
        if ($storedMax !== null) {
            $maxMb = max(1, min(500, (int) $storedMax));
        } elseif ($key === 'video') {
            $maxMb = $default['max_mb'];
        } elseif ($legacyMax !== null) {
            $maxMb = $legacyMax;
        } else {
            $maxMb = $default['max_mb'];
        }

        $resolved[$key] = [
            'key'       => $key,
            'label'     => $default['label'],
            'icon'      => $default['icon'],
            'ext'       => $ext,
            'ext_list'  => array_values(array_filter(explode(',', $ext))),
            'max_mb'    => $maxMb,
            'max_bytes' => $maxMb * 1048576,
        ];
    }

    return $resolved;
}

/**
 * 按扩展名归类到图片、视频或文件，未允许时返回 null
 */
function upload_category_for_extension(string $extension): ?string
{
    $extension = strtolower(trim($extension));
    if ($extension === '') {
        return null;
    }
    $categories = upload_categories();
    foreach (['image', 'video'] as $key) {
        if (in_array($extension, $categories[$key]['ext_list'], true)) {
            return $key;
        }
    }
    return in_array($extension, $categories['file']['ext_list'], true) ? 'file' : null;
}

/**
 * 供后台脚本使用的上传限制数据
 */
function upload_limits_payload(): array
{
    $payload = [];
    foreach (upload_categories() as $key => $category) {
        $payload[$key] = [
            'label'  => $category['label'],
            'ext'    => $category['ext_list'],
            'maxMb'  => $category['max_mb'],
        ];
    }
    return $payload;
}

function ensure_uploads_protection(): void
{
    $dir = public_path() . 'uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar)$\">\n    Require all denied\n</FilesMatch>\n");
    }
}

function is_installed(): bool
{
    return is_file(app()->getRootPath() . 'data' . DIRECTORY_SEPARATOR . 'install.lock');
}

function csrf_token(): string
{
    $token = Session::get('csrf_token');
    if (!$token) {
        $token = bin2hex(random_bytes(32));
        Session::set('csrf_token', $token);
    }
    return $token;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function admin_logged_in(): bool
{
    return (bool) Session::get('admin_id');
}

function current_admin(): array
{
    return [
        'id'       => (int) Session::get('admin_id', 0),
        'username' => (string) Session::get('admin_username', ''),
    ];
}

/**
 * 当前管理员的完整资料（昵称、头像），同一请求内只查询一次
 */
function admin_profile(?int $adminId = null): array
{
    static $cache = [];
    $adminId = $adminId ?? (int) Session::get('admin_id', 0);
    if ($adminId <= 0) {
        return ['id' => 0, 'username' => '', 'nickname' => '', 'avatar' => '', 'display_name' => '管理员'];
    }
    if (!array_key_exists($adminId, $cache)) {
        $row = null;
        try {
            $row = Db::name('admins')->field('id,username,nickname,avatar')->where('id', $adminId)->find();
        } catch (\Throwable $e) {
            $row = null;
        }
        if (!$row) {
            $row = ['id' => $adminId, 'username' => (string) Session::get('admin_username', ''), 'nickname' => '', 'avatar' => ''];
        }
        $nickname = trim((string) ($row['nickname'] ?? ''));
        $displayName = $nickname !== '' ? $nickname : (string) $row['username'];
        $cache[$adminId] = [
            'id'           => (int) $row['id'],
            'username'     => (string) $row['username'],
            'nickname'     => $nickname,
            'avatar'       => (string) ($row['avatar'] ?? ''),
            'display_name' => $displayName,
            'initial'      => mb_strtoupper(mb_substr($displayName !== '' ? $displayName : '管', 0, 1)),
        ];
    }
    return $cache[$adminId];
}

/**
 * 站点作者（唯一超级管理员）的公开资料，供前台展示
 */
function site_author(): array
{
    static $author = null;
    if ($author !== null) {
        return $author;
    }
    try {
        $row = cache_remember('site:author', cache_ttl(), function () {
            return Db::name('admins')->field('id,username,nickname,avatar')->order('id', 'asc')->find() ?: null;
        });
    } catch (\Throwable $e) {
        $row = null;
    }
    if (!$row) {
        return $author = ['id' => 0, 'username' => '', 'nickname' => '', 'avatar' => '', 'display_name' => '管理员', 'initial' => '管'];
    }
    $nickname = trim((string) ($row['nickname'] ?? ''));
    $displayName = $nickname !== '' ? $nickname : (string) $row['username'];
    $author = [
        'id'           => (int) $row['id'],
        'username'     => (string) $row['username'],
        'nickname'     => $nickname,
        'avatar'       => (string) ($row['avatar'] ?? ''),
        'display_name' => $displayName,
        'initial'      => mb_strtoupper(mb_substr($displayName !== '' ? $displayName : '管', 0, 1)),
    ];
    return $author;
}

/**
 * 写入后台操作日志，失败不影响主流程
 */
function audit_log(
    string $module,
    string $action,
    string $description,
    string $targetType = '',
    int $targetId = 0,
    ?string $username = null,
    ?int $adminId = null
): void {
    try {
        $admin = current_admin();
        $adminId = $adminId ?? (int) $admin['id'];
        $username = $username ?? (string) $admin['username'];
        $request = request();
        Db::name('audit_logs')->insert([
            'admin_id'   => $adminId,
            'username'   => mb_substr($username, 0, 50),
            'module'     => mb_substr($module, 0, 30),
            'action'     => mb_substr($action, 0, 30),
            'description'=> mb_substr($description, 0, 500),
            'target_type'=> mb_substr($targetType, 0, 30),
            'target_id'  => max(0, $targetId),
            'ip'         => mb_substr((string) $request->ip(), 0, 45),
            'user_agent' => mb_substr((string) $request->header('user-agent', ''), 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        // 日志失败不应阻断业务
    }
}

function admin_flash(string $message, string $type = 'success'): void
{
    Session::set('admin_flash', ['message' => $message, 'type' => $type]);
}

function admin_flash_pull(): array
{
    $flash = Session::get('admin_flash');
    Session::delete('admin_flash');
    if (!is_array($flash) || empty($flash['message'])) {
        return ['message' => '', 'type' => 'success'];
    }
    return ['message' => (string) $flash['message'], 'type' => (string) ($flash['type'] ?? 'success')];
}

/**
 * 将扁平分类转为带深度的有序列表（用于下拉与树形表格）
 */
function flatten_categories(array $categories, int $parentId = 0, int $depth = 0): array
{
    $rows = [];
    foreach ($categories as $category) {
        if ((int) $category['parent_id'] === $parentId) {
            $category['depth'] = $depth;
            $rows[] = $category;
            $rows = array_merge($rows, flatten_categories($categories, (int) $category['id'], $depth + 1));
        }
    }
    return $rows;
}

function format_size(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * 将扁平列表组装为无限级树
 */
function build_tree(array $items, int $parentId = 0): array
{
    $tree = [];
    foreach ($items as $item) {
        if ((int) $item['parent_id'] === $parentId) {
            $children = build_tree($items, (int) $item['id']);
            if ($children) {
                $item['children'] = $children;
            }
            $tree[] = $item;
        }
    }
    return $tree;
}

function normalize_visibility($value): string
{
    $value = (string) $value;
    return in_array($value, ['public', 'encrypted', 'private'], true) ? $value : 'public';
}

function visibility_label(string $visibility): string
{
    return ['public' => '公开', 'encrypted' => '加密', 'private' => '私有'][normalize_visibility($visibility)] ?? '公开';
}

/**
 * 分类的实际可见性：父级为私有时整个子树私有；父级加密时子分类继承加密
 */
function category_effective_visibility(int $categoryId, ?array $categories = null): string
{
    if ($categoryId <= 0) {
        return 'public';
    }
    if ($categories === null) {
        $categories = Db::name('categories')->field('id,parent_id,visibility')->select()->toArray();
    }
    $map = [];
    foreach ($categories as $c) {
        $map[(int) $c['id']] = $c;
    }
    $visibility = 'public';
    $guard = 0;
    $current = $categoryId;
    while ($current > 0 && isset($map[$current]) && $guard < 100) {
        $currentVisibility = normalize_visibility($map[$current]['visibility'] ?? 'public');
        if ($currentVisibility === 'private') {
            return 'private';
        }
        if ($currentVisibility === 'encrypted') {
            $visibility = 'encrypted';
        }
        $current = (int) $map[$current]['parent_id'];
        $guard++;
    }
    return $visibility;
}

/**
 * 取分类链上最近的加密分类密码哈希
 */
function category_effective_password(int $categoryId, ?array $categories = null): string
{
    if ($categoryId <= 0) {
        return '';
    }
    if ($categories === null) {
        $categories = Db::name('categories')->field('id,parent_id,visibility,password_hash')->select()->toArray();
    }
    $map = [];
    foreach ($categories as $c) {
        $map[(int) $c['id']] = $c;
    }
    $guard = 0;
    $current = $categoryId;
    while ($current > 0 && isset($map[$current]) && $guard < 100) {
        if (normalize_visibility($map[$current]['visibility'] ?? 'public') === 'encrypted') {
            return (string) ($map[$current]['password_hash'] ?? '');
        }
        $current = (int) $map[$current]['parent_id'];
        $guard++;
    }
    return '';
}

/**
 * 文档的实际访问级别：分类与文档自身取最严格的一档
 */
function document_access(array $doc, ?array $categories = null): array
{
    $categoryId = (int) ($doc['category_id'] ?? 0);
    $categoryVisibility = category_effective_visibility($categoryId, $categories);
    $documentVisibility = normalize_visibility($doc['visibility'] ?? 'public');

    if ($categoryVisibility === 'private' || $documentVisibility === 'private') {
        return ['visibility' => 'private', 'password_hash' => ''];
    }
    if ($categoryVisibility === 'encrypted' || $documentVisibility === 'encrypted') {
        $hash = '';
        if ($documentVisibility === 'encrypted' && !empty($doc['password_hash'])) {
            $hash = (string) $doc['password_hash'];
        } else {
            $hash = category_effective_password($categoryId, $categories);
        }
        return ['visibility' => 'encrypted', 'password_hash' => $hash];
    }
    return ['visibility' => 'public', 'password_hash' => ''];
}

/**
 * 兼容旧代码：分类链是否完全公开
 */
function category_is_public(int $categoryId, array $categories = null): bool
{
    return category_effective_visibility($categoryId, $categories) === 'public';
}

/**
 * 分类下拉选项的层级标签（在原生 select 中保持缩进）
 */
function category_option_label(string $name, int $depth): string
{
    if ($depth <= 0) {
        return $name;
    }
    return str_repeat("\u{00A0}\u{00A0}\u{00A0}", $depth) . '└─ ' . $name;
}

/**
 * 分类面包屑（从根到当前）
 */
function category_breadcrumb(int $categoryId, array $categories): array
{
    $map = [];
    foreach ($categories as $c) {
        $map[(int) $c['id']] = $c;
    }
    $path = [];
    $guard = 0;
    $current = $categoryId;
    while ($current > 0 && isset($map[$current]) && $guard < 100) {
        array_unshift($path, $map[$current]);
        $current = (int) $map[$current]['parent_id'];
        $guard++;
    }
    return $path;
}

/**
 * 将 Markdown 渲染为 HTML
 */
function markdown_to_html(string $text): string
{
    $parser = new \Parsedown();
    $parser->setSafeMode(false);
    return $parser->text($text);
}

/**
 * 为正文图片追加原生懒加载属性
 */
function lazy_load_images(string $html): string
{
    if ($html === '' || stripos($html, '<img') === false) {
        return $html;
    }
    return preg_replace_callback('/<img\b[^>]*>/i', function ($matches) {
        $tag = $matches[0];
        if (stripos($tag, 'loading=') !== false) {
            return $tag;
        }
        if (substr($tag, -2) === '/>') {
            return substr($tag, 0, -2) . ' loading="lazy" decoding="async" />';
        }
        return substr($tag, 0, -1) . ' loading="lazy" decoding="async">';
    }, $html) ?? $html;
}

/**
 * 文档正文 HTML（生成后随文档一起缓存）
 */
function document_content_html(array $doc): string
{
    if (isset($doc['_content_html'])) {
        return (string) $doc['_content_html'];
    }
    $html = ($doc['content_type'] ?? 'html') === 'markdown'
        ? markdown_to_html((string) ($doc['content'] ?? ''))
        : (string) ($doc['content'] ?? '');
    return lazy_load_images($html);
}

/**
 * 文档纯文本摘要
 */
function document_excerpt(array $doc): string
{
    if (isset($doc['_excerpt'])) {
        return (string) $doc['_excerpt'];
    }
    $source = (string) ($doc['content'] ?? '');
    if (($doc['content_type'] ?? 'html') === 'markdown') {
        $source = markdown_to_html($source);
    }
    $plain = strip_tags($source);
    $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode($plain, ENT_QUOTES, 'UTF-8')));
    return $plain === '' ? '暂无摘要' : mb_substr($plain, 0, 140) . (mb_strlen($plain) > 140 ? '…' : '');
}

/**
 * 渲染公开分类树（无限级，仅传入已过滤的公开分类）
 */
function render_category_tree(array $tree, int $activeId = 0): string
{
    if (!$tree) {
        return '';
    }
    return '<ul class="cat-tree list-unstyled mb-0">' . render_category_nodes($tree, $activeId) . '</ul>';
}

function render_category_nodes(array $nodes, int $activeId): string
{
    $html = '';
    foreach ($nodes as $node) {
        $id = (int) $node['id'];
        $hasChildren = !empty($node['children']);
        $isActive = $id === $activeId;
        $html .= '<li class="cat-item">';
        $html .= '<div class="cat-row' . ($isActive ? ' active' : '') . '">';
        if ($hasChildren) {
            $html .= '<button type="button" class="cat-toggle" data-cat-toggle="' . $id . '" aria-label="展开或折叠分类"><i class="ti ti-chevron-down"></i></button>';
        } else {
            $html .= '<span class="cat-toggle cat-toggle-empty"></span>';
        }
        $visibility = (string) ($node['effective_visibility'] ?? $node['visibility'] ?? 'public');
        $lock = $visibility === 'encrypted' ? '<i class="ti ti-lock cat-lock" title="加密分类"></i>' : '';
        $html .= '<a class="cat-link' . ($isActive ? ' active' : '') . '" href="/docs?cat=' . $id . '">'
            . '<i class="ti ti-folder"></i><span class="text-truncate">' . e($node['name']) . '</span>' . $lock . '</a>';
        $html .= '</div>';
        if ($hasChildren) {
            $html .= '<ul class="cat-children list-unstyled" id="cat-children-' . $id . '">'
                . render_category_nodes($node['children'], $activeId) . '</ul>';
        }
        $html .= '</li>';
    }
    return $html;
}
