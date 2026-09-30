<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/supabase.php';

const SESSION_TIMEOUT = 1800;      // 30 minutes idle
const MAX_FAILED_LOGINS = 5;       // per email or IP
const LOCKOUT_MINUTES = 15;

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
    if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > SESSION_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['last_seen'] = time();
}

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? ''; }

function redirect(string $url): never { header('Location: ' . $url); exit; }

function csrf_token(): string
{
    start_session();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_verify(): void
{
    start_session();
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(403);
        exit('Your session expired or the request was invalid. Please go back and try again.');
    }
}

function flash(string $type, string $msg): void { start_session(); $_SESSION['flash'][] = [$type, $msg]; }
function take_flash(): array { start_session(); $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function audit(?int $userId, string $action, string $entity, ?int $entityId, string $desc = ''): void
{
    try {
        db()->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description, ip_address)
                       VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $action, $entity, $entityId, $desc, client_ip()]);
    } catch (Throwable $e) { error_log('audit failed: ' . $e->getMessage()); }
}

function notify(int $userId, string $title, string $message, string $type = 'system'): void
{
    db()->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $title, $message, $type]);
}

function format_kes($amount): string { return 'KES ' . number_format((float) $amount, 2); }

/* ---------- Authentication ---------- */

function current_user(): ?array
{
    start_session();
    if (empty($_SESSION['uid'])) return null;
    static $user = false;
    if ($user === false) {
        $st = db()->prepare('SELECT id, full_name, email, role, phone, status FROM users WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $user = $st->fetch() ?: null;
        // Re-check on every request so deactivation takes effect immediately.
        if ($user && $user['status'] !== 'active') { $user = null; $_SESSION = []; }
    }
    return $user;
}

function require_login(?string $role = null): array
{
    $u = current_user();
    if (!$u) redirect('/login.php');
    if ($role !== null && $u['role'] !== $role) {
        http_response_code(403);
        exit('You are not authorized to perform this action.');
    }
    return $u;
}

function too_many_attempts(string $email): bool
{
    $st = db()->prepare("SELECT count(*) FROM login_attempts
        WHERE success = false AND attempted_at > now() - (? || ' minutes')::interval
          AND (lower(email) = lower(?) OR ip_address = ?)");
    $st->execute([LOCKOUT_MINUTES, $email, client_ip()]);
    return (int) $st->fetchColumn() >= MAX_FAILED_LOGINS;
}

/** Returns null on success, or an error message. */
function attempt_login(string $email, string $password): ?string
{
    $email = trim($email);
    if (too_many_attempts($email)) return 'Too many failed attempts. Please try again in ' . LOCKOUT_MINUTES . ' minutes.';

    $st = db()->prepare('SELECT * FROM users WHERE lower(email) = lower(?)');
    $st->execute([$email]);
    $u = $st->fetch();
    $ok = $u && password_verify($password, $u['password_hash']);

    db()->prepare('INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)')
        ->execute([$email, client_ip(), $ok ? 'true' : 'false']);

    if (!$ok) return 'Invalid email or password.';
    if ($u['status'] !== 'active') return 'Your account is inactive.';

    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    db()->prepare('UPDATE users SET last_login = now() WHERE id = ?')->execute([$u['id']]);
    audit((int) $u['id'], 'Login', 'user', (int) $u['id'], 'Successful login');
    return null;
}

function register_bidder(array $in): array   // returns [errors[], userId|null]
{
    $name  = trim($in['full_name'] ?? '');
    $email = trim($in['email'] ?? '');
    $phone = trim($in['phone'] ?? '');
    $pw    = (string) ($in['password'] ?? '');
    $err = [];
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) $err[] = 'Enter your full name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err[] = 'Enter a valid email address.';
    if ($phone !== '' && !preg_match('/^\+?[0-9 ()-]{7,20}$/', $phone)) $err[] = 'Enter a valid phone number.';
    if (strlen($pw) < 10) $err[] = 'Password must be at least 10 characters.';
    if ($pw !== ($in['password_confirm'] ?? null)) $err[] = 'Passwords do not match.';
    if ($err) return [$err, null];

    $st = db()->prepare('SELECT 1 FROM users WHERE lower(email) = lower(?)');
    $st->execute([$email]);
    if ($st->fetch()) return [['An account with this email already exists.'], null];

    $st = db()->prepare("INSERT INTO users (full_name, email, password_hash, role, phone)
                         VALUES (?, ?, ?, 'bidder', ?) RETURNING id");
    $st->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT), $phone ?: null]);
    $id = (int) $st->fetchColumn();
    audit($id, 'Registered', 'user', $id, 'Bidder account created');
    return [[], $id];
}

/* ---------- Bidding (delegates to the atomic SQL function) ---------- */

function place_bid(int $auctionId, int $bidderId, string $rawAmount): array
{
    $clean = str_replace(',', '', trim($rawAmount));
    if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $clean) || (float) $clean <= 0) {
        return ['ok' => false, 'error' => 'Enter a valid bid amount.'];
    }
    try {
        $st = db()->prepare('SELECT place_bid(?, ?, ?::numeric, ?)');
        $st->execute([$auctionId, $bidderId, $clean, client_ip()]);
        return json_decode((string) $st->fetchColumn(), true);
    } catch (Throwable $e) {
        error_log('place_bid failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Your bid could not be processed. Please try again.'];
    }
}

function close_expired_auctions(?int $actorId = null): int
{
    try {
        $st = db()->prepare('SELECT close_expired_auctions(?)');
        $st->execute([$actorId]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        error_log('close_expired_auctions failed: ' . $e->getMessage());
        return 0;
    }
}
