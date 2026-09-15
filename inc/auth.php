<?php
declare(strict_types=1);

function current_user(): ?array
{
    $userId=(int)($_SESSION['user_id']??0);
    if($userId<=0)return null;
    static $cachedId=0,$cachedUser=null;
    if($cachedId===$userId)return $cachedUser;
    $stmt = db()->prepare('SELECT id, name, email, role, active FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $cachedId=$userId;
    $cachedUser=(!$user || !(int)$user['active'])?null:$user;
    return $cachedUser;
}

function require_auth(): void
{
    if (!current_user()) redirect('login.php');
}

function attempt_login(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !(int)($user['active'] ?? 1) || !password_verify($password, $user['password_hash'])) return false;
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
