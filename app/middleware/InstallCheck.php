<?php

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 未安装时强制跳转安装向导，已安装时禁止再次进入安装向导
 */
class InstallCheck
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->pathinfo(), '/');
        $isInstall = $path === 'install' || str_starts_with($path, 'install/');

        if (is_installed()) {
            if ($isInstall) {
                return redirect('/');
            }
            return $next($request);
        }

        if ($isInstall) {
            return $next($request);
        }

        return redirect('/install');
    }
}
