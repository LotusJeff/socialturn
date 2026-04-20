<?php
/**
 * Edit post view — rendered by content/edit.
 *
 * Template variables:
 *   $post          array   Post row (id, account_id, body, attributed_to,
 *                          post_tags, image_filename, is_recyclable, is_active,
 *                          internal_note, created_at, account_name, platform)
 *   $accounts      array   Accessible accounts (id, name, platform) — for account reassignment
 *   $pendingCount  int     Number of pending scheduled_posts rows for this post
 *   $csrfToken     string
 *
 * Cascade warning: shown when pendingCount > 0.
 * Share Now: cascade first, save, then queue with scheduled_time = NOW().
 */

// Build a JS object mapping account_id => platform for the character counter
$accountPlatforms = [];
foreach ($accounts as $a) {
    $accountPlatforms[(int) $a['id']] = strtolower((string) $a['platform']);
}
$accountPlatformsJson = json_encode($accountPlatforms, JSON_HEX_TAG | JSON_HEX_QUOT);

// Build a JS object mapping account_id => default_tags array for the tags display
$accountDefaultTags = [];
foreach ($accounts as $a) {
    $decoded = json_decode((string) ($a['default_tags'] ?? ''), true);
    $accountDefaultTags[(int) $a['id']] = is_array($decoded) ? $decoded : [];
}
$accountDefaultTagsJson = json_encode($accountDefaultTags, JSON_HEX_TAG | JSON_HEX_QUOT);

$platformLimits = json_encode([
    'twitter'   => 280,
    'instagram' => 2200,
    'facebook'  => 63206,
], JSON_HEX_TAG | JSON_HEX_QUOT);

