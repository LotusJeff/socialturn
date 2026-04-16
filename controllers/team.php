<?php

/**
 * Team management controller — Admin only (type=1).
 *
 * Functions:
 *   index()       — list all users in the company, including inactive
 *   invite()      — render invite form
 *   invited()     — process invite POST, send Postmark setpassword email
 *   manage()      — render manage form for a user
 *   update()      — process role / active / account access changes
 *   delete()      — soft-delete a user (active=0)
 *   forceReset()  — generate a new password-reset token and send email
 */

authenticate();
checkPermission(1);

/**
 * Returns the current user's company ID, accepting both session keys.
 * company_id (new) takes precedence over companyid (legacy).
 */
function team_companyId(): int {
    return (int) ($_SESSION['user']['company_id'] ?? $_SESSION['user']['companyid'] ?? 0);
}

/**
 * Team index — lists all users, including inactive ones.
 * Inactive users float to the bottom via ORDER BY active DESC.
 */
function index(): void {
    global $dbh, $template;

    $stmt = $dbh->prepare(
        'SELECT id, email, type, active, last_login
           FROM users
          WHERE company_id = ?
          ORDER BY active DESC, type ASC, email ASC'
    );
    $stmt->execute([team_companyId()]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $template->set('users',     $users);
    $template->set('csrfToken', csrf_token());
}

/**
 * Invite form — GET only, renders the email input.
 */
function invite(): void {
    global $template;

    $template->set('csrfToken', csrf_token());
}

/**
 * Invite POST handler — generates a setpassword token and sends the invite email.
 *
 * The raw token is never passed to the view. Any existing unused invite for
 * that address is replaced before inserting the new one.
 */
function invited(): void {
    global $dbh, $template;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . BASE_URL . 'team/invite');
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . BASE_URL . 'team/invite');
        exit;
    }

    $companyId = team_companyId();
    $email     = sanitize(trim((string) ($_POST['email'] ?? '')), 'email');

    if ($email === '') {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'A valid email address is required.'];
        header('Location: ' . BASE_URL . 'team/invite');
        exit;
    }

    // Replace any existing unused invite for this address
    $dbh->prepare('DELETE FROM invites WHERE company_id = ? AND email = ? AND used_at IS NULL')
        ->execute([$companyId, $email]);

    $token = bin2hex(random_bytes(32));

    $dbh->prepare('INSERT INTO invites (company_id, email, token) VALUES (?, ?, ?)')
        ->execute([$companyId, $email, $token]);

    try {
        Mail_Postmark::compose()
            ->to($email)
            ->subject("You've been invited to SocialTurn")
            ->messagePlain(
                "You've been invited to join a SocialTurn account.\n\n" .
                "Click the link below to set your password and get started:\n\n" .
                BASE_URL . 'users/setpassword/' . $token . "\n\n" .
                "This link expires in 48 hours.\n\n" .
                "If you were not expecting this invitation, you can ignore this email."
            )
            ->send();
    } catch (Throwable) {
        // Invite row exists — admin can use Force Reset from the team index to resend.
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Invite created but the email could not be sent. Check Postmark settings in config.php.',
        ];
        header('Location: ' . BASE_URL . 'team');
        exit;
    }

    $template->set('email',     $email);
    $template->set('csrfToken', csrf_token());
}

/**
 * Manage form — GET: loads user and their current account access list.
 * Allows managing any user in the company, including admins and the current user.
 * isSelf is passed so the view can suppress role/active changes and the delete button.
 */
