<?php
/**
 * Edit post view — rendered by content/edit.
 *
 * Template variables:
 *   $post          array   Post row (id, account_id, body, attributed_to,
 *                          post_tags, is_recyclable, is_active,
 *                          internal_note, created_at, account_name, platform)
 *   $postImages    array   Rows from post_images ordered by sort_order (id, sort_order, image_filename, image_source)
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

        <input type="hidden" name="id"         value="<?= (int) $post['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="card mb-4">
            <div class="card-body">

                <!-- Workspace selection -->
                <div class="mb-3">
                    <label for="account_id" class="form-label fw-semibold">
                        Workspace
                        <span data-bs-toggle="tooltip"
                              data-bs-title="Reassigning to a different workspace will move the post to that workspace's queue."
                              class="text-muted ms-1" style="cursor:default">&#63;</span>
                        <span class="text-danger">*</span>
                    </label>
                    <select id="account_id" name="account_id" class="form-select"
                            style="max-width:320px" required
                            x-model.number="selectedAccountId">
                        <option value="0">— select a workspace —</option>
                        <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"
                            <?= (int) $a['id'] === $currentAccountId ? 'selected' : '' ?>>
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
                              @input="bodyLength = $event.target.value.length"><?= htmlspecialchars((string) $post['body'], ENT_QUOTES, 'UTF-8') ?></textarea>

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
                           placeholder="e.g. Winston Churchill"
                           value="<?= htmlspecialchars((string) ($post['attributed_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
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
                                   placeholder="e.g. Policy Education"
                                   value="<?= htmlspecialchars((string) ($post['post_tags'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <!-- Right: workspace tags header + values -->
                        <div x-show="selectedAccountId > 0 && currentAccountTags.length > 0" x-cloak>
                            <div class="form-label text-muted fw-normal">Workspace tags</div>
                            <div class="form-text" x-text="currentAccountTags.join(' ')"></div>
                        </div>

                    </div>
                </div>

                <!-- Image -->
                <?php
                $postImagesJson = json_encode(
                    array_map(function (array $img): array {
                        $url = ($img['image_source'] === 'uploaded')
                            ? BASE_URL . 'images/originals/' . $img['image_filename']
                            : BASE_URL . 'images/' . $img['image_filename'];
                        return [
                            'id'             => (int) $img['id'],
                            'image_filename' => $img['image_filename'],
                            'image_source'   => $img['image_source'],
                            'thumbnail_url'  => $url,
                        ];
                    }, $postImages ?? []),
                    JSON_HEX_TAG | JSON_HEX_QUOT
                );
                ?>
                <div class="mb-3">
                    <label for="image" class="form-label">Image
                        <span data-bs-toggle="tooltip"
                              data-bs-title="JPG or PNG. Up to 4 images per post. Leave blank for a text-only post."
                              class="text-muted ms-1" style="cursor:default">&#63;</span>
                        <span class="text-muted fw-normal">(optional)</span></label>

                    <div x-data='{
                        images: <?= $postImagesJson ?>,
                        moveUp(i) {
                            if (i === 0) return;
                            const moved = this.images.splice(i, 1)[0];
                            this.images.splice(i - 1, 0, moved);
                        },
                        moveDown(i) {
                            if (i >= this.images.length - 1) return;
                            const moved = this.images.splice(i, 1)[0];
                            this.images.splice(i + 1, 0, moved);
                        }
                    }'>
                        <template x-for='(img, i) in images' :key='img.id'>
                            <div class='d-flex align-items-center gap-2 mb-2 p-2 border rounded bg-light'>
                                <input type='hidden' :name='"image_order[" + i + "]"' :value='img.id'>
                                <img :src='img.thumbnail_url' width='56' height='56'
                                     class='rounded border flex-shrink-0' style='object-fit:cover'>
                                <div class='flex-grow-1 small text-muted text-truncate'
                                     x-text='img.image_filename'></div>
                                <div class='d-flex flex-column gap-1'>
                                    <button type='button'
                                            class='btn btn-sm btn-outline-secondary py-0 lh-1'
                                            @click='moveUp(i)' :disabled='i === 0'>&uarr;</button>
                                    <button type='button'
                                            class='btn btn-sm btn-outline-secondary py-0 lh-1'
                                            @click='moveDown(i)' :disabled='i === images.length - 1'>&darr;</button>
                                </div>
                                <div class='form-check mb-0 flex-shrink-0'>
                                    <input type='checkbox' class='form-check-input'
                                           name='delete_images[]' :value='img.id'
                                           :id='"del_img_" + img.id'>
                                    <label :for='"del_img_" + img.id'
                                           class='form-check-label text-danger small'>Delete</label>
                                </div>
                            </div>
                        </template>

                        <?php if (count($postImages ?? []) < 4): ?>
                        <div class="mt-2">
                            <input type="file" id="image" name="image"
                                   class="form-control" style="max-width:400px"
                                   accept=".jpg,.jpeg,.png">
                            <?php if (!empty($postImages)): ?>
                            <div class="form-text">Uploading a new image appends it after the existing ones.</div>
                            <?php endif; ?>
                        </div>
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
                        <?php endif; ?>
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
                                      data-bs-title="Time is interpreted in this workspace's posting timezone."
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
                               class="form-check-input" value="1"
                               <?= (int) $post['is_recyclable'] ? 'checked' : '' ?>>
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
                           placeholder="Private note — never sent, never shown publicly"
                           value="<?= htmlspecialchars((string) ($post['internal_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>

            </div>
        </div>

        <!-- Actions -->
        <div class="d-flex gap-2 align-items-center">
            <input type="hidden" name="intent" :value="intent">
            <button type="submit" class="btn btn-primary"
                    :disabled="!canSubmit"
                    x-text="intent === 'share_now' ? 'Post Now' : (intent === 'schedule' ? 'Schedule Post' : 'Save Changes')">
                Save Changes
            </button>
            <a href="<?= u('content', 'index', ['account_id' => $currentAccountId]) ?>"
               class="btn btn-outline-secondary">Cancel</a>
        </div>

        <div class="text-muted small mt-2">
            Added <?= htmlspecialchars(datify((string) $post['created_at']), ENT_QUOTES, 'UTF-8') ?>
        </div>

    </form>

</div>
