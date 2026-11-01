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
    cache_forget('site:sitemap');
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
 * 清理 SEO 标题/描述文本
 */
function seo_clean_text(string $input, int $maxLength = 300): string
{
    $text = strip_tags($input);
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') {
        return '';
    }
    return mb_substr($text, 0, max(1, $maxLength));
}

/**
 * 规范化 SEO 关键词：统一分隔符并去重
 */
function seo_clean_keywords(string $input, int $maxLength = 255): string
{
    $input = strip_tags($input);
    $input = str_replace(['，', '、', '；', ';', '|', "\r", "\n", "\t"], ',', $input);
    $parts = preg_split('/,/', $input) ?: [];
    $keywords = [];
    foreach ($parts as $part) {
        $part = trim(preg_replace('/\s+/u', ' ', $part) ?? '');
        $part = mb_substr($part, 0, 50);
        if ($part === '' || in_array($part, $keywords, true)) {
            continue;
        }
        $keywords[] = $part;
        if (count($keywords) >= 20) {
            break;
        }
    }
    return mb_substr(implode(',', $keywords), 0, max(1, $maxLength));
}

/**
 * 校验站点地址：仅接受 http(s) 站点根地址
 */
function normalize_site_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (mb_strlen($url) > 255 || !preg_match('#^https?://#i', $url)) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return null;
    }
    $path = (string) ($parts['path'] ?? '');
    if ($path !== '' && $path !== '/') {
        return null;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? 'https')) === 'http' ? 'http' : 'https';
    $host = strtolower((string) $parts['host']);
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    return $scheme . '://' . $host . $port;
}

/**
 * 站点根地址：优先使用后台配置，未配置时按当前请求推断
 */
function site_base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = normalize_site_url((string) sys_setting('site_url', ''));
    if (is_string($configured) && $configured !== '') {
        return $base = $configured;
    }
    try {
        $base = rtrim((string) request()->domain(), '/');
    } catch (\Throwable $e) {
        $base = '';
    }
    return $base;
}

function site_absolute_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    $base = site_base_url();
    if ($base === '') {
        return $url;
    }
    return $base . '/' . ltrim($url, '/');
}

/**
 * 全站 SEO 配置
 */
function seo_global_config(): array
{
    $siteName = (string) sys_setting('site_name', 'Northmu Docs');
    $title = seo_clean_text((string) sys_setting('site_seo_title', ''), 120);
    $keywords = seo_clean_keywords((string) sys_setting('site_seo_keywords', ''));
    $description = seo_clean_text((string) sys_setting('site_seo_description', ''), 300);
    $siteDesc = seo_clean_text((string) sys_setting('site_desc', ''), 300);
    if ($title === '') {
        $title = $siteName;
    }
    if ($description === '') {
        $description = $siteDesc;
    }
    return [
        'site_name'   => $siteName,
        'title'       => $title,
        'keywords'    => $keywords,
        'description' => $description,
        'url'         => site_base_url(),
        'logo'        => site_absolute_url((string) sys_setting('site_logo', '')),
    ];
}

function seo_iso_time($value): string
{
    if (empty($value)) {
        return '';
    }
    $timestamp = strtotime((string) $value);
    return $timestamp ? date('c', $timestamp) : '';
}

function seo_json_encode(array $data): string
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    return is_string($json) ? $json : '';
}

/**
 * 生成前台 head 所需的 SEO 变量
 */
function seo_meta(array $options = []): array
{
    $global = seo_global_config();
    $title = trim((string) ($options['title'] ?? ''));
    $description = trim((string) ($options['description'] ?? ''));
    $keywords = trim((string) ($options['keywords'] ?? ''));
    $canonical = trim((string) ($options['canonical'] ?? ''));
    $robots = (string) ($options['robots'] ?? 'index,follow');
    $type = ($options['type'] ?? 'website') === 'article' ? 'article' : 'website';
    $image = site_absolute_url((string) ($options['image'] ?? ''));
    $jsonLd = (string) ($options['json_ld'] ?? '');

    if ($title === '') {
        $title = $global['title'];
    }
    if ($description === '') {
        $description = $global['description'];
    }
    if ($keywords === '') {
        $keywords = $global['keywords'];
    }
    if ($image === '') {
        $image = $global['logo'];
    }
    if (!preg_match('/^[a-z]+(?:,[a-z]+)?$/', $robots)) {
        $robots = 'index,follow';
    }

    return [
        'seoTitle'       => $title,
        'seoKeywords'    => $keywords,
        'seoDescription' => mb_substr($description, 0, 300),
        'seoCanonical'   => $canonical,
        'seoRobots'      => $robots,
        'seoType'        => $type,
        'seoImage'       => $image,
        'seoSiteName'    => $global['site_name'],
        'seoLocale'      => 'zh_CN',
        'seoPublished'   => (string) ($options['published'] ?? ''),
        'seoModified'    => (string) ($options['modified'] ?? ''),
        'seoJsonLd'      => $jsonLd,
    ];
}

