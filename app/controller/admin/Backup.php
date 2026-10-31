<?php

namespace app\controller\admin;

use think\facade\View;

class Backup extends Base
{
    public function index()
    {
        $backups = [];
        $totalSize = 0;
        foreach (glob($this->backupDir() . 'backup_*.zip') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $size = (int) filesize($path);
            $name = basename($path);
            $totalSize += $size;
            $backups[] = [
                'name'         => $name,
                'size_text'    => format_size($size),
                'created_text' => date('Y-m-d H:i:s', (int) filemtime($path)),
                'download_url' => '/admin/backup/download?file=' . rawurlencode($name),
            ];
        }
        usort($backups, function ($a, $b) {
            return strcmp($b['created_text'], $a['created_text']);
        });

        $dbConfig = config('database.connections.mysql');
        $view = $this->viewData('数据备份', '一键导出数据库与附件，支持下载留存', 'backup') + [
            'backups'     => $backups,
            'backupCount' => count($backups),
            'totalSize'   => format_size($totalSize),
            'dbName'      => (string) ($dbConfig['database'] ?? ''),
        ];

        return View::fetch('/backup', $view);
    }

    public function create()
    {
        if (!class_exists('\ZipArchive')) {
            admin_flash('服务器未启用 zip 扩展，无法创建备份', 'danger');
            return redirect('/admin/backup');
        }

        try {
            $path = $this->createBackup();
            audit_log('backup', 'create_backup', '创建数据备份「' . basename($path) . '」（' . format_size((int) filesize($path)) . '）');
            admin_flash('备份创建成功：' . basename($path));
        } catch (\Throwable $e) {
            admin_flash('备份失败：' . $e->getMessage(), 'danger');
        }

        return redirect('/admin/backup');
    }

    public function download()
    {
        $name = basename((string) $this->request->get('file', ''));
        if (!preg_match('/^backup_\d{8}_\d{6}_[a-f0-9]{4}\.zip$/', $name)) {
            admin_flash('备份文件不存在', 'danger');
            return redirect('/admin/backup');
        }

        $path = $this->backupDir() . $name;
        $root = realpath($this->backupDir());
        $real = realpath($path);
        if (!$real || !$root || !str_starts_with($real, $root) || !is_file($real)) {
            admin_flash('备份文件不存在', 'danger');
            return redirect('/admin/backup');
        }

        audit_log('backup', 'download', '下载数据备份「' . $name . '」');
        return download($real, $name);
    }

    protected function backupDir(): string
    {
        $dir = root_path('data') . 'backups' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    protected function createBackup(): string
    {
        $dbConfig = config('database.connections.mysql');
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $dbConfig['hostname'] ?? '127.0.0.1',
            $dbConfig['hostport'] ?? '3306',
            $dbConfig['database'] ?? '',
            $dbConfig['charset'] ?? 'utf8mb4'
        );
        $pdo = new \PDO(
            $dsn,
            (string) ($dbConfig['username'] ?? ''),
            (string) ($dbConfig['password'] ?? ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $tmpSql = root_path('runtime') . 'backup_' . bin2hex(random_bytes(6)) . '.sql';
        $zipPath = $this->backupDir() . 'backup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '.zip';
        $fp = null;

        try {
            $fp = fopen($tmpSql, 'wb');
            if (!$fp) {
                throw new \RuntimeException('无法创建临时 SQL 文件');
            }

            fwrite($fp, "-- Northmu Docs database backup\n");
            fwrite($fp, '-- Database: ' . ($dbConfig['database'] ?? '') . "\n");
            fwrite($fp, '-- Generated: ' . date('Y-m-d H:i:s') . "\n");
            fwrite($fp, '-- Compatible with MySQL 5.7+\n');
            fwrite($fp, "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $quotedTable = '`' . str_replace('`', '``', (string) $table) . '`';

                $createRow = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(\PDO::FETCH_ASSOC);
                $createSql = $createRow['Create Table'] ?? '';
                if ($createSql === '') {
                    continue;
                }
                fwrite($fp, 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n");
                fwrite($fp, $createSql . ";\n\n");

                $columns = $pdo->query('SHOW COLUMNS FROM ' . $quotedTable)->fetchAll(\PDO::FETCH_COLUMN);
                $columnList = implode(',', array_map(function ($column) {
                    return '`' . str_replace('`', '``', (string) $column) . '`';
                }, $columns));

                $rows = $pdo->query('SELECT * FROM ' . $quotedTable);
                $buffer = [];
                while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
                    $values = [];
                    foreach (array_values($row) as $value) {
                        $values[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
                    }
                    $buffer[] = '(' . implode(',', $values) . ')';
                    if (count($buffer) >= 50) {
                        fwrite($fp, 'INSERT INTO ' . $quotedTable . ' (' . $columnList . ') VALUES ' . implode(',', $buffer) . ";\n");
                        $buffer = [];
                    }
                }
                if ($buffer) {
                    fwrite($fp, 'INSERT INTO ' . $quotedTable . ' (' . $columnList . ') VALUES ' . implode(',', $buffer) . ";\n");
                }
                fwrite($fp, "\n");
            }

            fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
            fclose($fp);

            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('无法创建备份压缩包');
            }
            $zip->addFile($tmpSql, 'database.sql');
            $zip->addEmptyDir('uploads');

            $uploads = public_path() . 'uploads';
            if (is_dir($uploads)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($uploads, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iterator as $file) {
                    if (!$file->isFile()) {
                        continue;
                    }
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($uploads) + 1));
                    $zip->addFile($file->getPathname(), 'uploads/' . $relative);
                }
            }

            if (!$zip->close()) {
                throw new \RuntimeException('备份压缩包写入失败');
            }

            return $zipPath;
        } catch (\Throwable $e) {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
            throw $e;
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
            if (is_file($tmpSql)) {
                @unlink($tmpSql);
            }
        }
    }
}
