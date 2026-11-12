<?php

namespace app\support;

use PDO;
use RuntimeException;
use ZipArchive;

/**
 * 数据备份：生成带清单的备份包，支持可选密码加密、完整性检测与在线恢复。
 *
 * 备份包结构（format 2）：
 *   未加密：manifest.json + database.sql + uploads/
 *   已加密：manifest.json + payload.enc（AES-256-CBC + HMAC-SHA256 加密的 ZIP）
 */
class BackupManager
{
    public const FORMAT = 2;
    public const MAGIC = 'NMBK01';
    public const HEADER_SIZE = 78; // magic(6) + salt(16) + iv(16) + mac(32) + plainLen(8)
    public const CHUNK = 1048576;
    public const KDF_ITERATIONS = 120000;
    public const MAX_PASSWORD_LENGTH = 128;

    protected string $dir;

    public function __construct()
    {
        $this->dir = root_path('data') . 'backups' . DIRECTORY_SEPARATOR;
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /* ================= 列表 ================= */

    public function listBackups(): array
    {
        $items = [];
        foreach (glob($this->dir . '*.zip') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $size = (int) filesize($path);
            $meta = null;
            $error = '';
            try {
                $meta = $this->readManifest($path);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            $tables = is_array($meta['tables'] ?? null) ? $meta['tables'] : [];
            $rows = 0;
            foreach ($tables as $table) {
                $rows += (int) ($table['rows'] ?? 0);
            }
            $createdTs = (int) filemtime($path);
            $items[] = [
                'name'            => basename($path),
                'size'            => $size,
                'size_text'       => format_size($size),
                'created_ts'      => $createdTs,
                'created_text'    => date('Y-m-d H:i:s', $createdTs),
                'format'          => (int) ($meta['format'] ?? 1),
                'site_name'       => (string) ($meta['site_name'] ?? ''),
                'app_version'     => (string) ($meta['app_version'] ?? ''),
                'db_name'         => (string) ($meta['db_name'] ?? ''),
                'encrypted'       => !empty($meta['encrypted']),
                'table_count'     => count($tables),
                'row_count'       => $rows,
                'upload_count'    => (int) ($meta['uploads']['count'] ?? 0),
                'upload_bytes'    => (int) ($meta['uploads']['bytes'] ?? 0),
                'documents'       => (int) ($meta['summary']['documents'] ?? 0),
                'drafts'          => (int) ($meta['summary']['drafts'] ?? 0),
                'attachments'     => (int) ($meta['summary']['attachments'] ?? 0),
                'valid'           => $meta !== null,
                'error'           => $error,
                'download_url'    => '/admin/backup/download?file=' . rawurlencode(basename($path)),
            ];
        }
        usort($items, function ($a, $b) {
            return $b['created_ts'] <=> $a['created_ts'];
        });
        return $items;
    }

    /* ================= 创建 ================= */

    public function create(string $password = ''): array
    {
        if (!class_exists('\ZipArchive')) {
            throw new RuntimeException('服务器未启用 zip 扩展，无法创建备份');
        }
        $password = (string) $password;
        if (mb_strlen($password) > self::MAX_PASSWORD_LENGTH) {
            throw new RuntimeException('备份密码不能超过 ' . self::MAX_PASSWORD_LENGTH . ' 个字符');
        }

        $pdo = $this->pdo();
        $siteName = (string) sys_setting('site_name', 'Northmu Docs');
        $dbConfig = config('database.connections.mysql');
        $encrypted = $password !== '';

        $tmpDir = $this->tempDir();
        $sqlPath = $tmpDir . 'database.sql';
        $outerPath = $tmpDir . 'backup.zip';

        try {
            [$tables, $rows] = $this->dumpDatabase($pdo, $sqlPath, $siteName);
            $uploads = $this->scanUploads();

            $manifest = [
                'format'      => self::FORMAT,
                'generator'   => 'Northmu Docs',
                'app_version' => app_version(),
                'site_name'   => $siteName,
                'site_url'    => (string) sys_setting('site_url', ''),
                'db_name'     => (string) ($dbConfig['database'] ?? ''),
                'created_at'  => date('Y-m-d H:i:s'),
                'timezone'    => date_default_timezone_get(),
                'encrypted'   => $encrypted,
                'tables'      => $tables,
                'row_count'   => $rows,
                'uploads'     => $uploads,
                'summary'     => [
                    'documents'   => $this->tableRows($tables, 'documents'),
                    'drafts'      => $this->tableRows($tables, 'document_drafts'),
                    'attachments' => $this->tableRows($tables, 'attachments'),
                ],
                'database'    => [
                    'path'   => 'database.sql',
                    'size'   => (int) filesize($sqlPath),
                    'sha256' => (string) hash_file('sha256', $sqlPath),
                ],
            ];

            $zip = new ZipArchive();
            if ($zip->open($outerPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('无法创建备份压缩包');
            }

            if ($encrypted) {
                $innerPath = $tmpDir . 'payload.zip';
                $inner = new ZipArchive();
                if ($inner->open($innerPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('无法创建数据包');
                }
                $inner->addFile($sqlPath, 'database.sql');
                $this->addUploads($inner);
                if (!$inner->close()) {
                    throw new RuntimeException('数据包写入失败');
                }

                $encPath = $tmpDir . 'payload.enc';
                $this->encryptFile($innerPath, $encPath, $password);
                $manifest['cipher'] = 'aes-256-cbc+hmac-sha256';
                $manifest['kdf'] = [
                    'algo'       => 'pbkdf2-sha256',
                    'iterations' => self::KDF_ITERATIONS,
                ];
                $manifest['payload'] = [
                    'path'   => 'payload.enc',
                    'size'   => (int) filesize($encPath),
                    'sha256' => (string) hash_file('sha256', $encPath),
                ];
                $zip->addFile($encPath, 'payload.enc');
            } else {
                $zip->addFile($sqlPath, 'database.sql');
                $this->addUploads($zip);
            }

            $zip->addFromString('manifest.json', $this->encodeManifest($manifest));
            if (!$zip->close()) {
                throw new RuntimeException('备份压缩包写入失败');
            }

            $target = $this->uniquePath($siteName);
            if (!@rename($outerPath, $target)) {
                if (!@copy($outerPath, $target)) {
                    throw new RuntimeException('无法保存备份文件，请检查 data/backups 目录权限');
                }
            }

            return [
                'file' => basename($target),
                'size' => (int) filesize($target),
                'meta' => $manifest,
            ];
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    /* ================= 检测 ================= */

    public function inspect(string $file, string $password = ''): array
    {
        $path = $this->resolve($file);
        $meta = $this->readManifest($path);
        if ((int) ($meta['format'] ?? 0) < 2) {
            throw new RuntimeException('旧版备份不支持在线恢复，请下载后手动导入');
        }

        $encrypted = !empty($meta['encrypted']);
        $password = (string) $password;
        if ($encrypted) {
            if ($password === '') {
                throw new RuntimeException('该备份已设置密码，请输入备份密码');
            }
            if (mb_strlen($password) > self::MAX_PASSWORD_LENGTH) {
                throw new RuntimeException('备份密码不正确');
            }
            $this->verifyEncryptedPayload($path, $meta, $password);
        } else {
            $this->verifyPlainArchive($path, $meta);
        }

        $conflicts = $this->detectConflicts($meta);
        $summary = is_array($meta['summary'] ?? null) ? $meta['summary'] : [];

        return [
            'meta' => [
                'file'           => basename($file),
                'site_name'      => (string) ($meta['site_name'] ?? ''),
                'site_url'       => (string) ($meta['site_url'] ?? ''),
                'db_name'        => (string) ($meta['db_name'] ?? ''),
                'app_version'    => (string) ($meta['app_version'] ?? ''),
                'created_at'     => (string) ($meta['created_at'] ?? ''),
                'encrypted'      => $encrypted,
                'size_text'      => format_size((int) filesize($path)),
                'table_count'    => count($meta['tables'] ?? []),
                'row_count'      => (int) ($meta['row_count'] ?? 0),
                'upload_count'   => (int) ($meta['uploads']['count'] ?? 0),
                'upload_size'    => format_size((int) ($meta['uploads']['bytes'] ?? 0)),
                'documents'      => (int) ($summary['documents'] ?? 0),
                'drafts'         => (int) ($summary['drafts'] ?? 0),
                'attachments'    => (int) ($summary['attachments'] ?? 0),
            ],
            'conflicts' => $conflicts,
        ];
    }

    /* ================= 恢复 ================= */

    public function restore(string $file, string $password, string $mode): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $mode = $mode === 'overwrite' ? 'overwrite' : 'skip';
        $path = $this->resolve($file);
        $meta = $this->readManifest($path);
        if ((int) ($meta['format'] ?? 0) < 2) {
            throw new RuntimeException('旧版备份不支持在线恢复，请下载后手动导入');
        }

        $encrypted = !empty($meta['encrypted']);
        $password = (string) $password;
        if ($encrypted) {
            if ($password === '') {
                throw new RuntimeException('该备份已设置密码，请输入备份密码');
            }
            if (mb_strlen($password) > self::MAX_PASSWORD_LENGTH) {
                throw new RuntimeException('备份密码不正确');
            }
        } else {
            $this->verifyPlainArchive($path, $meta);
        }

        $tmpDir = $this->tempDir();
        try {
            if ($encrypted) {
                $payloadZip = $tmpDir . 'payload.zip';
                $this->decryptArchive($path, $meta, $password, $payloadZip);
                $sourcePath = $payloadZip;
            } else {
                $sourcePath = $path;
            }

            $zip = new ZipArchive();
            if ($zip->open($sourcePath) !== true) {
                throw new RuntimeException('无法读取备份数据包');
            }

            try {
                $sqlPath = $tmpDir . 'database.sql';
                $this->extractEntry($zip, 'database.sql', $sqlPath);
                $database = is_array($meta['database'] ?? null) ? $meta['database'] : [];
                if (($database['sha256'] ?? '') !== '' && !hash_equals((string) $database['sha256'], (string) hash_file('sha256', $sqlPath))) {
                    throw new RuntimeException('数据库备份校验失败，数据包可能已损坏');
                }

                $sqlStats = $this->importSql($sqlPath, $mode);
                $uploadStats = $this->restoreUploads($zip, $meta, $mode);

                $this->clearCaches();

                return [
                    'mode'    => $mode,
                    'tables'  => $sqlStats['tables'],
                    'rows'    => $sqlStats['rows'],
                    'errors'  => $sqlStats['errors'],
                    'uploads' => $uploadStats['copied'],
                    'skipped_uploads' => $uploadStats['skipped'],
                ];
            } finally {
                $zip->close();
            }
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    /* ================= 删除 ================= */

    public function delete(string $file): void
    {
        $path = $this->resolve($file);
        if (!@unlink($path)) {
            throw new RuntimeException('删除失败，请检查 data/backups 目录权限');
        }
    }

    /* ================= 路径 ================= */

    public function resolve(string $file): string
    {
        $name = basename(str_replace('\\', '/', trim($file)));
        if ($name === '' || str_contains($name, '..') || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('备份文件不存在');
        }
        $path = $this->dir . $name;
        $real = realpath($path);
        $root = realpath($this->dir);
        if (!$real || !$root || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
            throw new RuntimeException('备份文件不存在');
        }
        return $real;
    }

    /* ================= 清单 ================= */

    protected function encodeManifest(array $manifest): string
    {
        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('备份清单生成失败');
        }
        return $json;
    }

    public function readManifest(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('无法读取压缩包');
        }
        try {
            $json = $zip->getFromName('manifest.json');
            if ($json === false) {
                throw new RuntimeException('旧版备份包：缺少数据清单，仅支持下载');
            }
            $meta = json_decode($json, true);
            if (!is_array($meta)) {
                throw new RuntimeException('备份清单已损坏');
            }
            return $meta;
        } finally {
            $zip->close();
        }
    }

    /* ================= 完整性 ================= */

    protected function verifyPlainArchive(string $path, array $meta): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('无法读取备份压缩包');
        }
        try {
            $database = is_array($meta['database'] ?? null) ? $meta['database'] : [];
            if (($database['path'] ?? '') === '' || $zip->locateName('database.sql') === false) {
                throw new RuntimeException('备份包缺少数据库文件');
            }
            $this->assertEntryHash($zip, 'database.sql', (string) ($database['sha256'] ?? ''), '数据库文件');

            $files = $meta['uploads']['files'] ?? [];
            if (!is_array($files)) {
                $files = [];
            }
            if ($zip->locateName('uploads/') === false && $files) {
                throw new RuntimeException('备份包缺少附件目录');
            }
            foreach ($files as $item) {
                $relative = $this->safeRelative((string) ($item['path'] ?? ''));
                $entry = 'uploads/' . $relative;
                $this->assertEntryHash($zip, $entry, (string) ($item['sha256'] ?? ''), '附件 ' . $relative);
            }
        } finally {
            $zip->close();
        }
    }

    protected function assertEntryHash(ZipArchive $zip, string $entry, string $expected, string $label): void
    {
        if ($zip->locateName($entry) === false) {
            throw new RuntimeException('备份包缺少' . $label);
        }
        if ($expected === '') {
            return;
        }
        $stream = $zip->getStream($entry);
        if (!$stream) {
            throw new RuntimeException('无法读取' . $label);
        }
        $hash = hash_init('sha256');
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK);
                if ($chunk === false) {
                    break;
                }
                if ($chunk !== '') {
                    hash_update($hash, $chunk);
                }
            }
        } finally {
            fclose($stream);
        }
        if (!hash_equals($expected, hash_final($hash))) {
            throw new RuntimeException($label . '校验失败，备份包可能已损坏');
        }
    }