function document_url(int $id, bool $absolute = false): string
{
    $path = '/doc/' . max(0, $id) . '.html';
    return $absolute ? site_base_url() . $path : $path;
}

/**
 * 文档自动 SEO 默认值：标题、标签/分类关键词与正文摘要
 */
function document_seo_defaults(array $doc, array $tagNames = [], string $categoryName = ''): array
{
    $global = seo_global_config();
    $title = seo_clean_text((string) ($doc['title'] ?? ''), 200);
    $autoTitle = $title === ''
        ? $global['title']
        : ($global['title'] !== '' ? $title . ' - ' . $global['title'] : $title);

    $keywords = [];
    foreach ($tagNames as $tag) {
        $tag = seo_clean_text((string) $tag, 50);
        if ($tag !== '' && !in_array($tag, $keywords, true)) {
            $keywords[] = $tag;
        }
        if (count($keywords) >= 10) {
            break;
        }
    }
    $categoryName = seo_clean_text($categoryName, 50);
    if ($categoryName !== '' && $categoryName !== '未分类' && !in_array($categoryName, $keywords, true)) {
        $keywords[] = $categoryName;
    }
    $autoKeywords = $keywords ? implode(',', $keywords) : $global['keywords'];
    if ($autoKeywords === '') {
        $autoKeywords = $global['title'];
    }

    $excerpt = document_excerpt($doc);
    if ($excerpt === '暂无摘要') {
        $excerpt = $global['description'];
    }

    return [
        'title'       => $autoTitle,
        'keywords'    => seo_clean_keywords($autoKeywords),
        'description' => seo_clean_text($excerpt, 160),
    ];
}

