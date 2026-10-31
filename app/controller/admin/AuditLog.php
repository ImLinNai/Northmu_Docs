<?php

namespace app\controller\admin;

use think\facade\Db;
use think\facade\View;

class AuditLog extends Base
{
    protected array $modules = [
        'auth'       => '登录认证',
        'document'   => '文档',
        'category'   => '分类',
        'tag'        => '标签',
        'attachment' => '附件',
        'setting'    => '系统设置',
        'appearance' => '前台设置',
        'profile'    => '管理员资料',
        'backup'     => '数据备份',
        'cache'      => '缓存管理',
    ];

    protected array $actions = [
        'login'      => '登录成功',
        'login_fail' => '登录失败',
        'logout'     => '退出登录',
        'create'     => '创建',
        'update'     => '修改',
        'delete'     => '删除',
        'restore'    => '恢复',
        'purge'      => '彻底删除',
        'publish'    => '发布',
        'unpublish'  => '取消发布',
        'top'        => '置顶',
        'untop'      => '取消置顶',
        'rollback'   => '版本回滚',
        'merge'      => '合并',
        'upload'     => '上传',
        'cleanup'    => '清理',
        'password'   => '修改密码',
        'create_backup' => '创建备份',
        'download'   => '下载',
        'clear'      => '清空缓存',
    ];

    public function index()
    {
        $filters = $this->filters();
        $paginator = $this->buildQuery($filters)
            ->order('id', 'desc')
            ->paginate(['list_rows' => 20, 'query' => $this->request->get()]);

        $logs = $paginator->items();
        $this->decorate($logs);

        $view = $this->viewData('操作日志', '查看后台关键操作记录，支持筛选、导出与清理', 'logs') + [
            'logs'        => $logs,
            'paginator'   => $paginator,
            'filters'     => $filters,
            'modules'     => $this->modules,
            'actions'     => $this->actions,
            'exportUrl'   => '/admin/logs/export' . ($this->request->get() ? '?' . http_build_query($this->request->get()) : ''),
            'totalCount'  => Db::name('audit_logs')->count(),
            'todayCount'  => Db::name('audit_logs')->whereBetweenTime('created_at', date('Y-m-d') . ' 00:00:00', date('Y-m-d') . ' 23:59:59')->count(),
            'loginCount'  => Db::name('audit_logs')->where('module', 'auth')->count(),
            'failCount'   => Db::name('audit_logs')->where('action', 'login_fail')->count(),
        ];

        return View::fetch('/logs', $view);
    }

    public function clear()
    {
        $mode = (string) $this->request->post('mode', '');
        if ($mode === 'days') {
            $days = max(1, min(3650, (int) $this->request->post('days', 90)));
            $before = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
            $count = Db::name('audit_logs')->where('created_at', '<', $before)->delete();
            admin_flash('已清理 ' . $days . ' 天前的 ' . $count . ' 条日志');
        } elseif ($mode === 'all') {
            $count = Db::name('audit_logs')->delete();
            admin_flash('已清空全部 ' . $count . ' 条操作日志');
        } else {
            admin_flash('请选择清理方式', 'warning');
        }
        return redirect('/admin/logs');
    }

    public function export()
    {
        $filters = $this->filters();
        $filename = 'audit_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache');

        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['时间', '管理员', '模块', '动作', '详情', '对象', 'IP', '客户端']);

        $query = $this->buildQuery($filters)->order('id', 'asc');
        $count = 0;
        foreach ($query->cursor() as $log) {
            $this->decorateRow($log);
            fputcsv($out, [
                $this->csvSafe((string) $log['created_at']),
                $this->csvSafe((string) $log['username']),
                $this->csvSafe((string) $log['module_label']),
                $this->csvSafe((string) $log['action_label']),
                $this->csvSafe((string) $log['description']),
                $this->csvSafe(trim((string) $log['target_type'] . '#' . (int) $log['target_id'], '#')),
                $this->csvSafe((string) $log['ip']),
                $this->csvSafe((string) $log['user_agent']),
            ]);
            $count++;
            if ($count >= 50000) {
                break;
            }
        }
        fclose($out);
        exit;
    }

    protected function filters(): array
    {
        $module = (string) $this->request->get('module', '');
        if ($module !== '' && !isset($this->modules[$module])) {
            $module = '';
        }
        $keyword = trim((string) $this->request->get('keyword', ''));
        if (mb_strlen($keyword) > 100) {
            $keyword = mb_substr($keyword, 0, 100);
        }
        $dateFrom = $this->normalizeDate((string) $this->request->get('date_from', ''));
        $dateTo = $this->normalizeDate((string) $this->request->get('date_to', ''));

        return [
            'module'    => $module,
            'keyword'   => $keyword,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ];
    }

    protected function normalizeDate(string $value): string
    {
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }
        return $value;
    }

    protected function buildQuery(array $filters)
    {
        $query = Db::name('audit_logs');
        if ($filters['module'] !== '') {
            $query->where('module', $filters['module']);
        }
        if ($filters['keyword'] !== '') {
            $like = '%' . $filters['keyword'] . '%';
            $query->where(function ($q) use ($like) {
                $q->whereLike('description', $like)
                    ->whereOr('username', 'like', $like)
                    ->whereOr('action', 'like', $like)
                    ->whereOr('ip', 'like', $like);
            });
        }
        if ($filters['date_from'] !== '') {
            $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if ($filters['date_to'] !== '') {
            $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }
        return $query;
    }

    protected function decorate(array &$logs): void
    {
        foreach ($logs as &$log) {
            $this->decorateRow($log);
        }
        unset($log);
    }

    protected function decorateRow(array &$log): void
    {
        $module = (string) $log['module'];
        $action = (string) $log['action'];
        $log['module_label'] = $this->modules[$module] ?? ($module !== '' ? $module : '系统');
        $log['action_label'] = $this->actions[$action] ?? ($action !== '' ? $action : '操作');
        $log['ua_short'] = mb_substr((string) $log['user_agent'], 0, 70);
        $log['target_text'] = trim((string) $log['target_type'] . '#' . (int) $log['target_id'], '#');

        if (in_array($action, ['delete', 'purge', 'login_fail'], true)) {
            $log['action_class'] = 'bg-red-lt text-red';
        } elseif (in_array($action, ['login', 'create', 'publish', 'restore', 'create_backup'], true)) {
            $log['action_class'] = 'bg-green-lt text-green';
        } elseif (in_array($action, ['untop', 'unpublish', 'cleanup', 'logout'], true)) {
            $log['action_class'] = 'bg-orange-lt text-orange';
        } else {
            $log['action_class'] = 'bg-azure-lt text-azure';
        }
    }

    protected function csvSafe(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
