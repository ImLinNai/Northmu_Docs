<?php

namespace app\controller;

use app\BaseController;
use PDO;
use PDOException;
use think\facade\View;

class Install extends BaseController
{
    protected string $stage = 'validate';

    public function index()
    {
        if (is_installed()) {
            return redirect('/');
        }

        $checks = $this->environmentChecks();
        return View::fetch('install/index', [
            'checks'    => $checks,
            'canInstall'=> $this->checksPassed($checks),
            'old'       => $this->defaults(),
        ]);
    }

    public function check()
    {
        if (is_installed()) {
            return json(['code' => 1, 'msg' => '系统已完成安装'], 403);
        }
        $checks = $this->environmentChecks();
        return json([
            'code' => 0,
            'msg'  => '环境检测完成',
            'data' => [
                'checks'     => $checks,
                'canInstall' => $this->checksPassed($checks),
            ],
        ]);
    }

    public function testDatabase()
    {
        if (is_installed()) {
            return json(['code' => 1, 'msg' => '系统已完成安装'], 403);
        }
        try {
            $db = $this->databaseOptions($this->request->post());
            $info = $this->probeDatabase($db);
            return json(['code' => 0, 'msg' => $info['message'], 'data' => $info]);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    public function testRedis()
    {
        if (is_installed()) {
            return json(['code' => 1, 'msg' => '系统已完成安装'], 403);
        }
        try {
            $config = $this->redisOptions($this->request->post(), true);
            $info = $this->probeRedis($config);
            return json(['code' => 0, 'msg' => $info['message'], 'data' => $info]);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    public function deploy()
    {
        if (is_installed()) {
            return json(['code' => 1, 'msg' => '系统已完成安装'], 403);
        }
        try {
            $checks = $this->environmentChecks();
            if (!$this->checksPassed($checks)) {
                $labels = [];
                foreach ($checks as $check) {
                    if ($check['required'] && !$check['ok']) {
                        $labels[] = $check['label'];
                    }
                }
                throw new \RuntimeException('环境检测未通过：' . implode('、', $labels));
            }
            $summary = $this->install($this->request->post());
            return json(['code' => 0, 'msg' => '部署完成', 'data' => $summary]);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage(), 'stage' => $this->stage]);
        }
    }

    protected function defaults(): array
    {
        return [
            'db_host'   => '127.0.0.1',
            'db_port'   => '3306',
            'db_name'   => 'northmu_docs',
            'db_user'   => 'root',
            'db_pass'   => '',
            'site_name' => 'Northmu Docs',
            'admin_user'=> '',
            'redis_enable'   => '0',
            'redis_host'     => '127.0.0.1',
            'redis_port'     => '6379',
            'redis_password' => '',
            'redis_database' => '0',
            'redis_prefix'   => 'northmu:',
            'redis_ttl'      => '3600',
        ];
    }

    protected function checksPassed(array $checks): bool
    {
        foreach ($checks as $check) {
            if (!empty($check['required']) && empty($check['ok'])) {
                return false;
            }
        }
        return true;
    }

    protected function databaseOptions(array $data): array
    {
        $host = trim((string) ($data['db_host'] ?? ''));
        $port = trim((string) ($data['db_port'] ?? '3306'));
        $name = trim((string) ($data['db_name'] ?? ''));
        $user = trim((string) ($data['db_user'] ?? ''));
        $pass = (string) ($data['db_pass'] ?? '');

        if ($host === '' || !preg_match('/^[A-Za-z0-9._:\-]{1,120}$/', $host)) {
            throw new \RuntimeException('数据库地址格式不正确');
        }
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new \RuntimeException('数据库端口不正确');
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new \RuntimeException('数据库名只能包含字母、数字和下划线');
        }
        if ($user === '' || mb_strlen($user) > 64) {
            throw new \RuntimeException('请填写数据库用户名（不超过 64 个字符）');
        }
        if (mb_strlen($pass) > 255) {
            throw new \RuntimeException('数据库密码过长');
        }

        return [
            'host' => $host,
            'port' => (int) $port,
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
        ];
    }

    protected function accountOptions(array $data): array
    {
        $siteName = trim((string) ($data['site_name'] ?? ''));
        $adminUser = trim((string) ($data['admin_user'] ?? ''));
        $adminPass = (string) ($data['admin_pass'] ?? '');
        $adminPass2 = (string) ($data['admin_pass2'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $adminUser)) {
            throw new \RuntimeException('管理员账号需为 3-30 位字母、数字或下划线');
        }
        if (strlen($adminPass) < 6 || strlen($adminPass) > 64) {
            throw new \RuntimeException('管理员密码长度需为 6-64 位');
        }
        if ($adminPass !== $adminPass2) {
            throw new \RuntimeException('两次输入的管理员密码不一致');
        }
        if ($siteName === '') {
            $siteName = 'Northmu Docs';
        }
        if (mb_strlen($siteName) > 50) {
            throw new \RuntimeException('站点名称不能超过 50 个字符');
        }

        return [
            'site_name'  => $siteName,
            'admin_user' => $adminUser,
            'admin_pass' => $adminPass,
        ];
    }

    protected function databaseErrorMessage(PDOException $e): string
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        if ($driverCode === 2002 || $driverCode === 2003) {
            return '无法连接数据库，请检查地址、端口与服务状态';
        }
        if ($driverCode === 1045) {
            return '数据库用户名或密码错误';
        }
        if ($driverCode === 1044) {
            return '数据库用户无权访问该数据库';
        }
        if ($driverCode === 1049) {
            return '数据库不存在';
        }
        return '数据库连接失败：' . $e->getMessage();
    }

