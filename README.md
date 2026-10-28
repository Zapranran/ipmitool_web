# IPMI Web 管理面板

一个轻量级的 IPMI / BMC 远程管理面板。基于 PHP 编写，无需编译、无需数据库，浏览器打开即可对服务器执行开机、关机、重启、查看传感器与硬件信息。

适合运维人员在没有专门 IPMI 客户端的环境下，用电脑或手机快速处理服务器电源问题。

[中文](README.md) | [English](README.en.md)

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![Platform](https://img.shields.io/badge/platform-Linux%20%7C%20Windows-lightgrey)
![Dependencies](https://img.shields.io/badge/dependencies-none-brightgreen)
![Responsive](https://img.shields.io/badge/UI-responsive-blue)

---

## 功能特性

| 功能 | 说明 |
|------|------|
| 电源控制 | 开机 / 关机 / 软关机 / 强制重启 / 复位 |
| 状态总览 | 首页卡片展示所有 BMC 的电源状态，支持一键操作 |
| 传感器 | 温度、电压、风扇转速读取 |
| 硬件信息 | FRU（厂商、型号、序列号） |
| 事件日志 | SEL 系统事件日志查询 |
| 命令终端 | 手动执行任意 ipmitool 子命令，内置快捷命令下拉 |
| 多 BMC 管理 | 增删改多台 BMC 目标，支持 lan / lanplus 接口 |
| 登录鉴权 | Session 认证，所有操作需登录 |
| 响应式界面 | 桌面、平板、手机自适应 |
| Demo 模式 | 无 ipmitool 环境也能跑通全部界面，方便二次开发 |

## 技术栈

- **后端**：原生 PHP（无框架、无 Composer 依赖），JSON 文件存储
- **前端**：原生 HTML + CSS + JavaScript（无构建步骤、无 npm）
- **执行层**：`proc_open` 以参数数组方式调用系统 `ipmitool`，不经过 shell

整个项目不到 50 KB，拷到服务器就能跑。

## 目录结构

```
web/
├── index.php              # 入口：渲染 SPA + 转发 /api/* 请求
├── dev-server.ps1         # Windows 下的模拟服务器（可选，用于无 PHP 环境预览）
├── api/
│   ├── config.php         # 配置文件（ipmitool 路径、账号密码、Demo 模式）
│   ├── router.php         # API 路由
│   ├── Auth.php           # Session 鉴权
│   ├── BmcStore.php       # BMC 目标 JSON 存储
│   └── IpmiRunner.php     # 安全执行 ipmitool
├── assets/
│   ├── css/style.css      # 响应式样式
│   └── js/app.js          # 前端逻辑
└── data/
    └── bmcs.json          # BMC 目标列表（需可写）
```

## 环境要求

- PHP **7.4+**（推荐 8.1），需启用 `session` 扩展
- 系统已安装 `ipmitool`
- 服务器能访问目标 BMC 的 **UDP 623** 端口
- 无需数据库

## 快速开始

### 方式一：PHP 内置服务器（最快验证）

```bash
# 1. 安装 ipmitool
sudo apt install -y ipmitool      # Debian / Ubuntu
sudo yum install -y ipmitool      # CentOS / RHEL

# 2. 启动
cd web
php -S 0.0.0.0:8080 index.php
```

浏览器访问 `http://服务器IP:8080`，默认账号 `admin / admin`。

### 方式二：宝塔面板

1. 「软件商店」安装 **Nginx** + **PHP 8.1**
2. 执行 `yum install -y ipmitool`（或 `apt install -y ipmitool`）
3. 「网站」→「添加站点」，PHP 版本选 8.1，将本项目全部文件上传到站点根目录
4. 「伪静态」填入：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

5. 「PHP 设置」→「禁用函数」中**删除 `proc_open`**（宝塔默认禁用，不删则所有 IPMI 命令失败）
6. 设置目录权限：

```bash
chown -R www:www /www/wwwroot/你的域名
chmod -R 755 /www/wwwroot/你的域名
chmod 755 /www/wwwroot/你的域名/data
```

### 方式三：Nginx + PHP-FPM

```nginx
server {
    listen 80;
    server_name ipmi.example.com;
    root /var/www/ipmi-web;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # 屏蔽 BMC 密码文件
    location ^~ /data/ {
        deny all;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

## 配置说明

编辑 `api/config.php`：

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `ipmitool_path` | `ipmitool` | ipmitool 可执行文件路径，Windows 下填完整路径 |
| `default_interface` | `lanplus` | 通信接口，`lanplus`（IPMI v2.0，推荐）或 `lan`（v1.5） |
| `bmc_store` | `../data/bmcs.json` | BMC 列表存储路径 |
| `auth_user` | `admin` | 登录账号，可用环境变量 `IPMI_WEB_USER` 覆盖 |
| `auth_pass` | `admin` | 登录密码，可用环境变量 `IPMI_WEB_PASS` 覆盖 |
| `demo_mode` | **`true`** | 模拟模式。**部署后必须改为 `false`**，否则不会真正调用 ipmitool |
| `timeout` | `10` | BMC 通信超时（秒） |

> **注意**：`demo_mode` 的默认表达式是 `(getenv('IPMI_DEMO') ?: '1') === '1'`，没有设置环境变量时等于**开启模拟模式**，界面右上角会显示 `DEMO 模式`。生产部署请把该行改为 `'demo_mode' => false`。

## 使用方法

1. 登录后进入「BMC 目标」页，点击「+ 新增 BMC」，填写 BMC 的 IP、账号、密码
2. 回到「仪表盘」查看电源状态，卡片上的按钮可直接开关机
3. 「命令终端」页可选择目标并执行任意 ipmitool 子命令，例如：

```
chassis status
chassis power status
sdr type temperature
sel
fru
```

## API 接口

所有接口返回 JSON，`/api/*` 由 `index.php` 内部转发至 `api/router.php`。

| 方法 | 路径 | 参数 | 说明 |
|------|------|------|------|
| GET | `/api/ping` | - | 服务健康检查，返回是否 Demo 模式 |
| POST | `/api/login` | `user`, `pass` | 登录 |
| GET | `/api/logout` | - | 退出登录 |
| GET | `/api/whoami` | - | 当前登录状态 |
| GET | `/api/bmcs` | - | BMC 列表（不含密码） |
| POST | `/api/bmcs` | `name`,`host`,`user`,`pass`,`interface`,`port`,`privl` | 新增 BMC |
| POST | `/api/bmcs_update?id=N` | 同上（`pass` 留空表示不修改） | 修改 BMC |
| GET | `/api/bmcs_delete?id=N` | - | 删除 BMC |
| POST | `/api/ipmi_run` | `id`, `cmd` | 执行任意 ipmitool 子命令 |
| GET | `/api/chassis_power` | `id`, `op` | 电源操作，`op` ∈ `status/on/off/cycle/reset/soft` |
| GET | `/api/dashboard` | - | 所有 BMC 的电源状态汇总 |

除 `ping` / `login` / `logout` / `whoami` 外，其余接口均需登录。

## 安全说明

这是一个能直接操作物理服务器电源的工具，**请务必注意以下几点**：

1. **修改默认密码**。`admin/admin` 仅供本地测试，公网暴露等同把服务器交给别人。
2. **启用 HTTPS**。登录密码在 HTTP 下是明文传输。
3. **BMC 密码以明文存储**在 `data/bmcs.json`，请将该目录移出 Web 根目录，或在 Nginx 中用 `location ^~ /data/ { deny all; }` 阻断外部访问。
4. **限制访问来源**。建议只在内网管理网段开放，或用 Nginx `allow/deny` 做 IP 白名单。
5. 命令注入已通过 `proc_open` + 参数数组规避（不拼接 shell 字符串），但「命令终端」仍允许执行任意 ipmitool 子命令——这是功能特性，不是漏洞，因此鉴权与访问控制尤其重要。
6. 建议为面板单独配置一个低权限 BMC 账号（`USER` 级别），仅在确需关机时使用 `ADMINISTRATOR`。

## 常见问题

**一直显示「DEMO 模式」/ 数据是假的**
`api/config.php` 中 `demo_mode` 仍为 `true`，改为 `false` 并确认服务器已安装 ipmitool。

**提示「无法启动 ipmitool 进程」**
1. PHP 的 `disable_functions` 中是否仍包含 `proc_open`（宝塔默认禁用，需手动删除）
2. `ipmitool -V` 是否能在命令行正常执行，`www` 用户是否有执行权限

**访问 `/api/ping` 返回 404**
Nginx 未配置伪静态，缺少 `try_files $uri $uri/ /index.php?$query_string;`。

**登录成功但刷新后掉线**
PHP 的 `session.save_path` 不可写，检查目录权限。

**执行命令超时或 `Unable to establish LAN session`**
1. 确认服务器能到达 BMC 的 UDP 623 端口（`nc -uz BMC_IP 623`）
2. 老设备只支持 IPMI v1.5，把接口改成 `lan` 试试

**Windows 上没有 PHP 也能看界面**
运行 `dev-server.ps1`，它用 .NET HttpListener 模拟后端接口，返回演示数据。

## License

本 Web 面板为独立实现，本项目与 ipmitool 源码合并发布，遵循 GPL-2.0。