function document_first_image(string $html): string
{
    if ($html === '') {
        return '';
    }
    if (preg_match('/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $matches)) {
        return trim($matches[1]);
    }
    if (preg_match('/!\[[^\]]*\]\(([^)\s]+)/', $html, $matches)) {
        return trim($matches[1]);
    }
    return '';
}

function seo_website_json_ld(): array
{
    $global = seo_global_config();
    $data = [
        '@context'    => 'https://schema.org',
        '@type'       => 'WebSite',
        'name'        => $global['site_name'],
        'url'         => $global['url'] !== '' ? $global['url'] . '/' : '',
        'description' => $global['description'],
        'inLanguage'  => 'zh-CN',
    ];
    if ($global['url'] !== '') {
        $data['potentialAction'] = [
            '@type'       => 'SearchAction',
            'target'      => $global['url'] . '/docs/search?q={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ];
    }
    return $data;
}

function seo_article_json_ld(array $doc, string $canonical, string $description, string $image = ''): array
{
    $global = seo_global_config();
    $author = site_author();
    $published = seo_iso_time($doc['published_at'] ?? ($doc['created_at'] ?? ''));
    $modified = seo_iso_time($doc['updated_at'] ?? '');
    $image = site_absolute_url($image);
    if ($image === '') {
        $image = $global['logo'];
    }

    $data = [
        '@context'         => 'https://schema.org',
        '@type'            => 'Article',
        'headline'         => seo_clean_text((string) ($doc['title'] ?? ''), 200),
        'description'      => $description,
        'url'              => $canonical,
        'mainEntityOfPage' => $canonical,
        'datePublished'    => $published,
        'dateModified'     => $modified,
        'inLanguage'       => 'zh-CN',
        'author'           => [
            '@type' => 'Person',
            'name'  => (string) ($author['display_name'] ?? '管理员'),
        ],
        'publisher'        => [
            '@type' => 'Organization',
            'name'  => $global['site_name'],
        ],
    ];
    if ($image !== '') {
        $data['image'] = $image;
        $data['publisher']['logo'] = ['@type' => 'ImageObject', 'url' => $image];
    }
    if ($published === '') {
        unset($data['datePublished']);
    }
    if ($modified === '') {
        unset($data['dateModified']);
    }
    return $data;
}

/**
 * 文档页 SEO：手动字段优先，留空时使用自动生成值
 */
function document_seo_meta(
    array $doc,
    array $tagNames,
    string $categoryName,
    string $canonical,
    string $robots = 'index,follow'
): array {
    $global = seo_global_config();
    $defaults = document_seo_defaults($doc, $tagNames, $categoryName);
    $customTitle = seo_clean_text((string) ($doc['seo_title'] ?? ''), 120);
    $customKeywords = seo_clean_keywords((string) ($doc['seo_keywords'] ?? ''));
    $customDescription = seo_clean_text((string) ($doc['seo_description'] ?? ''), 300);

    $pageTitle = $customTitle !== '' ? $customTitle : $defaults['title'];
    $keywords = $customKeywords !== '' ? $customKeywords : $defaults['keywords'];
    $description = $customDescription !== '' ? $customDescription : $defaults['description'];
    if ($description === '') {
        $description = $global['description'];
    }

    $image = document_first_image(document_content_html($doc));
    $jsonLd = '';
    if (str_starts_with($robots, 'index') && $canonical !== '') {
        $jsonLd = seo_json_encode(seo_article_json_ld($doc, $canonical, $description, $image));
    }

    $meta = seo_meta([
        'title'       => $pageTitle,
        'description' => $description,
        'keywords'    => $keywords,
        'canonical'   => $canonical,
        'robots'      => $robots,
        'type'        => 'article',
        'image'       => $image,
        'published'   => seo_iso_time($doc['published_at'] ?? ($doc['created_at'] ?? '')),
        'modified'    => seo_iso_time($doc['updated_at'] ?? ''),
        'json_ld'     => $jsonLd,
    ]);
    $meta['pageTitle'] = $pageTitle;
    return $meta;
}

/**
 * 百度收录配置
 */
function baidu_seo_config(): array
{
    $token = trim((string) sys_setting('baidu_token', ''));
    $common = (string) sys_setting('baidu_common_push', '1') === '1';
    $quick = (string) sys_setting('baidu_quick_push', '0') === '1';
    return [
        'auto'       => (string) sys_setting('baidu_auto_push', '0') === '1',
        'common'     => $common,
        'quick'      => $quick,
        'token'      => $token,
        'site'       => site_base_url(),
        'configured' => $token !== '' && ($common || $quick) && site_base_url() !== '',
    ];
}

/**
 * 调用百度主动推送接口；普通与快速收录共用同一接口，快速收录附加 type=quick
 */
function baidu_submit_urls(array $urls, string $type = 'common'): array
{
    $config = baidu_seo_config();
    $result = [
        'ok'      => false,
        'message' => '',
        'success' => null,
        'remain'  => null,
        'http'    => 0,
        'at'      => date('Y-m-d H:i:s'),
    ];
    if ($config['token'] === '') {
        $result['message'] = '未配置百度准入密钥 Token';
        return $result;
    }
    if ($config['site'] === '') {
        $result['message'] = '未配置站点地址';
        return $result;
    }
    $urls = array_values(array_unique(array_filter(array_map('trim', $urls), function ($url) {
        return is_string($url) && $url !== '';
    })));
    if (!$urls) {
        $result['message'] = '没有可提交的链接';
        return $result;
    }

    $type = $type === 'quick' ? 'quick' : 'common';
    $endpoint = 'https://data.zz.baidu.com/urls?site=' . rawurlencode($config['site'])
        . '&token=' . rawurlencode($config['token']);
    if ($type === 'quick') {
        $endpoint .= '&type=quick';
    }
    $payload = implode("\n", array_slice($urls, 0, 2000));
    $body = '';
    $httpCode = 0;
    $caFile = dirname(__DIR__) . '/extend/certs/cacert.pem';
    if (!is_file($caFile)) {
        $iniCa = (string) ini_get('curl.cainfo');
        $caFile = $iniCa !== '' && is_file($iniCa) ? $iniCa : '';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: text/plain; charset=utf-8'],
            CURLOPT_USERAGENT      => 'NorthmuDocs/1.4.2',
            CURLOPT_SSL_VERIFYPEER => true,
            // 百度 data.zz.baidu.com 证书仅覆盖 *.baidu.com，无法通过主机名校验；官方接口使用 http，这里保留 TLS 加密与证书链校验。
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        if ($caFile !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $caFile);
        }
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            $result['message'] = '网络请求失败：' . ($error !== '' ? $error : '未知错误');
            return $result;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: text/plain; charset=utf-8\r\nUser-Agent: NorthmuDocs/1.4.2",
                'content'       => $payload,
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => false,
            ] + ($caFile !== '' ? ['cafile' => $caFile] : []),
        ]);
        $body = @file_get_contents($endpoint, false, $context);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $httpCode = (int) $matches[1];
        }
        if ($body === false) {
            $result['message'] = '网络请求失败，请检查服务器网络与 OpenSSL 扩展';
            return $result;
        }
    }

    $result['http'] = $httpCode;
    $body = trim((string) $body);
    $json = json_decode($body, true);
    if (is_array($json)) {
        if (!empty($json['error'])) {
            $result['message'] = '错误 ' . $json['error'] . '：' . (string) ($json['message'] ?? '未知错误');
            return $result;
        }
        $result['ok'] = $httpCode >= 200 && $httpCode < 300;
        $result['success'] = isset($json['success']) ? (int) $json['success'] : null;
        $result['remain'] = isset($json['remain']) ? (int) $json['remain'] : null;
        $parts = [];
        if ($result['success'] !== null) {
            $parts[] = '成功提交 ' . $result['success'] . ' 条';
        }
        if ($result['remain'] !== null) {
            $parts[] = '今日剩余 ' . $result['remain'] . ' 条';
        }
        if (!empty($json['not_same_site']) && is_array($json['not_same_site'])) {
            $parts[] = '非本站 ' . count($json['not_same_site']) . ' 条';
        }
        if (!empty($json['not_valid']) && is_array($json['not_valid'])) {
            $parts[] = '无效 ' . count($json['not_valid']) . ' 条';
        }
        $result['message'] = $parts ? implode('，', $parts) : ($result['ok'] ? '提交成功' : '提交失败');
        return $result;
    }

    $snippet = mb_substr(preg_replace('/\s+/u', ' ', $body) ?? '', 0, 120);
    $result['message'] = $httpCode > 0
        ? 'HTTP ' . $httpCode . ($snippet !== '' ? '：' . $snippet : '')
        : ($snippet !== '' ? $snippet : '百度接口返回内容无法解析');
    return $result;
}

