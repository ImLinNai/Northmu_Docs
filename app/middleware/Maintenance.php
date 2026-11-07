<?php

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 维护模式：仅管理员可继续访问，访客返回维护页面
 */
class Maintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        $state = maintenance_state();
        if (!$state['enabled']) {
            return $next($request);
        }

        $path = trim($request->pathinfo(), '/');

        // 后台入口保留，交由 AdminAuth 校验登录态
        if ($path === 'admin' || str_starts_with($path, 'admin/')) {
            return $next($request);
        }

        // 维护页自身依赖的静态资源与搜索引擎约定文件
        foreach (['static/', 'uploads/', 'robots.txt'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }
        if ($path === 'favicon.ico') {
            return $next($request);
        }

        if (admin_logged_in()) {
            return $next($request);
        }

        $retryAfter = $state['until'] > time() ? max(60, $state['until'] - time()) : 3600;

        if ($request->isAjax() || $request->isJson()) {
            return json([
                'code' => 503,
                'msg'  => $state['reason'] !== '' ? $state['reason'] : '站点维护中，请稍后再试',
            ], 503)->header(['Retry-After' => (string) $retryAfter]);
        }

        return response(maintenance_render($state['template'], $state), 503)->header([
            'Content-Type'  => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, max-age=0',
            'Retry-After'   => (string) $retryAfter,
        ]);
    }
}
