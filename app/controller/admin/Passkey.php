<?php

namespace app\controller\admin;

use app\model\Admin;
use app\Support\WebAuthn;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;

class Passkey extends Base
{
    /**
     * 登录页获取断言选项（公开）
     */
    public function options()
    {
        if (!passkey_login_enabled()) {
            return $this->fail('通行密钥登录未启用');
        }
        $passkeys = $this->allPasskeys();
        if (!$passkeys) {
            return $this->fail('尚未注册通行密钥');
        }

        $challenge = WebAuthn::challenge();
        Session::set('passkey_login_challenge', [
            'challenge' => $challenge,
            'at'        => time(),
        ]);

        $allow = [];
        foreach ($passkeys as $row) {
            $allow[] = [
                'type'       => 'public-key',
                'id'         => (string) $row['credential_id'],
                'transports' => ['internal', 'hybrid', 'usb', 'nfc', 'ble'],
            ];
        }

        return $this->ok('ok', [
            'challenge'        => $challenge,
            'rpId'             => $this->rpId(),
            'timeout'          => 60000,
            'userVerification' => 'preferred',
            'allowCredentials' => $allow,
        ]);
    }

    /**
     * 验证断言并登录（公开）
     */
    public function login()
    {
        if (!passkey_login_enabled()) {
            return $this->fail('通行密钥登录未启用');
        }

        $ip = $this->clientIp();
        $failKey = 'passkey_fail_' . md5($ip);
        $fails = (int) Cache::get($failKey, 0);
        if ($fails >= 20) {
            return $this->fail('尝试次数过多，请 10 分钟后再试');
        }

        $record = Session::get('passkey_login_challenge');
        Session::delete('passkey_login_challenge');
        if (!is_array($record) || empty($record['challenge']) || time() - (int) ($record['at'] ?? 0) > WebAuthn::CHALLENGE_TTL) {
            return $this->fail('登录校验已过期，请重新点击通行密钥登录');
        }

        $credentialId = trim((string) $this->request->post('id', ''));
        $clientDataJson = (string) $this->request->post('clientDataJSON', '');
        $authenticatorData = (string) $this->request->post('authenticatorData', '');
        $signature = (string) $this->request->post('signature', '');

        if ($credentialId === '' || $clientDataJson === '' || $authenticatorData === '' || $signature === '') {
            $this->failLog($failKey, $fails);
            return $this->fail('通行密钥响应不完整，请重试');
        }

        $passkey = null;
        try {
            $passkey = Db::name('admin_passkeys')->where('credential_id', $credentialId)->find();
        } catch (\Throwable $e) {
            return $this->fail('通行密钥数据不可用，请先在后台执行 v1.7.1 升级脚本');
        }
        if (!$passkey) {
            $this->failLog($failKey, $fails);
            $this->writeLoginLog('', false, '通行密钥不存在');
            return $this->fail('该通行密钥未注册或已被删除');
        }

        try {
            $signCount = WebAuthn::parseAssertion(
                WebAuthn::b64uDecode($clientDataJson),
                WebAuthn::b64uDecode($authenticatorData),
                WebAuthn::b64uDecode($signature),
                (string) $passkey['public_key'],
                (string) $record['challenge'],
                $this->origin(),
                $this->rpId(),
                (int) $passkey['sign_count']
            );
        } catch (\Throwable $e) {
            $this->failLog($failKey, $fails);
            $this->writeLoginLog('', false, '通行密钥验证失败：' . $e->getMessage());
            return $this->fail($e->getMessage());
        }

        $admin = Admin::find((int) $passkey['admin_id']);
        if (!$admin) {
            return $this->fail('管理员账号不存在');
        }

        Cache::delete($failKey);
        try {
            Db::name('admin_passkeys')->where('id', (int) $passkey['id'])->update([
                'sign_count'   => $signCount,
                'last_used_at' => date('Y-m-d H:i:s'),
                'last_used_ip' => $ip,
            ]);
        } catch (\Throwable $e) {
            // 记录使用状态失败不影响登录
        }

        admin_complete_login([
            'id'       => (int) $admin->id,
            'username' => (string) $admin->username,
        ], '通行密钥登录成功');

        return $this->ok('登录成功', ['redirect' => '/admin']);
    }

    /**
     * 后台注册通行密钥：生成创建选项
     */
    public function registerOptions()
    {
        $admin = admin_profile();
        $challenge = WebAuthn::challenge();
        Session::set('passkey_reg_challenge', [
            'challenge' => $challenge,
            'at'        => time(),
            'admin_id'  => (int) $admin['id'],
        ]);

        $exclude = [];
        foreach ($this->adminPasskeys((int) $admin['id']) as $row) {
            $exclude[] = ['type' => 'public-key', 'id' => (string) $row['credential_id']];
        }

        return $this->ok('ok', [
            'challenge'        => $challenge,
            'rpId'             => $this->rpId(),
            'rpName'           => (string) sys_setting('site_name', 'Northmu Docs'),
            'userId'           => WebAuthn::b64uEncode(hash('sha256', 'northmu-admin-' . (int) $admin['id'], true)),
            'userName'         => (string) $admin['username'],
            'userDisplayName'  => (string) $admin['display_name'],
            'timeout'          => 60000,
            'attestation'      => 'none',
            'excludeCredentials' => $exclude,
            'authenticatorSelection' => [
                'residentKey'        => 'preferred',
                'requireResidentKey' => false,
                'userVerification'   => 'preferred',
            ],
        ]);
    }

