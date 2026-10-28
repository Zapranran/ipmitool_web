<?php
/**
 * 极简 Session 鉴权 — 生产环境请替换为更完善的方案
 */
class Auth
{
    public static function start(string $sessionName): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name($sessionName);
            session_start();
        }
    }

    public static function login(string $user, string $pass, array $cfg): bool
    {
        return hash_equals($cfg['auth_user'], $user)
            && hash_equals($cfg['auth_pass'], $pass);
    }

    public static function setLoggedIn(string $user): void
    {
        $_SESSION['ipmi_web'] = [
            'user'     => $user,
            'loggedAt' => time(),
        ];
    }

    public static function check(): bool
    {
        return !empty($_SESSION['ipmi_web']['user']);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => '未登录']);
            exit;
        }
    }
}