$currentBodyLength = mb_strlen((string) $post['body']);
$currentAccountId  = (int) $post['account_id'];
?>
<div class="container py-4" style="max-width:720px">

    <div class="d-flex align-items-center mb-4 gap-3">
        <a href="<?= u('content', 'index', ['account_id' => $currentAccountId]) ?>"
           class="text-muted text-decoration-none">&larr; Content Library</a>
        <h1 class="h3 mb-0">Edit Post</h1>
    </div>

    <?php if ($pendingCount > 0): ?>
    <div class="alert alert-warning d-flex gap-2 align-items-start mb-4" role="alert">
        <span class="flex-shrink-0 mt-1">&#9888;</span>
        <div>
            <strong>Cascade warning:</strong>
            This post has <?= $pendingCount ?> pending <?= $pendingCount === 1 ? 'queue entry' : 'queue entries' ?>.
            Saving changes will remove <?= $pendingCount === 1 ? 'it' : 'them' ?> from the queue.
            The post will re-enter the queue on the next population cycle.
        </div>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= u('content', 'update') ?>"
          enctype="multipart/form-data"
          x-data='{
              accountPlatforms:   <?= $accountPlatformsJson ?>,
              accountDefaultTags: <?= $accountDefaultTagsJson ?>,
              platformLimits:     <?= $platformLimits ?>,
              selectedAccountId: <?= $currentAccountId ?>,
              bodyLength: <?= $currentBodyLength ?>,
              get platform() {
                  return this.accountPlatforms[this.selectedAccountId] || "";
              },
              get charLimit() {
                  return this.platformLimits[this.platform] || null;
              },
              get overLimit() {
                  return this.charLimit !== null && this.bodyLength > this.charLimit;
              },
              get currentAccountTags() {
                  return this.accountDefaultTags[this.selectedAccountId] || [];
              }
          }'>

        <input type="hidden" name="id"         value="<?= (int) $post['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="card mb-4">
            <div class="card-body">

                <!-- Account selection -->
                <div class="mb-3">
                    <label for="account_id" class="form-label fw-semibold">Account</label>
                    <select id="account_id" name="account_id" class="form-select"
                            style="max-width:320px" required
                            x-model.number="selectedAccountId">
                        <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"
                            <?= (int) $a['id'] === $currentAccountId ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $a['name'], ENT_QUOTES, 'UTF-8') ?>
                            (<?= htmlspecialchars(ucfirst((string) $a['platform']), ENT_QUOTES, 'UTF-8') ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (count($accounts) > 1): ?>
                    <div class="form-text">Reassigning to a different account will move the post to that account&rsquo;s queue.</div>
                    <?php endif; ?>
                </div>

                <!-- Post body -->
                <div class="mb-3">
                    <label for="body" class="form-label fw-semibold">
                        Post text <span class="text-danger">*</span>
                    </label>
                    <textarea id="body" name="body" class="form-control"
                              rows="4" required
                              :class="overLimit ? 'is-invalid' : ''"
                              @input="bodyLength = $event.target.value.length"><?= htmlspecialchars((string) $post['body'], ENT_QUOTES, 'UTF-8') ?></textarea>

                    <!-- Character counter — pre-populated with existing body length -->
                    <div class="form-text d-flex justify-content-between align-items-center mt-1">
                        <span>Tags will be appended automatically up to the platform limit.</span>
                        <span x-show="charLimit !== null" x-cloak
                              :class="overLimit ? 'text-danger fw-semibold' : 'text-muted'">
                            <span x-text="bodyLength"></span> / <span x-text="charLimit"></span>
                        </span>
                    </div>
                    <div class="invalid-feedback" x-show="overLimit" x-cloak>
                        Post body exceeds the platform character limit.
                    </div>
                </div>

                <!-- Attribution -->
                <div class="mb-3">
                    <label for="attributed_to" class="form-label">Attribution <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" id="attributed_to" name="attributed_to"
                           class="form-control" style="max-width:360px"
                           placeholder="e.g. Winston Churchill"
                           value="<?= htmlspecialchars((string) ($post['attributed_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-text">
                        Shown as &ldquo;&mdash; Author&rdquo; after the post body and used for image overlay layout.
                    </div>
                </div>

                <!-- Post tags -->
                <div class="mb-3">
                    <div class="d-flex gap-4 align-items-start">

                        <!-- Left: label + input + helper text -->
                        <div>
                            <label for="post_tags" class="form-label">Post tags <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" id="post_tags" name="post_tags"
                                   class="form-control" style="max-width:480px"
                                   placeholder="e.g. Policy Education"
                                   value="<?= htmlspecialchars((string) ($post['post_tags'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="form-text">
                                Enter words without # &mdash; the # is added automatically when the post is sent.
                            </div>
                        </div>

                        <!-- Right: account tags header + values -->
                        <div x-show="selectedAccountId > 0 && currentAccountTags.length > 0" x-cloak>
                            <div class="form-label text-muted fw-normal">Account tags</div>
                            <div class="form-text" x-text="currentAccountTags.join(' ')"></div>
                        </div>

                    </div>
                </div>

                <!-- Image -->
                <div class="mb-3">
                    <label for="image" class="form-label">Image <span class="text-muted fw-normal">(optional)</span></label>

                    <?php if (!empty($post['image_filename'])): ?>
                    <div class="mb-2 text-muted small">
                        Current image: <code><?= htmlspecialchars((string) $post['image_filename'], ENT_QUOTES, 'UTF-8') ?></code>
                    </div>
                    <?php endif; ?>

                    <input type="file" id="image" name="image"
                           class="form-control" style="max-width:400px"
                           accept=".jpg,.jpeg,.png">
                    <div class="form-text">
                        <?= !empty($post['image_filename']) ? 'Upload a new image to replace the current one.' : 'JPG or PNG. Leave blank for a text-only post.' ?>
                    </div>
                </div>

                <!-- Recycle toggle -->
                <div class="mb-3">
                    <div class="form-check">
                        <input type="checkbox" id="is_recyclable" name="is_recyclable"
                               class="form-check-input" value="1"
                               <?= (int) $post['is_recyclable'] ? 'checked' : '' ?>>
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
                           placeholder="Private note — never sent, never shown publicly"
                           value="<?= htmlspecialchars((string) ($post['internal_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>

            </div>
        </div>

        <!-- Actions -->
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <button type="submit" name="save" class="btn btn-primary"
                    :disabled="overLimit">Save Changes</button>
            <button type="submit" name="share_now" value="1" class="btn btn-success"
                    :disabled="overLimit"
                    onclick="return confirm('<?= $pendingCount > 0 ? 'This will clear ' . $pendingCount . ' pending queue ' . ($pendingCount === 1 ? 'entry' : 'entries') . ' and post within 5 minutes. Continue?' : 'Post will publish within 5 minutes. Continue?' ?>')">
                Share Now
            </button>
            <a href="<?= u('content', 'index', ['account_id' => $currentAccountId]) ?>"
               class="btn btn-outline-secondary">Cancel</a>
        </div>

        <div class="text-muted small mt-2">
            Added <?= htmlspecialchars(datify((string) $post['created_at']), ENT_QUOTES, 'UTF-8') ?>
        </div>

    </form>

</div>
