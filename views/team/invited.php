<?php
/**
 * Invite sent confirmation — rendered by team/invited.
 *
 * Shows a success card with the invited address.
 * The raw token is never exposed here.
 *
 * Template variables:
 *   $email      string  Invited email address
 *   $csrfToken  string  (available but not used — included for consistency)
 */
?>
<div class="container py-4">

    <div class="row">
        <div class="col-12 col-md-6 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-4 text-center">

                    <div class="mb-3">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48"
                             fill="currentColor" class="text-success" viewBox="0 0 16 16"
                             aria-hidden="true">
                            <path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1zm13 2.383-4.708 2.825L15 11.105zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741M1 11.105l4.708-2.897L1 5.383z"/>
                        </svg>
                    </div>

                    <p class="fw-semibold mb-1">Invite sent</p>
                    <p class="text-muted mb-4">
                        An invitation has been sent to
                        <strong><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></strong>.
                        The link expires in 48&nbsp;hours. If they don&rsquo;t receive it,
                        use <em>Send reset</em> from the team list to resend.
                    </p>

                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                        <a href="<?php echo u('team', 'invite'); ?>"
                           class="btn btn-outline-secondary">Invite another</a>
                        <a href="<?php echo u('team'); ?>"
                           class="btn btn-primary">Back to team</a>
                    </div>

                </div>
            </div>
        </div>
    </div>

</div>
