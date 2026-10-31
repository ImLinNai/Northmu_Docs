<?php

// +----------------------------------------------------------------------
// | 缓存设置
// +----------------------------------------------------------------------

$redisFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'redis.php';
$redis = [];
if (is_file($redisFile)) {
    try {
        $loaded = include $redisFile;
        if (is_array($loaded)) {
            $redis = $loaded;
        }
    } catch (\Throwable $e) {
        $redis = [];
    }
}

$redisPrefix = trim((string) ($redis['prefix'] ?? 'northmu:'));
if ($redisPrefix === '') {
    $redisPrefix = 'northmu:';
}
if (!str_ends_with($redisPrefix, ':')) {
    $redisPrefix .= ':';
}
$redisDatabase = max(0, min(15, (int) ($redis['database'] ?? 0)));
$redisTtl = max(60, min(86400, (int) ($redis['ttl'] ?? 3600)));

return [
    // 默认缓存驱动；Redis 通过 cache_* 助手函数按需启用
    'default' => 'file',

    // 缓存连接方式配置
    'stores'  => [
        'file' => [
            'type'       => 'File',
            'path'       => '',
            'prefix'     => '',
            'expire'     => 0,
            'tag_prefix' => 'tag:',
            'serialize'  => [],
        ],
        'redis' => [
            'type'       => \think\cache\driver\Redis::class,
            'host'       => (string) ($redis['host'] ?? '127.0.0.1'),
            'port'       => max(1, min(65535, (int) ($redis['port'] ?? 6379))),
            'password'   => (string) ($redis['password'] ?? ''),
            'select'     => $redisDatabase,
            'database'   => $redisDatabase,
            'timeout'    => 2,
            'persistent' => false,
            'prefix'     => $redisPrefix,
            'expire'     => $redisTtl,
            'tag_prefix' => 'tag:',
            'serialize'  => [],
        ],
    ],
];
