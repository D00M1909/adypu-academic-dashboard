<?php
// Faculty accounts, sessions, and the join codes that let a school onboard
// itself. Everything lives in data/faculty.php, behind store.php's guard.
//
// No email anywhere in here, and that is a constraint rather than a choice:
// this host disables PHP's mail() and blocks outbound HTTP, so there is no
// "click the link we sent you" to build. Verification is therefore either a
// code handed out in advance or a human approving a queue, and this file does
// both — a right code activates immediately, anything else waits for an admin.
//
// Passwords are bcrypt via password_hash(). The first admin comes from
// ADMIN_EMAIL / ADMIN_HASH in includes/config.local.php, which lives only on
// the server, so no bootstrap account is ever committed.

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/config.php';
// For SCHOOLS, which a signup has to validate its school against.
require_once __DIR__ . '/attendance.php';

const AUTH_COOKIE      = 'adypu_faculty';
const AUTH_REMEMBER_DAYS = 30;
const AUTH_MAX_FAILS   = 8;
const AUTH_LOCK_MINS   = 15;
const AUTH_MIN_PASSWORD = 8;

// Anything that writes to $_SESSION goes through this first. auth_logout()
// destroys the session, so a sign-out followed by a sign-in inside one request
// — which is exactly what auth_user() does when it finds a disabled account —
// would otherwise regenerate an id that no longer exists.
function auth_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
}

function auth_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => AUTH_REMEMBER_DAYS * 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // Conditional, not always on. Their TLS was fully dead for three days
        // in Aug 2026; a hard secure flag would have logged every faculty
        // member out of a site that was still serving over http.
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
    if (empty($_SESSION['email'])) auth_resume();
}

// --- The account file -------------------------------------------------------

function auth_data(): array {
    $d = store_read('faculty');
    $d['users'] ??= [];
    $d['codes'] ??= [];
    return $d;
}

// Emails are the primary key, so they are normalised once, here, and every
// lookup goes through it. Two accounts differing only in case would each get
// half a faculty member's submissions.
function auth_key(string $email): string {
    return strtolower(trim($email));
}

function auth_find(string $email): ?array {
    $u = auth_data()['users'][auth_key($email)] ?? null;
    return is_array($u) ? $u + ['email' => auth_key($email)] : null;
}

function auth_put(string $email, array $fields): void {
    $key = auth_key($email);
    store_update('faculty', function (array $d) use ($key, $fields) {
        $d['users'][$key] ??= [];
        foreach ($fields as $k => $v) $d['users'][$key][$k] = $v;
        return $d;
    });
}

// --- Who is asking ----------------------------------------------------------

// Re-read from the file on every request rather than trusting a copy in the
// session: disabling an account has to take effect on that account's next
// click, not whenever it next happens to log in.
function auth_user(): ?array {
    if (empty($_SESSION['email'])) return null;
    if (!empty($_SESSION['is_config_admin'])) {
        return ['email' => $_SESSION['email'], 'name' => 'Administrator',
                'school' => '', 'status' => 'active', 'admin' => true];
    }
    $u = auth_find($_SESSION['email']);
    if (!$u || ($u['status'] ?? '') === 'disabled') {
        auth_logout();
        return null;
    }
    return $u;
}

function auth_is_admin(): bool {
    $u = auth_user();
    return $u !== null && !empty($u['admin']);
}

