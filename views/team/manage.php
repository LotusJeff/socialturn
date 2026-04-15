<?php
/**
 * Manage user view — rendered by team/manage.
 *
 * Alpine.js x-data drives the role toggle:
 *   - Admin   (role == 1):   shows "Full access" note, hides account checkboxes
 *   - Member  (role == 100): shows account checkboxes, hides note
 *
 * isSelf guard suppresses role/active controls and the delete button.
 *
 * Template variables:
 *   $user       array   Row from users (id, email, type, active, last_login)
 *   $accounts   array   All active accounts in the company
 *   $present    array   account_id values currently assigned to this user
 *   $isSelf     bool    True when managing the currently logged-in user
 *   $csrfToken  string
 */
?>
<div class="container py-4">

    <div class="d-flex align-items-center mb-4">
        <a href="<?php echo BASE_URL; ?>team"
           class="text-muted text-decoration-none me-3"
           aria-label="Back to team">&larr;</a>
        <h1 class="h3 mb-0">
            <?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>
        </h1>
        <?php if (!$user['active']): ?>
            <span class="badge bg-secondary ms-2">Inactive</span>
        <?php endif; ?>
        <?php if ($isSelf): ?>
            <span class="badge bg-secondary ms-2">You</span>
        <?php endif; ?>
    </div>

    <?php if ($isSelf): ?>
    <div class="alert alert-info mb-4" role="alert">
        You are viewing your own account. Role and status cannot be changed here &mdash;
        ask another admin to update your permissions.
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-lg-7">

            <!-- Role, active, and account access -->
            <form method="POST" action="<?php echo BASE_URL; ?>team/update"
                  x-data="{ role: <?php echo (int) $user['type']; ?> }">
                <input type="hidden" name="csrf_token"
                       value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="id"
                       value="<?php echo (int) $user['id']; ?>">

                <div class="card shadow-sm mb-3">
                    <div class="card-body p-4">
                        <h5 class="card-title mb-3">Role</h5>

                        <?php if ($isSelf): ?>
                            <!-- Read-only display for self -->
                            <p class="mb-0">
                                <?php echo (int) $user['type'] === 1 ? 'Admin' : 'Team Member'; ?>
                                <span class="text-muted small">(cannot change own role)</span>
                            </p>
                            <input type="hidden" name="role" value="<?php echo (int) $user['type']; ?>">
                        <?php else: ?>
                            <select name="role" class="form-select mb-3" x-model.number="role">
                                <option value="1"  <?php echo (int) $user['type'] === 1   ? 'selected' : ''; ?>>Admin</option>
                                <option value="100"<?php echo (int) $user['type'] === 100 ? 'selected' : ''; ?>>Team Member</option>
                            </select>

                            <div x-show="role == 1"
                                 <?php if ((int) $user['type'] !== 1) echo 'style="display: none"'; ?>
                                 class="alert alert-info mb-0" role="alert">
                                Admins have full access to all accounts &mdash;
                                no per-account permissions are needed.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!$isSelf): ?>
                <div class="card shadow-sm mb-3">
                    <div class="card-body p-4">
                        <h5 class="card-title mb-3">Account access</h5>

                        <div x-show="role == 1"
                             <?php if ((int) $user['type'] !== 1) echo 'style="display: none"'; ?>
                             class="text-muted small">
                            Admins have access to all accounts automatically.
                        </div>

                        <div x-show="role == 100"
                             <?php if ((int) $user['type'] !== 100) echo 'style="display: none"'; ?>>
                            <?php if (empty($accounts)): ?>
                                <p class="text-muted small mb-0">
                                    No accounts exist yet. Create accounts first,
                                    then return here to assign access.
                                </p>
                            <?php else: ?>
                                <p class="text-muted small mb-3">
                                    Select the accounts this team member can access.
                                </p>
                                <div class="row g-2">
                                    <?php foreach ($accounts as $account): ?>
                                    <div class="col-12 col-sm-6">
                                        <div class="form-check">
                                            <input type="checkbox"
                                                   id="account_<?php echo (int) $account['id']; ?>"
                                                   name="accounts[]"
                                                   value="<?php echo (int) $account['id']; ?>"
                                                   class="form-check-input"
                                                   <?php echo in_array($account['id'], $present) ? 'checked' : ''; ?>>
                                            <label class="form-check-label"
                                                   for="account_<?php echo (int) $account['id']; ?>">
                                                <?php echo htmlspecialchars(
                                                    $account['display_name'] ?: $account['name'],
                                                    ENT_QUOTES, 'UTF-8'
                                                ); ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>

                <div class="card shadow-sm mb-3">
                    <div class="card-body p-4">
                        <h5 class="card-title mb-3">Status</h5>
                        <div class="form-check">
                            <input type="checkbox"
                                   id="active"
                                   name="active"
                                   value="1"
                                   class="form-check-input"
                                   <?php echo $user['active'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="active">Active</label>
                        </div>
                        <div class="form-text">
                            Inactive users cannot log in. Their history is preserved.
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$isSelf): ?>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save changes</button>
                    <a href="<?php echo BASE_URL; ?>team" class="btn btn-outline-secondary">Cancel</a>
                </div>
                <?php else: ?>
                <a href="<?php echo BASE_URL; ?>team" class="btn btn-outline-secondary">Back to team</a>
                <?php endif; ?>

            </form>

        </div>

        <div class="col-12 col-lg-4">

            <!-- Force reset -->
            <div class="card shadow-sm mb-3">
                <div class="card-body p-4">
                    <h5 class="card-title mb-1">Password reset</h5>
                    <p class="text-muted small mb-3">
                        Send this user a new password reset email.
                        Any existing unused reset link will be invalidated.
                    </p>
                    <?php if ($user['active']): ?>
                    <form method="POST" action="<?php echo BASE_URL; ?>team/forceReset">
                        <input type="hidden" name="csrf_token"
                               value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="id"
                               value="<?php echo (int) $user['id']; ?>">
                        <button type="submit" class="btn btn-outline-warning btn-sm">
                            Send reset email
                        </button>
                    </form>
                    <?php else: ?>
                    <p class="text-muted small mb-0">Reactivate this user to send a reset email.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Delete — hidden for self; uses Alpine.js confirm -->
            <?php if (!$isSelf): ?>
            <div class="card shadow-sm border-danger-subtle">
                <div class="card-body p-4">
                    <h5 class="card-title text-danger mb-1">Remove from team</h5>
                    <p class="text-muted small mb-3">
                        Deactivates this account. Their post history is preserved.
                    </p>
                    <form method="POST" action="<?php echo BASE_URL; ?>team/delete" x-data>
                        <input type="hidden" name="csrf_token"
                               value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="id"
                               value="<?php echo (int) $user['id']; ?>">
                        <button type="submit"
                                class="btn btn-outline-danger btn-sm"
                                @click.prevent="window.confirm('Remove this user from the team?') && $el.closest('form').submit()">
                            Remove from team
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

</div>
