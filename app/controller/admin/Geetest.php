<?php

namespace app\controller\admin;

use app\BaseController;

/**
 * 极验行为验证：校验通过后按场景签发一次性授权，由登录/文档操作消费
 */
class Geetest extends BaseController
{
    public function verify()
    {
        $config = geetest_config();
        if (!$config['ready']) {
            return json(['code' => 1, 'msg' => '行为验证服务未启用，请先在「三方服务」中完成配置']);
        }

        $scene = (string) $this->request->post('scene', '');
        if (!in_array($scene, ['login', 'publish', 'delete'], true)) {
            return json(['code' => 1, 'msg' => '无效的验证场景']);
        }
        if ($scene !== 'login' && !admin_logged_in()) {
            return json(['code' => 1, 'msg' => '登录状态已失效，请重新登录']);
        }

        $result = geetest_verify_payload([
            'lot_number'     => $this->request->post('lot_number', ''),
            'captcha_output' => $this->request->post('captcha_output', ''),
            'pass_token'     => $this->request->post('pass_token', ''),
            'gen_time'       => $this->request->post('gen_time', ''),
        ]);
        if (empty($result['ok'])) {
            return json(['code' => 1, 'msg' => $result['message']]);
        }

        return json([
            'code' => 0,
            'msg'  => '验证通过',
            'data' => ['grant' => geetest_issue_grant($scene)],
        ]);
    }
}
