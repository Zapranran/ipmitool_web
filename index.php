<?php
/**
 * 主入口：浏览器访问 / 时渲染 SPA 外壳
 * 同时也用 PATH_INFO 把 /api/* 请求转发给 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';

// 把 /api/xxx 转发给 router.php
if (preg_match('#^/api(?:/(.*))?$#', $uri, $m)) {
    $_GET['action'] = $m[1] ?? 'index';
    require __DIR__ . '/api/router.php';
    exit;
}

// 否则渲染 SPA
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<title>IPMI Web 管理</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<!-- 登录页面 -->
<section id="view-login" class="view hidden">
  <div class="login-card">
    <h1>🔐 IPMI Web 管理</h1>
    <p class="sub">登录以管理服务器 BMC</p>
    <form id="form-login" autocomplete="off">
      <label>账号 <input name="user" value=""></label>
      <label>密码 <input name="pass" type="password"></label>
      <button type="submit">登 录</button>
      <p id="login-msg" class="msg"></p>
    </form>
  </div>
</section>

<!-- 主应用（SPA 单页，由 JS 切换内容） -->
<div id="view-app" class="view hidden">
  <header class="topbar">
    <div class="brand">
      <span class="logo">🛠</span>
      <span>IPMI Web</span>
      <span id="badge-demo" class="badge badge-demo">DEMO 模式</span>
    </div>
    <nav class="tabs" id="tabs">
      <button data-tab="dashboard" class="active">仪表盘</button>
      <button data-tab="bmcs">BMC 目标</button>
      <button data-tab="console">命令终端</button>
    </nav>
    <div class="userbox">
      <span id="whoami"></span>
      <button id="btn-logout" class="btn-ghost">退出</button>
    </div>
  </header>

  <main id="content">
    <!-- 仪表盘 -->
    <section class="tab-content" data-pane="dashboard">
      <div class="pane-head">
        <h2>服务器状态总览</h2>
        <button id="btn-refresh-dash" class="btn">↻ 刷新</button>
      </div>
      <div id="dash-grid" class="card-grid"></div>
    </section>

    <!-- BMC 目标管理 -->
    <section class="tab-content hidden" data-pane="bmcs">
      <div class="pane-head">
        <h2>BMC 目标列表</h2>
        <button id="btn-add-bmc" class="btn btn-primary">+ 新增 BMC</button>
      </div>
      <div class="table-wrap">
        <table id="bmc-table" class="t">
          <thead><tr>
            <th>名称</th><th>主机</th><th>用户</th><th>接口</th><th>权限</th><th style="width:160px;">操作</th>
          </tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </section>

    <!-- 命令终端 -->
    <section class="tab-content hidden" data-pane="console">
      <div class="pane-head">
        <h2>IPMI 命令</h2>
      </div>

      <div class="row-2">
        <label>目标 BMC
          <select id="sel-bmc"></select>
        </label>
        <label>快捷命令
          <select id="sel-quick">
            <option value="">-- 选择快捷命令 --</option>
            <option value="chassis status">chassis status — 机箱状态</option>
            <option value="chassis power status">chassis power status — 电源状态</option>
            <option value="sdr type temperature">sdr type temperature — 温度传感器</option>
            <option value="sdr type voltage">sdr type voltage — 电压传感器</option>
            <option value="sdr type fan">sdr type fan — 风扇传感器</option>
            <option value="sel">sel — 系统事件日志</option>
            <option value="fru">fru — 现场可更换单元信息</option>
            <option value="raw 6 4">raw 6 4 — 原始命令示例</option>
          </select>
        </label>
      </div>

      <div class="console">
        <div class="row-cmd">
          <input id="cmd-input" placeholder="例如：chassis power status">
          <button id="cmd-run" class="btn btn-primary">执行</button>
          <button id="cmd-stop" class="btn btn-ghost hidden">停止</button>
        </div>
        <pre id="cmd-output" class="output"></pre>
      </div>

      <div class="actions-row">
        <button id="pw-on"    class="btn btn-green">▶ 开机</button>
        <button id="pw-off"   class="btn btn-red">■ 关机</button>
        <button id="pw-cycle" class="btn btn-orange">⟳ 强制重启</button>
        <button id="pw-reset" class="btn btn-orange">⟲ 复位</button>
        <button id="pw-soft"  class="btn btn-blue">⏻ 软关机</button>
      </div>
    </section>
  </main>
</div>

<!-- BMC 编辑对话框（自绘，避免原生 confirm） -->
<div id="modal" class="modal hidden">
  <div class="modal-inner">
    <div class="modal-head">
      <h3 id="modal-title">新增 BMC</h3>
      <button class="modal-close" aria-label="关闭">×</button>
    </div>
    <form id="form-bmc" class="modal-body">
      <input type="hidden" name="id">
      <div class="row-2">
        <label>名称 <input name="name" required></label>
        <label>主机 IP / 域名 <input name="host" required placeholder="192.168.1.100"></label>
      </div>
      <div class="row-2">
        <label>用户名 <input name="user" required value="admin"></label>
        <label>密码 <input name="pass" type="password" required></label>
      </div>
      <div class="row-3">
        <label>接口
          <select name="interface">
            <option value="lanplus" selected>lanplus (IPMIv2.0)</option>
            <option value="lan">lan (IPMIv1.5)</option>
          </select>
        </label>
        <label>端口 <input name="port" type="number" value="623"></label>
        <label>权限级别
          <select name="privl">
            <option value="USER">USER</option>
            <option value="OPERATOR">OPERATOR</option>
            <option value="ADMINISTRATOR" selected>ADMINISTRATOR</option>
          </select>
        </label>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn-ghost modal-cancel">取消</button>
        <button type="submit" class="btn btn-primary">保 存</button>
      </div>
    </form>
  </div>
</div>

<!-- 确认对话框（危险操作） -->
<div id="confirm" class="modal hidden">
  <div class="modal-inner modal-sm">
    <div class="modal-head">
      <h3 id="confirm-title">确认</h3>
    </div>
    <div class="modal-body">
      <p id="confirm-msg"></p>
      <div class="modal-foot">
        <button type="button" class="btn-ghost" id="confirm-cancel">取消</button>
        <button type="button" class="btn btn-red" id="confirm-ok">确 定</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast 提示 -->
<div id="toast" class="toast hidden"></div>

<script src="assets/js/app.js"></script>
</body>
</html>
