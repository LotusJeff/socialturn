<?php
/**
 * Invite form view — rendered by team/invite.
 *
 * Template variables:
 *   $csrfToken  string
 */
?>
<div class="container py-4">

    <div class="d-flex align-items-center mb-4">
        <a href="<?php echo u('team'); ?>"
           class="text-muted text-decoration-none me-3"
           aria-label="Back to team">&larr;</a>
        <h1 class="h3 mb-0">Invite team member</h1>
    </div>

    <div class="row">
        <div class="col-12 col-md-6 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">

                    <p class="text-muted mb-4">
                        Enter the email address of the person you want to invite.
                        They will receive a link to set their password and join your team.
                        The link expires in 48&nbsp;hours.
                    </p>

                    <form method="POST" action="<?php echo u('team', 'invited'); ?>">
                        <input type="hidden" name="csrf_token"
                               value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-4">
                            <label for="email" class="form-label">Email address</label>
                            <input type="email"
                                   id="email"
                                   name="email"
                                   class="form-control"
                                   autocomplete="email"
                                   autofocus
                                   required>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Send invite</button>
                        </div>

                    </form>

                </div>
            </div>
        </div>
    </div>

</div>
