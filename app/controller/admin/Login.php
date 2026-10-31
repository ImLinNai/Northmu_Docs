<?php

namespace app\controller\admin;

use app\BaseController;
use app\model\Admin;
use app\model\LoginLog;
use think\facade\Cache;
use think\facade\Session;
use think\facade\View;

class Login extends BaseController
{
    public function index()
    {
        if (admin_logged_in()) {
            return redirect('/admin');
        }
        return $this->renderLogin();
    }

    public function doLogin()
    {
        if (admin_logged_in()) {
            return redirect('/admin');
        }

        $username = trim((string) $this->request->post('username', ''));
        $password = (string) $this->request->post('password', '');
        $captcha = trim((string) $this->request->post('captcha', ''));
        $captchaOn = sys_setting('captcha_on', '1') === '1';
        $ip = (string) $this->request->ip();

        $limit = max(1, (int) sys_setting('login_fail_limit', 5));
        $lockMinutes = max(1, (int) sys_setting('login_lock_minutes', 10));
        $failKey = 'login_fail_' . md5($ip . '|' . $username);
        $fails = (int) Cache::get($failKey, 0);

        if ($fails >= $limit) {
            $this->writeLog($username, false, '失败次数过多，已锁定');
            return $this->renderLogin('登录失败次数过多，请 ' . $lockMinutes . ' 分钟后再试', $username);
        }

        $fail = function (string $message) use ($failKey, $fails, $lockMinutes, $username) {
            Cache::set($failKey, $fails + 1, $lockMinutes * 60);
            $this->writeLog($username, false, $message);
            return $this->renderLogin($message, $username);
        };

        if ($username === '' || $password === '') {
            return $fail('请输入账号和密码');
        }

        if ($captchaOn) {
            if ($captcha === '') {
                return $fail('请输入验证码');
            }
            if (!captcha_check($captcha)) {
                return $fail('验证码错误');
            }
        }

        $admin = Admin::where('username', $username)->find();
        if (!$admin || !password_verify($password, (string) $admin->password_hash)) {
            return $fail('账号或密码错误');
        }

        Cache::delete($failKey);
        Session::regenerate();
        Session::set('admin_id', (int) $admin->id);
        Session::set('admin_username', (string) $admin->username);

        $admin->save([
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
        ]);
        $this->writeLog($username, true, '登录成功', (int) $admin->id);

        return redirect('/admin');
    }

    public function logout()
    {
        audit_log('auth', 'logout', '管理员退出登录');
        Session::delete('admin_id');
        Session::delete('admin_username');
        Session::delete('csrf_token');
        return redirect('/admin/login');
    }

    protected function renderLogin(string $error = '', string $username = '')
    {
        $siteName = sys_setting('site_name', 'Northmu Docs');
        $login = site_login_config();
        $loginIcon = $login['icon'] !== '' ? $login['icon'] : (string) sys_setting('site_logo', '');

        return View::fetch('/login', [
            'siteName'  => $siteName,
            'siteLogo'  => (string) sys_setting('site_logo', ''),
            'siteDesc'  => sys_setting('site_desc', '个人文档与笔记管理系统'),
            'loginTitle'=> $login['title'],
            'loginDesc' => $login['desc'],
            'loginIcon' => $loginIcon,
            'loginBg'   => $login['bg'],
            'loginOverlayAlpha' => $login['overlayAlpha'],
            'themeColor'=> site_theme_color(),
            'copyright' => sys_setting('site_copyright', '© ' . date('Y') . ' ' . $siteName),
            'error'     => $error,
            'username'  => $username,
            'captchaOn' => sys_setting('captcha_on', '1') === '1',
        ]);
    }

    protected function writeLog(string $username, bool $success, string $message, int $adminId = 0)
    {
        try {
            LoginLog::create([
                'username'   => mb_substr($username, 0, 50),
                'ip'         => (string) $this->request->ip(),
                'user_agent' => mb_substr((string) $this->request->header('user-agent', ''), 0, 255),
                'success'    => $success ? 1 : 0,
                'message'    => mb_substr($message, 0, 100),
            ]);
        } catch (\Throwable $e) {
            // 登录日志失败不影响主流程
        }

        $account = $username !== '' ? $username : '空';
        audit_log(
            'auth',
            $success ? 'login' : 'login_fail',
            $success ? '登录成功（账号：' . $account . '）' : '登录失败：' . $message . '（账号：' . $account . '）',
            'admin',
            $adminId,
            $username,
            $adminId
        );
    }
}
