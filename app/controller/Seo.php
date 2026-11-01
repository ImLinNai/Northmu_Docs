<?php

namespace app\controller;

use app\BaseController;
use think\facade\Db;

class Seo extends BaseController
{
    public function sitemap()
    {
        $xml = cache_remember('site:sitemap', cache_ttl(), function () {
            return $this->buildSitemap();
        });
        if (!is_string($xml) || $xml === '') {
            $xml = $this->buildSitemap();
        }

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public function robots()
    {
        $base = site_base_url();
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /install',
            'Disallow: /docs/search',
            'Disallow: /doc/*/unlock',
        ];
        if ($base !== '') {
            $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';
        }

        return response(implode("\n", $lines) . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    protected function buildSitemap(): string
    {
        $base = site_base_url();
        $now = date('c');
        $urls = [
            ['loc' => $base . '/', 'lastmod' => $now, 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => $base . '/docs', 'lastmod' => $now, 'changefreq' => 'daily', 'priority' => '0.9'],
        ];

        $categories = site_categories();
        foreach ($categories as $category) {
            if (category_effective_visibility((int) $category['id'], $categories) !== 'public') {
                continue;
            }
            $lastmod = seo_iso_time($category['updated_at'] ?? ($category['created_at'] ?? ''));
            $urls[] = [
                'loc'        => $base . '/docs?cat=' . (int) $category['id'],
                'lastmod'    => $lastmod !== '' ? $lastmod : $now,
                'changefreq' => 'weekly',
                'priority'   => '0.6',
            ];
        }

        $docs = Db::name('documents')
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->field('id,category_id,visibility,updated_at,published_at')
            ->order('updated_at', 'desc')
            ->select()
            ->toArray();
        foreach ($docs as $doc) {
            if (document_access($doc, $categories)['visibility'] !== 'public') {
                continue;
            }
            $lastmod = seo_iso_time($doc['updated_at'] ?? ($doc['published_at'] ?? ''));
            $urls[] = [
                'loc'        => document_url((int) $doc['id'], true),
                'lastmod'    => $lastmod !== '' ? $lastmod : $now,
                'changefreq' => 'monthly',
                'priority'   => '0.8',
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . htmlspecialchars($url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
            if ($url['lastmod'] !== '') {
                $xml .= '<lastmod>' . htmlspecialchars($url['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</lastmod>';
            }
            $xml .= '<changefreq>' . $url['changefreq'] . '</changefreq>';
            $xml .= '<priority>' . $url['priority'] . '</priority></url>' . "\n";
        }
        $xml .= '</urlset>';

        return $xml;
    }
}
