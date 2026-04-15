<?php
/**
 * Team index view — rendered by team/index.
 *
 * Lists all users in the company, including inactive ones.
 * Inactive users float to the bottom (ORDER BY active DESC in controller).
 *
 * Template variables:
 *   $users      array   Rows from users table (id, email, type, active, last_login)
 *   $csrfToken  string
 */
$selfId = (int) ($_SESSION['user']['loggedin'] ?? 0);
?>
<div class="container py-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0">Team</h1>
        <a href="<?php echo BASE_URL; ?>team/invite" class="btn btn-primary">Invite team member</a>
    </div>

    <?php if (empty($users)): ?>
        <p class="text-muted">No team members yet.</p>
    <?php else: ?>

        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last login</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <?php $isSelf = ((int) $user['id'] === $selfId); ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($isSelf): ?>
                                    <span class="badge bg-secondary ms-1">You</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $user['type'] === 1): ?>
                                    <span class="badge bg-primary">Admin</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Team Member</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small">
                                <?php echo $user['last_login']
                                    ? date('M j, Y g:ia', strtotime($user['last_login']))
                                    : 'Never'; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-2 justify-content-end flex-wrap">

                                    <a href="<?php echo BASE_URL; ?>team/manage/<?php echo (int) $user['id']; ?>"
                                       class="btn btn-sm btn-outline-secondary">Manage</a>

                                    <?php if ($user['active']): ?>

                                        <!-- Force reset — no confirm needed (non-destructive) -->
                                        <form method="POST"
                                              action="<?php echo BASE_URL; ?>team/forceReset">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="id"
                                                   value="<?php echo (int) $user['id']; ?>">
                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-warning">
                                                Send reset
                                            </button>
                                        </form>

                                        <?php if (!$isSelf): ?>
                                        <!-- Delete — soft-delete with Alpine confirm -->
                                        <form method="POST"
                                              action="<?php echo BASE_URL; ?>team/delete"
                                              x-data>
                                            <input type="hidden" name="csrf_token"
                                                   value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="id"
                                                   value="<?php echo (int) $user['id']; ?>">
                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                    @click.prevent="window.confirm('Remove this user from the team?') && $el.closest('form').submit()">
                                                Remove
                                            </button>
                                        </form>
                                        <?php endif; ?>

                                    <?php endif; ?>

                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

</div>