/**
 * 按启用通道推送单篇文档，返回可写入 documents.baidu_push 的结果
 */
function baidu_push_document(array $doc, string $trigger = 'manual'): array
{
    $config = baidu_seo_config();
    $url = document_url((int) ($doc['id'] ?? 0), true);
    $channels = [];
    if ($config['common']) {
        $channels['common'] = baidu_submit_urls([$url], 'common');
    }
    if ($config['quick']) {
        $channels['quick'] = baidu_submit_urls([$url], 'quick');
    }
    if (!$channels) {
        return ['ok' => false, 'message' => '请先启用百度普通收录或快速收录', 'payload' => null];
    }

    $ok = false;
    $messages = [];
    foreach ($channels as $channel => $result) {
        if (!empty($result['ok'])) {
            $ok = true;
        }
        $label = $channel === 'quick' ? '快速收录' : '普通收录';
        $messages[] = $label . '：' . (string) ($result['message'] ?? '');
    }

    return [
        'ok'      => $ok,
        'message' => implode('；', $messages),
        'payload' => [
            'trigger'  => $trigger === 'auto' ? 'auto' : 'manual',
            'url'      => $url,
            'at'       => date('Y-m-d H:i:s'),
            'channels' => $channels,
        ],
    ];
}

function baidu_push_store_json(array $payload): string
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    return is_string($json) ? $json : '';
}

function baidu_push_status(?string $raw): array
{
    $status = ['at' => '', 'trigger' => '', 'url' => '', 'channels' => []];
    if ($raw === null || trim($raw) === '') {
        return $status;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $status;
    }
    $status['at'] = (string) ($decoded['at'] ?? '');
    $status['trigger'] = (string) ($decoded['trigger'] ?? '');
    $status['url'] = (string) ($decoded['url'] ?? '');
    foreach (['common', 'quick'] as $key) {
        if (isset($decoded['channels'][$key]) && is_array($decoded['channels'][$key])) {
            $row = $decoded['channels'][$key];
            $status['channels'][$key] = [
                'ok'      => !empty($row['ok']),
                'message' => (string) ($row['message'] ?? ''),
                'at'      => (string) ($row['at'] ?? ''),
                'success' => isset($row['success']) ? (int) $row['success'] : null,
                'remain'  => isset($row['remain']) ? (int) $row['remain'] : null,
            ];
        }
    }
    return $status;
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
