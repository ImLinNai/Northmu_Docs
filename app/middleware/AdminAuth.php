<?php

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 后台登录校验
 */
class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!admin_logged_in()) {
            if ($request->isAjax() || $request->isJson()) {
                return json(['code' => 401, 'msg' => '登录状态已失效，请重新登录'], 401);
            }
            return redirect('/admin/login');
        }

        return $next($request);
    }
}
