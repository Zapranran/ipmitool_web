# IPMI Web Console

A lightweight remote management panel for IPMI / BMC. Written in plain PHP — no compiling, no database. Open it in a browser to power on, power off, reboot servers, and read sensors and hardware inventory.

Built for sysadmins who need to fix a server's power state quickly from a laptop or phone, without a dedicated IPMI client installed.

[中文](README.md) | English

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![Platform](https://img.shields.io/badge/platform-Linux%20%7C%20Windows-lightgrey)
![Dependencies](https://img.shields.io/badge/dependencies-none-brightgreen)
![Responsive](https://img.shields.io/badge/UI-responsive-blue)

---

## Features

| Feature | Description |
|---------|-------------|
| Power control | Power on / off / soft shutdown / hard reset / reset |
| Overview | Dashboard cards showing power state for every BMC, with one-click actions |
| Sensors | Temperature, voltage and fan speed readings |
| Hardware info | FRU (vendor, model, serial number) |
| Event log | Query the SEL (System Event Log) |
| Command console | Run any ipmitool sub-command manually, with a built-in shortcut list |
| Multi-BMC | Add, edit and delete multiple BMC targets; supports `lan` and `lanplus` |
| Authentication | Session-based login; every action requires authentication |
| Responsive UI | Adapts to desktop, tablet and phone |
| Demo mode | Runs the full UI without ipmitool installed, handy for development |

## Tech stack

- **Backend**: plain PHP (no framework, no Composer), JSON file storage
- **Frontend**: plain HTML + CSS + JavaScript (no build step, no npm)
- **Execution**: `proc_open` invokes the system `ipmitool` with an argument array, bypassing the shell

The whole project is under 50 KB — copy it to a server and it runs.

## Project layout

```
web/
├── index.php              # Entry point: renders the SPA and forwards /api/* requests
├── dev-server.ps1         # Windows mock server (optional, for previewing without PHP)
├── api/
│   ├── config.php         # Configuration (ipmitool path, credentials, demo mode)
│   ├── router.php         # API routing
│   ├── Auth.php           # Session authentication
│   ├── BmcStore.php       # JSON storage for BMC targets
│   └── IpmiRunner.php     # Safe execution of ipmitool
├── assets/
│   ├── css/style.css      # Responsive styles
│   └── js/app.js          # Frontend logic
└── data/
    └── bmcs.json          # BMC target list (must be writable)
```

## Requirements

- PHP **7.4+** (8.1 recommended) with the `session` extension enabled
- `ipmitool` installed on the host
- The host must be able to reach the target BMC on **UDP port 623**
- No database required

## Quick start

### Option 1: PHP built-in server (fastest way to try it)

```bash
# 1. Install ipmitool
sudo apt install -y ipmitool      # Debian / Ubuntu
sudo yum install -y ipmitool      # CentOS / RHEL

# 2. Start the server
cd web
php -S 0.0.0.0:8080 index.php
```

Open `http://<server-ip>:8080` in a browser. Default credentials: `admin / admin`.

### Option 2: BT Panel (宝塔面板)

1. Install **Nginx** and **PHP 8.1** from the software store
2. Run `yum install -y ipmitool` (or `apt install -y ipmitool`)
3. Create a site (**Websites → Add site**) with PHP 8.1, then upload all project files to the site root
4. Add the following to **URL rewrite** (伪静态):

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

5. Under **PHP settings → Disabled functions** (禁用函数), **remove `proc_open`** — BT Panel disables it by default, and every IPMI command will fail if you leave it
6. Fix directory permissions:

```bash
chown -R www:www /www/wwwroot/your-domain
chmod -R 755 /www/wwwroot/your-domain
chmod 755 /www/wwwroot/your-domain/data
```

### Option 3: Nginx + PHP-FPM

```nginx
server {
    listen 80;
    server_name ipmi.example.com;
    root /var/www/ipmi-web;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Block access to the BMC credential file
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

## Configuration

Edit `api/config.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `ipmitool_path` | `ipmitool` | Path to the ipmitool binary; use the full path on Windows |
| `default_interface` | `lanplus` | Transport, `lanplus` (IPMI v2.0, recommended) or `lan` (v1.5) |
| `bmc_store` | `../data/bmcs.json` | Storage path for the BMC list |
| `auth_user` | `admin` | Login user; can be overridden with the `IPMI_WEB_USER` environment variable |
| `auth_pass` | `admin` | Login password; can be overridden with the `IPMI_WEB_PASS` environment variable |
| `demo_mode` | **`true`** | Simulation mode. **Must be set to `false` after deployment**, otherwise ipmitool is never actually called |
| `timeout` | `10` | BMC communication timeout in seconds |

> **Note**: `demo_mode` defaults to `(getenv('IPMI_DEMO') ?: '1') === '1'`, which evaluates to **enabled** when no environment variable is set. The UI shows a `DEMO` badge in the top-right corner. For production, change the line to `'demo_mode' => false`.

## Usage

1. After logging in, go to **BMC Targets** and click **+ Add BMC**; enter the BMC IP, username and password
2. Back on the **Dashboard**, the cards show power state and the buttons act directly on the host
3. On the **Command Console** page, pick a target and run any ipmitool sub-command, for example:

```
chassis status
chassis power status
sdr type temperature
sel
fru
```

## API

All endpoints return JSON. Requests to `/api/*` are forwarded internally by `index.php` to `api/router.php`.

| Method | Path | Parameters | Description |
|--------|------|------------|-------------|
| GET | `/api/ping` | - | Health check; reports whether demo mode is on |
| POST | `/api/login` | `user`, `pass` | Log in |
| GET | `/api/logout` | - | Log out |
| GET | `/api/whoami` | - | Current login state |
| GET | `/api/bmcs` | - | List BMCs (passwords omitted) |
| POST | `/api/bmcs` | `name`,`host`,`user`,`pass`,`interface`,`port`,`privl` | Add a BMC |
| POST | `/api/bmcs_update?id=N` | Same as above (`pass` left empty means unchanged) | Update a BMC |
| GET | `/api/bmcs_delete?id=N` | - | Delete a BMC |
| POST | `/api/ipmi_run` | `id`, `cmd` | Run any ipmitool sub-command |
| GET | `/api/chassis_power` | `id`, `op` | Power operation, `op` ∈ `status/on/off/cycle/reset/soft` |
| GET | `/api/dashboard` | - | Power state summary for all BMCs |

Except for `ping` / `login` / `logout` / `whoami`, every endpoint requires authentication.

## Security

This tool can directly change the power state of physical servers. **Please read the following carefully**:

1. **Change the default password.** `admin/admin` is for local testing only; exposing it publicly is equivalent to handing over the servers.
2. **Enable HTTPS.** Login credentials are transmitted in plaintext over HTTP.
3. **BMC passwords are stored in plaintext** in `data/bmcs.json`. Move that directory outside the web root, or block external access in Nginx with `location ^~ /data/ { deny all; }`.
4. **Restrict the source of access.** Prefer exposing the panel only on an internal management network, or use Nginx `allow/deny` for IP allowlisting.
5. Command injection is prevented by using `proc_open` with an argument array (no shell string concatenation). However, the command console intentionally allows arbitrary ipmitool sub-commands — that is a feature, not a vulnerability, which makes authentication and access control especially important.
6. Prefer a dedicated low-privilege BMC account (`USER` level) for the panel, and use `ADMINISTRATOR` only when a power-off is genuinely required.

## FAQ

**The UI always shows "DEMO" / the data is fake**
`demo_mode` in `api/config.php` is still `true`. Set it to `false` and confirm ipmitool is installed on the server.

**"Unable to start ipmitool process"**
1. Check whether `proc_open` is still listed in PHP's `disable_functions` (BT Panel disables it by default and you must remove it)
2. Check that `ipmitool -V` runs from the command line and that the `www` user has permission to execute it

**`/api/ping` returns 404**
Nginx URL rewrite is missing — add `try_files $uri $uri/ /index.php?$query_string;`.

**Login succeeds but the session drops on refresh**
PHP's `session.save_path` is not writable. Check directory permissions.

**Commands time out or return `Unable to establish LAN session`**
1. Verify the server can reach UDP port 623 on the BMC (`nc -uz BMC_IP 623`)
2. Older devices support IPMI v1.5 only — try switching the interface to `lan`

**Previewing the UI on Windows without PHP**
Run `dev-server.ps1`; it emulates the backend API with .NET HttpListener and returns demo data.

## License

This web panel is an independent implementation. It is released together with the ipmitool source code in this repository and is therefore distributed under GPL-2.0.
