<?php

namespace app\controller\admin;

use think\facade\View;

class Service extends Base
{
    public function index()
    {
        $services = $this->registry();
        $view = $this->viewData('三方服务', '接入与管理第三方服务', 'services') + [
            'services'     => $services,
            'serviceCount' => count($services),
            'servicesJson' => json_encode($services, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ];
        $view['extraScripts'] = '<script src="/static/js/admin-services.js"></script>';

        return View::fetch('/services', $view);
    }

    public function save()
    {
        $id = trim((string) $this->request->param('id', ''));
        $services = $this->registry();
        $target = null;
        foreach ($services as $service) {
            if ($service['id'] === $id) {
                $target = $service;
                break;
            }
        }
        if ($target === null) {
            return $this->fail('服务不存在或已下线');
        }

        $enabled = (int) $this->request->param('enabled', 0) === 1;
        $config = $this->request->param('config', null);
        $config = is_array($config) ? $config : [];

        if ($id === 'baidu-seo') {
            $result = $this->saveBaiduSeo($config, $enabled);
        } elseif ($id === 'geetest') {
            $result = $this->saveGeetest($config, $enabled);
        } elseif ($id === 'ai-summary') {
            $result = $this->saveAiSummary($config, $enabled);
        } elseif ($id === 'qq-login') {
            $result = $this->saveQqLogin($config, $enabled);
        } elseif ($id === 'suyan') {
            $result = $this->saveSuyan($config, $enabled);
        } else {
            $result = $this->saveGeneric($target, $config, $enabled);
        }
        if (!empty($result['error'])) {
            return $this->fail($result['error']);
        }

        cache_forget('site:settings');
        cache_forget('site:sitemap');

        if (!empty($result['message'])) {
            $message = $result['message'];
        } elseif ($enabled && !$target['enabled']) {
            $message = '服务已启用';
        } elseif (!$enabled && $target['enabled']) {
            $message = '服务已停用，配置已保留';
        } else {
            $message = '服务配置已保存';
        }
        audit_log('service', 'save', ($enabled ? '启用' : '更新') . '三方服务「' . $target['name'] . '」');

        return $this->ok($message, ['service' => $result['snapshot']]);
    }

    protected function saveBaiduSeo(array $config, bool $enabled): array
    {
        $current = baidu_seo_config();
        $token = array_key_exists('baidu_token', $config)
            ? trim((string) $config['baidu_token'])
            : $current['token'];
        $common = array_key_exists('baidu_common_push', $config)
            ? (int) $config['baidu_common_push'] === 1
            : $current['common'];
        $quick = array_key_exists('baidu_quick_push', $config)
            ? (int) $config['baidu_quick_push'] === 1
            : $current['quick'];
        $auto = array_key_exists('baidu_auto_push', $config)
            ? (int) $config['baidu_auto_push'] === 1
            : $current['auto'];

        if ($token !== '' && !preg_match('/^[A-Za-z0-9_-]{6,100}$/', $token)) {
            return ['error' => '百度准入密钥 Token 格式不正确，请从百度搜索资源平台复制'];
        }
        if ($enabled) {
            if ($token === '') {
                return ['error' => '启用前请填写百度准入密钥 Token'];
            }
            if (!$common && !$quick) {
                return ['error' => '请至少开启普通收录或快速收录其中一项'];
            }
            if (site_base_url() === '') {
                return ['error' => '请先在「系统设置 → 站点与 SEO」中填写站点地址'];
            }
        }

        save_setting('baidu_token', $token);
        save_setting('baidu_common_push', $common ? '1' : '0');
        save_setting('baidu_quick_push', $quick ? '1' : '0');
        save_setting('baidu_auto_push', $auto ? '1' : '0');
        save_setting('service_enabled_baidu_seo', $enabled ? '1' : '0');

        $configured = $token !== '' && ($common || $quick) && site_base_url() !== '';

        return ['snapshot' => $this->statusSnapshot('baidu-seo', $enabled, $configured)];
    }

    protected function saveGeetest(array $config, bool $enabled): array
    {
        $current = geetest_config();
        $captchaId = array_key_exists('captcha_id', $config)
            ? trim((string) $config['captcha_id'])
            : $current['captcha_id'];
        $captchaKey = trim((string) ($config['captcha_key'] ?? ''));
        if ($captchaKey === '') {
            $captchaKey = $current['captcha_key'];
        }
        $product = (string) ($config['product'] ?? $current['product']);
        if (!in_array($product, ['float', 'popup'], true)) {
            $product = 'float';
        }

        if ($captchaId !== '' && !preg_match('/^[A-Za-z0-9_-]{8,80}$/', $captchaId)) {
            return ['error' => '验证 ID（Captcha ID）格式不正确，请从极验控制台复制'];
        }
        if ($captchaKey !== '' && !preg_match('/^[A-Za-z0-9_-]{8,80}$/', $captchaKey)) {
            return ['error' => '验证密钥（Captcha Key）格式不正确，请从极验控制台复制'];
        }
        if ($enabled) {
            if ($captchaId === '') {
                return ['error' => '启用前请填写验证 ID（Captcha ID）'];
            }
            if ($captchaKey === '') {
                return ['error' => '启用前请填写验证密钥（Captcha Key）'];
            }
        }

        save_setting('service_config_geetest', json_encode([
            'captcha_id' => $captchaId,
            'product'    => $product,
        ], JSON_UNESCAPED_UNICODE));
        save_setting('geetest_captcha_key', $captchaKey);
        save_setting('service_enabled_geetest', $enabled ? '1' : '0');

        return ['snapshot' => $this->statusSnapshot('geetest', $enabled, $captchaId !== '' && $captchaKey !== '')];
    }

    protected function saveAiSummary(array $config, bool $enabled): array
    {
        $current = ai_summary_config();
        $key = trim((string) ($config['key'] ?? ''));
        if ($key === '') {
            $key = $current['key'];
        }
        if ($key !== '' && !preg_match('/^[A-Za-z0-9_-]{6,120}$/', $key)) {
            return ['error' => 'API KEY 格式不正确，请从 PostChat 项目管理中复制'];
        }
        if ($enabled && $key === '') {
            return ['error' => '启用前请填写 AI 摘要 API KEY'];
        }

        $selector = array_key_exists('post_selector', $config) ? trim((string) $config['post_selector']) : $current['post_selector'];
        if ($selector === '') {
            $selector = '.article-body';
        }
        if (mb_strlen($selector) > 120) {
            return ['error' => '文章内容选择器不能超过 120 个字符'];
        }

        $injectDom = array_key_exists('inject_dom', $config) ? trim((string) $config['inject_dom']) : $current['inject_dom'];
        if (mb_strlen($injectDom) > 120) {
            return ['error' => '插入位置选择器不能超过 120 个字符'];
        }

        $postUrl = array_key_exists('post_url', $config) ? trim((string) $config['post_url']) : $current['post_url'];
        if (mb_strlen($postUrl) > 500) {
            return ['error' => 'URL 匹配规则不能超过 500 个字符'];
        }

        $blacklist = array_key_exists('blacklist', $config) ? trim((string) $config['blacklist']) : $current['blacklist'];
        if ($blacklist !== '' && (!filter_var($blacklist, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $blacklist))) {
            return ['error' => '黑名单 JSON 地址需以 http:// 或 https:// 开头'];
        }

        $wordLimit = array_key_exists('word_limit', $config) ? (int) $config['word_limit'] : $current['word_limit'];
        if ($wordLimit < 100 || $wordLimit > 5000) {
            return ['error' => '字数限制需在 100 到 5000 之间'];
        }

        $title = array_key_exists('title', $config) ? trim((string) $config['title']) : $current['title'];
        if ($title === '') {
            $title = '文章摘要';
        }
        if (mb_strlen($title) > 30) {
            return ['error' => '摘要标题不能超过 30 个字符'];
        }

        $name = array_key_exists('name', $config) ? trim((string) $config['name']) : $current['name'];
        if ($name === '') {
            $name = '洪墨AI';
        }
        if (mb_strlen($name) > 20) {
            return ['error' => '右上角标识不能超过 20 个字符'];
        }

        $beginningText = array_key_exists('beginning_text', $config) ? trim((string) $config['beginning_text']) : $current['beginning_text'];
        if (mb_strlen($beginningText) > 60) {
            return ['error' => '摘要开头文本不能超过 60 个字符'];
        }

        $theme = array_key_exists('theme', $config) ? (string) $config['theme'] : $current['theme'];
        if (!in_array($theme, ['default', 'simple', 'yanzhi', 'menghuan'], true)) {
            $theme = 'default';
        }

        $typingAnimate = array_key_exists('typing_animate', $config) ? (int) $config['typing_animate'] === 1 : $current['typing_animate'];
        $loadingText = array_key_exists('loading_text', $config) ? (int) $config['loading_text'] === 1 : $current['loading_text'];

        save_setting('service_config_ai_summary', json_encode([
            'post_selector'  => $selector,
            'post_url'       => $postUrl,
            'blacklist'      => $blacklist,
            'word_limit'     => $wordLimit,
            'typing_animate' => $typingAnimate ? '1' : '0',
            'title'          => $title,
            'name'           => $name,
            'inject_dom'     => $injectDom,
            'beginning_text' => $beginningText,
            'loading_text'   => $loadingText ? '1' : '0',
            'theme'          => $theme,
        ], JSON_UNESCAPED_UNICODE));
        save_setting('ai_summary_key', $key);
        save_setting('service_enabled_ai_summary', $enabled ? '1' : '0');

        return ['snapshot' => $this->statusSnapshot('ai-summary', $enabled, $key !== '')];
    }

    protected function saveQqLogin(array $config, bool $enabled): array
    {
        $current = qq_login_config();
        $appId = array_key_exists('app_id', $config) ? trim((string) $config['app_id']) : $current['app_id'];
        $appKey = trim((string) ($config['app_key'] ?? ''));
        if ($appKey === '') {
            $appKey = $current['app_key'];
        }

        if ($appId !== '' && !preg_match('/^\d{5,20}$/', $appId)) {
            return ['error' => 'AppID 格式不正确，应为 QQ互联 应用管理中的数字 ID'];
        }
        if ($appKey !== '' && !preg_match('/^[A-Za-z0-9]{16,64}$/', $appKey)) {
            return ['error' => 'AppKey 格式不正确，请从 QQ互联 应用管理中复制'];
        }
        if ($enabled) {
            if ($appId === '') {
                return ['error' => '启用前请填写 QQ互联应用 AppID'];
            }
            if ($appKey === '') {
                return ['error' => '启用前请填写 QQ互联应用 AppKey'];
            }
            if (site_base_url() === '') {
                return ['error' => '请先在「系统设置 → 站点与 SEO」中填写站点地址'];
            }
        }

        $changedApp = $current['app_id'] !== '' && $current['app_id'] !== $appId;
        save_setting('service_config_qq_login', json_encode(['app_id' => $appId], JSON_UNESCAPED_UNICODE));
        save_setting('qq_login_app_key', $appKey);
        save_setting('service_enabled_qq_login', $enabled ? '1' : '0');

        $bound = $current['binding'] !== null;
        $message = '';
        if ($changedApp && $bound) {
            save_setting('qq_login_binding', '');
            $bound = false;
            $message = 'AppID 已变更，原 QQ 绑定已解除，请重新绑定';
        }

        $snapshot = $this->qqStatusSnapshot($enabled, $appId !== '' && $appKey !== '', $bound);

        return $message !== ''
            ? ['snapshot' => $snapshot, 'message' => $message]
            : ['snapshot' => $snapshot];
    }

    protected function qqStatusSnapshot(bool $enabled, bool $configured, bool $bound): array
    {
        if (!$enabled) {
            $status = 'disabled';
            $label = '未启用';
        } elseif (!$configured) {
            $status = 'pending';
            $label = '待配置';
        } elseif (!$bound) {
            $status = 'pending';
            $label = '待绑定';
        } else {
            $status = 'running';
            $label = '运行中';
        }

        return [
            'id'          => 'qq-login',
            'enabled'     => $enabled,
            'configured'  => $configured,
            'status'      => $status,
            'statusLabel' => $label,
        ];
    }

    protected function saveSuyan(array $config, bool $enabled): array
    {
        $current = json_decode((string) sys_setting('service_config_suyan', '{}'), true);
        if (!is_array($current)) {
            $current = [];
        }

        $subKeys = ['tongyi3_enabled'];
        $next = [];
        foreach ($subKeys as $key) {
            if (array_key_exists($key, $config)) {
                $next[$key] = (string) $config[$key] === '1' ? '1' : '0';
            } else {
                $next[$key] = (string) ($current[$key] ?? '0') === '1' ? '1' : '0';
            }
        }

        save_setting('service_config_suyan', json_encode($next, JSON_UNESCAPED_UNICODE));
        save_setting('service_enabled_suyan', $enabled ? '1' : '0');

        $anySub = in_array('1', $next, true);
        $result = ['snapshot' => $this->suyanStatusSnapshot($enabled, $anySub)];
        if ($enabled && !$anySub) {
            $result['message'] = '素颜API 已启用，请开启至少一个子服务后聊天入口才会显示';
        }

        return $result;
    }

    protected function suyanStatusSnapshot(bool $enabled, bool $configured): array
    {
        if (!$enabled) {
            $status = 'disabled';
            $label = '未启用';
        } elseif (!$configured) {
            $status = 'pending';
            $label = '待开启子服务';
        } else {
            $status = 'running';
            $label = '运行中';
        }

        return [
            'id'          => 'suyan',
            'enabled'     => $enabled,
            'configured'  => $configured,
            'status'      => $status,
            'statusLabel' => $label,
        ];
    }

    protected function saveGeneric(array $service, array $config, bool $enabled): array
    {
        $key = $this->settingKey($service['id']);
        save_setting('service_enabled_' . $key, $enabled ? '1' : '0');

        if ($config) {
            $clean = [];
            foreach ($service['fields'] as $field) {
                $name = (string) $field['name'];
                if (!array_key_exists($name, $config)) {
                    continue;
                }
                if (($field['type'] ?? 'text') === 'checkbox') {
                    $clean[$name] = (int) $config[$name] === 1 ? '1' : '0';
                } else {
                    $clean[$name] = mb_substr(trim((string) $config[$name]), 0, 500);
                }
            }
            save_setting('service_config_' . $key, json_encode($clean, JSON_UNESCAPED_UNICODE));
        }

        return ['snapshot' => $this->statusSnapshot($service['id'], $enabled, true)];
    }

    protected function statusSnapshot(string $id, bool $enabled, bool $configured): array
    {
        $status = !$enabled ? 'disabled' : ($configured ? 'running' : 'pending');
        $labels = ['running' => '运行中', 'pending' => '待配置', 'disabled' => '未启用'];

        return [
            'id'          => $id,
            'enabled'     => $enabled,
            'configured'  => $configured,
            'status'      => $status,
            'statusLabel' => $labels[$status],
        ];
    }

    protected function settingKey(string $id): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($id)), '_');
    }

    /**
     * 三方服务注册表：接入新服务时在此追加条目，并在 fields 中声明配置项
     */
    protected function registry(): array
    {
        return [
            $this->baiduService(),
            $this->geetestService(),
            $this->aiSummaryService(),
            $this->suyanService(),
            $this->qqLoginService(),
        ];
    }

    protected function baiduService(): array
    {
        $config = baidu_seo_config();
        $status = !$config['enabled'] ? 'disabled' : ($config['configured'] ? 'running' : 'pending');
        $labels = ['running' => '运行中', 'pending' => '待配置', 'disabled' => '未启用'];

        return [
            'id'             => 'baidu-seo',
            'name'           => '百度收录',
            'tag'            => '搜索收录',
            'desc'           => '向百度搜索资源平台主动推送文档链接，加快新内容被收录。',
            'icon'           => 'https://www.baidu.com/favicon.ico',
            'enabled'        => $config['enabled'],
            'configured'     => $config['configured'],
            'requiresConfig' => true,
            'config'         => [
                'baidu_common_push' => $config['common'] ? '1' : '0',
                'baidu_quick_push'  => $config['quick'] ? '1' : '0',
                'baidu_auto_push'   => $config['auto'] ? '1' : '0',
                'baidu_token'       => $config['token'],
            ],
            'fields'         => [
                ['name' => 'baidu_common_push', 'label' => '普通收录', 'type' => 'checkbox', 'hint' => '常规推送通道，配额较高。'],
                ['name' => 'baidu_quick_push', 'label' => '快速收录', 'type' => 'checkbox', 'hint' => '配额较少，账号未开通时会返回错误，不影响普通收录。'],
                ['name' => 'baidu_auto_push', 'label' => '全站自动提交', 'type' => 'checkbox', 'hint' => '新建并发布公开文档时自动提交一次。'],
                ['name' => 'baidu_token', 'label' => '百度准入密钥 Token', 'type' => 'password', 'placeholder' => '粘贴百度搜索资源平台提供的 Token', 'hint' => 'Token 仅保存在站点数据库中，用于调用推送接口，不会出现在前台页面。'],
            ],
            'links'          => [
                ['label' => '开通指引', 'url' => 'https://ziyuan.baidu.com/college/courseinfo?id=267&page=2#h2_article_title12'],
                ['label' => '获取准入密钥', 'url' => 'https://zn.baidu.com/linksubmit'],
            ],
            'status'         => $status,
            'statusLabel'    => $labels[$status],
        ];
    }

    protected function geetestService(): array
    {
        $config = geetest_config();
        $status = !$config['enabled'] ? 'disabled' : ($config['configured'] ? 'running' : 'pending');
        $labels = ['running' => '运行中', 'pending' => '待配置', 'disabled' => '未启用'];

        return [
            'id'             => 'geetest',
            'name'           => '极验行为验证',
            'tag'            => '安全验证',
            'desc'           => '以滑块拼图等行为验证替代登录图形验证码，并在发布、删除文档时要求完成人机校验。',
            'icon'           => 'https://www.geetest.com/favicon.ico',
            'enabled'        => $config['enabled'],
            'configured'     => $config['configured'],
            'requiresConfig' => true,
            'config'         => [
                'captcha_id' => $config['captcha_id'],
                'product'    => $config['product'],
            ],
            'fields'         => [
                ['name' => 'captcha_id', 'label' => '验证 ID（Captcha ID）', 'type' => 'text', 'placeholder' => '从极验控制台复制 Captcha ID', 'hint' => '用于在页面中初始化验证组件，可公开使用。'],
                ['name' => 'captcha_key', 'label' => '验证密钥（Captcha Key）', 'type' => 'password', 'placeholder' => '留空则保持已保存的密钥', 'hint' => '仅保存在站点数据库中，用于服务端二次校验，不会输出到页面。'],
                ['name' => 'product', 'label' => '验证形式', 'type' => 'select', 'options' => [
                    ['value' => 'float', 'label' => '浮动式（推荐）'],
                    ['value' => 'popup', 'label' => '弹出式'],
                ], 'hint' => '浮动式在页面中央展示验证面板，弹出式适用于按钮触发的场景。'],
            ],
            'links'          => [
                ['label' => '快速接入', 'url' => 'https://docs.geetest.com/gt4/quick_integration_guide'],
                ['label' => 'API 文档', 'url' => 'https://docs.geetest.com/gt4/apirefer/api/web'],
                ['label' => '控制台', 'url' => 'https://console.geetest.com/'],
            ],
            'status'         => $status,
            'statusLabel'    => $labels[$status],
        ];
    }

    protected function aiSummaryService(): array
    {
        $config = ai_summary_config();
        $status = !$config['enabled'] ? 'disabled' : ($config['configured'] ? 'running' : 'pending');
        $labels = ['running' => '运行中', 'pending' => '待配置', 'disabled' => '未启用'];

        return [
            'id'             => 'ai-summary',
            'name'           => 'AI 摘要',
            'tag'            => '内容增强',
            'desc'           => '为公开文档生成 AI 摘要，展示在正文上方，帮助访客快速了解文章内容。',
            'icon'           => 'https://ai.zhheo.com/assets/PostChatCircle-Dk6e43Ra.webp',
            'enabled'        => $config['enabled'],
            'configured'     => $config['configured'],
            'requiresConfig' => true,
            'config'         => [
                'post_selector'  => $config['post_selector'],
                'post_url'       => $config['post_url'],
                'blacklist'      => $config['blacklist'],
                'word_limit'     => $config['word_limit'],
                'typing_animate' => $config['typing_animate'] ? '1' : '0',
                'title'          => $config['title'],
                'name'           => $config['name'],
                'inject_dom'     => $config['inject_dom'],
                'beginning_text' => $config['beginning_text'],
                'loading_text'   => $config['loading_text'] ? '1' : '0',
                'theme'          => $config['theme'],
            ],
            'fields'         => [
                ['name' => 'key', 'label' => 'API KEY', 'type' => 'password', 'placeholder' => '留空则保持已保存的密钥', 'hint' => '在 ai.zhheo.com 项目管理中创建项目后获取，并确保已绑定当前站点域名。密钥仅保存在站点数据库中。'],
                ['name' => 'theme', 'label' => '摘要主题', 'type' => 'select', 'options' => [
                    ['value' => 'default', 'label' => '默认'],
                    ['value' => 'simple', 'label' => '简约'],
                    ['value' => 'yanzhi', 'label' => '胭脂'],
                    ['value' => 'menghuan', 'label' => '梦幻'],
                ], 'hint' => '控制摘要卡片的配色与样式，自动跟随站点深色模式。'],
                ['name' => 'title', 'label' => '摘要标题', 'type' => 'text', 'placeholder' => '文章摘要', 'hint' => '显示在摘要卡片顶部的标题。'],
                ['name' => 'name', 'label' => '右上角标识', 'type' => 'text', 'placeholder' => '洪墨AI', 'hint' => '显示在摘要卡片右上角的标识文字，留空则使用官方默认「洪墨AI」。'],
                ['name' => 'beginning_text', 'label' => '摘要开头文本', 'type' => 'text', 'placeholder' => '这篇文章介绍了', 'hint' => '生成摘要前的固定引导语，留空则直接显示摘要正文。'],
                ['name' => 'word_limit', 'label' => '字数限制', 'type' => 'text', 'placeholder' => '1000', 'hint' => '100–5000 字符，按提交内容计费；修改后同一文章会重新生成摘要。'],
                ['name' => 'typing_animate', 'label' => '打字动画', 'type' => 'checkbox', 'hint' => '关闭后直接显示完整摘要文本。'],
                ['name' => 'loading_text', 'label' => '加载提示文本', 'type' => 'checkbox', 'hint' => '生成过程中显示「生成中…」提示。'],
                ['name' => 'post_selector', 'label' => '文章内容选择器', 'type' => 'text', 'placeholder' => '.article-body', 'hint' => '用于提取正文的 CSS 选择器，默认已适配本站文档正文。'],
                ['name' => 'inject_dom', 'label' => '插入位置', 'type' => 'text', 'placeholder' => '#docAiSummary', 'hint' => '摘要插入到该元素内部开头；留空则插入正文内容开头。'],
                ['name' => 'post_url', 'label' => 'URL 匹配规则', 'type' => 'text', 'placeholder' => '*', 'hint' => '支持 * 通配符或 /正则/ 形式。留空表示不限制（仅文档详情页会加载摘要）。'],
                ['name' => 'blacklist', 'label' => '黑名单 JSON 地址', 'type' => 'text', 'placeholder' => 'https://example.com/ai-blacklist.json', 'hint' => '可选。JSON 格式：{ "blackurls": ["https://example.com/somepath/*"] }。'],
            ],
            'links'          => [
                ['label' => '官网', 'url' => 'https://ai.zhheo.com/'],
                ['label' => '接入文档', 'url' => 'https://ai.zhheo.com/docs/summary.html'],
                ['label' => '配置文档', 'url' => 'https://ai.zhheo.com/docs/'],
            ],
            'status'         => $status,
            'statusLabel'    => $labels[$status],
        ];
    }

    protected function suyanService(): array
    {
        $config = suyan_config();
        $snapshot = $this->suyanStatusSnapshot($config['enabled'], $config['configured']);

        return [
            'id'             => 'suyan',
            'name'           => '素颜API',
            'tag'            => 'API服务',
            'desc'           => '接入 素颜API 服务能力',
            'icon'           => 'https://api.suyanw.cn/favicon.ico',
            'enabled'        => $config['enabled'],
            'configured'     => $config['configured'],
            'requiresConfig' => true,
            'config'         => [
                'tongyi3_enabled' => $config['subs']['tongyi3']['enabled'] ? '1' : '0',
            ],
            'fields'         => [],
            'sections'       => [
                [
                    'name'   => '通义3.0Ai',
                    'desc'   => '素颜API 提供的 AI 对话子服务，开启后站点右侧会显示聊天入口。',
                    'fields' => [
                        ['name' => 'tongyi3_enabled', 'label' => '启用通义3.0Ai 聊天', 'type' => 'checkbox'],
                    ],
                ],
            ],
            'links'          => [
                ['label' => '官网', 'url' => 'https://api.suyanw.cn/'],
            ],
            'status'         => $snapshot['status'],
            'statusLabel'    => $snapshot['statusLabel'],
        ];
    }

    protected function qqLoginService(): array
    {
        $config = qq_login_config();
        $snapshot = $this->qqStatusSnapshot($config['enabled'], $config['configured'], $config['bound']);

        return [
            'id'             => 'qq-login',
            'name'           => 'QQ 登录',
            'tag'            => '账号登录',
            'desc'           => '接入 QQ互联 快捷登录，绑定管理员 QQ 后可在登录页一键进入后台，减少密码暴露。',
            'icon'           => 'https://connect.qq.com/favicon.ico',
            'enabled'        => $config['enabled'],
            'configured'     => $config['configured'],
            'requiresConfig' => true,
            'config'         => [
                'app_id' => $config['app_id'],
            ],
            'fields'         => [
                ['name' => 'app_id', 'label' => '应用 AppID', 'type' => 'text', 'placeholder' => 'QQ互联 应用管理中的 APP ID', 'hint' => '数字形式的 APP ID，可在 QQ互联 应用管理中查看。'],
                ['name' => 'app_key', 'label' => '应用 AppKey', 'type' => 'password', 'placeholder' => '留空则保持已保存的 AppKey', 'hint' => '仅保存在站点数据库中，用于服务端换取访问令牌，不会输出到页面。'],
            ],
            'links'          => [
                ['label' => '官网', 'url' => 'https://connect.qq.com/'],
                ['label' => '接入流程', 'url' => 'https://wiki.connect.qq.com/%E7%BD%91%E7%AB%99%E5%BA%94%E7%94%A8%E6%8E%A5%E5%85%A5%E6%B5%81%E7%A8%8B/'],
                ['label' => '应用管理', 'url' => 'https://connect.qq.com/manage.html'],
            ],
            'status'         => $snapshot['status'],
            'statusLabel'    => $snapshot['statusLabel'],
        ];
    }
}
