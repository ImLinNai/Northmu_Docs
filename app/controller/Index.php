<?php

namespace app\controller;

use app\BaseController;
use think\facade\View;

class Index extends BaseController
{
    public function index()
    {
        $siteName = sys_setting('site_name', 'Northmu Docs');
        $siteDesc = sys_setting('site_desc', '个人文档与笔记管理系统');
        $copyright = sys_setting('site_copyright', '© ' . date('Y') . ' ' . $siteName);
        $front = site_front_config();
        $home = site_home_config();
        $seoGlobal = seo_global_config();
        $seo = seo_meta([
            'title'       => $seoGlobal['title'],
            'description' => $seoGlobal['description'],
            'keywords'    => $seoGlobal['keywords'],
            'canonical'   => $seoGlobal['url'] !== '' ? $seoGlobal['url'] . '/' : '',
            'robots'      => 'index,follow',
            'type'        => 'website',
            'image'       => $seoGlobal['logo'],
            'json_ld'     => seo_json_encode(seo_website_json_ld()),
        ]);

        return View::fetch('index/index', [
            'siteName'    => $siteName,
            'siteLogo'    => (string) sys_setting('site_logo', ''),
            'siteDesc'    => $siteDesc,
            'siteIcp'     => sys_setting('site_icp', ''),
            'copyright'   => $copyright,
            'pageTitle'   => $seoGlobal['title'],
            'navActive'   => 'home',
            'navItems'    => front_nav_items(),
            'siteBrandMode'  => $front['brandMode'],
            'siteBrandImage' => $front['brandImage'],
            'siteNavStyle'   => $front['navStyle'],
            'siteNavLogin'   => $front['navLogin'],
            'contextMenuMode'=> site_context_menu_mode(),
            'siteCopyGuard'  => site_copy_guard(),
            'memorialActive' => memorial_active_for(true),
            'musicEnabled' => site_music_enabled(),
            'musicJson'   => site_music_json(),
            'themeColor'  => site_theme_color(),
            'home'        => $home,
            'homeButtons' => $home['buttons'],
            'immersiveNav' => $home['bgType'] !== 'none',
            'adminLogged' => admin_logged_in(),
            'adminProfile'=> admin_logged_in() ? admin_profile() : null,
            'maintenanceBanner' => admin_logged_in() ? maintenance_state() : null,
            'noticeBanner' => front_notices('banner'),
            'noticePopups' => front_notices('popup'),
            'noticePlacement' => 'home',
            'author'      => site_author(),
            'hideFooter'  => true,
            'searchKeyword' => '',
            'extraHead'   => '',
            'extraScripts'=> '',
        ] + $seo);
    }

    public function linkNotice()
    {
        if (!site_link_notice_enabled()) {
            return redirect('/');
        }

        $raw = trim((string) $this->request->get('url', ''));
        if (strlen($raw) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $raw)) {
            $raw = '';
        }

        $target = '';
        $targetHost = '';
        if ($raw !== '' && preg_match('#^https?://#i', $raw)) {
            $parts = parse_url($raw);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $targetHost = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
            if (
                in_array($scheme, ['http', 'https'], true)
                && $targetHost !== ''
                && !isset($parts['user'])
                && !isset($parts['pass'])
            ) {
                $target = $raw;
            }
        }

        $siteHost = strtolower((string) parse_url((string) $this->request->domain(), PHP_URL_HOST));
        if ($target === '' || ($siteHost !== '' && $targetHost === $siteHost)) {
            return redirect('/');
        }

        $siteName = (string) sys_setting('site_name', 'Northmu Docs');
        return View::fetch('index/link_notice', [
            'pageTitle'   => '即将离开本站 - ' . $siteName,
            'siteName'    => $siteName,
            'siteLogo'    => (string) sys_setting('site_logo', ''),
            'targetUrl'   => $target,
            'targetHost'  => $targetHost,
            'openBlank'   => (int) $this->request->get('blank', 0) === 1,
            'themeColor'  => site_theme_color(),
            'faviconUrl'  => site_favicon_url(),
        ]);
    }
}
