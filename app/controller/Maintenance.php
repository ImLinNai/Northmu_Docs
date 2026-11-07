<?php

namespace app\controller;

use app\BaseController;

class Maintenance extends BaseController
{
    public function preview(string $template = 'macos')
    {
        if (!array_key_exists($template, maintenance_templates())) {
            $template = 'macos';
        }

        $untilRaw = trim((string) sys_setting('maintenance_until', ''));
        $untilTs = $untilRaw !== '' ? (int) strtotime($untilRaw) : 0;

        $state = [
            'template' => $template,
            'reason'   => trim((string) sys_setting('maintenance_reason', '')),
            'until'    => max(0, $untilTs),
        ];

        return response(maintenance_render($template, $state))
            ->header(['Cache-Control' => 'no-store, max-age=0']);
    }
}
