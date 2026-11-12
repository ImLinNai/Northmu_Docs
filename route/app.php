<?php
use think\facade\Route;

// 前台
Route::get('/', 'Index/index');
Route::get('link-notice', 'Index/linkNotice');
Route::get('sitemap.xml', 'Seo/sitemap');
Route::get('robots.txt', 'Seo/robots');

// 访客文档
Route::get('docs', 'Doc/index');
Route::get('docs/search', 'Doc/search');
Route::post('docs/unlock', 'Doc/categoryUnlock');
Route::get('doc/:id', 'Doc/read')->pattern(['id' => '\d+(?:\.html)?']);
Route::post('doc/:id/unlock', 'Doc/unlock')->pattern(['id' => '\d+']);

// 维护页面预览（仅管理员）
Route::get('maintenance/preview/:template', 'Maintenance/preview')
    ->pattern(['template' => 'macos|cyberpunk|code|minimal'])
    ->middleware(\app\middleware\AdminAuth::class);

// 安装向导
Route::get('install', 'Install/index');
Route::post('install/check', 'Install/check');
Route::post('install/test-db', 'Install/testDatabase');
Route::post('install/test-redis', 'Install/testRedis');
Route::post('install/deploy', 'Install/deploy');

// 后台登录
Route::get('admin/login', 'admin.Login/index');
Route::post('admin/login', 'admin.Login/doLogin');
Route::get('admin/logout', 'admin.Login/logout');

// 通行密钥登录（公开）
Route::get('admin/passkey/options', 'admin.Passkey/options');
Route::post('admin/passkey/login', 'admin.Passkey/login');

// 后台（需登录）
Route::group('admin', function () {
    Route::get('/', 'admin.Index/index');
    Route::get('', 'admin.Index/index');

    // 分类
    Route::get('categories', 'admin.Category/index');
    Route::post('categories/save', 'admin.Category/save');
    Route::post('categories/sort', 'admin.Category/sort');
    Route::post('categories/delete', 'admin.Category/delete');

    // 文档
    Route::get('documents', 'admin.Document/index');
    Route::get('documents/create', 'admin.Document/create');
    Route::get('documents/edit/:id', 'admin.Document/edit')->pattern(['id' => '\d+']);
    Route::get('documents/trash', 'admin.Document/trash');
    Route::get('documents/versions/:id', 'admin.Document/versions')->pattern(['id' => '\d+']);
    Route::post('documents/save', 'admin.Document/save');
    Route::post('documents/delete', 'admin.Document/delete');
    Route::post('documents/batch', 'admin.Document/batch');
    Route::post('documents/restore', 'admin.Document/restore');
    Route::post('documents/purge', 'admin.Document/purge');
    Route::post('documents/rollback', 'admin.Document/rollback');
    Route::post('documents/push/:id', 'admin.Document/push')->pattern(['id' => '\d+']);
    Route::post('documents/draft', 'admin.Document/saveDraft');
    Route::post('documents/draft/discard', 'admin.Document/discardDraft');

    // 公告通知
    Route::get('notices', 'admin.Notice/index');
    Route::get('notices/create', 'admin.Notice/create');
    Route::get('notices/edit/:id', 'admin.Notice/edit')->pattern(['id' => '\d+']);
    Route::post('notices/save', 'admin.Notice/save');
    Route::post('notices/toggle', 'admin.Notice/toggle');
    Route::post('notices/delete', 'admin.Notice/delete');

    // 标签
    Route::get('tags', 'admin.Tag/index');
    Route::post('tags/save', 'admin.Tag/save');
    Route::post('tags/delete', 'admin.Tag/delete');
    Route::post('tags/merge', 'admin.Tag/merge');

    // 附件
    Route::get('attachments', 'admin.Attachment/index');
    Route::get('attachments/picker', 'admin.Attachment/picker');
    Route::post('attachments/upload', 'admin.Attachment/upload');
    Route::post('attachments/delete', 'admin.Attachment/delete');
    Route::post('attachments/cleanup', 'admin.Attachment/cleanup');

    // 前台设置
    Route::get('appearance', 'admin.Appearance/index');
    Route::post('appearance/save', 'admin.Appearance/save');

    // 系统设置
    Route::get('settings', 'admin.Settings/index');
    Route::post('settings/save', 'admin.Settings/save');
    Route::post('settings/password', 'admin.Settings/password');
    Route::post('settings/profile', 'admin.Settings/profile');

    // 通行密钥管理
    Route::post('passkey/register/options', 'admin.Passkey/registerOptions');
    Route::post('passkey/register', 'admin.Passkey/register');
    Route::post('passkey/delete', 'admin.Passkey/delete');

    // 操作日志
    Route::get('logs', 'admin.AuditLog/index');
    Route::post('logs/clear', 'admin.AuditLog/clear');
    Route::get('logs/export', 'admin.AuditLog/export');

    // 缓存管理
    Route::get('cache', 'admin.Cache/index');
    Route::post('cache/save', 'admin.Cache/save');
    Route::post('cache/clear', 'admin.Cache/clear');
    Route::post('cache/test', 'admin.Cache/test');

    // 数据备份
    Route::get('backup', 'admin.Backup/index');
    Route::post('backup/create', 'admin.Backup/create');
    Route::get('backup/download', 'admin.Backup/download');

    // 检测更新
    Route::get('update', 'admin.Update/index');
    Route::get('update/check', 'admin.Update/check');
    Route::post('update/skip', 'admin.Update/skip');
})->middleware(\app\middleware\AdminAuth::class);
