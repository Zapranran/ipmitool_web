<?php
/**
 * IPMI Web 管理界面 - 配置文件
 * 部署时请根据实际环境修改
 */

return [
    // ipmitool 可执行文件路径。Linux 上通常是 'ipmitool'，Windows 上填完整路径
    'ipmitool_path' => getenv('IPMI_TOOL_BIN') ?: 'ipmitool',

    // 通信接口: 'lanplus' (IPMIv2 推荐) | 'lan' (IPMIv1.5)
    'default_interface' => 'lanplus',

    // 目标列表 JSON 文件路径（相对 web/ 根目录）
    'bmc_store' => __DIR__ . '/../data/bmcs.json',

    // Session 存储路径
    'session_name' => 'ipmi_web_auth',

    // 登录账号（修改这里或用环境变量覆盖）
    'auth_user' => getenv('IPMI_WEB_USER') ?: 'admin',
    'auth_pass' => getenv('IPMI_WEB_PASS') ?: 'admin',

    // ===== Demo / 模拟模式 =====
    // 设为 true 时不执行真实 ipmitool 命令，返回模拟数据，方便无 ipmitool 环境下开发调试
    'demo_mode' => (getenv('IPMI_DEMO') ?: '1') === '1',

    // BMC 网络超时（秒）
    'timeout' => 10,
];
