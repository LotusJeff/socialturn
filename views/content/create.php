<?php
/**
 * Create post view — rendered by content/create.
 *
 * Template variables:
 *   $accounts   array   Accessible accounts (id, name, platform) for the dropdown
 *   $preselect  int     account_id to pre-select (from ?account_id= query param), or 0
 *   $csrfToken  string
 *
 * Platform character limits (used by the Alpine.js counter):
 *   Twitter / X   280
 *   Instagram    2200
 *   Facebook    63206
 *
 * The counter reads the selected account's platform from the dropdown's
 * data-platform attribute and updates the limit live.
 */

// Build a JS object mapping account_id => platform for the character counter
$accountPlatforms = [];
foreach ($accounts as $a) {
    $accountPlatforms[(int) $a['id']] = strtolower((string) $a['platform']);
}
$accountPlatformsJson = json_encode($accountPlatforms, JSON_HEX_TAG | JSON_HEX_QUOT);

$platformLimits = json_encode([
    'twitter'   => 280,
    'instagram' => 2200,
    'facebook'  => 63206,
], JSON_HEX_TAG | JSON_HEX_QUOT);
?>
<div class="container py-4" style="max-width:720px">

    <div class="d-flex align-items-center mb-4 gap-3">
        <a href="<?= BASE_URL ?>content" class="text-muted text-decoration-none">&larr; Content Library</a>
        <h1 class="h3 mb-0">New Post</h1>
    </div>

    <form method="POST" action="<?= BASE_URL ?>content/store"
          enctype="multipart/form-data"
          x-data="{
              accountPlatforms: <?= $accountPlatformsJson ?>,
              platformLimits:   <?= $platformLimits ?>,
              selectedAccountId: <?= $preselect > 0 ? $preselect : 0 ?>,
              bodyLength: 0,
              get platform() {
                  return this.accountPlatforms[this.selectedAccountId] || '';
              },
              get charLimit() {
                  return this.platformLimits[this.platform] || null;
              },
              get overLimit() {
                  return this.charLimit !== null && this.bodyLength > this.charLimit;
              }
          }">

        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="card mb-4">
            <div class="card-body">

                <!-- Account selection -->
                <div class="mb-3">
                    <label for="account_id" class="form-label fw-semibold">
                        Account <span class="text-danger">*</span>
                    </label>
                    <select id="account_id" name="account_id" class="form-select"
                            style="max-width:320px" required
                            x-model.number="selectedAccountId">
                        <option value="0">— select an account —</option>
                        <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"
                            <?= (int) $a['id'] === $preselect ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $a['name'], ENT_QUOTES, 'UTF-8') ?>
                            (<?= htmlspecialchars(ucfirst((string) $a['platform']), ENT_QUOTES, 'UTF-8') ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Post body -->
                <div class="mb-3">
                    <label for="body" class="form-label fw-semibold">
                        Post text <span class="text-danger">*</span>
                    </label>
                    <textarea id="body" name="body" class="form-control"
                              rows="5" required
                              :class="overLimit ? 'is-invalid' : ''"
                              @input="bodyLength = $event.target.value.length"></textarea>

                    <!-- Character counter -->
                    <div class="form-text d-flex justify-content-between align-items-center mt-1">
                        <span>Tags will be appended automatically up to the platform limit.</span>
                        <span x-show="charLimit !== null" x-cloak
                              :class="overLimit ? 'text-danger fw-semibold' : 'text-muted'">
                            <span x-text="bodyLength"></span> / <span x-text="charLimit"></span>
                        </span>
                    </div>
                    <div class="invalid-feedback" x-show="overLimit" x-cloak>
                        Post body exceeds the platform character limit.
                        Tags are appended after the body, so the body itself must fit within the limit.
                    </div>
                </div>

                <!-- Attribution -->
                <div class="mb-3">
                    <label for="attributed_to" class="form-label">Attribution <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" id="attributed_to" name="attributed_to"
                           class="form-control" style="max-width:360px"
                           placeholder="e.g. Winston Churchill">
                    <div class="form-text">
                        Shown as &ldquo;&mdash; Author&rdquo; after the post body and used for image overlay layout.
                    </div>
                </div>

                <!-- Image -->
                <div class="mb-3">
                    <label for="image" class="form-label">Image <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="file" id="image" name="image"
                           class="form-control" style="max-width:400px"
                           accept=".jpg,.jpeg,.png">
                    <div class="form-text">JPG or PNG. Leave blank for a text-only post.</div>
                </div>

                <!-- Recycle toggle -->
                <div class="mb-3">
                    <div class="form-check">
                        <input type="checkbox" id="is_recyclable" name="is_recyclable"
                               class="form-check-input" value="1" checked>
                        <label for="is_recyclable" class="form-check-label">Recycle after posting</label>
                    </div>
                    <div class="form-text ms-4 mb-0">
                        Uncheck to send this post once and then deactivate it automatically.
                    </div>
                </div>

                <!-- Internal note -->
                <div class="mb-0">
                    <label for="internal_note" class="form-label">Internal note <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" id="internal_note" name="internal_note"
                           class="form-control" style="max-width:480px"
                           placeholder="Private note — never sent, never shown publicly">
                </div>

            </div>
        </div>

        <!-- Actions -->
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <button type="submit" name="save" class="btn btn-primary"
                    :disabled="overLimit">Save to Library</button>
            <button type="submit" name="share_now" value="1" class="btn btn-success"
                    :disabled="overLimit"
                    onclick="return confirm('Post will publish within 5 minutes. Continue?')">
                Share Now
            </button>
            <a href="<?= BASE_URL ?>content" class="btn btn-outline-secondary">Cancel</a>
            <span class="text-muted small ms-2">Share Now adds the post to the library and queues it for the next cron run.</span>
        </div>

    </form>

</div>
