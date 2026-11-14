<?php

namespace app\controller;

use app\BaseController;
use app\support\SuyanApi;
use think\facade\Session;

class AiChat extends BaseController
{
    private const MAX_LENGTH = 1000;
    private const RATE_WINDOW = 600;
    private const RATE_LIMIT = 60;

    public function chat()
    {
        if (!suyan_chat_ready()) {
            return json(['code' => 1, 'msg' => 'AI 聊天尚未启用']);
        }

        $message = trim((string) $this->request->post('msg', ''));
        if ($message === '') {
            return json(['code' => 1, 'msg' => '请输入要发送的内容']);
        }
        if (mb_strlen($message) > self::MAX_LENGTH) {
            return json(['code' => 1, 'msg' => '单条消息不能超过 1000 个字符']);
        }

        $now = time();
        $rate = Session::get('suyan_chat_rate', []);
        if (!is_array($rate) || $now - (int) ($rate['start'] ?? 0) >= self::RATE_WINDOW) {
            $rate = ['start' => $now, 'count' => 0];
        }
        if ((int) ($rate['count'] ?? 0) >= self::RATE_LIMIT) {
            return json(['code' => 1, 'msg' => '对话次数已达上限，请稍后再试']);
        }
        $rate['count'] = (int) ($rate['count'] ?? 0) + 1;
        Session::set('suyan_chat_rate', $rate);

        try {
            $result = SuyanApi::chat($message);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }

        return json(['code' => 0, 'data' => $result]);
    }
}
