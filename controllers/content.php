<?php
declare(strict_types=1);

use SocialTurn\Services\StorageService;
use SocialTurn\Services\TagAppenderService;

/**
 * Content library controller — Admins and authorized team members.
 *
 * A post is a piece of content in the library. It may be text-only or include
 * an image. Posts belong to an account. The queue population engine draws
 * from active, recyclable posts to fill scheduled_posts.
 *
 * Functions:
 *   index()   — list posts for accessible accounts with filter/search
 *   create()  — render create form
 *   store()   — process create POST; optionally Share Now
 *   edit()    — render edit form pre-populated with existing data
 *   update()  — process edit POST; cascade pending queue; optionally Share Now
 *   delete()  — soft-delete (is_active=0); clears pending queue
 *   toggle()  — flip is_recyclable; redirect back preserving filter params
 */

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------

function content_companyId(): int
{
    return (int) ($_SESSION['user']['company_id'] ?? $_SESSION['user']['companyid'] ?? 0);
}

function content_userId(): int
{
    return (int) ($_SESSION['user']['loggedin'] ?? 0);
}

function content_isAdmin(): bool
{
    return (int) ($_SESSION['user']['type'] ?? 999) === 1;
}

/**
 * Returns all active accounts accessible to the current user, joined to their
 * platform so the view can display the platform name alongside the account name.
 *
 * Admins: all active accounts for the company.
 * Team members: only accounts listed in users_accounts for this user.
 */
