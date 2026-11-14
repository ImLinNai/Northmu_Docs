<?php

namespace app\support;

/**
 * 素颜API · 通义3.0Ai 对话客户端
 */
class SuyanApi
{
    private const ENDPOINT = 'https://api.suyanw.cn/api/zpai.php';

    /**
     * @return array{content:string,usage:array}
     */
    public static function chat(string $message): array
    {
        $url = self::ENDPOINT . '?' . http_build_query(['msg' => $message]);
        $caFile = dirname(__DIR__, 2) . '/extend/certs/cacert.pem';
        if (!is_file($caFile)) {
            $iniCa = (string) ini_get('curl.cainfo');
            $caFile = $iniCa !== '' && is_file($iniCa) ? $iniCa : '';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'NorthmuDocs/' . app_version(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($caFile !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $caFile);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('AI 服务连接失败，请稍后重试');
        }
        if ($status === 429) {
            throw new \RuntimeException('AI 服务请求过于频繁，请稍后再试');
        }
        if ($status >= 500) {
            throw new \RuntimeException('AI 服务暂时不可用，请稍后再试');
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('AI 服务返回内容无法解析，请稍后再试');
        }

        $state = strtolower((string) ($data['status'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));
        if ($state !== 'success' || $content === '') {
            $message = trim((string) ($data['msg'] ?? $data['message'] ?? ''));
            throw new \RuntimeException($message !== '' ? $message : 'AI 暂时无法回复，请换个问法试试');
        }

        return [
            'content' => $content,
            'usage'   => is_array($data['usage'] ?? null) ? $data['usage'] : [],
        ];
    }
}
