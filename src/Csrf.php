<?php

declare(strict_types=1);

namespace Cms;

/**
 * CSRF tokens for every state-changing request.
 *
 * One token per session rather than one per form: simpler, and it does not
 * break when someone opens two admin tabs, which per-form rotation does.
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf'];
    }

    /** A hidden input for form templates. */
    public static function field(): string
    {
        return '<input type="hidden" name="csrf" value="' . Html::escape(self::token()) . '">';
    }

    /**
     * Compare in constant time. A plain === leaks how many leading characters
     * matched through timing, which is enough to reconstruct a token.
     */
    public static function verify(?string $sent): bool
    {
        $expected = $_SESSION['csrf'] ?? '';

        return is_string($sent)
            && $sent !== ''
            && $expected !== ''
            && hash_equals($expected, $sent);
    }

    /**
     * Guard a POST handler. Sends 419 and stops if the token is missing or wrong.
     */
    public static function require(?string $sent): void
    {
        if (!self::verify($sent)) {
            http_response_code(419);
            exit('Session expired. Go back, reload the page and try again.');
        }
    }
}
