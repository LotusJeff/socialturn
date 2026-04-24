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
?>
<div class="container py-4" style="max-width:720px">

    <div class="d-flex align-items-center mb-4 gap-3">
        <a href="<?= u('content') ?>" class="text-muted text-decoration-none">&larr; Content Library</a>
        <h1 class="h3 mb-0">New Post</h1>
    </div>

    <form method="POST" action="<?= u('content', 'store') ?>"
          enctype="multipart/form-data"
          x-data='{
              accountPlatforms:   <?= $accountPlatformsJson ?>,
              accountDefaultTags: <?= $accountDefaultTagsJson ?>,
              platformLimits:     <?= $platformLimits ?>,
              selectedAccountId: <?= $preselect > 0 ? $preselect : 0 ?>,
              bodyLength: 0,
              intent: "save",
              scheduleDay: "",
              scheduleTime: "",
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
              },
              get schedulePicked() {
                  return this.scheduleDay !== "" && this.scheduleTime !== "";
              },
              get canSubmit() {
                  if (this.overLimit) return false;
                  if (this.intent === "schedule" && !this.schedulePicked) return false;
                  return true;
              }
          }'>

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
                        Post text
                        <span data-bs-toggle="tooltip"
                              data-bs-title="Tags will be appended automatically up to the platform limit."
                              class="text-muted ms-1" style="cursor:default">&#63;</span>
                        <span class="text-danger">*</span>
                    </label>
                    <textarea id="body" name="body" class="form-control"
                              rows="4" required
                              :class="overLimit ? 'is-invalid' : ''"
                              @input="bodyLength = $event.target.value.length"></textarea>

                    <!-- Character counter -->
                    <div class="form-text mt-1 text-end">
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
                    <label for="attributed_to" class="form-label">Attribution
                        <span data-bs-toggle="tooltip"
                              data-bs-title="Shown as — Author after the post body and used for image overlay layout."
                              class="text-muted ms-1" style="cursor:default">&#63;</span>
                        <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" id="attributed_to" name="attributed_to"
                           class="form-control" style="max-width:360px"
                           placeholder="e.g. Winston Churchill">
                </div>

                <!-- Post tags -->
                <div class="mb-3">
                    <div class="d-flex gap-4 align-items-start">

                        <!-- Left: label + input + helper text -->
                        <div>
                            <label for="post_tags" class="form-label">Post tags
                                <span data-bs-toggle="tooltip"
                                      data-bs-title="Enter words without # — the # is added automatically when the post is sent."
                                      class="text-muted ms-1" style="cursor:default">&#63;</span>
                                <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" id="post_tags" name="post_tags"
                                   class="form-control" style="max-width:480px"
                                   placeholder="e.g. Policy Education">
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
                    <label for="image" class="form-label">Image
                        <span data-bs-toggle="tooltip"
                              data-bs-title="JPG or PNG. Leave blank for a text-only post."
                              class="text-muted ms-1" style="cursor:default">&#63;</span>
                        <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="file" id="image" name="image"
                           class="form-control" style="max-width:400px"
                           accept=".jpg,.jpeg,.png">
                    <div class="mt-2">
                        <label for="image_url" class="form-label">Or fetch from URL
                            <span data-bs-toggle="tooltip"
                                  data-bs-title="Paste a direct link to a JPG or PNG. A file upload and a URL can both be added at once."
                                  class="text-muted ms-1" style="cursor:default">&#63;</span>
                            <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="url" id="image_url" name="image_url"
                               class="form-control" style="max-width:400px"
                               placeholder="https://example.com/image.jpg">
                    </div>
                </div>

                <!-- Posting intent -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Posting intent</label>
                    <div class="d-flex gap-4">
                        <div class="form-check">
                            <input type="radio" id="intent_save" name="_intent"
                                   class="form-check-input" value="save"
                                   x-model="intent">
                            <label for="intent_save" class="form-check-label">Save to Library</label>
                        </div>
                        <div class="form-check">
                            <input type="radio" id="intent_share_now" name="_intent"
                                   class="form-check-input" value="share_now"
                                   x-model="intent">
                            <label for="intent_share_now" class="form-check-label">Post Now</label>
                        </div>
                        <div class="form-check">
                            <input type="radio" id="intent_schedule" name="_intent"
                                   class="form-check-input" value="schedule"
                                   x-model="intent">
                            <label for="intent_schedule" class="form-check-label">Schedule for Later</label>
                        </div>
                    </div>
                </div>

                <!-- Schedule date/time picker -->
                <div class="mb-3" x-show="intent === 'schedule'" x-cloak>
                    <div class="d-flex gap-3 align-items-end flex-wrap">
                        <div>
                            <label for="schedule_day" class="form-label">Date</label>
                            <input type="date" id="schedule_day" name="schedule_day"
                                   class="form-control" style="max-width:180px"
                                   min="<?= date('Y-m-d') ?>"
                                   max="<?= date('Y-m-d', strtotime('+30 days')) ?>"
                                   x-model="scheduleDay">
                        </div>
                        <div>
                            <label for="schedule_time" class="form-label">Time
                                <span data-bs-toggle="tooltip"
                                      data-bs-title="Time is interpreted in this account's posting timezone."
                                      class="text-muted ms-1" style="cursor:default">&#63;</span>
                            </label>
                            <input type="time" id="schedule_time" name="schedule_time"
                                   class="form-control" style="max-width:140px"
                                   x-model="scheduleTime">
                        </div>
                    </div>
                </div>

                <!-- Recycle toggle -->
                <div class="mb-3">
                    <div class="form-check">
                        <input type="checkbox" id="is_recyclable" name="is_recyclable"
                               class="form-check-input" value="1" checked>
                        <label for="is_recyclable" class="form-check-label">Recycle after posting
                            <span data-bs-toggle="tooltip"
                                  data-bs-title="Uncheck to send this post once and then deactivate it automatically."
                                  class="text-muted ms-1" style="cursor:default">&#63;</span>
                        </label>
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
        <div class="d-flex gap-2 align-items-center">
            <input type="hidden" name="intent" :value="intent">
            <button type="submit" class="btn btn-primary"
                    :disabled="!canSubmit"
                    x-text="intent === 'share_now' ? 'Post Now' : (intent === 'schedule' ? 'Schedule Post' : 'Save to Library')">
                Save to Library
            </button>
            <a href="<?= u('content') ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>

    </form>

</div>
