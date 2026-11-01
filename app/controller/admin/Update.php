<?php

namespace app\controller\admin;

use think\facade\Db;
use think\facade\View;

class Update extends Base
{
    protected const GITHUB_REPO = 'ImLinNai/Northmu_Docs';
    protected const GITEE_REPO = 'imlinnai/Northmu_Docs';
    protected const CACHE_KEY = 'update:latest-release';
    protected const CACHE_TTL = 600;

    public function index()
    {
        $settings = [];
        foreach (Db::name('settings')->select() as $row) {
            $settings[$row['name']] = $row['value'];
        }

        $view = $this->viewData('检测更新', '检查 GitHub / Gitee 发行版，获取最新版本与安装包', 'update') + [
            'currentVersion' => app_version(),
            'skippedVersion' => (string) ($settings['update_skipped_version'] ?? ''),
            'githubUrl'      => 'https://github.com/' . self::GITHUB_REPO . '/releases',
            'giteeUrl'       => 'https://gitee.com/' . self::GITEE_REPO . '/releases',
        ];
        $view['extraScripts'] = '<script src="/static/js/admin-update.js"></script>';

        return View::fetch('/update', $view);
    }

    public function check()
    {
        $force = (int) $this->request->get('force', 0) === 1;
        $current = app_version();
        $errors = [];
        $fallbackReason = '';

        if ($force) {
            cache_forget(self::CACHE_KEY);
        }

        $release = cache_remember(self::CACHE_KEY, self::CACHE_TTL, function () use (&$errors, &$fallbackReason) {
            $github = $this->fetchRelease('github');
            if ($github['ok'] && $github['elapsed'] <= 3.5) {
                return $github['release'];
            }
            if ($github['ok']) {
                $fallbackReason = 'GitHub 响应较慢（' . number_format($github['elapsed'], 1) . ' 秒），已切换 Gitee 检测';
            } elseif ($github['error'] !== '') {
                $errors[] = 'GitHub：' . $github['error'];
                $fallbackReason = 'GitHub 访问失败，已切换 Gitee 检测';
            }

            $gitee = $this->fetchRelease('gitee');
            if ($gitee['ok']) {
                return $gitee['release'];
            }
            if ($gitee['error'] !== '') {
                $errors[] = 'Gitee：' . $gitee['error'];
            }
            if ($github['ok']) {
                $fallbackReason = 'Gitee 访问失败，已使用 GitHub 结果';
                return $github['release'];
            }
            return null;
        });

        if (!is_array($release) || empty($release['latest'])) {
            return $this->fail('检测更新失败：' . ($errors ? implode('；', $errors) : 'GitHub 与 Gitee 均无法访问，请检查服务器网络后重试'));
        }

        $latest = (string) $release['latest'];
        $skippedVersion = (string) sys_setting('update_skipped_version', '');

        $data = $release + [
            'current'       => $current,
            'has_update'    => version_compare($latest, $current, '>'),
            'skipped'       => $skippedVersion !== '' && version_compare($skippedVersion, $latest, '='),
            'skipped_version' => $skippedVersion,
            'fallback_reason' => $fallbackReason,
            'checked_at'    => date('Y-m-d H:i:s'),
        ];
        $data['source_label'] = $data['source'] === 'github' ? 'GitHub' : 'Gitee';

        return $this->ok('检测完成', $data);
    }

    public function skip()
    {
        $version = trim((string) $this->request->post('version', ''));
        if ($version === '' || !preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,39}$/', $version)) {
            return $this->fail('版本号格式不正确');
        }

        save_setting('update_skipped_version', $version);
        cache_forget('site:settings');
        audit_log('update', 'skip', '跳过版本 ' . $version);

