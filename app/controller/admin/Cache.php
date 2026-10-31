<?php

namespace app\controller\admin;

use think\facade\View;

class Cache extends Base
{
    public function index()
    {
        $config = redis_config();
        $status = $this->status($config);

        $view = $this->viewData('缓存管理', '查看 Redis 连接状态与缓存占用，修改缓存配置', 'cache') + [
            'config' => $config,
            'status' => $status,
        ];
        $view['extraScripts'] = '<script src="/static/js/admin-pages.js"></script>';

        return View::fetch('/cache', $view);
    }

    public function save()
    {
        $old = redis_config();
        $enabled = (int) $this->request->post('enabled', 0) === 1;
        $host = trim((string) $this->request->post('host', '127.0.0.1'));
        $port = (int) $this->request->post('port', 6379);
        $password = (string) $this->request->post('password', '');
        $keepPassword = (int) $this->request->post('password_keep', 0) === 1;
        $database = (int) $this->request->post('database', 0);
        $prefix = trim((string) $this->request->post('prefix', 'northmu:'));
        $ttl = (int) $this->request->post('ttl', 3600);

        if ($keepPassword && $password === '') {
            $password = (string) ($old['password'] ?? '');
        }
        if ($host === '' || !preg_match('/^[A-Za-z0-9_.:\-]{1,120}$/', $host)) {
            admin_flash('Redis 地址格式不正确', 'warning');
            return redirect('/admin/cache');
        }
        if ($port < 1 || $port > 65535) {
            admin_flash('Redis 端口需在 1-65535 之间', 'warning');
            return redirect('/admin/cache');
        }
        if ($database < 0 || $database > 15) {
            admin_flash('Redis 数据库编号需在 0-15 之间', 'warning');
            return redirect('/admin/cache');
        }
        if ($ttl < 60 || $ttl > 86400) {
            admin_flash('缓存有效期需在 60-86400 秒之间', 'warning');
            return redirect('/admin/cache');
        }
        if ($prefix === '' || !preg_match('/^[A-Za-z0-9_:\-]{1,40}$/', $prefix)) {
            admin_flash('缓存前缀只能包含字母、数字、冒号、下划线和短横线', 'warning');
            return redirect('/admin/cache');
        }
        if (!str_ends_with($prefix, ':')) {
            $prefix .= ':';
        }
        if (mb_strlen($password) > 128) {
            admin_flash('Redis 密码不能超过 128 个字符', 'warning');
            return redirect('/admin/cache');
        }

        $config = [
            'enabled'  => $enabled,
            'host'     => $host,
            'port'     => $port,
            'password' => $password,
            'database' => $database,
            'prefix'   => $prefix,
            'ttl'      => $ttl,
        ];

        if ($enabled) {
            try {
                $this->probe($config);
            } catch (\Throwable $e) {
                admin_flash('Redis 连接失败：' . $e->getMessage(), 'failure');
                return redirect('/admin/cache');
            }
        }

        if (!$this->writeConfig($config)) {
            admin_flash('缓存配置写入失败，请检查 data 目录权限', 'failure');
            return redirect('/admin/cache');
        }

        audit_log('cache', 'update', $enabled ? '启用 Redis 缓存并更新配置' : '关闭 Redis 缓存');
        admin_flash($enabled ? 'Redis 缓存配置已保存并启用' : 'Redis 缓存已关闭');
        return redirect('/admin/cache');
    }

    public function clear()
    {
        $deleted = cache_forget_prefix('');
        audit_log('cache', 'clear', '清空站点缓存' . ($deleted > 0 ? '（删除 ' . $deleted . ' 个键）' : ''));
        admin_flash($deleted > 0 ? '已清空 ' . $deleted . ' 个缓存键' : '缓存已重置');
        return redirect('/admin/cache');
    }