// Sends anywhere unauthenticated back to the login page, remembering where they
// were headed so approving a link in a message still lands on the right class.
function auth_require(bool $admin = false): array {
    $u = auth_user();
    if ($u === null) {
        header('Location: login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? 'mark.php'));
        exit;
    }
    if ($admin && empty($u['admin'])) {
        http_response_code(403);
        exit('Not permitted.');
    }
    return $u;
}

// --- Logging in -------------------------------------------------------------

// Returns '' on success, or a message safe to show. Deliberately the same
// message for a wrong password and an unknown email: distinguishing them turns
// the login form into a list of who works here.
function auth_login(string $email, string $password): string {
    auth_session();
    $key = auth_key($email);

    if (defined('ADMIN_EMAIL') && defined('ADMIN_HASH') && ADMIN_EMAIL !== ''
        && $key === auth_key(ADMIN_EMAIL) && password_verify($password, ADMIN_HASH)) {
        session_regenerate_id(true);
        $_SESSION['email'] = $key;
        $_SESSION['is_config_admin'] = true;
        return '';
    }

    $u = auth_find($key);
    if ($u && ($u['locked'] ?? 0) > time()) {
        return 'Too many attempts. Try again in ' . max(1, (int) ceil((($u['locked'] - time()) / 60))) . ' minutes.';
    }
    if (!$u || ($u['status'] ?? '') === 'disabled' || !password_verify($password, $u['hash'] ?? '')) {
        if ($u) {
            $fails = (int) ($u['fails'] ?? 0) + 1;
            auth_put($key, [
                'fails'  => $fails,
                'locked' => $fails >= AUTH_MAX_FAILS ? time() + AUTH_LOCK_MINS * 60 : 0,
            ]);
        }
        return 'That email and password do not match.';
    }

    session_regenerate_id(true);
    $_SESSION['email'] = $key;
    unset($_SESSION['is_config_admin']);
    auth_put($key, ['fails' => 0, 'locked' => 0, 'seen' => date('Y-m-d H:i')]);
    auth_remember($key);
    return '';
}

function auth_logout(): void {
    $_SESSION = [];
    setcookie(AUTH_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

// A 30 day token beside the session, because session files on shared hosting are
// garbage-collected on a schedule that is not ours to set. A faculty member
// logged out at 08:59 is the failure that gets the whole thing abandoned.
//
// ponytail: the token is not rotated on use, so a stolen cookie stays valid
// until it expires or the admin disables the account. Rotate per request if
// these ever guard anything more than an attendance count.
function auth_remember(string $key): void {
    $token = bin2hex(random_bytes(32));
    auth_put($key, ['remember' => hash('sha256', $token)]);
    setcookie(AUTH_COOKIE, $key . '|' . $token, [
        'expires'  => time() + AUTH_REMEMBER_DAYS * 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

function auth_resume(): void {
    [$key, $token] = array_pad(explode('|', (string) ($_COOKIE[AUTH_COOKIE] ?? ''), 2), 2, '');
    if ($key === '' || $token === '') return;
    $u = auth_find($key);
    if (!$u || empty($u['remember']) || ($u['status'] ?? '') === 'disabled') return;
    if (!hash_equals($u['remember'], hash('sha256', $token))) return;
    $_SESSION['email'] = auth_key($key);
}

// --- Signing up -------------------------------------------------------------

// Codes get read off a whiteboard or a WhatsApp message, so case and the dashes
// people insert are ignored on both sides of the comparison.
function auth_normalise_code(string $code): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
}

function auth_new_code(): string {
    // No O/0/I/1: these are read aloud and copied by hand.
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 8; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return substr($out, 0, 4) . '-' . substr($out, 4);
}

// A right code activates on the spot. Anything else, including no code at all,
// lands in the admin queue rather than being refused: a teacher who missed the
// memo must still end up somewhere an admin can see them, not at a dead end.
function auth_signup(string $name, string $email, string $password, string $school, string $code): string {
    $name = trim($name);
    $key = auth_key($email);

    if ($name === '') return 'Please enter your name.';
    if (!filter_var($key, FILTER_VALIDATE_EMAIL)) return 'That does not look like an email address.';
    if (strlen($password) < AUTH_MIN_PASSWORD) return 'Please choose a password of at least ' . AUTH_MIN_PASSWORD . ' characters.';
    if (!isset(SCHOOLS[$school])) return 'Please choose your school.';
    if (auth_find($key)) return 'There is already an account for that email. Try signing in.';

    $codes = auth_data()['codes'];
    $given = auth_normalise_code($code);
    $expected = auth_normalise_code((string) ($codes[$school] ?? ''));
    $active = $given !== '' && $expected !== '' && hash_equals($expected, $given);

    auth_put($key, [
        'name'     => $name,
        'school'   => $school,
        'hash'     => password_hash($password, PASSWORD_DEFAULT),
        'status'   => $active ? 'active' : 'pending',
        'admin'    => false,
        'created'  => date('Y-m-d H:i'),
        'fails'    => 0,
        'locked'   => 0,
    ]);
    return '';
}

function auth_change_password(string $email, string $current, string $new): string {
    $u = auth_find($email);
    if (!$u || !password_verify($current, $u['hash'] ?? '')) return 'Your current password is not right.';
    if (strlen($new) < AUTH_MIN_PASSWORD) return 'The new password needs at least ' . AUTH_MIN_PASSWORD . ' characters.';
    auth_put($email, ['hash' => password_hash($new, PASSWORD_DEFAULT), 'remember' => '']);
    auth_remember(auth_key($email));
    return '';
}

// There is no "email me a reset link" on this host, so an admin generating one
// temporary password and reading it out is the whole recovery story.
function auth_reset_password(string $email): string {
    $temp = auth_new_code() . auth_new_code();
    auth_put($email, ['hash' => password_hash($temp, PASSWORD_DEFAULT), 'remember' => '', 'fails' => 0, 'locked' => 0]);
    return $temp;
}

// --- CSRF -------------------------------------------------------------------

function csrf_token(): string {
    auth_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_ok(): bool {
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''));
}