    /**
     * 后台注册通行密钥：校验并保存
     */
    public function register()
    {
        $admin = admin_profile();
        $record = Session::get('passkey_reg_challenge');
        Session::delete('passkey_reg_challenge');
        if (!is_array($record) || empty($record['challenge']) || time() - (int) ($record['at'] ?? 0) > WebAuthn::CHALLENGE_TTL
            || (int) ($record['admin_id'] ?? 0) !== (int) $admin['id']) {
            return $this->fail('注册校验已过期，请重新点击添加通行密钥');
        }

        $id = trim((string) $this->request->post('id', ''));
        $clientDataJson = (string) $this->request->post('clientDataJSON', '');
        $attestationObject = (string) $this->request->post('attestationObject', '');
        if ($id === '' || $clientDataJson === '' || $attestationObject === '') {
            return $this->fail('通行密钥响应不完整，请重试');
        }

        try {
            $result = WebAuthn::parseCreation(
                WebAuthn::b64uDecode($clientDataJson),
                WebAuthn::b64uDecode($attestationObject),
                (string) $record['challenge'],
                $this->origin(),
                $this->rpId()
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        if ($id !== $result['credential_id']) {
            return $this->fail('通行密钥凭据校验失败，请重试');
        }

        try {
            Db::name('admin_passkeys')->insert([
                'admin_id'      => (int) $admin['id'],
                'credential_id' => $result['credential_id'],
                'public_key'    => $result['public_key'],
                'alg'           => $result['alg'],
                'sign_count'    => $result['sign_count'],
                'label'         => $this->deviceLabel((string) $this->request->header('user-agent', '')),
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return $this->fail('保存通行密钥失败，该设备可能已经注册过');
        }

        save_setting('passkey_login', '1');
        cache_forget('site:settings');
        audit_log('auth', 'passkey_add', '添加通行密钥');

        return $this->ok('通行密钥已添加');
    }

    /**
     * 删除通行密钥
     */
    public function delete()
    {
        $admin = admin_profile();
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return $this->fail('请选择要删除的通行密钥');
        }
        $row = Db::name('admin_passkeys')->where('id', $id)->where('admin_id', (int) $admin['id'])->find();
        if (!$row) {
            return $this->fail('通行密钥不存在');
        }
        Db::name('admin_passkeys')->where('id', $id)->delete();
        $remaining = $this->adminPasskeys((int) $admin['id']) ? 1 : 0;
        if (!$remaining) {
            save_setting('passkey_login', '0');
            cache_forget('site:settings');
        }
        audit_log('auth', 'passkey_delete', '删除通行密钥（' . (string) $row['label'] . '）');
        return $this->ok('通行密钥已删除', ['remaining' => $remaining]);
    }

    protected function allPasskeys(): array
    {
        try {
            return Db::name('admin_passkeys')->order('id', 'asc')->limit(20)->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function adminPasskeys(int $adminId): array
    {
        try {
            return Db::name('admin_passkeys')->where('admin_id', $adminId)->order('id', 'asc')->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function origin(): string
    {
        return rtrim((string) request()->domain(), '/');
    }

    protected function rpId(): string
    {
        $host = (string) request()->host();
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            return $end !== false ? substr($host, 1, $end - 1) : $host;
        }
        return explode(':', $host)[0];
    }

    protected function clientIp(): string
    {
        try {
            return (string) request()->ip();
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function failLog(string $key, int $fails): void
    {
        Cache::set($key, $fails + 1, 600);
    }

    protected function writeLoginLog(string $username, bool $success, string $message): void
    {
        try {
            Db::name('login_logs')->insert([
                'username'   => mb_substr($username, 0, 50),
                'ip'         => mb_substr($this->clientIp(), 0, 45),
                'user_agent' => mb_substr((string) $this->request->header('user-agent', ''), 0, 255),
                'success'    => $success ? 1 : 0,
                'message'    => mb_substr($message, 0, 100),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 忽略日志失败
        }
        audit_log('auth', $success ? 'login' : 'login_fail', $message, 'admin', 0, $username, 0);
    }

    protected function deviceLabel(string $ua): string
    {
        $os = '未知设备';
        foreach ([
            'Windows' => 'Windows',
            'iPhone'  => 'iPhone',
            'iPad'    => 'iPad',
            'Android' => 'Android',
            'Mac OS'  => 'macOS',
            'Macintosh' => 'macOS',
            'Linux'   => 'Linux',
        ] as $needle => $label) {
            if (stripos($ua, $needle) !== false) {
                $os = $label;
                break;
            }
        }
        $browser = '';
        foreach ([
            'Edg/'     => 'Edge',
            'OPR/'     => 'Opera',
            'Firefox/' => 'Firefox',
            'Chrome/'  => 'Chrome',
            'Safari/'  => 'Safari',
        ] as $needle => $label) {
            if (stripos($ua, $needle) !== false) {
                $browser = $label;
                break;
            }
        }
        return mb_substr($browser !== '' ? $os . ' · ' . $browser : $os, 0, 100);
    }
}
