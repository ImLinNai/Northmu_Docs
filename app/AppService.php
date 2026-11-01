<?php
declare (strict_types = 1);

namespace app;

use think\Service;

/**
 * 应用服务类
 */
class AppService extends Service
{
    public function register()
    {
        $this->app->bind(\think\Paginator::class, \app\support\Pagination::class);
    }

    public function boot()
    {
        // 服务启动
    }
}
