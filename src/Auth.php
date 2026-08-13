<?php

declare(strict_types=1);

namespace Cms;

/**
 * Admin authentication.
 *
 * There is one account and no public sign-up route. That is not a limitation
 * being apologised for — it is the whole security model. A CMS for a brochure
 * site has one person editing it, and every additional account is another
 * password that can be weak.
 */
final class Auth
{
    private const STORE = 'admin';
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 900;

    public function __construct(private Store $store) {}

    /**
     * Start the session with cookie flags that survive a hostile network.
     *
     * HttpOnly keeps the cookie away from JavaScript, SameSite=Lax blocks it
     * from cross-site POSTs, and Secure is set whenever the request arrived
     * over HTTPS — including behind a TLS-terminating proxy, which is how
     * shared hosts almost always run.
     */
    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $this->isHttps(),
        ]);

        session_name('cms_session');
        session_start();
    }

    public function isConfigured(): bool
    {
        $account = $this->store->read(self::STORE);

        return isset($account['username'], $account['hash'])
            && is_string($account['hash'])
            && $account['hash'] !== '';
    }

    public function isLoggedIn(): bool
    {
        $this->startSession();

        return ($_SESSION['admin'] ?? false) === true;
    }

    /**
     * Create the single admin account. Refuses to run once one exists, so the
     * setup route cannot be used to overwrite the password.
     */
    public function install(string $username, string $password): true|string
    {
        if ($this->isConfigured()) {
            return 'An account already exists.';
        }

        if (strlen($username) < 3) {
            return 'Username must be at least 3 characters.';
        }

        if (($problem = $this->weakPassword($password)) !== null) {
            return $problem;
        }

        $this->store->write(self::STORE, [
            'username'   => $username,
            'hash'       => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => gmdate('c'),
        ]);

        return true;
    }

    /**
     * Verify credentials and open a session.
     *
     * Failures are counted per-username and lock out for 15 minutes. The error
     * message never distinguishes an unknown username from a wrong password —
     * that difference is how an attacker learns which accounts exist.
     */
    public function login(string $username, string $password): true|string
    {
        $this->startSession();

        if ($this->lockedOutFor() > 0) {
            $minutes = (int) ceil($this->lockedOutFor() / 60);

            return "Too many failed attempts. Try again in {$minutes} minute(s).";
        }

        $account = $this->store->read(self::STORE);
        $hash    = is_string($account['hash'] ?? null) ? $account['hash'] : '';

        // Always run a hash comparison, even when the username is wrong, so the
        // response time does not reveal whether the account exists.
        $dummy   = '$2y$12$usesomesillystringfoeXOaMbT1uPMPKAeF9k9uZ5wPZ1t3aNSy';
        $matches = password_verify($password, $hash !== '' ? $hash : $dummy);

        if ($hash === '' || !$matches || !hash_equals((string) ($account['username'] ?? ''), $username)) {
            $this->recordFailure();

            return 'Incorrect username or password.';
        }

        // Rehash if PHP's default cost has moved on since the account was made.
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $account['hash'] = password_hash($password, PASSWORD_DEFAULT);
            $this->store->write(self::STORE, $account);
        }

        $this->clearFailures();

        // A fresh session id on privilege change closes session fixation: an id
        // planted before login stops being the one that is now authenticated.
        session_regenerate_id(true);
        $_SESSION['admin']    = true;
        $_SESSION['username'] = $username;

        return true;
    }

    public function logout(): void
    {
        $this->startSession();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => $this->isHttps(),
            ]);
        }

        session_destroy();
    }

    public function changePassword(string $current, string $new): true|string
    {
        $account = $this->store->read(self::STORE);

        if (!password_verify($current, (string) ($account['hash'] ?? ''))) {
            return 'Current password is incorrect.';
        }

        if (($problem = $this->weakPassword($new)) !== null) {
            return $problem;
        }

        $account['hash'] = password_hash($new, PASSWORD_DEFAULT);
        $this->store->write(self::STORE, $account);

        return true;
    }

    /** Seconds remaining on a lockout, or 0 if not locked out. */
    private function lockedOutFor(): int
    {
        $throttle = $this->store->read('throttle');
        $count    = (int) ($throttle['count'] ?? 0);
        $last     = (int) ($throttle['last'] ?? 0);

        if ($count < self::MAX_ATTEMPTS) {
            return 0;
        }

        return max(0, ($last + self::LOCKOUT_SECONDS) - time());
    }

    private function recordFailure(): void
    {
        $this->store->mutate('throttle', function (array $t): array {
            $expired = (int) ($t['last'] ?? 0) + self::LOCKOUT_SECONDS < time();

            return [
                'count' => $expired ? 1 : (int) ($t['count'] ?? 0) + 1,
                'last'  => time(),
            ];
        });
    }

    private function clearFailures(): void
    {
        $this->store->write('throttle', ['count' => 0, 'last' => 0]);
    }

    /**
     * Length over composition rules. A 12-character passphrase beats an
     * 8-character one with a digit and a symbol bolted on the end.
     */
    private function weakPassword(string $password): ?string
    {
        if (strlen($password) < 12) {
            return 'Password must be at least 12 characters.';
        }

        if (preg_match('/^(.)\1+$/', $password)) {
            return 'Password cannot be a single repeated character.';
        }

        return null;
    }

    private function isHttps(): bool
    {
        // Shared hosts terminate TLS at a proxy, so the request reaches PHP on
        // port 80 with X-Forwarded-Proto set. Checking $_SERVER['HTTPS'] alone
        // marks the cookie insecure on every HTTPS request.
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
