<?php

namespace app\middleware;

use Closure;
use think\facade\Session;
use think\Request;
use think\Response;

/**
 * 简单表单令牌校验，覆盖所有写请求
 */
class Csrf
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isGet() && !$request->isHead() && !$request->isOptions()) {
            $token = (string) ($request->post('_token', '') ?: $request->header('X-CSRF-TOKEN', ''));
            $expected = (string) Session::get('csrf_token', '');

            if ($expected === '' || !hash_equals($expected, $token)) {
                if ($request->isAjax() || $request->isJson()) {
                    return json(['code' => 419, 'msg' => '页面会话已过期，请刷新后重试'], 419);
                }
                return response('<div style="font-family:sans-serif;padding:40px;text-align:center">页面会话已过期，请返回刷新后重试。<br><br><a href="/">返回首页</a></div>', 419);
            }
        }

        return $next($request);
    }
}
