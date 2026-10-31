<?php

namespace app\controller\admin;

use think\facade\Db;
use think\facade\View;

class Attachment extends Base
{
    public function index()
    {
        $paginator = Db::name('attachments')
            ->order('id', 'desc')
            ->paginate(['list_rows' => 20, 'query' => $this->request->get()]);
        $items = $paginator->items();

        foreach ($items as &$item) {
            $item['size_text'] = format_size((int) $item['size']);
            $item['url'] = (string) $item['path'];
            $item['is_image'] = in_array(strtolower((string) $item['extension']), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
            $item['ref_count'] = $this->referenceCount((string) $item['path']);
        }
        unset($item);

        $view = $this->viewData('附件管理', '上传附件、查看引用并清理无用文件', 'attachments') + [
            'items'            => $items,
            'paginator'        => $paginator,
            'totalCount'       => Db::name('attachments')->count(),
            'totalSize'        => format_size((int) Db::name('attachments')->sum('size')),
            'uploadCategories' => upload_categories(),
        ];
        $view['extraScripts'] = '<script src="/static/js/admin-pages.js"></script>';

        return View::fetch('/attachments', $view);
    }

    public function picker()
    {
        $type = (string) $this->request->get('type', 'image');
        if (!in_array($type, ['image', 'video', 'file', 'all'], true)) {
            $type = 'image';
        }
        $keyword = trim((string) $this->request->get('keyword', ''));
        $page = max(1, (int) $this->request->get('page', 1));
        $limit = min(48, max(12, (int) $this->request->get('limit', 24)));

        $categories = upload_categories();
        $imageExtensions = $categories['image']['ext_list'];
        $videoExtensions = $categories['video']['ext_list'];
        $fileExtensions = array_values(array_diff($categories['file']['ext_list'], $imageExtensions, $videoExtensions));

        $buildQuery = function () use ($type, $keyword, $imageExtensions, $videoExtensions, $fileExtensions) {
            $query = Db::name('attachments');
            if ($type === 'image') {
                $query->whereIn('extension', $imageExtensions);
            } elseif ($type === 'video') {
                $query->whereIn('extension', $videoExtensions);
            } elseif ($type === 'file') {
                if ($fileExtensions) {
                    $query->whereIn('extension', $fileExtensions);
                } else {
                    $query->whereRaw('1 = 0');
                }
            }
            if ($keyword !== '') {
                $query->where(function ($query) use ($keyword) {
                    $query->whereLike('original_name', '%' . $keyword . '%')
                        ->whereOr('path', 'like', '%' . $keyword . '%');
                });
            }
            return $query;
        };

        $total = $buildQuery()->count();
        $rows = $buildQuery()->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $items = [];
        foreach ($rows as $row) {
            $extension = strtolower((string) $row['extension']);
            $isImage = in_array($extension, $imageExtensions, true);
            $isVideo = in_array($extension, $videoExtensions, true);
            $items[] = [
                'id'            => (int) $row['id'],
                'name'          => (string) $row['original_name'],
                'url'           => (string) $row['path'],
                'extension'     => $extension,
                'category'      => $isImage ? 'image' : ($isVideo ? 'video' : 'file'),
                'is_image'      => $isImage,
                'is_video'      => $isVideo,
                'size'          => (int) $row['size'],
                'size_text'     => format_size((int) $row['size']),
                'created_at'    => (string) $row['created_at'],
            ];
        }

        return $this->ok('', [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'limit'    => $limit,
            'has_more' => $page * $limit < $total,
        ]);
    }

    public function upload()
    {
        $file = $this->request->file('file');
        if (!$file) {
            return $this->fail('请选择要上传的文件');
        }

        $original = (string) $file->getOriginalName();
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $categoryKey = upload_category_for_extension($extension);
        if ($categoryKey === null) {
            return $this->fail('不允许的文件类型：' . ($extension === '' ? '未知' : $extension));
        }
        $category = upload_categories()[$categoryKey];
        $size = (int) $file->getSize();
        if ($size > $category['max_bytes']) {
            return $this->fail($category['label'] . '大小超过限制（最大 ' . $category['max_mb'] . ' MB，当前 ' . format_size($size) . '）');
        }

        $relativeDir = 'uploads/' . date('Y') . '/' . date('m');
        $targetDir = public_path() . $relativeDir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return $this->fail('无法创建上传目录');
        }
        ensure_uploads_protection();

        $filename = date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
        $mime = '';
        try {
            $mime = (string) $file->getMime();
        } catch (\Throwable $e) {
            $mime = '';
        }

        try {
            $file->move($targetDir, $filename);
        } catch (\Throwable $e) {
            return $this->fail('文件保存失败：' . $e->getMessage());
        }

        $path = '/' . $relativeDir . '/' . $filename;

        Db::name('attachments')->insert([
            'original_name' => mb_substr(basename($original), 0, 255),
            'path'          => $path,
            'size'          => $size,
            'mime'          => mb_substr($mime, 0, 100),
            'extension'     => $extension,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        audit_log('attachment', 'upload', '上传附件「' . basename($original) . '」（' . format_size($size) . '）', 'attachment', (int) Db::name('attachments')->where('path', $path)->value('id'));

        return $this->ok('上传成功', [
            'id'       => (int) Db::name('attachments')->where('path', $path)->value('id'),
            'url'      => $path,
            'name'     => basename($original),
            'size'     => $size,
            'category' => $categoryKey,
            'is_image' => $categoryKey === 'image',
            'is_video' => $categoryKey === 'video',
        ]);
    }

    public function delete()
    {
        $id = (int) $this->request->post('id', 0);
        $attachment = $id > 0 ? Db::name('attachments')->where('id', $id)->find() : null;
        if (!$attachment) {
            return $this->fail('附件不存在');
        }

        $refs = $this->referenceCount((string) $attachment['path']);
        if ($refs > 0) {
            return $this->fail('该附件仍被 ' . $refs . ' 篇文档引用，请先移除引用后再删除');
        }
        if ($this->settingReference((string) $attachment['path'])) {
            return $this->fail('该附件正被站点设置或管理员头像使用，请先更换后再删除');
        }

        $this->removeFile((string) $attachment['path']);
        Db::name('attachments')->where('id', $id)->delete();
        audit_log('attachment', 'delete', '删除附件「' . $attachment['original_name'] . '」', 'attachment', $id);

        return $this->ok('附件已删除');
    }

    public function cleanup()
    {
        $removedRows = 0;
        $removedFiles = 0;
        $kept = [];

        $items = Db::name('attachments')->select()->toArray();
        foreach ($items as $item) {
            $path = (string) $item['path'];
            if ($this->referenceCount($path) === 0 && !$this->settingReference($path)) {
                $this->removeFile($path);
                Db::name('attachments')->where('id', (int) $item['id'])->delete();
                $removedRows++;
            } else {
                $kept[$path] = true;
            }
        }

        $root = public_path() . 'uploads';
        if (is_dir($root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $relative = '/uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if (basename($relative) === '.htaccess') {
                    continue;
                }
                // 头像、站点素材与首页背景不属于附件，清理时跳过
                if (str_starts_with($relative, '/uploads/avatar/')
                    || str_starts_with($relative, '/uploads/site/')
                    || str_starts_with($relative, '/uploads/home/')) {
                    continue;
                }
                if ($this->settingReference($relative)) {
                    continue;
                }
                if (!isset($kept[$relative]) && !Db::name('attachments')->where('path', $relative)->find()) {
                    @unlink($file->getPathname());
                    $removedFiles++;
                }
            }
        }

        audit_log('attachment', 'cleanup', '清理附件：删除 ' . $removedRows . ' 条记录、' . $removedFiles . ' 个孤立文件');
        return $this->ok('清理完成：删除 ' . $removedRows . ' 条记录、' . $removedFiles . ' 个孤立文件');
    }

    protected function referenceCount(string $path): int
    {
        if ($path === '') {
            return 0;
        }
        return Db::name('documents')->whereLike('content', '%' . $path . '%')->count();
    }

    protected function settingReference(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        foreach (Db::name('settings')->column('value') as $value) {
            if (str_contains((string) $value, $path)) {
                return true;
            }
        }
        foreach (Db::name('admins')->column('avatar') as $avatar) {
            if ((string) $avatar === $path) {
                return true;
            }
        }
        return false;
    }

    protected function removeFile(string $path): void
    {
        if (!str_starts_with($path, '/uploads/') || str_contains($path, '..')) {
            return;
        }
        $absolute = public_path() . ltrim($path, '/');
        $root = realpath(public_path() . 'uploads');
        $real = realpath($absolute);
        if ($real && $root && str_starts_with($real, $root) && is_file($real)) {
            @unlink($real);
        }
    }
}
