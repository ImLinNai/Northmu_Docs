<?php

namespace app\support;

/**
 * QQ 互联（QQ Connect）网站应用 OAuth 客户端，仅覆盖登录所需的最小接口。
 */
class QqConnect
{
    private string $appId;
    private string $appKey;
    private string $redirectUri;

    public function __construct(string $appId, string $appKey, string $redirectUri)
    {
        $this->appId = $appId;
        $this->appKey = $appKey;
        $this->redirectUri = $redirectUri;
    }

    public function authorizeUrl(string $state): string
    {
        return self::base() . '/oauth2.0/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->appId,
            'redirect_uri'  => $this->redirectUri,
            'state'         => $state,
            'scope'         => 'get_user_info',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{access_token:string, expires_in?:string, refresh_token?:string}
     */
    public function exchangeCode(string $code): array
    {
        $data = $this->request('/oauth2.0/token?' . http_build_query([
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->appId,
            'client_secret' => $this->appKey,
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
            'fmt'           => 'json',
        ], '', '&', PHP_QUERY_RFC3986));

        $token = trim((string) ($data['access_token'] ?? ''));
        if ($token === '') {
            throw new \RuntimeException('QQ 授权失败：未获取到访问令牌');
        }
        $data['access_token'] = $token;

        return $data;
    }

    public function fetchOpenid(string $accessToken): string
    {
        $data = $this->request('/oauth2.0/me?' . http_build_query([
            'access_token' => $accessToken,
            'fmt'          => 'json',
        ], '', '&', PHP_QUERY_RFC3986));

        $openid = trim((string) ($data['openid'] ?? ''));
        if ($openid === '') {
            throw new \RuntimeException('QQ 授权失败：未获取到用户 OpenID');
        }

        return $openid;
    }

    /**
     * @return array{nickname:string, avatar:string}
     */
    public function fetchUserInfo(string $accessToken, string $openid): array
    {
        $data = $this->request('/user/get_user_info?' . http_build_query([
            'access_token'       => $accessToken,
            'oauth_consumer_key' => $this->appId,
            'openid'             => $openid,
            'fmt'                => 'json',
        ], '', '&', PHP_QUERY_RFC3986));

        $avatar = trim((string) ($data['figureurl_qq_2'] ?? ''));
        if ($avatar === '') {
            $avatar = trim((string) ($data['figureurl_qq_1'] ?? ''));
        }
        if ($avatar === '') {
            $avatar = trim((string) ($data['figureurl'] ?? ''));
        }

        return [
            'nickname' => trim((string) ($data['nickname'] ?? '')),
            'avatar'   => $avatar,
        ];
    }

    /**
     * 本地联调时可通过 QQ_GRAPH_BASE 指向模拟服务
     */
    private static function base(): string
    {
        $base = trim((string) getenv('QQ_GRAPH_BASE'));
        return $base !== '' ? rtrim($base, '/') : 'https://graph.qq.com';
    }

    private function request(string $path): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('服务器未启用 cURL 扩展，无法完成 QQ 登录');
        }

        $url = preg_match('#^https?://#i', $path) ? $path : self::base() . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'NorthmuDocs QQConnect',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status >= 500) {
            throw new \RuntimeException('无法连接 QQ 互联服务，请稍后重试');
        }

        $data = self::decode((string) $body);
        if (!empty($data['error'])) {
            throw new \RuntimeException(self::errorMessage(
                (string) $data['error'],
                (string) ($data['error_description'] ?? '')
            ));
        }
        if (isset($data['ret']) && (int) $data['ret'] !== 0) {
            $message = trim((string) ($data['msg'] ?? ''));
            throw new \RuntimeException('QQ 用户信息获取失败' . ($message !== '' ? '：' . $message : '（错误码 ' . (int) $data['ret'] . '）'));
        }

        return $data;
    }

    private static function decode(string $body): array
    {
        $text = trim($body);
        if (stripos($text, 'callback') === 0) {
            $start = strpos($text, '(');
            $end = strrpos($text, ')');
            if ($start !== false && $end !== false && $end > $start) {
                $text = trim(substr($text, $start + 1, $end - $start - 1));
            }
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new \RuntimeException('QQ 互联返回数据格式异常，请稍后重试');
        }

        return $data;
    }

    private static function errorMessage(string $code, string $description): string
    {
        $map = [
            '100019' => 'QQ 授权码已失效，请重新登录',
            '100020' => 'QQ 访问令牌无效，请重新登录',
            '100030' => 'QQ 授权已过期，请重新登录',
            '100035' => 'QQ 账号未授权该应用，请重新授权',
            '100016' => 'QQ 应用参数错误，请检查 AppID 与 AppKey',
        ];
        if (isset($map[$code])) {
            return $map[$code];
        }
        $description = trim($description);

        return 'QQ 授权失败' . ($description !== '' ? '：' . $description : '（错误码 ' . $code . '）');
    }
}