        return $this->ok('已跳过 ' . $version . '，后续新版本仍会正常提示');
    }

    protected function fetchRelease(string $source): array
    {
        $started = microtime(true);
        if ($source === 'github') {
            $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
            $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
        } else {
            $url = 'https://gitee.com/api/v5/repos/' . self::GITEE_REPO . '/releases/latest';
            $headers = ['Accept: application/json'];
        }

        $response = $this->httpGetJson($url, $headers);
        $elapsed = microtime(true) - $started;
        if (!$response['ok']) {
            return ['ok' => false, 'elapsed' => $elapsed, 'error' => $response['error'], 'release' => null];
        }

        $raw = $response['data'];
        if (!is_array($raw) || empty($raw['tag_name'])) {
            return ['ok' => false, 'elapsed' => $elapsed, 'error' => '发行版数据格式不正确', 'release' => null];
        }

        $tag = trim((string) $raw['tag_name']);
        $version = ltrim($tag, 'vV');
        if ($version === '' || !preg_match('/^\d+(?:\.\d+)*/', $version)) {
            return ['ok' => false, 'elapsed' => $elapsed, 'error' => '发行版版本号格式不正确', 'release' => null];
        }

        $assets = is_array($raw['assets'] ?? null) ? $raw['assets'] : [];
        $downloadUrl = '';
        $downloadName = '';
        $downloadSize = 0;
        $fallbackUrl = '';
        $fallbackName = '';
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            $link = (string) ($asset['browser_download_url'] ?? $asset['download_url'] ?? '');
            if ($link === '' || !preg_match('#^https://#i', $link) || !preg_match('/\.zip$/i', $name)) {
                continue;
            }
            if ($fallbackUrl === '') {
                $fallbackUrl = $link;
                $fallbackName = $name;
            }
            if (stripos($name, 'Northmu_Docs') !== false) {
                $downloadUrl = $link;
                $downloadName = $name;
                $downloadSize = (int) ($asset['size'] ?? 0);
                break;
            }
        }
        if ($downloadUrl === '') {
            $downloadUrl = $fallbackUrl;
            $downloadName = $fallbackName;
        }

        $repoUrl = $source === 'github'
            ? 'https://github.com/' . self::GITHUB_REPO
            : 'https://gitee.com/' . self::GITEE_REPO;

        return [
            'ok'      => true,
            'elapsed' => $elapsed,
            'error'   => '',
            'release' => [
                'latest'        => $version,
                'tag'           => $tag,
                'name'          => trim((string) ($raw['name'] ?? '')) ?: ('v' . $version),
                'notes'         => mb_substr((string) ($raw['body'] ?? ''), 0, 8000),
                'published_at'  => (string) ($raw['published_at'] ?? $raw['created_at'] ?? ''),
                'release_url'   => $repoUrl . '/releases/tag/' . rawurlencode($tag),
                'download_url'  => $downloadUrl,
                'download_name' => $downloadName,
                'download_size' => $downloadSize,
                'source'        => $source,
            ],
        ];
    }

    protected function httpGetJson(string $url, array $headers = []): array
    {
        $caFile = dirname(__DIR__, 3) . '/extend/certs/cacert.pem';
        if (!is_file($caFile)) {
            $iniCa = (string) ini_get('curl.cainfo');
            $caFile = $iniCa !== '' && is_file($iniCa) ? $iniCa : '';
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
                CURLOPT_USERAGENT      => 'NorthmuDocs/' . app_version(),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($caFile !== '') {
                curl_setopt($ch, CURLOPT_CAINFO, $caFile);
            }
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = (string) curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                return ['ok' => false, 'error' => $error !== '' ? $error : '网络请求失败', 'data' => null];
            }
            if ($status < 200 || $status >= 300) {
                return ['ok' => false, 'error' => '接口返回 HTTP ' . $status, 'data' => null];
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method'        => 'GET',
                    'header'        => implode("\r\n", array_merge(['User-Agent: NorthmuDocs/' . app_version()], $headers)),
                    'timeout'       => 5,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                ] + ($caFile !== '' ? ['cafile' => $caFile] : []),
            ]);
            $body = @file_get_contents($url, false, $context);
            $status = 0;
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
                $status = (int) $matches[1];
            }
            if ($body === false) {
                return ['ok' => false, 'error' => '网络请求失败', 'data' => null];
            }
            if ($status !== 0 && ($status < 200 || $status >= 300)) {
                return ['ok' => false, 'error' => '接口返回 HTTP ' . $status, 'data' => null];
            }
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => '返回内容不是有效的 JSON', 'data' => null];
        }

        return ['ok' => true, 'error' => '', 'data' => $data];
    }
}
