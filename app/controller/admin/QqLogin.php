<?php

namespace app\controller\admin;

use app\BaseController;
use app\model\Admin;
use app\support\QqConnect;
use think\facade\Session;

class QqLogin extends BaseController
{
    private const STATE_TTL = 600;

    public function startLogin()
    {
        if (admin_logged_in()) {
            return redirect('/admin');
        }

        $config = qq_login_config();
        if (!$config['enabled'] || !$config['configured']) {
            return $this->loginFail('QQ 登录尚未配置，请联系站点管理员');
        }
        if ($config['binding'] === null) {
            return $this->loginFail('站点尚未绑定 QQ 账号，请先登录后台完成绑定');
        }

        return $this->redirectToAuthorize('login');
    }

    public function startBind()
    {
        if (!admin_logged_in()) {
            return redirect('/admin/login');
        }

        $config = qq_login_config();
        if (!$config['enabled'] || !$config['configured']) {
            admin_flash('请先在三方服务中配置并启用 QQ 登录', 'danger');
            return redirect('/admin/settings#qqPanel');
        }

        return $this->redirectToAuthorize('bind');
    }

    public function callback()
    {
        $code = trim((string) $this->request->get('code', ''));
        $state = trim((string) $this->request->get('state', ''));

        $pending = Session::get('qq_oauth_state', []);
        Session::delete('qq_oauth_state');
        $mode = is_array($pending) ? (string) ($pending['mode'] ?? 'login') : 'login';

        $fail = function (string $message) use ($mode) {
            if ($mode === 'bind') {
                admin_flash($message, 'danger');
                return redirect('/admin/settings#qqPanel');
            }
            return $this->loginFail($message);
        };

        if (!is_array($pending)
            || (int) ($pending['expire'] ?? 0) < time()
            || !hash_equals((string) ($pending['state'] ?? ''), $state)) {
            return $fail('QQ 授权请求已失效，请重新发起');
        }
        if ($code === '') {
            return $fail('QQ 授权未完成，请重试');
        }

        $config = qq_login_config();
        if (!$config['enabled'] || !$config['configured']) {
            return $fail('QQ 登录尚未配置，请稍后再试');
        }
        $redirectUri = qq_login_redirect_uri();
        if ($redirectUri === '') {
            return $fail('站点地址未配置，无法完成 QQ 登录');
        }

        try {
            $client = new QqConnect($config['app_id'], $config['app_key'], $redirectUri);
            $token = $client->exchangeCode($code);
            $openid = $client->fetchOpenid((string) $token['access_token']);
            $profile = $client->fetchUserInfo((string) $token['access_token'], $openid);
        } catch (\Throwable $e) {
            return $fail($e->getMessage() !== '' ? $e->getMessage() : 'QQ 授权失败，请稍后重试');
        }

        if ($mode === 'bind') {
            return $this->completeBind($openid, $profile);
        }

        return $this->completeLogin($openid, $profile);
    }

    public function unbind()
    {
        if (!admin_logged_in()) {
            return $this->request->isAjax()
                ? json(['code' => 401, 'msg' => '登录已失效，请重新登录'])
                : redirect('/admin/login');
        }

        $binding = qq_login_binding();
        if ($binding !== null) {
            save_setting('qq_login_binding', '');
            cache_forget('site:settings');
            audit_log('service', 'unbind', '解除 QQ 登录绑定（QQ：' . ($binding['nickname'] !== '' ? $binding['nickname'] : $binding['openid']) . '）');
            $message = '已解除 QQ 绑定，QQ 登录入口将立即隐藏';
        } else {
            $message = '当前没有已绑定的 QQ 账号';
        }

        if ($this->request->isAjax()) {
            return json(['code' => 0, 'msg' => $message]);
        }

        admin_flash($message, $binding !== null ? 'success' : 'info');
        return redirect('/admin/settings#qqPanel');
    }

