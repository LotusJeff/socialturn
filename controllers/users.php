<?php

/**
 * Returns the post-login destination URL for the given user type.
 *
 * Admin (type=1): accounts/index when no active accounts exist yet,
 *   queue/index otherwise — so a fresh install lands on the connect flow.
 * Team member (type=100): always queue/index.
 */
function post_login_url(int $type): string
{
    if ($type === 1) {
        global $dbh;
        $stmt = $dbh->prepare('SELECT COUNT(*) FROM accounts WHERE is_active = 1');
        $stmt->execute();
        if ((int) $stmt->fetchColumn() === 0) {
            return u('accounts', 'index');
        }
    }
    return u('queue', 'index');
}

/**
 * Password set and password reset — shared flow.
 *
 * Used by: initial owner setup, team member invites, and forgot-password resets.
 * Token arrives as the third path segment: /users/setpassword/{token}
 *
 * GET:  Validates the token. Invalid/expired → redirect to login with error.
 *       Valid → render password form with email pre-filled.
 *
 * POST: Re-validates token, enforces password rules, then:
 *         New user  — INSERT into users (type=1 for OWNER_EMAIL, type=100 otherwise).
 *         Existing  — UPDATE password only.
 *       Marks invite as used, logs the user in, regenerates CSRF token,
 *       redirects to queue/index (or accounts/index on first run for admins).
 *
 * Server-side rules mirror the Alpine.js client-side checklist exactly:
 *   12+ characters · uppercase · number · special character · passwords match
 */
function setpassword(): void {
    global $dbh, $template;

    $template->set('noextra', true);
    $template->set('passwordError', null);
    $template->set('invite', null);

    $token = trim((string) ($_GET['token'] ?? ''));

    if ($token === '') {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'This link is invalid.'];
        header('Location: ' . u('users', 'login'));
        exit;
    }

    // Validate token: unused and created within the last 48 hours
    $stmt = $dbh->prepare(
        "SELECT id, company_id, email, token
           FROM invites
          WHERE token = ?
            AND used_at IS NULL
            AND created_at > NOW() - INTERVAL 48 HOUR"
    );
    $stmt->execute([$token]);
    $invite = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invite) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'This link has expired or has already been used.',
        ];
        header('Location: ' . u('users', 'login'));
        exit;
    }

    $template->set('invite', $invite);
    $template->set('csrfToken', csrf_token());

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('users', 'setpassword', ['token' => $token]));
        exit;
    }

    $password        = (string) ($_POST['password']         ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    // Password strength rules — identical to Alpine.js client-side checks
    $errors = [];
    if (strlen($password) < 12) {
        $errors[] = 'at least 12 characters';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'at least one uppercase letter';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'at least one number';
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        $errors[] = 'at least one special character';
    }
    if ($password !== $passwordConfirm) {
        $errors[] = 'passwords must match';
    }

    if (!empty($errors)) {
        $template->set('passwordError', 'Password must contain: ' . implode(', ', $errors) . '.');
        return;
    }

    $hash      = password_hash($password, PASSWORD_BCRYPT);
    $email     = (string) $invite['email'];
    $companyId = (int) $invite['company_id'];

    // OWNER_EMAIL gets admin (type=1); all other invite recipients get team member (type=100)
    $type = (defined('OWNER_EMAIL') && $email === OWNER_EMAIL) ? 1 : 100;

    // Determine new user vs. password reset by checking for an existing account
    $stmt = $dbh->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmt = $dbh->prepare('UPDATE users SET password = ? WHERE email = ?');
        $stmt->execute([$hash, $email]);
        $userId = (int) $existing['id'];
    } else {
        $stmt = $dbh->prepare(
            'INSERT INTO users (company_id, email, password, type, active) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$companyId, $email, $hash, $type]);
        $userId = (int) $dbh->lastInsertId();
    }

    // Mark invite as consumed
    $stmt = $dbh->prepare('UPDATE invites SET used_at = NOW() WHERE token = ?');
    $stmt->execute([$token]);

    // Log in automatically and regenerate CSRF token for the new session
    $_SESSION['user'] = [
        'loggedin'   => $userId,
        'email'      => $email,
        'company_id' => $companyId,
        'type'       => $type,
    ];
    csrf_regenerate();

    header('Location: ' . post_login_url($type));
    exit;
}

