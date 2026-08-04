<?php
/**
 * Auth — user accounts, login sessions, and encrypted API-key storage.
 *
 * Added for the accounts feature: the app now requires a login. Accounts are
 * username + password (password_hash/password_verify — never the raw password).
 * Login state lives in a PHP session ($_SESSION), not the tab-scoped sessionStorage
 * the API key uses.
 *
 * Ownership: a session .json now carries an `owner` (the user_id that created it).
 * listSessions() filters by owner so each user only sees their own; direct access
 * is guarded by ownsSession(). Legacy sessions with no owner (created before this
 * feature) are treated as orphaned — hidden from every user's list, but still
 * openable by direct link so existing demo data isn't lost.
 *
 * API key: optionally stored per account, encrypted at rest with AES-256-GCM. The
 * encryption key (APP_ENC_KEY) lives outside the DB — in config/local.php / env —
 * so a database dump alone can't reveal a user's key. Decryption happens only in
 * memory, per request, right before the key is injected into the LLM client.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/InterviewSession.php';

class Auth
{
    private const CIPHER = 'aes-256-gcm';

    // ───────────────────────── PHP session bootstrap ─────────────────────────

    /** Start the PHP session once, with hardened cookie flags. Safe to call repeatedly. */
    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { return; }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');

        session_set_cookie_params([
            'lifetime' => 0,          // cookie dies with the browser session
            'path'     => '/',
            'httponly' => true,       // JS can't read the session cookie
            'secure'   => $https,     // HTTPS-only once TLS is on (see deployment plan)
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    // ───────────────────────── state accessors ─────────────────────────

    public static function check(): bool
    {
        self::boot();
        return isset($_SESSION['user_id']);
    }

    public static function currentUserId(): ?int
    {
        self::boot();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function currentUsername(): ?string
    {
        self::boot();
        return $_SESSION['username'] ?? null;
    }

    /**
     * Gate an HTML page behind login. Redirects to login.php (remembering where the
     * user was headed) and stops the script when no one is logged in.
     */
    public static function requireLogin(): void
    {
        if (self::check()) { return; }
        $target = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: login.php?next=' . urlencode($target));
        exit;
    }

    // ───────────────────────── register / login / logout ─────────────────────────

    /**
     * Create an account. Email is required (for password recovery) and the password
     * must be confirmed. Returns ['ok'=>bool, 'error'=>?string, 'user_id'=>?int].
     * Fails cleanly (never throws) so the DB being down surfaces as a message.
     */
    public static function register(string $username, string $email, string $password, string $passwordConfirm): array
    {
        $username = trim($username);
        $email    = trim($email);

        if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username)) {
            return ['ok' => false, 'error' => 'Username must be 3–64 characters (letters, numbers, . _ -).'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            return ['ok' => false, 'error' => 'Please enter a valid email address.'];
        }
        if (strlen($password) < 8) {
            return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        }
        if ($password !== $passwordConfirm) {
            return ['ok' => false, 'error' => 'The two passwords do not match.'];
        }

        try {
            $db = getDB();
            $exists = $db->prepare('SELECT 1 FROM users WHERE username = ?');
            $exists->execute([$username]);
            if ($exists->fetch()) {
                return ['ok' => false, 'error' => 'That username is already taken.'];
            }
            $emailTaken = $db->prepare('SELECT 1 FROM users WHERE email = ?');
            $emailTaken->execute([$email]);
            if ($emailTaken->fetch()) {
                return ['ok' => false, 'error' => 'An account with that email already exists.'];
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)')
               ->execute([$username, $email, $hash]);

            return ['ok' => true, 'error' => null, 'user_id' => (int) $db->lastInsertId()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach the database. Is MySQL running?'];
        }
    }

    /**
     * Verify credentials and start a login session. Returns ['ok'=>bool, 'error'=>?string].
     */
    public static function login(string $username, string $password): array
    {
        $username = trim($username);
        try {
            $db   = getDB();
            $stmt = $db->prepare('SELECT user_id, username, password_hash FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($password, $row['password_hash'])) {
                return ['ok' => false, 'error' => 'Incorrect username or password.'];
            }

            self::boot();
            session_regenerate_id(true);   // new id on privilege change — blocks fixation
            $_SESSION['user_id']  = (int) $row['user_id'];
            $_SESSION['username'] = $row['username'];
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach the database. Is MySQL running?'];
        }
    }

    public static function logout(): void
    {
        self::boot();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // ───────────────────────── password recovery ─────────────────────────

    /**
     * Start a password reset for an email. To avoid revealing which emails have
     * accounts, the RETURN shape is the same whether or not the email exists —
     * except 'dev_link', which is only populated in local (mail-disabled) mode so
     * the demo can click the link without real email.
     *
     * @return array{ok:bool, sent:bool, dev_link:?string, error:?string}
     */
    public static function createPasswordReset(string $email): array
    {
        $email = trim($email);
        $out   = ['ok' => true, 'sent' => false, 'dev_link' => null, 'error' => null];

        try {
            $db   = getDB();
            $stmt = $db->prepare('SELECT user_id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $uid = $stmt->fetchColumn();
            if ($uid === false) { return $out; }   // unknown email → look identical to success

            // One live token per user: drop any earlier unused ones.
            $db->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')
               ->execute([$uid]);

            $raw     = bin2hex(random_bytes(32));                 // 64 hex chars, emailed to user
            $hash    = hash('sha256', $raw);                      // only the hash is stored
            $expires = gmdate('Y-m-d H:i:s', time() + 3600);      // valid 1 hour (UTC)
            $db->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
               ->execute([(int) $uid, $hash, $expires]);

            $link = self::resetLink($raw);

            if (self::mailEnabled()) {
                $out['sent'] = self::sendResetEmail($email, $link);
                if (!$out['sent']) {
                    $out['ok']    = false;
                    $out['error'] = 'We could not send the email right now. Please try again later.';
                }
            } else {
                // Local demo mode: no SMTP configured — surface the link directly.
                error_log("[password-reset] dev link for {$email}: {$link}");
                $out['dev_link'] = $link;
            }
            return $out;
        } catch (Throwable $e) {
            return ['ok' => false, 'sent' => false, 'dev_link' => null,
                    'error' => 'Could not process the request. Is MySQL running?'];
        }
    }

    /** The user_id a valid (unused, unexpired) reset token belongs to, or null. */
    public static function findUserByResetToken(string $token): ?int
    {
        try {
            $hash = hash('sha256', trim($token));
            $stmt = getDB()->prepare(
                'SELECT user_id FROM password_resets
                 WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
                 LIMIT 1'
            );
            $stmt->execute([$hash]);
            $uid = $stmt->fetchColumn();
            return $uid === false ? null : (int) $uid;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Consume a reset token and set a new (confirmed) password.
     * @return array{ok:bool, error:?string}
     */
    public static function resetPassword(string $token, string $newPassword, string $confirm): array
    {
        if (strlen($newPassword) < 8) {
            return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        }
        if ($newPassword !== $confirm) {
            return ['ok' => false, 'error' => 'The two passwords do not match.'];
        }

        $uid = self::findUserByResetToken($token);
        if ($uid === null) {
            return ['ok' => false, 'error' => 'This reset link is invalid or has expired. Request a new one.'];
        }

        try {
            $db   = getDB();
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $db->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')->execute([$hash, $uid]);
            // Burn every outstanding token for this user so the link can't be reused.
            $db->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL')
               ->execute([$uid]);
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not update the password. Please try again.'];
        }
    }

    private static function mailEnabled(): bool
    {
        return getenv('APP_MAIL_ENABLED') === '1';
    }

    /** Build the absolute reset URL. APP_BASE_URL wins; otherwise auto-detect from the request. */
    private static function resetLink(string $token): string
    {
        $base = getenv('APP_BASE_URL') ?: '';
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
            $base   = $scheme . '://' . $host . $dir;
        }
        return rtrim($base, '/') . '/reset_password.php?token=' . urlencode($token);
    }

    private static function sendResetEmail(string $to, string $link): bool
    {
        $from    = getenv('APP_MAIL_FROM') ?: 'no-reply@localhost';
        $subject = 'Reset your Requirement Orchestrator password';
        $body    = "We received a request to reset your password.\r\n\r\n"
                 . "Open this link to choose a new password (valid for 1 hour):\r\n{$link}\r\n\r\n"
                 . "If you didn't request this, you can safely ignore this email.";
        $headers = 'From: ' . $from . "\r\n"
                 . "Content-Type: text/plain; charset=utf-8\r\n";
        try {
            return @mail($to, $subject, $body, $headers);
        } catch (Throwable $e) {
            return false;
        }
    }

    // ───────────────────────── session ownership ─────────────────────────

    /**
     * May the current user open this session? True when they own it, or when the
     * session is orphaned (owner === null: legacy/pre-accounts data). New sessions
     * always carry an owner, so real users stay isolated from each other.
     */
    public static function ownsSession(string $sessionId): bool
    {
        $owner = InterviewSession::sessionOwner($sessionId);
        return $owner === null || $owner === self::currentUserId();
    }

    // ───────────────────────── encrypted API-key storage ─────────────────────────

    private static function encKey(): ?string
    {
        $b64 = getenv('APP_ENC_KEY') ?: '';
        if ($b64 === '') { return null; }
        $raw = base64_decode($b64, true);
        return ($raw !== false && strlen($raw) === 32) ? $raw : null;
    }

    /** Is per-account key storage available (encryption key configured)? */
    public static function keyStorageAvailable(): bool
    {
        return self::encKey() !== null;
    }

    /**
     * Encrypt and save the current user's API key. Blob layout: nonce(12) | tag(16) |
     * ciphertext. Returns true on success. No-ops to false if storage is unavailable.
     */
    public static function storeApiKey(string $plainKey, ?string $provider = null): bool
    {
        $key = self::encKey();
        $uid = self::currentUserId();
        if ($key === null || $uid === null || $plainKey === '') { return false; }

        try {
            $nonce = random_bytes(12);
            $tag   = '';
            $ct    = openssl_encrypt($plainKey, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
            if ($ct === false) { return false; }
            $blob = $nonce . $tag . $ct;

            getDB()->prepare('UPDATE users SET api_key_enc = ?, api_provider = ? WHERE user_id = ?')
                   ->execute([$blob, $provider, $uid]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Decrypt and return the current user's stored API key, or null if none/undecryptable. */
    public static function getApiKey(): ?string
    {
        $key = self::encKey();
        $uid = self::currentUserId();
        if ($key === null || $uid === null) { return null; }

        try {
            $stmt = getDB()->prepare('SELECT api_key_enc FROM users WHERE user_id = ?');
            $stmt->execute([$uid]);
            $blob = $stmt->fetchColumn();
            if (!is_string($blob) || strlen($blob) < 29) { return null; }

            $nonce = substr($blob, 0, 12);
            $tag   = substr($blob, 12, 16);
            $ct    = substr($blob, 28);
            $plain = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
            return $plain === false ? null : $plain;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Forget the current user's stored key. */
    public static function clearApiKey(): bool
    {
        $uid = self::currentUserId();
        if ($uid === null) { return false; }
        try {
            getDB()->prepare('UPDATE users SET api_key_enc = NULL, api_provider = NULL WHERE user_id = ?')
                   ->execute([$uid]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function hasStoredKey(): bool
    {
        return self::getApiKey() !== null;
    }
}
