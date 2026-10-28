<?php
/**
 * API 统一路由入口 —— 所有 /api/*.php 请求都走这里（通过 index.php 的 path 重写）
 * 也可以直接访问：/api/router.php?action=xxx
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/BmcStore.php';
require_once __DIR__ . '/IpmiRunner.php';

$cfg   = require __DIR__ . '/config.php';
Auth::start($cfg['session_name']);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    $store  = new BmcStore($cfg['bmc_store']);
    $runner = new IpmiRunner($cfg);

    // 路由来源：query 里的 action 参数 或 PATH_INFO
    $action = $_GET['action'] ?? $_SERVER['PATH_INFO'] ?? 'index';
    $action = ltrim($action, '/');

    // ===== 不需要登录的公开接口 =====
    if ($action === 'ping') {
        echo json_encode(['ok' => true, 'demo' => $cfg['demo_mode']]);
        exit;
    }
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $u = trim($in['user'] ?? '');
        $p = trim($in['pass'] ?? '');
        if (Auth::login($u, $p, $cfg)) {
            Auth::setLoggedIn($u);
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => '账号或密码错误']);
        }
        exit;
    }
    if ($action === 'logout') {
        Auth::logout();
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'whoami') {
        echo json_encode(['ok' => true, 'logged' => Auth::check(), 'user' => $_SESSION['ipmi_web']['user'] ?? null]);
        exit;
    }

    // ===== 以下均需登录 =====
    Auth::requireLogin();

    switch ($action) {

        // --- BMC 目标管理 ---
        case 'bmcs':
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                // 返回列表时把密码字段隐藏掉（前端展示时不带密码）
                $list = $store->listAll();
                foreach ($list as &$b) unset($b['pass']);
                echo json_encode(['ok' => true, 'data' => $list]);
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
                $req = ['name','host','user','pass'];
                foreach ($req as $k) {
                    if (empty($in[$k])) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>"缺少字段: $k"]); exit; }
                }
                $in['interface'] = $in['interface'] ?? $cfg['default_interface'];
                $in['port']       = (int)($in['port'] ?? 623);
                $in['privl']      = $in['privl'] ?? 'USER';
                $b = $store->add($in);
                unset($b['pass']);
                echo json_encode(['ok' => true, 'data' => $b]);
            }
            break;

        case 'bmcs_update':
            Auth::requireLogin();
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            if ($id <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'需要 id']); exit; }
            $allowed = ['name','host','user','pass','interface','port','privl'];
            $patch = array_intersect_key($in, array_flip($allowed));
            $b = $store->update($id, $patch);
            if (!$b) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'未找到']); exit; }
            unset($b['pass']);
            echo json_encode(['ok' => true, 'data' => $b]);
            break;

        case 'bmcs_delete':
            $id = (int)($_GET['id'] ?? 0);
            if ($store->delete($id)) {
                echo json_encode(['ok' => true]);
            } else {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => '删除失败']);
            }
            break;

        // --- IPMI 命令执行 ---
        case 'ipmi_run':
            $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = (int)($in['id'] ?? 0);
            $subcmd = trim($in['cmd'] ?? '');
            if ($id <= 0 || $subcmd === '') {
                http_response_code(400);
                echo json_encode(['ok'=>false,'error'=>'需要 BMC id 和 cmd']); exit;
            }
            $bmc = $store->find($id);
            if (!$bmc) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'BMC 不存在']); exit; }
            $res = $runner->run($bmc, $subcmd);
            echo json_encode(['ok' => true, 'data' => $res]);
            break;

        case 'chassis_power':
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            $action2 = $_GET['op'] ?? $_POST['op'] ?? 'status';
            $allowed = ['status','on','off','cycle','reset','soft'];
            if (!in_array($action2, $allowed, true)) {
                http_response_code(400);
                echo json_encode(['ok'=>false,'error'=>'非法操作']); exit;
            }
            $bmc = $store->find($id);
            if (!$bmc) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'BMC 不存在']); exit; }
            $subcmd = $action2 === 'status' ? 'chassis power status' : "chassis power $action2";
            $res = $runner->run($bmc, $subcmd);
            echo json_encode(['ok' => true, 'data' => $res]);
            break;

        case 'dashboard':
            // 一次返回所有 BMC 的 chassis status（用于首页卡片）
            $list = $store->listAll();
            $out = [];
            foreach ($list as $b) {
                $r = $runner->run($b, 'chassis status');
                $out[] = [
                    'id'     => $b['id'],
                    'name'   => $b['name'],
                    'host'   => $b['host'],
                    'status' => parseChassisStatus($r['stdout']),
                    'ok'     => $r['ok'],
                    'stderr' => $r['stderr'] ?? '',
                ];
            }
            echo json_encode(['ok' => true, 'data' => $out]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => '未知 action: ' . $action]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

/**
 * 把 chassis status 输出里的 System Power 行解析出来
 */
function parseChassisStatus(string $stdout): array
{
    $status = ['power' => 'unknown', 'cooling_fault' => false, 'drive_fault' => false];
    foreach (preg_split('/\r?\n/', $stdout) as $line) {
        if (preg_match('/System Power\s*:\s*(\S+)/', $line, $m))       $status['power']        = $m[1];
        if (preg_match('/Cooling\/Fan Fault\s*:\s*(\S+)/', $line, $m)) $status['cooling_fault']= $m[1] === 'true';
        if (preg_match('/Drive Fault\s*:\s*(\S+)/', $line, $m))        $status['drive_fault']  = $m[1] === 'true';
    }
    return $status;
}