/**
 * Login — GET renders the form; POST authenticates.
 *
 * The form posts to users/login (not the old users/validate).
 * On success: records last_login, sets session with company_id,
 * regenerates CSRF token, and redirects to the originally attempted
 * URL (stored by authenticate()) or queue/index as the default.
 * redirect_after_login is always unset after consuming to prevent
 * stale redirects on subsequent logins.
 */
function login(): void {
    global $dbh, $template;

    $template->set('noextra', true);
    $template->set('loginError', null);
    $template->set('csrfToken', csrf_token());

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('users', 'login'));
        exit;
    }

    $email    = trim((string) ($_POST['email']    ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $template->set('loginError', 'Email and password are required.');
        return;
    }

    $stmt = $dbh->prepare(
        'SELECT id, company_id, password, type FROM users WHERE email = ? AND active = 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, (string) $user['password'])) {
        $template->set('loginError', 'Email or password is incorrect.');
        return;
    }

    // Record last login timestamp
    $dbh->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
        ->execute([(int) $user['id']]);

    $_SESSION['user'] = [
        'loggedin'   => (int) $user['id'],
        'email'      => $email,
        'company_id' => (int) $user['company_id'],
        'type'       => (int) $user['type'],
    ];
    csrf_regenerate();

    // Consume stored redirect — always unset to prevent stale redirects
    $redirect = $_SESSION['redirect_after_login'] ?? null;
    unset($_SESSION['redirect_after_login']);

    if (
        empty($redirect)
        || str_contains($redirect, 'a=login')
        || str_contains($redirect, 'a=validate')
    ) {
        $redirect = post_login_url((int) $user['type']);
    }

    header('Location: ' . $redirect);
    exit;
}

function logout(): void {
    // Destroy the session entirely — CSRF token is regenerated on next login.
    session_destroy();
    header('Location: ' . u('users', 'login'));
    exit;
}

/**
 * Forgot password — GET renders the email form; POST sends the reset link.
 *
 * Always shows the same success message regardless of whether the email
 * exists — never reveal account existence to an unauthenticated visitor.
 * Any error during token generation or email send is swallowed silently;
 * the user sees the same success state either way.
 */
function forgot(): void {
    global $dbh, $template;

    $template->set('noextra', true);
    $template->set('sent', false);
    $template->set('csrfToken', csrf_token());

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('users', 'forgot'));
        exit;
    }

    $email = trim((string) ($_POST['email'] ?? ''));

    if ($email !== '') {
        try {
            $stmt = $dbh->prepare(
                'SELECT id, company_id FROM users WHERE email = ? AND active = 1'
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $token = bin2hex(random_bytes(32));

                $dbh->prepare('DELETE FROM invites WHERE company_id = ? AND email = ? AND used_at IS NULL')
                    ->execute([(int) $user['company_id'], $email]);

                $dbh->prepare('INSERT INTO invites (company_id, email, token) VALUES (?, ?, ?)')
                    ->execute([(int) $user['company_id'], $email, $token]);

                Mail_Postmark::compose()
                    ->to($email)
                    ->subject('Reset your SocialTurn password')
                    ->messagePlain(
                        "You requested a password reset for your SocialTurn account.\n\n" .
                        "Click the link below to set a new password:\n\n" .
                        u('users', 'setpassword', ['token' => $token]) . "\n\n" .
                        "This link expires in 48 hours.\n\n" .
                        "If you did not request this, you can ignore this email."
                    )
                    ->send();
            }
        } catch (Throwable) {
            // Swallow all errors — same success message regardless of outcome.
        }
    }

    $template->set('sent', true);
}