function content_accessibleAccounts(): array
{
    global $dbh;

    $companyId = content_companyId();

    if (content_isAdmin()) {
        $stmt = $dbh->prepare(
            'SELECT a.id, a.name, cp.platform
               FROM accounts a
               JOIN connected_platforms cp ON cp.id = a.connected_platform_id
              WHERE a.company_id = ? AND a.is_active = 1
              ORDER BY a.name ASC'
        );
        $stmt->execute([$companyId]);
    } else {
        $userId = content_userId();
        $stmt = $dbh->prepare(
            'SELECT a.id, a.name, cp.platform
               FROM accounts a
               JOIN connected_platforms cp ON cp.id = a.connected_platform_id
               JOIN users_accounts ua ON ua.account_id = a.id
              WHERE a.company_id = ? AND a.is_active = 1
                AND ua.user_id = ? AND ua.company_id = ?
              ORDER BY a.name ASC'
        );
        $stmt->execute([$companyId, $userId, $companyId]);
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Builds a final_body for a Share Now queue insert.
 * Uses TagAppenderService to append default_tags up to the platform limit.
 */
function content_buildFinalBody(string $body, ?string $defaultTagsJson, string $platform): string
{
    $appender = new TagAppenderService();
    $result   = $appender->append($body, $defaultTagsJson, $platform);
    return $result['body'];
}

// -----------------------------------------------------------------------
// Actions
// -----------------------------------------------------------------------

/**
 * Index — lists posts for all accessible accounts.
 *
 * Supports filtering by account_id and a text search against body and
 * attributed_to. Both filters are preserved as GET params across pagination.
 */
function index(): void
{
    global $dbh, $template;

    $companyId = content_companyId();
    $accounts  = content_accessibleAccounts();

    if (empty($accounts)) {
        $template->set('posts',            []);
        $template->set('accounts',         []);
        $template->set('filterAccountId',  0);
        $template->set('filterSearch',     '');
        $template->set('csrfToken',        csrf_token());
        return;
    }

    // Collect accessible account IDs for the scoping WHERE IN clause
    $accessibleIds = array_column($accounts, 'id');

    // Filters
    $filterAccountId = (int) ($_GET['account_id'] ?? 0);
    $filterSearch    = trim((string) ($_GET['q'] ?? ''));

    // If a non-zero filter_account_id was provided, verify it's accessible
    if ($filterAccountId !== 0 && !in_array($filterAccountId, $accessibleIds, true)) {
        $filterAccountId = 0;
    }

    // Build query dynamically
    $conditions = ['p.is_active = 1', 'a.company_id = ' . $companyId];
    $params     = [];

    // Account scope: either one account or all accessible
    if ($filterAccountId !== 0) {
        $conditions[] = 'p.account_id = ?';
        $params[]     = $filterAccountId;
    } else {
        $inList       = implode(',', array_fill(0, count($accessibleIds), '?'));
        $conditions[] = "p.account_id IN ($inList)";
        $params       = array_merge($params, $accessibleIds);
    }

    if ($filterSearch !== '') {
        $conditions[] = '(p.body LIKE ? OR p.attributed_to LIKE ?)';
        $like         = '%' . $filterSearch . '%';
        $params[]     = $like;
        $params[]     = $like;
    }

    $where = implode(' AND ', $conditions);

    $stmt = $dbh->prepare(
        "SELECT p.id, p.body, p.attributed_to, p.image_filename,
                p.is_recyclable, p.internal_note, p.created_at,
                a.id AS account_id, a.name AS account_name, cp.platform
           FROM posts p
           JOIN accounts a ON a.id = p.account_id
           JOIN connected_platforms cp ON cp.id = a.connected_platform_id
          WHERE $where
          ORDER BY p.created_at DESC
          LIMIT 200"
    );
    $stmt->execute($params);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $template->set('posts',           $posts);
    $template->set('accounts',        $accounts);
    $template->set('filterAccountId', $filterAccountId);
    $template->set('filterSearch',    $filterSearch);
    $template->set('csrfToken',       csrf_token());
}

/**
 * Create — GET: renders the create form.
 *
 * Passes accessible accounts with platform info for the dropdown.
 * If ?account_id= is provided and accessible, it is pre-selected.
 */
function create(): void
{
    global $template;

    $accounts = content_accessibleAccounts();

    if (empty($accounts)) {
        $_SESSION['notification'] = [
            'type'    => 'info',
            'message' => 'Create an account before adding content.',
        ];
        header('Location: ' . BASE_URL . 'content');
        exit;
    }

    $preselect = (int) ($_GET['account_id'] ?? 0);
    $accessibleIds = array_column($accounts, 'id');
    if (!in_array($preselect, $accessibleIds, true)) {
        $preselect = 0;
    }

    $template->set('accounts',  $accounts);
    $template->set('preselect', $preselect);
    $template->set('csrfToken', csrf_token());
}

/**
 * Store — POST: creates a new post.
 *
 * If the "share_now" button was used instead of the regular submit, also
 * inserts a scheduled_posts row with scheduled_time = NOW() so the next
 * cron run (within 5 minutes) picks it up and posts it.
 *
 * Image upload: accepted extensions jpg/jpeg/png only; stored via
 * StorageService with a random filename (8 random bytes, hex-encoded).
 */
function store(): void
{
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . BASE_URL . 'content/create');
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . BASE_URL . 'content/create');
        exit;
    }

    $accountId  = (int) ($_POST['account_id'] ?? 0);
    $body       = trim((string) ($_POST['body'] ?? ''));
    $shareNow   = isset($_POST['share_now']);

    if ($accountId === 0 || $body === '') {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Account and post body are required.'];
        header('Location: ' . BASE_URL . 'content/create');
        exit;
    }

    // Authorize access to this account
    authorizeAccount($accountId);

    $companyId    = content_companyId();
    $userId       = content_userId();
    $attributedTo = trim((string) ($_POST['attributed_to'] ?? '')) ?: null;
    $internalNote = trim((string) ($_POST['internal_note'] ?? '')) ?: null;
    $isRecyclable = isset($_POST['is_recyclable']) ? 1 : 0;

    // Verify account belongs to this company
    $stmt = $dbh->prepare(
        'SELECT a.id, cp.platform, cp.id AS cp_id, a.default_tags
           FROM accounts a
           JOIN connected_platforms cp ON cp.id = a.connected_platform_id
          WHERE a.id = ? AND a.company_id = ? AND a.is_active = 1'
    );
    $stmt->execute([$accountId, $companyId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($account['id'])) {
        error404();
    }

    // Image upload
    $imageFilename = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower((string) pathinfo((string) $_FILES['image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $newFilename = bin2hex(random_bytes(8)) . '.' . $ext;
            $storage = new StorageService();
            if ($storage->store((string) $_FILES['image']['tmp_name'], $newFilename)) {
                $imageFilename = $newFilename;
            }
        }
    }

    $dbh->beginTransaction();
    try {
        $dbh->prepare(
            'INSERT INTO posts
                 (account_id, body, attributed_to, image_filename, is_recyclable, is_active, internal_note, created_by)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
        )->execute([
            $accountId, $body, $attributedTo, $imageFilename,
            $isRecyclable, $internalNote, $userId,
        ]);
        $postId = (int) $dbh->lastInsertId();

        if ($shareNow) {
            $finalBody = content_buildFinalBody($body, $account['default_tags'], (string) $account['platform']);
            $dbh->prepare(
                "INSERT INTO scheduled_posts
                     (connected_platform_id, post_id, scheduled_time, status, final_body, final_image_filename)
                 VALUES (?, ?, NOW(), 'pending', ?, ?)"
            )->execute([$account['cp_id'], $postId, $finalBody, $imageFilename]);
        }

        $dbh->commit();
    } catch (Throwable) {
        $dbh->rollBack();
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Could not save post. Please try again.'];
        header('Location: ' . BASE_URL . 'content/create');
        exit;
    }

    if ($shareNow) {
        $_SESSION['notification'] = [
            'type'    => 'success',
            'message' => 'Post saved and will publish within 5 minutes.',
        ];
    } else {
        $_SESSION['notification'] = ['type' => 'success', 'message' => 'Post added to library.'];
    }

    header('Location: ' . BASE_URL . 'content?account_id=' . $accountId);
    exit;
}

/**
 * Edit — GET: loads a post for editing.
 *
 * Only active posts are editable. Authorizes access to the owning account.
 */
function edit(): void
{
    global $dbh, $template, $path;

    $companyId = content_companyId();
    $postId    = isset($path[2]) ? (int) $path[2] : 0;

    if ($postId === 0) {
        error404();
    }

    $stmt = $dbh->prepare(
        'SELECT p.id, p.account_id, p.body, p.attributed_to, p.image_filename,
                p.is_recyclable, p.is_active, p.internal_note, p.created_at,
                a.name AS account_name, cp.platform
           FROM posts p
           JOIN accounts a ON a.id = p.account_id
           JOIN connected_platforms cp ON cp.id = a.connected_platform_id
          WHERE p.id = ? AND a.company_id = ? AND p.is_active = 1'
    );
    $stmt->execute([$postId, $companyId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($post['id'])) {
        error404();
    }

    authorizeAccount((int) $post['account_id']);

    $accounts = content_accessibleAccounts();

    // Pending queue depth for this post — shown as cascade warning
    $stmt = $dbh->prepare(
        "SELECT COUNT(*) FROM scheduled_posts WHERE post_id = ? AND status = 'pending'"
    );
    $stmt->execute([$postId]);
    $pendingCount = (int) $stmt->fetchColumn();

    $template->set('post',         $post);
    $template->set('accounts',     $accounts);
    $template->set('pendingCount', $pendingCount);
    $template->set('csrfToken',    csrf_token());
}

/**
 * Update — POST: saves edits to an existing post.
 *
 * Post-edit cascade: all pending scheduled_posts rows for this post_id are
 * deleted before writing the update. This ensures the queue repopulates with
 * the fresh body on the next cron run instead of sending stale final_body
 * content.
 *
 * Share Now path: cascade first, then update the post, then insert a new
 * scheduled_posts row with scheduled_time = NOW() so the next cron run sends
 * it. Notification: "Post saved and will publish within 5 minutes."
 *
 * Account change: if the account_id is changed, authorizeAccount() is called
 * for both the original and the new account to ensure access to both.
 */
function update(): void
{
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . BASE_URL . 'content');
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . BASE_URL . 'content');
        exit;
    }

    $companyId = content_companyId();
    $postId    = (int) ($_POST['id'] ?? 0);
    $shareNow  = isset($_POST['share_now']);

    if ($postId === 0) {
        header('Location: ' . BASE_URL . 'content');
        exit;
    }

    // Load the existing post to verify ownership and get original account_id
    $stmt = $dbh->prepare(
        'SELECT p.id, p.account_id, p.image_filename
           FROM posts p
           JOIN accounts a ON a.id = p.account_id
          WHERE p.id = ? AND a.company_id = ? AND p.is_active = 1'
    );
    $stmt->execute([$postId, $companyId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($existing['id'])) {
        error404();
    }

    $originalAccountId = (int) $existing['account_id'];
    authorizeAccount($originalAccountId);

    $newAccountId = (int) ($_POST['account_id'] ?? $originalAccountId);
    if ($newAccountId !== $originalAccountId) {
        authorizeAccount($newAccountId);
        // Verify new account belongs to this company
        $stmt = $dbh->prepare('SELECT id FROM accounts WHERE id = ? AND company_id = ? AND is_active = 1');
        $stmt->execute([$newAccountId, $companyId]);
        if (!$stmt->fetchColumn()) {
            $newAccountId = $originalAccountId;
        }
    }

    $body         = trim((string) ($_POST['body'] ?? ''));
    $attributedTo = trim((string) ($_POST['attributed_to'] ?? '')) ?: null;
    $internalNote = trim((string) ($_POST['internal_note'] ?? '')) ?: null;
    $isRecyclable = isset($_POST['is_recyclable']) ? 1 : 0;

    if ($body === '') {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Post body cannot be empty.'];
        header('Location: ' . BASE_URL . 'content/edit/' . $postId);
        exit;
    }

    // Image upload — preserve existing if no new file uploaded
    $imageFilename = (string) ($existing['image_filename'] ?? '');
    $imageFilename = $imageFilename !== '' ? $imageFilename : null;

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower((string) pathinfo((string) $_FILES['image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $newFilename = bin2hex(random_bytes(8)) . '.' . $ext;
            $storage = new StorageService();
            if ($storage->store((string) $_FILES['image']['tmp_name'], $newFilename)) {
                $imageFilename = $newFilename;
            }
        }
    }

    // Load account info needed for Share Now final_body construction
    $stmt = $dbh->prepare(
        'SELECT cp.platform, cp.id AS cp_id, a.default_tags
           FROM accounts a
           JOIN connected_platforms cp ON cp.id = a.connected_platform_id
          WHERE a.id = ? AND a.company_id = ? AND a.is_active = 1'
    );
    $stmt->execute([$newAccountId, $companyId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($account['platform'])) {
        error404();
    }

    $dbh->beginTransaction();
    try {
        // Cascade: clear all pending queue rows for this post before updating
        $dbh->prepare(
            "DELETE FROM scheduled_posts WHERE post_id = ? AND status = 'pending'"
        )->execute([$postId]);

        $dbh->prepare(
            'UPDATE posts
                SET account_id = ?, body = ?, attributed_to = ?, image_filename = ?,
                    is_recyclable = ?, internal_note = ?
              WHERE id = ? AND is_active = 1'
        )->execute([
            $newAccountId, $body, $attributedTo, $imageFilename,
            $isRecyclable, $internalNote,
            $postId,
        ]);

        if ($shareNow) {
            $finalBody = content_buildFinalBody($body, $account['default_tags'], (string) $account['platform']);
            $dbh->prepare(
                "INSERT INTO scheduled_posts
                     (connected_platform_id, post_id, scheduled_time, status, final_body, final_image_filename)
                 VALUES (?, ?, NOW(), 'pending', ?, ?)"
            )->execute([$account['cp_id'], $postId, $finalBody, $imageFilename]);
        }

        $dbh->commit();
    } catch (Throwable) {
        $dbh->rollBack();
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Could not save changes. Please try again.'];
        header('Location: ' . BASE_URL . 'content/edit/' . $postId);
        exit;
    }

    if ($shareNow) {
        $_SESSION['notification'] = [
            'type'    => 'success',
            'message' => 'Post saved and will publish within 5 minutes.',
        ];
    } else {
        $_SESSION['notification'] = ['type' => 'success', 'message' => 'Post updated.'];
    }

    header('Location: ' . BASE_URL . 'content?account_id=' . $newAccountId);
    exit;
}

/**
 * Delete — POST: soft-deletes a post (is_active=0).
 *
 * Also clears all pending scheduled_posts rows for this post so the queue
 * does not attempt to send it. History rows (posted/failed/skipped) are
 * preserved. The post cannot be recovered from the UI.
 */
function delete(): void
{
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . BASE_URL . 'content');
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . BASE_URL . 'content');
        exit;
    }

    $companyId = content_companyId();
    $postId    = (int) ($_POST['id'] ?? 0);

    $stmt = $dbh->prepare(
        'SELECT p.id, p.account_id
           FROM posts p
           JOIN accounts a ON a.id = p.account_id
          WHERE p.id = ? AND a.company_id = ? AND p.is_active = 1'
    );
    $stmt->execute([$postId, $companyId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($post['id'])) {
        error404();
    }

    authorizeAccount((int) $post['account_id']);

    $dbh->beginTransaction();
    try {
        $dbh->prepare(
            "DELETE FROM scheduled_posts WHERE post_id = ? AND status = 'pending'"
        )->execute([$postId]);

        $dbh->prepare('UPDATE posts SET is_active = 0 WHERE id = ?')
            ->execute([$postId]);

        $dbh->commit();
    } catch (Throwable) {
        $dbh->rollBack();
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Could not delete post. Please try again.'];
        header('Location: ' . BASE_URL . 'content');
        exit;
    }

    $_SESSION['notification'] = ['type' => 'success', 'message' => 'Post deleted.'];
    header('Location: ' . BASE_URL . 'content?account_id=' . (int) $post['account_id']);
    exit;
}

