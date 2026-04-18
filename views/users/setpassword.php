<?php
/**
 * Password set / reset view — rendered by users/setpassword.
 *
 * Used for initial owner setup, team member invites, and forgot-password resets.
 * $noextra is set by the controller — navbar and flash notifications suppressed.
 *
 * Alpine.js tracks password strength live:
 *   - Progress bar reflects rules met (0–4) with colour transitions
 *   - Submit button is disabled until all four rules pass
 *   - Server validates independently regardless of client state
 *
 * Template variables:
 *   $invite        array   Invite row — email and company_id
 *   $csrfToken     string
 *   $passwordError string|null  Server-side validation message on POST failure
 */
?>
<div class="min-vh-100 d-flex align-items-center justify-content-center bg-light">
    <div class="col-12 col-sm-10 col-md-6 col-lg-5 col-xl-4">

        <div class="text-center mb-4">
            <img src="<?php echo BASE_URL; ?>assets/img/logo.png" alt="SocialTurn" height="48" class="mb-3">
            <h1 class="h3 fw-semibold">Set your password</h1>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-4">

                <!-- Account email — read-only display -->
                <div class="mb-4">
                    <p class="text-muted small mb-1">Setting password for</p>
                    <p class="fw-semibold mb-0">
                        <?php echo htmlspecialchars($invite['email'], ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>

                <?php if (!empty($passwordError)): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars($passwordError, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php endif; ?>

                <form method="POST"
                      action="<?php echo u('users', 'setpassword', ['token' => $invite['token']]); ?>"
                      x-data="{
                          password: '',
                          confirm: '',
                          get hasLength()  { return this.password.length >= 12 },
                          get hasUpper()   { return /[A-Z]/.test(this.password) },
                          get hasNumber()  { return /[0-9]/.test(this.password) },
                          get hasSpecial() { return /[^a-zA-Z0-9]/.test(this.password) },
                          get rulesMet() {
                              return [this.hasLength, this.hasUpper, this.hasNumber, this.hasSpecial]
                                     .filter(Boolean).length;
                          },
                          get allPass()   { return this.rulesMet === 4 },
                          get barWidth()  { return (this.rulesMet * 25) + '%' },
                          get barClass() {
                              if (this.rulesMet <= 1) return 'bg-danger';
                              if (this.rulesMet <= 3) return 'bg-warning';
                              return 'bg-success';
                          }
                      }">

                    <input type="hidden" name="csrf_token"
                           value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                    <!-- Password -->
                    <div class="mb-3">
                        <label for="password" class="form-label">New password</label>
                        <input type="password"
                               id="password"
                               name="password"
                               class="form-control"
                               autocomplete="new-password"
                               x-model="password"
                               required>
                    </div>

                    <!-- Confirm password -->
                    <div class="mb-4">
                        <label for="password_confirm" class="form-label">Confirm password</label>
                        <input type="password"
                               id="password_confirm"
                               name="password_confirm"
                               class="form-control"
                               autocomplete="new-password"
                               x-model="confirm"
                               required>
                    </div>

                    <!-- Strength progress bar -->
                    <div class="mb-2">
                        <div class="progress" style="height: 6px;" role="progressbar"
                             :aria-valuenow="rulesMet * 25" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar"
                                 :class="barClass"
                                 :style="'width: ' + barWidth"
                                 style="transition: width 0.2s ease, background-color 0.2s ease;">
                            </div>
                        </div>
                    </div>

                    <!-- Strength checklist -->
                    <ul class="list-unstyled small mb-4">
                        <li :class="hasLength ? 'text-success' : 'text-muted'">
                            <span x-text="hasLength ? '✓' : '–'" class="me-1 fw-bold"></span>
                            At least 12 characters
                        </li>
                        <li :class="hasUpper ? 'text-success' : 'text-muted'">
                            <span x-text="hasUpper ? '✓' : '–'" class="me-1 fw-bold"></span>
                            At least one uppercase letter
                        </li>
                        <li :class="hasNumber ? 'text-success' : 'text-muted'">
                            <span x-text="hasNumber ? '✓' : '–'" class="me-1 fw-bold"></span>
                            At least one number
                        </li>
                        <li :class="hasSpecial ? 'text-success' : 'text-muted'">
                            <span x-text="hasSpecial ? '✓' : '–'" class="me-1 fw-bold"></span>
                            At least one special character
                        </li>
                    </ul>

                    <div class="d-grid">
                        <button type="submit"
                                class="btn btn-primary btn-lg"
                                :disabled="!allPass">
                            Set password
                        </button>
                    </div>

                </form>

            </div>
        </div>

    </div>
</div>
