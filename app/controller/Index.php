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

        return View::fetch('index/index', [
            'siteName'    => $siteName,
            'siteLogo'    => (string) sys_setting('site_logo', ''),
            'siteDesc'    => $siteDesc,
            'siteIcp'     => sys_setting('site_icp', ''),
            'copyright'   => $copyright,
            'pageTitle'   => $siteName,
            'navActive'   => 'home',
            'navItems'    => front_nav_items(),
            'siteBrandMode'  => $front['brandMode'],
            'siteBrandImage' => $front['brandImage'],
            'siteNavStyle'   => $front['navStyle'],
            'siteNavLogin'   => $front['navLogin'],
            'themeColor'  => site_theme_color(),
            'home'        => $home,
            'homeButtons' => $home['buttons'],
            'adminLogged' => admin_logged_in(),
            'adminProfile'=> admin_logged_in() ? admin_profile() : null,
            'author'      => site_author(),
            'hideFooter'  => true,
            'searchKeyword' => '',
            'extraHead'   => '',
            'extraScripts'=> '',
        ]);
    }
}
