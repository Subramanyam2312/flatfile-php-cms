<?php

declare(strict_types=1);

use Cms\Auth;

/**
 * Authentication. The interesting assertions are the negative ones: that a
 * second account cannot be installed, that failures do not distinguish an
 * unknown user from a wrong password, and that the lockout actually engages.
 */

return [

    'install creates the account' => function (): void {
        resetSession();
        $auth = new Auth($store = tmpStore());

        Assert::false($auth->isConfigured(), 'a fresh store has no account');
        Assert::same(true, $auth->install('editor', 'correct-horse-battery'), 'install should succeed');
        Assert::true($auth->isConfigured(), 'and the account should now exist');

        $stored = $store->read('admin');
        Assert::same('editor', $stored['username'], 'the username is stored as given');
        Assert::missing('correct-horse-battery', json_encode($stored) ?: '', 'the password must never be stored in clear');
        Assert::true(
            password_verify('correct-horse-battery', (string) $stored['hash']),
            'the stored hash must verify against the password'
        );
    },

    'install refuses a second account' => function (): void {
        // The setup route is reachable before configuration. If install() did
        // not refuse, anyone hitting /admin/setup could overwrite the password.
        resetSession();
        $auth = new Auth(tmpStore());
        $auth->install('editor', 'correct-horse-battery');

        $result = $auth->install('attacker', 'another-long-password');
        Assert::same('An account already exists.', $result, 'a second install must be refused');
    },

    'install rejects weak passwords' => function (): void {
        resetSession();
        $auth = new Auth(tmpStore());

        Assert::same(
            'Password must be at least 12 characters.',
            $auth->install('editor', 'short'),
            'short passwords are refused'
        );
        Assert::same(
            'Password cannot be a single repeated character.',
            $auth->install('editor', 'aaaaaaaaaaaaaaa'),
            'a repeated character is refused even when long enough'
        );
        Assert::false((new Auth(tmpStore()))->isConfigured(), 'a refused install must not create anything');
    },

    'install rejects a short username' => function (): void {
        resetSession();
        $auth = new Auth(tmpStore());
        Assert::same(
            'Username must be at least 3 characters.',
            $auth->install('ab', 'correct-horse-battery'),
            'usernames have a floor too'
        );
    },

    'login succeeds with correct credentials' => function (): void {
        resetSession();
        $auth = new Auth(tmpStore());
        $auth->install('editor', 'correct-horse-battery');

        Assert::same(true, $auth->login('editor', 'correct-horse-battery'), 'correct credentials should authenticate');
        Assert::true($auth->isLoggedIn(), 'and the session should reflect it');
    },

    'login fails on a wrong password' => function (): void {
        resetSession();
        $auth = new Auth(tmpStore());
        $auth->install('editor', 'correct-horse-battery');

        Assert::same('Incorrect username or password.', $auth->login('editor', 'wrong-password-here'), 'wrong password is refused');
        Assert::false($auth->isLoggedIn(), 'and no session is opened');
    },

    'login does not reveal whether the username exists' => function (): void {
        // Distinguishable messages let an attacker enumerate valid usernames
        // before spending any effort on passwords.
        resetSession();
        $auth = new Auth(tmpStore());
        $auth->install('editor', 'correct-horse-battery');

        $wrongUser = $auth->login('nosuchuser', 'correct-horse-battery');

        resetSession();
        $auth2 = new Auth(tmpStore());
        $auth2->install('editor', 'correct-horse-battery');
        $wrongPass = $auth2->login('editor', 'definitely-wrong-x');

        Assert::same($wrongUser, $wrongPass, 'both failures must produce an identical message');
    },

    'repeated failures trigger a lockout' => function (): void {
        resetSession();
        $auth = new Auth(tmpStore());
        $auth->install('editor', 'correct-horse-battery');

        for ($i = 0; $i < 5; $i++) {
            $auth->login('editor', 'wrong-password-here');
        }

        $result = $auth->login('editor', 'correct-horse-battery');
        Assert::contains('Too many failed attempts', (string) $result, 'the sixth attempt must be locked out');
        Assert::false($auth->isLoggedIn(), 'even the correct password must not authenticate while locked');
    },

    'a successful login clears the failure count' => function (): void {
        resetSession();
        $auth = new Auth($store = tmpStore());
        $auth->install('editor', 'correct-horse-battery');

        $auth->login('editor', 'wrong-password-here');
        $auth->login('editor', 'wrong-password-here');
        $auth->login('editor', 'correct-horse-battery');

        Assert::same(0, (int) $store->read('throttle')['count'], 'the counter resets on success');
    },

    'logout ends the session' => function (): void {
        resetSession();
        $auth = new Auth(tmpStore());
        $auth->install('editor', 'correct-horse-battery');
        $auth->login('editor', 'correct-horse-battery');

        $auth->logout();
        Assert::false($auth->isLoggedIn(), 'after logout the session must not be authenticated');
    },

    'changePassword requires the current password' => function (): void {
        resetSession();
        $auth = new Auth(tmpStore());
        $auth->install('editor', 'correct-horse-battery');

        Assert::same(
            'Current password is incorrect.',
            $auth->changePassword('not-the-password', 'a-brand-new-passphrase'),
            'the current password must be proved'
        );
    },

    'changePassword enforces the same strength rules' => function (): void {
        resetSession();
        $auth = new Auth(tmpStore());
        $auth->install('editor', 'correct-horse-battery');

        Assert::same(
            'Password must be at least 12 characters.',
            $auth->changePassword('correct-horse-battery', 'short'),
            'a change must not be a way around the password policy'
        );
    },

    'changePassword replaces the hash' => function (): void {
        resetSession();
        $auth = new Auth($store = tmpStore());
        $auth->install('editor', 'correct-horse-battery');
        $before = $store->read('admin')['hash'];

        Assert::same(true, $auth->changePassword('correct-horse-battery', 'a-brand-new-passphrase'), 'the change should succeed');

        $after = $store->read('admin')['hash'];
        Assert::true($before !== $after, 'the stored hash must change');
        Assert::true(password_verify('a-brand-new-passphrase', (string) $after), 'and verify against the new password');

        resetSession();
        Assert::same(true, $auth->login('editor', 'a-brand-new-passphrase'), 'the new password should work');
    },

    'isConfigured is false when the hash is empty' => function (): void {
        // A truncated or half-written admin document must not be treated as a
        // configured account, or the login check has nothing to compare against.
        $store = tmpStore();
        $store->write('admin', ['username' => 'editor', 'hash' => '']);

        Assert::false((new Auth($store))->isConfigured(), 'an empty hash is not a configured account');
    },
];
