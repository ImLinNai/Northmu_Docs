<?php

namespace app\controller\admin;

use app\support\BackupManager;
use think\facade\View;

class Backup extends Base
{
    public function index()
    {
        $manager = new BackupManager();
        $backups = $manager->listBackups();
        $totalSize = 0;
        foreach ($backups as $backup) {
            $totalSize += (int) $backup['size'];
        }
        $latest = $backups[0] ?? null;

        $dbConfig = config('database.connections.mysql');
        $view = $this->viewData('数据备份', '创建带密码保护的备份包，支持在线检测与恢复', 'backup') + [
            'backups'      => $backups,
            'backupCount'  => count($backups),
            'totalSize'    => format_size($totalSize),
            'latestBackup' => $latest ? $latest['created_text'] : '暂无备份',
            'dbName'       => (string) ($dbConfig['database'] ?? ''),
        ];
        $view['extraScripts'] = '<script src="/static/js/admin-backup.js"></script>';

        return View::fetch('/backup', $view);
    }

    public function create()
    {
        if (!$this->request->isPost()) {
            return redirect('/admin/backup');
        }

        $password = (string) $this->request->post('password', '');
        try {
            $result = (new BackupManager())->create($password);
            audit_log('backup', 'create', '创建数据备份「' . $result['file'] . '」（' . format_size((int) $result['size']) . ($password !== '' ? '，已加密' : '') . '）');
            admin_flash('备份创建成功：' . $result['file']);
        } catch (\Throwable $e) {
            admin_flash('备份失败：' . $e->getMessage(), 'danger');
        }

        return redirect('/admin/backup');
    }

    public function download()
    {
        $file = (string) $this->request->get('file', '');
        try {
            $manager = new BackupManager();
            $path = $manager->resolve($file);
        } catch (\Throwable $e) {
            admin_flash($e->getMessage(), 'danger');
            return redirect('/admin/backup');
        }

        audit_log('backup', 'download', '下载数据备份「' . basename($path) . '」');
        return download($path, basename($path));
    }

    public function delete()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请求方式不正确');
        }
        $file = (string) $this->request->post('file', '');
        try {
            (new BackupManager())->delete($file);
            audit_log('backup', 'delete', '删除数据备份「' . basename($file) . '」');
            return $this->ok('备份已删除');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function inspect()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请求方式不正确');
        }
        $file = (string) $this->request->post('file', '');
        $password = (string) $this->request->post('password', '');
        try {
            $result = (new BackupManager())->inspect($file, $password);
            return $this->ok('检测通过', $result);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function restore()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请求方式不正确');
        }
        $file = (string) $this->request->post('file', '');
        $password = (string) $this->request->post('password', '');
        $mode = (string) $this->request->post('mode', 'skip');
        try {
            $result = (new BackupManager())->restore($file, $password, $mode);
            audit_log('backup', 'restore', '恢复数据备份「' . basename($file) . '」（' . ($result['mode'] === 'overwrite' ? '覆盖' : '跳过') . '模式，' . $result['tables'] . ' 张表 / ' . $result['rows'] . ' 条记录 / ' . $result['uploads'] . ' 个附件）');
            return $this->ok('恢复完成', $result);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}