    public function test()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请求方式不正确');
        }
        $config = [
            'host'     => trim((string) $this->request->post('host', '127.0.0.1')),
            'port'     => (int) $this->request->post('port', 6379),
            'password' => (string) $this->request->post('password', ''),
            'database' => (int) $this->request->post('database', 0),
        ];
        if ($config['password'] === '' && (int) $this->request->post('password_keep', 0) === 1) {
            $config['password'] = (string) redis_config()['password'];
        }
        try {
            $info = $this->probe($config);
            return $this->ok('连接成功', $info);
        } catch (\Throwable $e) {
            return $this->fail('连接失败：' . $e->getMessage());
        }
    }

    protected function status(array $config): array
    {
        $status = [
            'supported' => redis_supported(),
            'enabled'   => (bool) $config['enabled'],
            'connected' => false,
            'error'     => '',
            'version'   => '',
            'memory'    => '',
            'clients'   => 0,
            'keys'      => 0,
            'app_keys'  => 0,
            'hits'      => 0,
            'misses'    => 0,
            'uptime'    => '',
        ];

        if (!$config['enabled']) {
            return $status;
        }
        if (!redis_supported()) {
            $status['error'] = '未检测到 Redis 扩展或 Predis 依赖';
            return $status;
        }

        $driver = redis_driver();
        if (!$driver) {
            $status['error'] = '无法连接 Redis，请检查地址、端口与密码';
            return $status;
        }

        try {
            $client = $driver->handler();
            $info = $client->info();
            $status['connected'] = true;
            $status['version'] = (string) ($info['Server']['redis_version'] ?? '');
            $status['memory'] = (string) ($info['Memory']['used_memory_human'] ?? '');
            $status['clients'] = (int) ($info['Clients']['connected_clients'] ?? 0);
            $status['hits'] = (int) ($info['Stats']['keyspace_hits'] ?? 0);
            $status['misses'] = (int) ($info['Stats']['keyspace_misses'] ?? 0);
            $status['uptime'] = (string) ($info['Server']['uptime_in_days'] ?? '');
            $status['keys'] = (int) $client->dbsize();
            $status['app_keys'] = count($this->appKeys($client));
        } catch (\Throwable $e) {
            $status['error'] = $e->getMessage();
        }
        return $status;
    }

    protected function appKeys($client): array
    {
        return redis_keys_by_pattern($client, redis_config()['prefix'] . '*');
    }

    protected function probe(array $config): array
    {
        if (!redis_supported()) {
            throw new \RuntimeException('未安装 Redis 扩展或 Predis 依赖');
        }
        $port = (int) ($config['port'] ?? 6379);
        $database = (int) ($config['database'] ?? 0);
        $password = (string) ($config['password'] ?? '');
        $host = (string) ($config['host'] ?? '127.0.0.1');

        if (extension_loaded('redis')) {
            $client = new \Redis();
            if (!$client->connect($host, $port, 2)) {
                throw new \RuntimeException('无法连接到 Redis 服务');
            }
            if ($password !== '' && !$client->auth($password)) {
                throw new \RuntimeException('Redis 密码不正确');
            }
            if ($database > 0) {
                $client->select($database);
            }
            $client->ping();
            $info = $client->info();
        } else {
            $client = new \Predis\Client([
                'scheme'   => 'tcp',
                'host'     => $host,
                'port'     => $port,
                'password' => $password !== '' ? $password : null,
                'database' => $database,
                'timeout'  => 2,
            ]);
            $client->ping();
            $info = $client->info();
        }

        return [
            'version' => (string) ($info['Server']['redis_version'] ?? ($info['redis_version'] ?? '')),
            'memory'  => (string) ($info['Memory']['used_memory_human'] ?? ($info['used_memory_human'] ?? '')),
        ];
    }

    protected function writeConfig(array $config): bool
    {
        $file = app()->getRootPath() . 'data' . DIRECTORY_SEPARATOR . 'redis.php';
        $content = "<?php\n// 本文件由安装向导或后台缓存管理生成，请勿手动修改\nreturn " . var_export($config, true) . ";\n";
        return @file_put_contents($file, $content, LOCK_EX) !== false;
    }
}