    private function redirectToAuthorize(string $mode)
    {
        $redirectUri = qq_login_redirect_uri();
        if ($redirectUri === '') {
            return $mode === 'bind'
                ? $this->bindFail('请先在「系统设置 → 站点与 SEO」中填写站点地址')
                : $this->loginFail('站点地址未配置，无法发起 QQ 登录');
        }

        $config = qq_login_config();
        $state = bin2hex(random_bytes(16));
        Session::set('qq_oauth_state', [
            'state'  => $state,
            'mode'   => $mode,
            'expire' => time() + self::STATE_TTL,
        ]);

        $client = new QqConnect($config['app_id'], $config['app_key'], $redirectUri);

        return redirect($client->authorizeUrl($state));
    }

    private function completeLogin(string $openid, array $profile)
    {
        $binding = qq_login_binding();
        if ($binding === null || !hash_equals($binding['openid'], $openid)) {
            return $this->loginFail('当前 QQ 账号未绑定后台管理员，请登录后台完成绑定');
        }

        $admin = Admin::find((int) $binding['admin_id']);
        if (!$admin) {
            return $this->loginFail('绑定的管理员账号不存在，请重新绑定 QQ');
        }

        $nickname = $this->cleanNickname((string) ($profile['nickname'] ?? ''));
        $avatar = $this->cleanAvatar((string) ($profile['avatar'] ?? ''));
        if (($nickname !== '' && $nickname !== $binding['nickname']) || ($avatar !== '' && $avatar !== $binding['avatar'])) {
            save_setting('qq_login_binding', json_encode([
                'openid'   => $binding['openid'],
                'nickname' => $nickname !== '' ? $nickname : $binding['nickname'],
                'avatar'   => $avatar !== '' ? $avatar : $binding['avatar'],
                'admin_id' => $binding['admin_id'],
                'bound_at' => $binding['bound_at'],
            ], JSON_UNESCAPED_UNICODE));
            cache_forget('site:settings');
        }

        admin_complete_login([
            'id'       => (int) $admin->id,
            'username' => (string) $admin->username,
        ], 'QQ 登录成功（QQ：' . ($nickname !== '' ? $nickname : $openid) . '）');

        return redirect('/admin');
    }

    private function completeBind(string $openid, array $profile)
    {
        $admin = current_admin();
        if ($admin['id'] <= 0) {
            return redirect('/admin/login');
        }

        $binding = qq_login_binding();
        if ($binding !== null && !hash_equals($binding['openid'], $openid) && $binding['admin_id'] !== $admin['id']) {
            return $this->bindFail('该 QQ 账号已绑定其他管理员，请先解除原绑定');
        }

        $nickname = $this->cleanNickname((string) ($profile['nickname'] ?? ''));
        save_setting('qq_login_binding', json_encode([
            'openid'   => $openid,
            'nickname' => $nickname,
            'avatar'   => $this->cleanAvatar((string) ($profile['avatar'] ?? '')),
            'admin_id' => $admin['id'],
            'bound_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE));
        cache_forget('site:settings');

        audit_log('service', 'bind', '绑定 QQ 登录账号（QQ：' . ($nickname !== '' ? $nickname : $openid) . '）', 'admin', $admin['id']);
        admin_flash('QQ 账号绑定成功，登录页已显示 QQ 登录入口');

        return redirect('/admin/settings#qqPanel');
    }

    private function loginFail(string $message)
    {
        return redirect('/admin/login?qq_error=' . rawurlencode($message));
    }

    private function bindFail(string $message)
    {
        admin_flash($message, 'danger');
        return redirect('/admin/settings#qqPanel');
    }

    private function cleanNickname(string $nickname): string
    {
        return mb_substr(trim(strip_tags($nickname)), 0, 60);
    }

    private function cleanAvatar(string $avatar): string
    {
        $avatar = trim($avatar);
        return preg_match('#^https?://#i', $avatar) ? mb_substr($avatar, 0, 255) : '';
    }
}
