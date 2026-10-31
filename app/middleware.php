<?php
// 全局中间件定义文件
return [
    // Session初始化
    \think\middleware\SessionInit::class,
    // 安装检测
    \app\middleware\InstallCheck::class,
    // 表单令牌校验
    \app\middleware\Csrf::class,
];