    protected function verifyEncryptedPayload(string $path, array $meta, string $password): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('无法读取备份压缩包');
        }
        try {
            $payload = is_array($meta['payload'] ?? null) ? $meta['payload'] : [];
            $entry = (string) ($payload['path'] ?? 'payload.enc');
            $stream = $zip->getStream($entry);
            if (!$stream) {
                throw new RuntimeException('备份包缺少加密数据');
            }
            try {
                $this->checkEncryptedStream($stream, $password, (string) ($payload['sha256'] ?? ''));
            } finally {
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * 在不解密整包的前提下校验密码与数据完整性：SHA-256 + HMAC。
     */
    protected function checkEncryptedStream($stream, string $password, string $expectedSha): void
    {
        $header = $this->readExact($stream, self::HEADER_SIZE);
        if (strlen($header) !== self::HEADER_SIZE || substr($header, 0, 6) !== self::MAGIC) {
            throw new RuntimeException('加密数据格式无效');
        }
        $salt = substr($header, 6, 16);
        $mac = substr($header, 38, 32);
        $key = hash_pbkdf2('sha256', $password, $salt, self::KDF_ITERATIONS, 64, true);
        $macKey = substr($key, 32, 32);

        $hmac = hash_init('sha256', HASH_HMAC, $macKey);
        $sha = hash_init('sha256');
        hash_update($sha, $header);
        while (!feof($stream)) {
            $chunk = fread($stream, self::CHUNK);
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                continue;
            }
            hash_update($hmac, $chunk);
            hash_update($sha, $chunk);
        }
        if (!hash_equals($mac, hash_final($hmac, true))) {
            throw new RuntimeException('备份密码不正确，或加密数据已损坏');
        }
        if ($expectedSha !== '' && !hash_equals($expectedSha, hash_final($sha))) {
            throw new RuntimeException('加密数据校验失败，备份包可能已损坏');
        }
    }

    /* ================= 加密 / 解密 ================= */

    protected function encryptFile(string $src, string $dst, string $password): void
    {
        $plainSize = (int) filesize($src);
        $salt = random_bytes(16);
        $iv = random_bytes(16);
        $key = hash_pbkdf2('sha256', $password, $salt, self::KDF_ITERATIONS, 64, true);
        $encKey = substr($key, 0, 32);
        $macKey = substr($key, 32, 32);

        $in = fopen($src, 'rb');
        $out = fopen($dst, 'wb');
        if (!$in || !$out) {
            throw new RuntimeException('无法写入加密数据');
        }

        try {
            $this->writeAll($out, self::MAGIC . $salt . $iv . str_repeat("\0", 32) . pack('J', $plainSize));
            $hmac = hash_init('sha256', HASH_HMAC, $macKey);
            $currentIv = $iv;

            while (true) {
                $chunk = $this->readExact($in, self::CHUNK);
                if ($chunk === '') {
                    break;
                }
                $remainder = strlen($chunk) % 16;
                if ($remainder !== 0) {
                    $chunk .= str_repeat("\0", 16 - $remainder);
                }
                $cipher = openssl_encrypt($chunk, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $currentIv);
                if ($cipher === false) {
                    throw new RuntimeException('数据加密失败');
                }
                $currentIv = substr($cipher, -16);
                hash_update($hmac, $cipher);
                $this->writeAll($out, $cipher);
            }

            $mac = hash_final($hmac, true);
            if (fseek($out, 38) !== 0) {
                throw new RuntimeException('数据加密失败');
            }
            $this->writeAll($out, $mac);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    protected function decryptArchive(string $path, array $meta, string $password, string $zipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('无法读取备份压缩包');
        }
        try {
            $payload = is_array($meta['payload'] ?? null) ? $meta['payload'] : [];
            $entry = (string) ($payload['path'] ?? 'payload.enc');
            $stream = $zip->getStream($entry);
            if (!$stream) {
                throw new RuntimeException('备份包缺少加密数据');
            }
            try {
                $this->decryptStream($stream, $zipPath, $password, (string) ($payload['sha256'] ?? ''));
            } finally {
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    protected function decryptStream($stream, string $dst, string $password, string $expectedSha): void
    {
        $header = $this->readExact($stream, self::HEADER_SIZE);
        if (strlen($header) !== self::HEADER_SIZE || substr($header, 0, 6) !== self::MAGIC) {
            throw new RuntimeException('加密数据格式无效');
        }
        $salt = substr($header, 6, 16);
        $iv = substr($header, 22, 16);
        $mac = substr($header, 38, 32);
        $plainSize = (int) unpack('J', substr($header, 70, 8))[1];

        $key = hash_pbkdf2('sha256', $password, $salt, self::KDF_ITERATIONS, 64, true);
        $encKey = substr($key, 0, 32);
        $macKey = substr($key, 32, 32);

        $out = fopen($dst, 'wb');
        if (!$out) {
            throw new RuntimeException('无法写入解密数据');
        }

        try {
            $hmac = hash_init('sha256', HASH_HMAC, $macKey);
            $sha = hash_init('sha256');
            hash_update($sha, $header);
            $currentIv = $iv;

            while (true) {
                $chunk = $this->readExact($stream, self::CHUNK);
                if ($chunk === '') {
                    break;
                }
                hash_update($hmac, $chunk);
                hash_update($sha, $chunk);
                $plain = openssl_decrypt($chunk, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $currentIv);
                if ($plain === false) {
                    throw new RuntimeException('备份密码不正确，或加密数据已损坏');
                }
                $currentIv = substr($chunk, -16);
                $this->writeAll($out, $plain);
            }

            if (!hash_equals($mac, hash_final($hmac, true))) {
                throw new RuntimeException('备份密码不正确，或加密数据已损坏');
            }
            if ($expectedSha !== '' && !hash_equals($expectedSha, hash_final($sha))) {
                throw new RuntimeException('加密数据校验失败，备份包可能已损坏');
            }
            if ($plainSize < 0 || ftruncate($out, $plainSize) === false) {
                throw new RuntimeException('解密数据不完整');
            }
        } finally {
            fclose($out);
        }
    }

    /* ================= 数据库导出 ================= */

    protected function dumpDatabase(PDO $pdo, string $sqlPath, string $siteName): array
    {
        $fp = fopen($sqlPath, 'wb');
        if (!$fp) {
            throw new RuntimeException('无法创建临时 SQL 文件');
        }

        $tables = [];
        $totalRows = 0;
        try {
            fwrite($fp, "-- Northmu Docs database backup\n");
            fwrite($fp, '-- Site: ' . $siteName . "\n");
            fwrite($fp, '-- Generated: ' . date('Y-m-d H:i:s') . "\n");
            fwrite($fp, "-- Compatible with MySQL 5.7+\n");
            fwrite($fp, "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

            $names = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($names as $table) {
                $quotedTable = '`' . str_replace('`', '``', (string) $table) . '`';
                $createRow = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_ASSOC);
                $createSql = $createRow['Create Table'] ?? '';
                if ($createSql === '') {
                    continue;
                }

                $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . $quotedTable)->fetchColumn();
                $tables[] = ['name' => (string) $table, 'rows' => $count];
                $totalRows += $count;

                fwrite($fp, 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n");
                fwrite($fp, $createSql . ";\n\n");

                $columns = $pdo->query('SHOW COLUMNS FROM ' . $quotedTable)->fetchAll(PDO::FETCH_COLUMN);
                $columnList = implode(',', array_map(function ($column) {
                    return '`' . str_replace('`', '``', (string) $column) . '`';
                }, $columns));

                $insertPrefix = 'INSERT INTO ' . $quotedTable . ' (' . $columnList . ') VALUES ';
                $rows = $pdo->query('SELECT * FROM ' . $quotedTable);
                $buffer = [];
                while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                    $values = [];
                    foreach (array_values($row) as $value) {
                        $values[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
                    }
                    $buffer[] = '(' . implode(',', $values) . ')';
                    if (count($buffer) >= 50) {
                        fwrite($fp, $insertPrefix . implode(',', $buffer) . ";\n");
                        $buffer = [];
                    }
                }
                if ($buffer) {
                    fwrite($fp, $insertPrefix . implode(',', $buffer) . ";\n");
                }
                fwrite($fp, "\n");
            }

            fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
        } finally {
            fclose($fp);
        }

        return [$tables, $totalRows];
    }

    /* ================= 附件 ================= */

    protected function scanUploads(): array
    {
        $root = public_path() . 'uploads';
        $files = [];
        $bytes = 0;
        if (is_dir($root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $full = str_replace('\\', '/', $file->getPathname());
                $base = rtrim(str_replace('\\', '/', $root), '/');
                $relative = ltrim(substr($full, strlen($base)), '/');
                if ($relative === '') {
                    continue;
                }
                $size = (int) $file->getSize();
                $files[] = [
                    'path'   => $relative,
                    'size'   => $size,
                    'sha256' => (string) hash_file('sha256', $file->getPathname()),
                ];
                $bytes += $size;
            }
        }
        usort($files, function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });
        return [
            'count' => count($files),
            'bytes' => $bytes,
            'files' => $files,
        ];
    }

    protected function addUploads(ZipArchive $zip): void
    {
        $root = public_path() . 'uploads';
        $zip->addEmptyDir('uploads');
        if (!is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $full = str_replace('\\', '/', $file->getPathname());
            $base = rtrim(str_replace('\\', '/', $root), '/');
            $relative = ltrim(substr($full, strlen($base)), '/');
            if ($relative !== '') {
                $zip->addFile($file->getPathname(), 'uploads/' . $relative);
            }
        }
    }

    protected function restoreUploads(ZipArchive $zip, array $meta, string $mode): array
    {
        $files = $meta['uploads']['files'] ?? [];
        if (!is_array($files)) {
            $files = [];
        }
        $root = public_path() . 'uploads';
        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
            throw new RuntimeException('无法创建 uploads 目录');
        }

        $copied = 0;
        $skipped = 0;
        foreach ($files as $item) {
            $relative = $this->safeRelative((string) ($item['path'] ?? ''));
            $target = rtrim(str_replace('\\', '/', $root), '/') . '/' . $relative;
            if ($mode === 'skip' && file_exists($target)) {
                $skipped++;
                continue;
            }
            $dir = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('无法创建附件目录：' . $relative);
            }
            $entry = 'uploads/' . $relative;
            $this->extractEntry($zip, $entry, $target);
            $expected = (string) ($item['sha256'] ?? '');
            if ($expected !== '' && !hash_equals($expected, (string) hash_file('sha256', $target))) {
                @unlink($target);
                throw new RuntimeException('附件校验失败：' . $relative);
            }
            $copied++;
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    protected function safeRelative(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new RuntimeException('备份包中包含非法路径');
            }
            $parts[] = $part;
        }
        if (!$parts) {
            throw new RuntimeException('备份包中包含非法路径');
        }
        return implode('/', $parts);
    }

    protected function extractEntry(ZipArchive $zip, string $entry, string $target): void
    {
        if ($zip->locateName($entry) === false) {
            throw new RuntimeException('备份包缺少文件：' . $entry);
        }
        $stream = $zip->getStream($entry);
        if (!$stream) {
            throw new RuntimeException('无法读取备份文件：' . $entry);
        }
        $out = fopen($target, 'wb');
        if (!$out) {
            fclose($stream);
            throw new RuntimeException('无法写入文件：' . basename($target));
        }
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK);
                if ($chunk === false) {
                    throw new RuntimeException('读取备份文件失败：' . $entry);
                }
                if ($chunk !== '') {
                    $this->writeAll($out, $chunk);
                }
            }
        } finally {
            fclose($stream);
            fclose($out);
        }
    }

    /* ================= SQL 导入 ================= */

    protected function importSql(string $sqlPath, string $mode): array
    {
        $pdo = $this->pdo();
        $pdo->exec('SET NAMES utf8mb4');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $stats = ['tables' => 0, 'rows' => 0, 'errors' => []];
        $handle = fopen($sqlPath, 'rb');
        if (!$handle) {
            throw new RuntimeException('无法读取数据库备份文件');
        }

        $state = [
            'single'   => false,
            'double'   => false,
            'backtick' => false,
            'line'     => false,
            'block'    => false,
            'escape'   => false,
        ];

        $statement = '';
        $carry = '';

        try {
            while (!feof($handle)) {
                $chunk = $carry . (string) fread($handle, 65536);
                $carry = '';
                $length = strlen($chunk);
                if ($length === 0) {
                    continue;
                }

                // 块尾若是可能组成转义/注释/成对引号的字符，留到下一块一起解析
                $scanLength = $length;
                if (!feof($handle) && in_array($chunk[$length - 1], ["'", '"', '`', '\\', '-', '/'], true)) {
                    $scanLength = $length - 1;
                    $carry = $chunk[$length - 1];
                }

                $start = 0;
                for ($i = 0; $i < $scanLength; $i++) {
                    $char = $chunk[$i];
                    $next = $i + 1 < $scanLength ? $chunk[$i + 1] : '';

                    if ($state['line']) {
                        if ($char === "\n") {
                            $state['line'] = false;
                        }
                        continue;
                    }
                    if ($state['block']) {
                        if ($char === '*' && $next === '/') {
                            $state['block'] = false;
                            $i++;
                        }
                        continue;
                    }
                    if ($state['escape']) {
                        $state['escape'] = false;
                        continue;
                    }
                    if ($state['single']) {
                        if ($char === '\\') {
                            $state['escape'] = true;
                        } elseif ($char === "'") {
                            if ($next === "'") {
                                $i++;
                            } else {
                                $state['single'] = false;
                            }
                        }
                        continue;
                    }
                    if ($state['double']) {
                        if ($char === '\\') {
                            $state['escape'] = true;
                        } elseif ($char === '"') {
                            if ($next === '"') {
                                $i++;
                            } else {
                                $state['double'] = false;
                            }
                        }
                        continue;
                    }
                    if ($state['backtick']) {
                        if ($char === '`') {
                            if ($next === '`') {
                                $i++;
                            } else {
                                $state['backtick'] = false;
                            }
                        }
                        continue;
                    }

                    if ($char === "'") {
                        $state['single'] = true;
                    } elseif ($char === '"') {
                        $state['double'] = true;
                    } elseif ($char === '`') {
                        $state['backtick'] = true;
                    } elseif ($char === '-' && $next === '-') {
                        $state['line'] = true;
                        $i++;
                    } elseif ($char === '/' && $next === '*') {
                        $state['block'] = true;
                        $i++;
                    } elseif ($char === ';') {
                        $statement .= substr($chunk, $start, $i - $start);
                        $this->execStatement($pdo, $statement, $mode, $stats);
                        $statement = '';
                        $start = $i + 1;
                    }
                }

                $statement .= substr($chunk, $start, $scanLength - $start);
            }

            if ($carry !== '') {
                $statement .= $carry;
            }

            if (trim($statement) !== '') {
                $this->execStatement($pdo, $statement, $mode, $stats);
            }
        } finally {
            fclose($handle);
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Throwable $e) {
                // 连接已失效时忽略
            }
        }

        return $stats;
    }

    protected function execStatement(PDO $pdo, string $statement, string $mode, array &$stats): void
    {
        $statement = $this->stripLeadingComments(rtrim(trim($statement), ';'));
        if ($statement === '') {
            return;
        }

        if ($mode === 'skip') {
            if (preg_match('/^DROP\s+TABLE\b/i', $statement)) {
                return;
            }
            $statement = preg_replace('/^CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $statement);
            $statement = preg_replace('/^INSERT\s+INTO\b/i', 'INSERT IGNORE INTO', $statement);
        }

        $isCreate = (bool) preg_match('/^CREATE\s+TABLE\b/i', $statement);
        $isInsert = (bool) preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\b/i', $statement);

        try {
            $affected = $pdo->exec($statement);
            if ($isCreate) {
                $stats['tables']++;
            } elseif ($isInsert) {
                $stats['rows'] += max(0, (int) $affected);
            }
        } catch (\Throwable $e) {
            if ($mode === 'overwrite') {
                $preview = mb_substr(preg_replace('/\s+/', ' ', $statement), 0, 120);
                throw new RuntimeException('恢复失败：' . $e->getMessage() . '（语句：' . $preview . '…）');
            }
            if (count($stats['errors']) < 20) {
                $stats['errors'][] = $e->getMessage();
            }
        }
    }

    /* ================= 冲突检测 ================= */

    protected function detectConflicts(array $meta): array
    {
        $pdo = $this->pdo();
        $existing = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $existingMap = array_flip($existing);

        $tables = [];
        $totalRows = 0;
        foreach (($meta['tables'] ?? []) as $table) {
            $name = (string) ($table['name'] ?? '');
            if ($name === '' || !isset($existingMap[$name])) {
                continue;
            }
            $quoted = '`' . str_replace('`', '``', $name) . '`';
            $current = (int) $pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
            if ($current > 0) {
                $tables[] = [
                    'name'         => $name,
                    'current_rows' => $current,
                    'backup_rows'  => (int) ($table['rows'] ?? 0),
                ];
                $totalRows += $current;
            }
        }

        $uploadConflicts = 0;
        $root = rtrim(str_replace('\\', '/', public_path() . 'uploads'), '/');
        foreach (($meta['uploads']['files'] ?? []) as $item) {
            try {
                $relative = $this->safeRelative((string) ($item['path'] ?? ''));
            } catch (\Throwable $e) {
                continue;
            }
            if (is_file($root . '/' . $relative)) {
                $uploadConflicts++;
            }
        }

        return [
            'tables'        => $tables,
            'table_rows'    => $totalRows,
            'uploads'       => $uploadConflicts,
            'has_conflict'  => (bool) $tables || $uploadConflicts > 0,
        ];
    }

    /* ================= 缓存 ================= */

    protected function clearCaches(): void
    {
        try {
            cache_forget_all();
        } catch (\Throwable $e) {
            // 缓存不可用时忽略
        }
        $cacheDir = root_path('runtime') . 'cache';
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                $this->removePath($path);
            }
        }
    }

    /* ================= 辅助 ================= */

    protected function pdo(): PDO
    {
        $dbConfig = config('database.connections.mysql');
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $dbConfig['hostname'] ?? '127.0.0.1',
            $dbConfig['hostport'] ?? '3306',
            $dbConfig['database'] ?? '',
            $dbConfig['charset'] ?? 'utf8mb4'
        );
        return new PDO(
            $dsn,
            (string) ($dbConfig['username'] ?? ''),
            (string) ($dbConfig['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    protected function tempDir(): string
    {
        $dir = root_path('runtime') . 'backup_' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR;
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建临时目录，请检查 runtime 目录权限');
        }
        return $dir;
    }

    protected function uniquePath(string $siteName): string
    {
        $base = $this->safeName($siteName);
        $stamp = date('Ymd_His');
        $name = $base . '_Backup_' . $stamp . '.zip';
        $index = 2;
        while (file_exists($this->dir . $name)) {
            $name = $base . '_Backup_' . $stamp . '_' . $index . '.zip';
            $index++;
        }
        return $this->dir . $name;
    }

    protected function safeName(string $siteName): string
    {
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', $siteName);
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name), " ._");
        if ($name === '') {
            $name = 'Northmu Docs';
        }
        if (mb_strlen($name) > 50) {
            $name = trim(mb_substr($name, 0, 50), " ._");
        }
        return $name !== '' ? $name : 'Northmu Docs';
    }

    protected function tableRows(array $tables, string $suffix): int
    {
        foreach ($tables as $table) {
            $name = (string) ($table['name'] ?? '');
            if ($name === $suffix || str_ends_with($name, '_' . $suffix)) {
                return (int) ($table['rows'] ?? 0);
            }
        }
        return 0;
    }

    protected function stripLeadingComments(string $statement): string
    {
        while (true) {
            $statement = ltrim($statement);
            if (str_starts_with($statement, '--')) {
                $pos = strpos($statement, "\n");
                if ($pos === false) {
                    return '';
                }
                $statement = substr($statement, $pos + 1);
                continue;
            }
            if (str_starts_with($statement, '/*')) {
                $pos = strpos($statement, '*/');
                if ($pos === false) {
                    return '';
                }
                $statement = substr($statement, $pos + 2);
                continue;
            }
            return $statement;
        }
    }

    protected function readExact($handle, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($handle, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }

    protected function writeAll($handle, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('写入文件失败，请检查磁盘空间');
            }
            $offset += $written;
        }
    }

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '*') ?: [] as $path) {
            $this->removePath($path);
        }
        @rmdir($dir);
    }

    protected function removePath(string $path): void
    {
        if (is_dir($path)) {
            $this->removeDir(rtrim($path, '\\/') . DIRECTORY_SEPARATOR);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }
}
