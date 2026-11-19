<?php
declare(strict_types=1);
namespace RakanWiman\Core;

use RakanWiman\Core\Database;

final class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['admin_id'])) return null;
        $stmt = Database::connection()->prepare('SELECT id, name, email, role FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([(int) $_SESSION['admin_id']]);
        return $stmt->fetch() ?: null;
    }

    public static function check(): bool { return self::user() !== null; }

    public static function requireLogin(): void
    {
        if (!self::check()) redirect('login');
    }

    public static function login(string $email, string $password): bool
    {
        $stmt = Database::connection()->prepare('SELECT id, password_hash FROM admins WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([mb_strtolower(trim($email))]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) return false;
        $touch = Database::connection()->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?');
        $touch->execute([(int) $row['id']]);
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $row['id'];
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