function manage(): void {
    global $dbh, $template, $path;

    $companyId = team_companyId();
    $userId    = isset($path[2]) ? (int) $path[2] : 0;

    if ($userId === 0) {
        error404();
    }

    // Load user — any type, including inactive
    $stmt = $dbh->prepare(
        'SELECT id, email, type, active, last_login
           FROM users
          WHERE id = ? AND company_id = ?'
    );
    $stmt->execute([$userId, $companyId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($user['id'])) {
        error404();
    }

    // All active accounts in this company
    $stmt = $dbh->prepare(
        'SELECT id, name, display_name
           FROM accounts
          WHERE company_id = ? AND is_active = 1
          ORDER BY name ASC'
    );
    $stmt->execute([$companyId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Currently assigned account IDs for this user
    $stmt = $dbh->prepare(
        'SELECT account_id FROM users_accounts WHERE company_id = ? AND user_id = ?'
    );
    $stmt->execute([$companyId, $userId]);
    $present = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $isSelf = ((int) ($_SESSION['user']['loggedin'] ?? 0) === $userId);

    $template->set('user',      $user);
    $template->set('accounts',  $accounts);
    $template->set('present',   $present);
    $template->set('isSelf',    $isSelf);
    $template->set('csrfToken', csrf_token());
}

/**
 * Update — POST: saves role, active status, and account access for a user.
 *
 * Guards:
 * - Self-protection: can't change own role or deactivate self
 * - Last-admin guard: can't demote or deactivate the last active admin
 * - Account access is reset then rebuilt from POST; absent checkboxes = no access
 */
function update(): void {
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . BASE_URL . 'team');
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . BASE_URL . 'team');
        exit;
    }

    $companyId = team_companyId();
    $userId    = (int) ($_POST['id'] ?? 0);
    $selfId    = (int) ($_SESSION['user']['loggedin'] ?? 0);

    $stmt = $dbh->prepare(
        'SELECT id, email, type, active FROM users WHERE id = ? AND company_id = ?'
    );
    $stmt->execute([$userId, $companyId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($user['id'])) {
        error404();
    }

    // Self-protection: silently preserve own role and keep self active
    if ($userId === $selfId) {
        $newRole   = (int) $user['type'];
        $newActive = 1;
    } else {
        $newRole   = in_array((int) ($_POST['role'] ?? 0), [1, 100]) ? (int) $_POST['role'] : (int) $user['type'];
        $newActive = isset($_POST['active']) ? 1 : 0;
    }

    // Last-admin guard: can't demote or deactivate the last active admin
    if ((int) $user['type'] === 1 && ($newRole !== 1 || $newActive === 0)) {
        $stmt = $dbh->prepare(
            'SELECT COUNT(*) FROM users WHERE company_id = ? AND type = 1 AND active = 1'
        );
        $stmt->execute([$companyId]);
        if ((int) $stmt->fetchColumn() <= 1) {
            $_SESSION['notification'] = [
                'type'    => 'error',
                'message' => 'Cannot remove the last admin. Promote another user to admin first.',
            ];
            header('Location: ' . BASE_URL . 'team/manage/' . $userId);
            exit;
        }
    }

    $dbh->prepare('UPDATE users SET type = ?, active = ? WHERE id = ? AND company_id = ?')
        ->execute([$newRole, $newActive, $userId, $companyId]);

    // Reset and rebuild account access for this user
    $dbh->prepare('DELETE FROM users_accounts WHERE company_id = ? AND user_id = ?')
        ->execute([$companyId, $userId]);

    if (!empty($_POST['accounts']) && is_array($_POST['accounts'])) {
        $stmt = $dbh->prepare(
            'INSERT IGNORE INTO users_accounts (company_id, user_id, account_id) VALUES (?, ?, ?)'
        );
        foreach ($_POST['accounts'] as $accountId) {
            $accountId = (int) $accountId;
            if ($accountId > 0) {
                $stmt->execute([$companyId, $userId, $accountId]);
            }
        }
    }

    $_SESSION['notification'] = [
        'type'    => 'success',
        'message' => 'Permissions updated for ' . htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8') . '.',
    ];

    header('Location: ' . BASE_URL . 'team');
    exit;
}

/**
 * Delete — POST: soft-deletes a user (active=0).
 *
 * Preserves FK integrity (post_history references users via posts.created_by).
 * Guards: can't delete self; can't delete the last active admin.
 */
function delete(): void {
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . BASE_URL . 'team');
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . BASE_URL . 'team');
        exit;
    }

    $companyId = team_companyId();
    $userId    = (int) ($_POST['id'] ?? 0);
    $selfId    = (int) ($_SESSION['user']['loggedin'] ?? 0);

    if ($userId === $selfId) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'You cannot remove yourself.'];
        header('Location: ' . BASE_URL . 'team');
        exit;
    }

    $stmt = $dbh->prepare(
        'SELECT id, email, type FROM users WHERE id = ? AND company_id = ?'
    );
    $stmt->execute([$userId, $companyId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($user['id'])) {
        error404();
    }

    // Last-admin guard
    if ((int) $user['type'] === 1) {
        $stmt = $dbh->prepare(
            'SELECT COUNT(*) FROM users WHERE company_id = ? AND type = 1 AND active = 1'
        );
        $stmt->execute([$companyId]);
        if ((int) $stmt->fetchColumn() <= 1) {
            $_SESSION['notification'] = [
                'type'    => 'error',
                'message' => 'Cannot remove the last admin. Promote another user to admin first.',
            ];
            header('Location: ' . BASE_URL . 'team');
            exit;
        }
    }

    $dbh->prepare('UPDATE users SET active = 0 WHERE id = ? AND company_id = ?')
        ->execute([$userId, $companyId]);

    $_SESSION['notification'] = [
        'type'    => 'success',
        'message' => htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8') . ' has been removed from the team.',
    ];

    header('Location: ' . BASE_URL . 'team');
    exit;
}

/**
 * Force reset — POST: generates a fresh password-reset token and emails it.
 *
 * Any existing unused invite for that address is replaced. Only works for
 * active users (inactive users cannot log in so a reset would be meaningless).
 */
function forceReset(): void {
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . BASE_URL . 'team');
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . BASE_URL . 'team');
        exit;
    }

    $companyId = team_companyId();
    $userId    = (int) ($_POST['id'] ?? 0);

    $stmt = $dbh->prepare(
        'SELECT id, email FROM users WHERE id = ? AND company_id = ? AND active = 1'
    );
    $stmt->execute([$userId, $companyId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($user['id'])) {
        error404();
    }

    $email = (string) $user['email'];

    // Replace any existing unused invite token for this address
    $dbh->prepare('DELETE FROM invites WHERE company_id = ? AND email = ? AND used_at IS NULL')
        ->execute([$companyId, $email]);

    $token = bin2hex(random_bytes(32));

    $dbh->prepare('INSERT INTO invites (company_id, email, token) VALUES (?, ?, ?)')
        ->execute([$companyId, $email, $token]);

    try {
        Mail_Postmark::compose()
            ->to($email)
            ->subject('Reset your SocialTurn password')
            ->messagePlain(
                "An admin has sent you a password reset link for your SocialTurn account.\n\n" .
                "Click the link below to set a new password:\n\n" .
                BASE_URL . 'users/setpassword/' . $token . "\n\n" .
                "This link expires in 48 hours.\n\n" .
                "If you did not request this, you can ignore this email."
            )
            ->send();

        $_SESSION['notification'] = [
            'type'    => 'success',
            'message' => 'Password reset email sent to ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '.',
        ];
    } catch (Throwable) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Reset token created but the email could not be sent. Check Postmark settings in config.php.',
        ];
    }

    header('Location: ' . BASE_URL . 'team');
    exit;
}