    protected function probeDatabase(array $db): array
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ];
        $baseDsn = "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4";

        try {
            $pdo = new PDO($baseDsn . ";dbname={$db['name']}", $db['user'], $db['pass'], $options);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1049) {
                try {
                    $server = new PDO($baseDsn, $db['user'], $db['pass'], $options);
                    $version = (string) $server->query('SELECT VERSION()')->fetchColumn();
                } catch (PDOException $inner) {
                    throw new \RuntimeException($this->databaseErrorMessage($inner));
                }
                return [
                    'server_version'   => $version,
                    'database_exists'  => false,
                    'tables'           => 0,
                    'message'          => '连接成功（MySQL ' . $version . '），数据库「' . $db['name'] . '」不存在，部署时将自动创建',
                ];
            }
            throw new \RuntimeException($this->databaseErrorMessage($e));
        }

        try {
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $tables = (int) $pdo->query(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . $pdo->quote($db['name'])
            )->fetchColumn();
        } catch (PDOException $e) {
            throw new \RuntimeException($this->databaseErrorMessage($e));
        }

        $message = '数据库连接成功（MySQL ' . $version . '）';
        if ($tables > 0) {
            $message .= '，当前库已有 ' . $tables . ' 张表，请确认不是已安装的站点';
        }
        return [
            'server_version'  => $version,
            'database_exists' => true,
            'tables'          => $tables,
            'message'         => $message,
        ];
    }

    protected function environmentChecks(): array
    {
        $root = root_path();
        $dataDir = $root . 'data' . DIRECTORY_SEPARATOR;
        $uploadDir = $root . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;

        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0755, true);
        }
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $phpOk = PHP_VERSION_ID >= 80100;

        return [
            ['label' => 'PHP 版本', 'value' => PHP_VERSION, 'ok' => $phpOk, 'required' => true, 'tip' => '需要 8.1 及以上'],
            ['label' => 'PDO MySQL 扩展', 'value' => extension_loaded('pdo_mysql') ? '已安装' : '未安装', 'ok' => extension_loaded('pdo_mysql'), 'required' => true, 'tip' => '用于连接 MySQL'],
            ['label' => 'mbstring 扩展', 'value' => extension_loaded('mbstring') ? '已安装' : '未安装', 'ok' => extension_loaded('mbstring'), 'required' => true, 'tip' => '用于多字节字符串处理'],
            ['label' => 'GD 扩展', 'value' => extension_loaded('gd') ? '已安装' : '未安装', 'ok' => extension_loaded('gd'), 'required' => true, 'tip' => '用于登录验证码'],
            ['label' => 'Zip 扩展', 'value' => extension_loaded('zip') ? '已安装' : '未安装', 'ok' => extension_loaded('zip'), 'required' => true, 'tip' => '用于数据备份打包'],
            ['label' => 'fileinfo 扩展', 'value' => extension_loaded('fileinfo') ? '已安装' : '未安装', 'ok' => extension_loaded('fileinfo'), 'required' => true, 'tip' => '用于附件类型识别'],
            ['label' => '根目录写入', 'value' => is_writable($root) ? '可写' : '不可写', 'ok' => is_writable($root), 'required' => true, 'tip' => '用于生成 .env'],
            ['label' => 'runtime 目录', 'value' => is_writable(runtime_path()) ? '可写' : '不可写', 'ok' => is_writable(runtime_path()), 'required' => true, 'tip' => '缓存与日志目录'],
            ['label' => 'data 目录', 'value' => is_writable($dataDir) ? '可写' : '不可写', 'ok' => is_writable($dataDir), 'required' => true, 'tip' => '保存数据库配置与安装锁'],
            ['label' => 'uploads 目录', 'value' => is_writable($uploadDir) ? '可写' : '不可写', 'ok' => is_writable($uploadDir), 'required' => true, 'tip' => '附件上传目录'],
            ['label' => '数据库结构文件', 'value' => is_file($root . 'database' . DIRECTORY_SEPARATOR . 'schema.sql') ? '存在' : '缺失', 'ok' => is_file($root . 'database' . DIRECTORY_SEPARATOR . 'schema.sql'), 'required' => true, 'tip' => 'database/schema.sql'],
        ];
    }

    protected function install(array $data): array
    {
        $startedAt = microtime(true);
        $this->stage = 'validate';

        $db = $this->databaseOptions($data);
        $account = $this->accountOptions($data);
        $redisConfig = $this->redisOptions($data);
        if ($redisConfig['enabled']) {
            $this->probeRedis($redisConfig);
        }

        $dbHost = $db['host'];
        $dbPort = $db['port'];
        $dbName = $db['name'];
        $dbUser = $db['user'];
        $dbPass = $db['pass'];
        $siteName = $account['site_name'];
        $adminUser = $account['admin_user'];
        $adminPass = $account['admin_pass'];

        $this->stage = 'database';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ];

        $baseDsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
        try {
            $pdo = new PDO($baseDsn . ";dbname={$dbName}", $dbUser, $dbPass, $options);
        } catch (PDOException $e) {
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($driverCode === 1049) {
                try {
                    $pdo = new PDO($baseDsn, $dbUser, $dbPass, $options);
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $pdo->exec("USE `{$dbName}`");
                } catch (PDOException $inner) {
                    throw new \RuntimeException('创建数据库失败：' . $this->databaseErrorMessage($inner));
                }
            } else {
                throw new \RuntimeException($this->databaseErrorMessage($e));
            }
        }
        $serverVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

        $this->stage = 'schema';
        $schemaFile = root_path() . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';
        $schema = file_get_contents($schemaFile);
        if ($schema === false) {
            throw new \RuntimeException('无法读取数据库结构文件');
        }

        $statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $schema)));
        try {
            foreach ($statements as $sql) {
                if ($sql === '' || str_starts_with($sql, '--')) {
                    continue;
                }
                $pdo->exec($sql);
            }
        } catch (PDOException $e) {
            throw new \RuntimeException('创建数据表失败：' . $e->getMessage());
        }

        $exists = (int) $pdo->query('SELECT COUNT(*) FROM `admins`')->fetchColumn();
        if ($exists > 0) {
            throw new \RuntimeException('该数据库已安装过本系统，请更换数据库名或清空数据库后重试');
        }
        $tableCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . $pdo->quote($dbName)
        )->fetchColumn();

        $this->stage = 'data';
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO `admins` (`username`,`password_hash`,`created_at`,`updated_at`) VALUES (?,?,?,?)');
        $stmt->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), $now, $now]);
        $adminId = (int) $pdo->lastInsertId();

        $settings = [
            'site_name'          => $siteName,
            'site_desc'          => '个人文档与笔记管理系统',
            'site_logo'          => '',
            'site_brand_mode'    => 'icon_text',
            'site_brand_image'   => '',
            'site_nav_style'     => 'top',
            'site_nav_login'     => '1',
            'site_nav_items'     => '[{"title":"首页","url":"/","target":"_self"},{"title":"浏览文档","url":"/docs","target":"_self"}]',
            'site_home_badge'    => '',
            'site_home_title'    => '',
            'site_home_intro'    => '',
            'site_home_note'     => '',
            'site_home_template' => 'classic',
            'site_home_bg_type'  => 'none',
            'site_home_bg_image' => '',
            'site_home_bg_video' => '',
            'site_home_overlay'  => '45',
            'site_home_buttons'  => '[{"label":"浏览文档","url":"/docs","style":"primary"}]',
            'site_theme_color'   => 'blue',
            'site_icp'           => '',
            'site_copyright'     => '© ' . date('Y') . ' ' . $siteName,
            'upload_file_ext'    => 'pdf,zip,rar,7z,txt,md,doc,docx,xls,xlsx,ppt,pptx',
            'upload_file_max_mb' => '10',
            'upload_image_ext'   => 'jpg,jpeg,png,gif,webp,ico',
            'upload_image_max_mb'=> '8',
            'upload_video_ext'   => 'mp4,webm,ogg',
            'upload_video_max_mb'=> '30',
            'captcha_on'         => '1',
            'login_fail_limit'   => '5',
            'login_lock_minutes' => '10',
            'site_doc_note'      => '',
            'maintenance_enabled'=> '0',
            'maintenance_template'=> 'macos',
            'maintenance_reason' => '',
            'maintenance_until'  => '',
        ];
        $stmt = $pdo->prepare('INSERT INTO `settings` (`name`,`value`) VALUES (?,?)');
        foreach ($settings as $name => $value) {
            $stmt->execute([$name, $value]);
        }

        $tagIds = [];
        $stmt = $pdo->prepare('INSERT INTO `tags` (`name`,`created_at`) VALUES (?,?)');
        foreach (['使用指南', '功能说明', '安全'] as $tagName) {
            $stmt->execute([$tagName, $now]);
            $tagIds[$tagName] = (int) $pdo->lastInsertId();
        }

        $welcomeTitle = '欢迎使用 Northmu Docs';
        $welcomeContent = $this->welcomeContent();
        $stmt = $pdo->prepare('INSERT INTO `documents` (`category_id`,`title`,`content`,`content_type`,`status`,`visibility`,`password_hash`,`show_toc`,`is_top`,`top_at`,`views`,`published_at`,`created_at`,`updated_at`) VALUES (0,?,?,?,1,\'public\',\'\',1,1,?,0,?,?,?)');
        $stmt->execute([$welcomeTitle, $welcomeContent, 'html', $now, $now, $now, $now]);
        $welcomeId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare('INSERT INTO `document_versions` (`document_id`,`title`,`content`,`content_type`,`version_no`,`created_at`) VALUES (?,?,?,?,1,?)');
        $stmt->execute([$welcomeId, $welcomeTitle, $welcomeContent, 'html', $now]);

        $stmt = $pdo->prepare('INSERT INTO `document_tag` (`document_id`,`tag_id`) VALUES (?,?)');
        foreach (['使用指南', '功能说明'] as $tagName) {
            $stmt->execute([$welcomeId, $tagIds[$tagName]]);
        }

        $this->stage = 'config';
        $this->writeDatabaseConfig([
            'type'     => 'mysql',
            'hostname' => $dbHost,
            'database' => $dbName,
            'username' => $dbUser,
            'password' => $dbPass,
            'hostport' => $dbPort,
            'charset'  => 'utf8mb4',
            'prefix'   => '',
        ]);
        $this->writeRedisConfig($redisConfig);

        $envFile = root_path() . '.env';
        $env = "APP_DEBUG = false\n\nDEFAULT_LANG = zh-cn\n";
        if (@file_put_contents($envFile, $env) === false) {
            throw new \RuntimeException('无法写入 .env 文件，请检查根目录权限');
        }

        $lockFile = root_path('data') . 'install.lock';
        $lock = json_encode(['installed_at' => $now, 'version' => '1.6.0'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (@file_put_contents($lockFile, $lock) === false) {
            throw new \RuntimeException('无法写入安装锁文件，请检查 data 目录权限');
        }

        $this->stage = 'done';
        return [
            'version'      => '1.6.0',
            'site_name'    => $siteName,
            'admin_user'   => $adminUser,
            'admin_id'     => $adminId,
            'db'           => [
                'host'           => $dbHost,
                'port'           => $dbPort,
                'name'           => $dbName,
                'server_version' => $serverVersion,
            ],
            'redis'        => [
                'enabled'  => $redisConfig['enabled'],
                'host'     => $redisConfig['host'],
                'port'     => $redisConfig['port'],
                'database' => $redisConfig['database'],
            ],
            'tables'       => $tableCount,
            'installed_at' => $now,
            'duration'     => round(microtime(true) - $startedAt, 2),
        ];
    }

    protected function welcomeContent(): string
    {
        return <<<'HTML'
<p class="lead">这是一篇随系统自动创建的欢迎文档，用来帮助你快速了解 Northmu Docs 的功能与使用方式。你可以随时在后台编辑或删除它。</p>

<h2>项目简介</h2>
<p>Northmu Docs 是一个面向个人使用的文档与笔记管理系统，基于 ThinkPHP 8 与 MySQL 5.7 构建。它提供无限级分类、富文本与 Markdown 双模式编辑、版本历史、附件管理、标签管理、数据备份等能力，并区分公开、加密、私有三种可见性。</p>

<h2>核心功能</h2>
<h3>文档编辑</h3>
<ul>
    <li>富文本与 Markdown 双模式编辑，可随时切换。</li>
    <li>每 20 秒自动保存草稿，避免意外丢失内容。</li>
    <li>每次保存生成版本记录，支持差异对比与一键回滚。</li>
    <li>可为每篇文档单独设置是否显示右侧目录树。</li>
</ul>
<h3>分类与标签</h3>
<ul>
    <li>无限级分类，支持公开、加密、私有三种可见性，并自动继承上级限制。</li>
    <li>标签需先在「标签管理」中创建，编辑文档时直接勾选，避免重复与拼写不一致。</li>
    <li>支持标签合并，把重复标签的文档关联一次性转移。</li>
</ul>
<h3>前台展示</h3>
<ul>
    <li>三栏式文档页：左侧分类树、中间文档内容、右侧 H1-H4 目录。</li>
    <li>深色与浅色主题切换，选择会保存在浏览器中。</li>
    <li>加密文档在前台显示标题，输入访问密码后才能查看正文。</li>
    <li>私有文档与私有分类不会出现在前台，直接访问也不会暴露内容。</li>
</ul>

<h2>快速开始</h2>
<ol>
    <li>进入「分类管理」创建分类，并为分类选择可见性。</li>
    <li>进入「标签管理」创建标签，供文档编辑时选择。</li>
    <li>进入「文档管理」点击「新建文档」，填写标题、分类、可见性与标签。</li>
    <li>使用富文本或 Markdown 编写正文，点击「保存文档」发布。</li>
    <li>在「系统设置」中修改站点名称、Logo、管理员昵称与头像。</li>
</ol>

<h2>可见性说明</h2>
<table>
    <thead>
    <tr><th>可见性</th><th>前台是否显示</th><th>访问方式</th></tr>
    </thead>
    <tbody>
    <tr><td>公开</td><td>显示</td><td>任何人可直接阅读</td></tr>
    <tr><td>加密</td><td>显示标题</td><td>输入访问密码后阅读</td></tr>
    <tr><td>私有</td><td>不显示</td><td>仅管理员在后台查看</td></tr>
    </tbody>
</table>
<p>文档的最终可见性取分类与文档自身中最严格的一档：分类私有则文档不可见；分类加密则文档需要密码。</p>

<h2>安全建议</h2>
<ul>
    <li>安装完成后立即修改默认管理员密码，并保持登录验证码开启。</li>
    <li>定期在「数据备份」中导出数据库与附件的 ZIP 备份。</li>
    <li>「操作日志」会记录登录、文档、分类、标签、附件、设置等关键操作，可导出或清理。</li>
    <li>部署时请将运行目录指向 <code>public</code>，并禁止上传目录执行 PHP。</li>
</ul>

<h2>常见问题</h2>
<h3>忘记管理员密码怎么办？</h3>
<p>可以在数据库中重置管理员密码哈希，或重新初始化站点。重置前请先备份数据库。</p>
<h3>如何让文档置顶？</h3>
<p>在编辑文档时勾选「在列表中置顶」。支持多篇文档同时置顶，置顶文档按置顶时间倒序排列，其余文档按发布时间倒序排列。</p>
<h3>加密文档的密码保存在哪里？</h3>
<p>密码使用 PHP 的 password_hash 单向加密存储，管理员也无法直接查看明文；忘记密码时可以在后台重新设置。</p>

<hr>
<p class="text-secondary">提示：你可以在「系统设置」中修改站点名称、管理员昵称与头像，让前台文档页展示你的个人信息。</p>
HTML;
    }

    protected function redisOptions(array $data, bool $strict = false): array
    {
        $enabled = (int) ($data['redis_enable'] ?? 0) === 1;
        $host = trim((string) ($data['redis_host'] ?? '127.0.0.1'));
        $port = trim((string) ($data['redis_port'] ?? '6379'));
        $password = (string) ($data['redis_password'] ?? '');
        $database = (int) ($data['redis_database'] ?? 0);
        $prefix = trim((string) ($data['redis_prefix'] ?? 'northmu:'));
        $ttl = (int) ($data['redis_ttl'] ?? 3600);

        $fallback = [
            'enabled'  => 0,
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'password' => '',
            'database' => 0,
            'prefix'   => 'northmu:',
            'ttl'      => 3600,
        ];
        if (!$enabled && !$strict) {
            return $fallback;
        }
        if (!preg_match('/^[A-Za-z0-9_.:\-]{1,120}$/', $host)) {
            throw new \RuntimeException('Redis 地址格式不正确');
        }
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new \RuntimeException('Redis 端口不正确');
        }
        if ($database < 0 || $database > 15) {
            throw new \RuntimeException('Redis 数据库编号需在 0-15 之间');
        }
        if ($prefix === '' || !preg_match('/^[A-Za-z0-9_:\-]{1,40}$/', $prefix)) {
            throw new \RuntimeException('Redis 缓存前缀格式不正确');
        }
        if (!str_ends_with($prefix, ':')) {
            $prefix .= ':';
        }
        if ($ttl < 60 || $ttl > 86400) {
            throw new \RuntimeException('Redis 缓存有效期需在 60-86400 秒之间');
        }
        if (mb_strlen($password) > 128) {
            throw new \RuntimeException('Redis 密码不能超过 128 个字符');
        }

        return [
            'enabled'  => $enabled ? 1 : 0,
            'host'     => $host,
            'port'     => (int) $port,
            'password' => $password,
            'database' => $database,
            'prefix'   => $prefix,
            'ttl'      => $ttl,
        ];
    }

    protected function probeRedis(array $config): array
    {
        if (!extension_loaded('redis') && !class_exists('\Predis\Client')) {
            throw new \RuntimeException('当前环境缺少 Redis 扩展或 Predis 依赖，请关闭 Redis 缓存选项');
        }

        try {
            if (extension_loaded('redis')) {
                $client = new \Redis();
                if (!$client->connect($config['host'], $config['port'], 2)) {
                    throw new \RuntimeException('无法连接到 Redis 服务');
                }
                if ($config['password'] !== '' && !$client->auth($config['password'])) {
                    throw new \RuntimeException('Redis 密码不正确');
                }
                if ($config['database'] > 0) {
                    $client->select($config['database']);
                }
                $client->ping();
            } else {
                $client = new \Predis\Client([
                    'scheme'   => 'tcp',
                    'host'     => $config['host'],
                    'port'     => $config['port'],
                    'password' => $config['password'] !== '' ? $config['password'] : null,
                    'database' => $config['database'],
                    'timeout'  => 2,
                ]);
                $client->ping();
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'invalid password') !== false || stripos($message, 'noauth') !== false || str_contains($message, '密码不正确')) {
                throw new \RuntimeException('Redis 密码不正确');
            }
            if (stripos($message, 'connection refused') !== false || stripos($message, 'timed out') !== false || str_contains($message, '无法连接到 Redis 服务')) {
                throw new \RuntimeException('无法连接到 Redis 服务，请检查地址、端口与服务状态');
            }
            throw new \RuntimeException('Redis 连接失败：' . $message);
        }

        return [
            'host'     => $config['host'],
            'port'     => $config['port'],
            'database' => $config['database'],
            'message'  => 'Redis 连接成功（数据库 ' . $config['database'] . '）',
        ];
    }

    protected function writeRedisConfig(array $config): void
    {
        $content = "<?php\n// 本文件由安装向导自动生成，请勿手动修改\nreturn " . var_export($config, true) . ";\n";
        $file = root_path('data') . 'redis.php';
        if (@file_put_contents($file, $content) === false) {
            throw new \RuntimeException('无法写入 Redis 配置文件，请检查 data 目录权限');
        }
    }

    protected function writeDatabaseConfig(array $config)
    {
        $content = "<?php\n// 本文件由安装向导自动生成，请勿手动修改\nreturn " . var_export($config, true) . ";\n";
        $file = root_path('data') . 'database.php';
        if (@file_put_contents($file, $content) === false) {
            throw new \RuntimeException('无法写入数据库配置，请检查 data 目录权限');
        }
    }
}