/**
 * Toggle — POST: flips is_recyclable for a post.
 *
 * Redirects back preserving the caller's account_id and q filter params
 * so the user ends up back in the same filtered view they were in.
 */
function toggle(): void
{
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . BASE_URL . 'content');
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . BASE_URL . 'content');
        exit;
    }

    $companyId = content_companyId();
    $postId    = (int) ($_POST['id'] ?? 0);

    $stmt = $dbh->prepare(
        'SELECT p.id, p.is_recyclable, p.account_id
           FROM posts p
           JOIN accounts a ON a.id = p.account_id
          WHERE p.id = ? AND a.company_id = ? AND p.is_active = 1'
    );
    $stmt->execute([$postId, $companyId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($post['id'])) {
        error404();
    }

    authorizeAccount((int) $post['account_id']);

    $newValue = (int) $post['is_recyclable'] === 1 ? 0 : 1;
    $dbh->prepare('UPDATE posts SET is_recyclable = ? WHERE id = ?')
        ->execute([$newValue, $postId]);

    // Preserve filter params from hidden inputs in the toggle form
    $accountId = (int) ($_POST['filter_account_id'] ?? 0);
    $search    = trim((string) ($_POST['filter_search'] ?? ''));

    $qs = [];
    if ($accountId > 0) {
        $qs[] = 'account_id=' . $accountId;
    }
    if ($search !== '') {
        $qs[] = 'q=' . urlencode($search);
    }

    $redirect = BASE_URL . 'content' . (!empty($qs) ? '?' . implode('&', $qs) : '');
    header('Location: ' . $redirect);
    exit;
}
