<?php
declare(strict_types=1);

use SocialTurn\Services\CsvParser;
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
 *   index()              — list posts for accessible accounts with filter/search
 *   create()             — render create form
 *   store()              — process create POST; optionally Share Now
 *   edit()               — render edit form pre-populated with existing data
 *   update()             — process edit POST; cascade pending queue; optionally Share Now
 *   delete()             — soft-delete (is_active=0); clears pending queue
 *   toggle()             — flip is_recyclable; redirect back preserving filter params
 *   importForm()         — render CSV import form; display last import result
 *   importSample()       — stream sample CSV as download
 *   importProcess()      — parse uploaded CSV and bulk-insert posts
 *   importErrors()       — stream row-level error report as download
 *   content_duplicates() — list posts with duplicate body_normalized per account
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
        $template->set('page',             1);
        $template->set('perPage',          50);
        $template->set('totalPages',       1);
        $template->set('totalItems',       0);
        $template->set('paginationParams', []);
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

    // Build WHERE clause dynamically — shared by count and data queries
    $conditions = ['p.is_active = 1', 'a.company_id = ?'];
    $params     = [$companyId];

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

    // Count query — same WHERE, no ORDER BY or LIMIT
    $countStmt = $dbh->prepare(
        "SELECT COUNT(*)
           FROM posts p
           JOIN accounts a ON a.id = p.account_id
           JOIN connected_platforms cp ON cp.id = a.connected_platform_id
          WHERE $where"
    );
    $countStmt->execute($params);
    $totalItems = (int) $countStmt->fetchColumn();

    [$page, $perPage, $offset, $totalPages] = pagination_calc($totalItems);

    $stmt = $dbh->prepare(
        "SELECT p.id, p.body, p.attributed_to, p.image_filename,
                p.is_recyclable, p.internal_note, p.created_at,
                a.id AS account_id, a.name AS account_name, cp.platform
           FROM posts p
           JOIN accounts a ON a.id = p.account_id
           JOIN connected_platforms cp ON cp.id = a.connected_platform_id
          WHERE $where
          ORDER BY p.created_at DESC
          LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $paginationParams = ['c' => 'content', 'a' => 'index'];
    if ($filterAccountId > 0) {
        $paginationParams['account_id'] = $filterAccountId;
    }
    if ($filterSearch !== '') {
        $paginationParams['q'] = $filterSearch;
    }

    $template->set('posts',            $posts);
    $template->set('accounts',         $accounts);
    $template->set('filterAccountId',  $filterAccountId);
    $template->set('filterSearch',     $filterSearch);
    $template->set('page',             $page);
    $template->set('perPage',          $perPage);
    $template->set('totalPages',       $totalPages);
    $template->set('totalItems',       $totalItems);
    $template->set('paginationParams', $paginationParams);
    $template->set('csrfToken',        csrf_token());
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
        header('Location: ' . u('content'));
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
        header('Location: ' . u('content', 'create'));
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('content', 'create'));
        exit;
    }

    $accountId  = (int) ($_POST['account_id'] ?? 0);
    $body       = trim((string) ($_POST['body'] ?? ''));
    $shareNow   = isset($_POST['share_now']);

    if ($accountId === 0 || $body === '') {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Account and post body are required.'];
        header('Location: ' . u('content', 'create'));
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
        $ext  = strtolower((string) pathinfo((string) $_FILES['image']['name'], PATHINFO_EXTENSION));
        $mime = getMimeType((string) $_FILES['image']['tmp_name']);
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true) && in_array($mime, ['image/jpeg', 'image/png'], true)) {
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
                 (account_id, body, body_normalized, attributed_to, image_filename,
                  is_recyclable, is_active, internal_note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
        )->execute([
            $accountId, $body, normalize_body($body), $attributedTo, $imageFilename,
            $isRecyclable, $internalNote, $userId,
        ]);
        $postId = (int) $dbh->lastInsertId();

        if ($shareNow) {
            $finalBody = build_final_body($body, $attributedTo, $account['default_tags'], (string) $account['platform']);
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
        header('Location: ' . u('content', 'create'));
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

    header('Location: ' . u('content', 'index', ['account_id' => $accountId]));
    exit;
}

/**
 * Edit — GET: loads a post for editing.
 *
 * Only active posts are editable. Authorizes access to the owning account.
 */
function edit(): void
{
    global $dbh, $template;

    $companyId = content_companyId();
    $postId    = (int) ($_GET['id'] ?? 0);

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
        header('Location: ' . u('content'));
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('content'));
        exit;
    }

    $companyId = content_companyId();
    $postId    = (int) ($_POST['id'] ?? 0);
    $shareNow  = isset($_POST['share_now']);

    if ($postId === 0) {
        header('Location: ' . u('content'));
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
        header('Location: ' . u('content', 'edit', ['id' => $postId]));
        exit;
    }

    // Image upload — preserve existing if no new file uploaded
    $imageFilename = (string) ($existing['image_filename'] ?? '');
    $imageFilename = $imageFilename !== '' ? $imageFilename : null;

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $ext  = strtolower((string) pathinfo((string) $_FILES['image']['name'], PATHINFO_EXTENSION));
        $mime = getMimeType((string) $_FILES['image']['tmp_name']);
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true) && in_array($mime, ['image/jpeg', 'image/png'], true)) {
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
                SET account_id = ?, body = ?, body_normalized = ?,
                    attributed_to = ?, image_filename = ?,
                    is_recyclable = ?, internal_note = ?
              WHERE id = ? AND is_active = 1'
        )->execute([
            $newAccountId, $body, normalize_body($body), $attributedTo, $imageFilename,
            $isRecyclable, $internalNote,
            $postId,
        ]);

        if ($shareNow) {
            $finalBody = build_final_body($body, $attributedTo, $account['default_tags'], (string) $account['platform']);
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
        header('Location: ' . u('content', 'edit', ['id' => $postId]));
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

    header('Location: ' . u('content', 'index', ['account_id' => $newAccountId]));
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
        header('Location: ' . u('content'));
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('content'));
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
        header('Location: ' . u('content'));
        exit;
    }

    $_SESSION['notification'] = ['type' => 'success', 'message' => 'Post deleted.'];
    header('Location: ' . u('content', 'index', ['account_id' => (int) $post['account_id']]));
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
        header('Location: ' . u('content'));
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('content'));
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

    // Preserve filter and pagination params from hidden inputs in the toggle form
    $accountId = (int) ($_POST['filter_account_id'] ?? 0);
    $search    = trim((string) ($_POST['filter_search'] ?? ''));
    $page      = max(1, (int) ($_POST['page'] ?? 1));
    $perPage   = in_array((int) ($_POST['per_page'] ?? 50), [25, 50, 100], true)
                    ? (int) $_POST['per_page'] : 50;

    $params = [];
    if ($accountId > 0) {
        $params['account_id'] = $accountId;
    }
    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }
    $params['per_page'] = $perPage;

    header('Location: ' . u('content', 'index', $params));
    exit;
}

/**
 * Import Form — GET: renders the CSV import form.
 *
 * Checks for a completed-import result in SESSION and passes it to the
 * view for display, then clears it so it only renders once.
 */
function importForm(): void
{
    global $template;

    $accounts = content_accessibleAccounts();

    if (empty($accounts)) {
        $_SESSION['notification'] = [
            'type'    => 'info',
            'message' => 'Create an account before importing content.',
        ];
        header('Location: ' . u('content'));
        exit;
    }

    $importResult = null;
    if (isset($_SESSION['import_result'])) {
        $importResult = $_SESSION['import_result'];
        unset($_SESSION['import_result']);
    }

    $template->set('accounts',     $accounts);
    $template->set('importResult', $importResult);
    $template->set('csrfToken',    csrf_token());
}

/**
 * Import Sample — GET: streams the sample CSV as a file download.
 *
 * No CSRF needed — read-only GET. Must exit before $template->render().
 */
function importSample(): void
{
    $samplePath = ROOT . DS . 'views' . DS . 'content' . DS . 'import_sample.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="socialturn_import_sample.csv"');
    header('Cache-Control: no-store');

    readfile($samplePath);
    exit;
}

/**
 * Import Process — POST: parses an uploaded CSV and bulk-inserts posts.
 *
 * Architecture note: the original plan referenced a posts_accounts junction
 * table that does not exist. The posts table carries account_id directly, so
 * multi-account import creates one post row per CSV row per selected account.
 *
 * Validation aborts (redirect to importForm) on: bad method, CSRF failure,
 * no valid accounts, upload error, wrong MIME, file too large, no body column.
 * Row-level failures (empty body, over character limit) are collected and
 * written to a downloadable error report; they never abort the whole import.
 * Missing image files produce a warning and the row is imported without image.
 *
 * The transaction is opened only after all rows are parsed so the parse phase
 * is entirely separate from the write phase.
 */
function importProcess(): void
{
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . u('content', 'importForm'));
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('content', 'importForm'));
        exit;
    }

    // -----------------------------------------------------------------------
    // Account validation
    // -----------------------------------------------------------------------

    $rawIds = $_POST['account_ids'] ?? [];
    if (!is_array($rawIds) || count($rawIds) === 0) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Select at least one account.'];
        header('Location: ' . u('content', 'importForm'));
        exit;
    }

    $accounts      = content_accessibleAccounts();
    $accessibleIds = array_column($accounts, 'id');
    $accountsById  = array_column($accounts, null, 'id');

    $selectedIds = [];
    foreach ($rawIds as $raw) {
        $id = (int) $raw;
        if ($id === 0 || !in_array($id, $accessibleIds, true)) {
            continue;
        }
        authorizeAccount($id); // redirects automatically on access failure
        $selectedIds[] = $id;
    }

    if (empty($selectedIds)) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'No valid accounts selected.'];
        header('Location: ' . u('content', 'importForm'));
        exit;
    }

    // -----------------------------------------------------------------------
    // File validation
    // -----------------------------------------------------------------------

    if (!isset($_FILES['csv_file']) || (int) $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'No CSV file uploaded or the upload failed.'];
        header('Location: ' . u('content', 'importForm'));
        exit;
    }

    $mime = getMimeType((string) $_FILES['csv_file']['tmp_name']);
    if (!in_array($mime, ['text/csv', 'text/plain'], true)) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Uploaded file must be a CSV. Expected text/csv or text/plain.',
        ];
        header('Location: ' . u('content', 'importForm'));
        exit;
    }

    if ((int) $_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'File is too large. Maximum upload size is 5 MB.'];
        header('Location: ' . u('content', 'importForm'));
        exit;
    }

    // -----------------------------------------------------------------------
    // Character limit — most restrictive platform across selected accounts
    // -----------------------------------------------------------------------

    $platformLimits = ['twitter' => 280, 'instagram' => 2200, 'facebook' => 63206];
    $charLimit      = PHP_INT_MAX;
    $limitPlatform  = '';

    foreach ($selectedIds as $id) {
        $platform = strtolower((string) ($accountsById[$id]['platform'] ?? ''));
        $limit    = $platformLimits[$platform] ?? PHP_INT_MAX;
        if ($limit < $charLimit) {
            $charLimit     = $limit;
            $limitPlatform = $platform;
        }
    }

    $isRecyclableDefault = isset($_POST['is_recyclable_default']) ? 1 : 0;
    $userId              = content_userId();

    // -----------------------------------------------------------------------
    // Parse CSV via CsvParser
    // -----------------------------------------------------------------------

    $tmpPath = (string) $_FILES['csv_file']['tmp_name'];
    $parsed  = (new CsvParser(new StorageService()))->parse(
        $tmpPath,
        $charLimit,
        $limitPlatform,
        $isRecyclableDefault
    );

    if ($parsed['parse_error'] !== null) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => $parsed['parse_error']];
        header('Location: ' . u('content', 'importForm'));
        exit;
    }

    if ($parsed['cap_exceeded']) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'CSV exceeds the 5,000-row import limit. Split the file and import in batches.',
        ];
        header('Location: ' . u('content', 'importForm'));
        exit;
    }

    $rowsToInsert = $parsed['rows'];
    $errors       = $parsed['errors'];
    $warnings     = $parsed['warnings'];
    $skipped      = $parsed['skipped'];
    $failed       = $parsed['failed'];

    // -----------------------------------------------------------------------
    // Duplicate detection — load existing body_normalized per selected account
    // -----------------------------------------------------------------------

    $existingNormalized = []; // $existingNormalized[$accountId][$normalized] = true
    foreach ($selectedIds as $accountId) {
        $stmt = $dbh->prepare(
            "SELECT body_normalized FROM posts
              WHERE account_id = ? AND is_active = 1 AND body_normalized != ''"
        );
        $stmt->execute([$accountId]);
        $existingNormalized[$accountId] = array_flip(
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    // -----------------------------------------------------------------------
    // Transaction — one post per CSV row per selected account
    // -----------------------------------------------------------------------

    $inserted        = 0;
    $seenThisImport  = []; // $seenThisImport[$accountId][$normalized] = true

    $dbh->beginTransaction();
    try {
        $stmt = $dbh->prepare(
            'INSERT INTO posts
                 (account_id, body, body_normalized, attributed_to, image_filename,
                  is_recyclable, is_active, internal_note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
        );

        foreach ($rowsToInsert as $row) {
            foreach ($selectedIds as $accountId) {
                $normalized  = $row['body_normalized'];
                $accountName = (string) ($accountsById[$accountId]['name'] ?? '');

                // Duplicate against existing DB posts for this account
                if (isset($existingNormalized[$accountId][$normalized])) {
                    $skipped++;
                    $warnings[] = "Row {$row['row_num']}: duplicate of existing post in"
                        . " account \"{$accountName}\" — skipped.";
                    continue;
                }

                // Duplicate within this import for this account
                if (isset($seenThisImport[$accountId][$normalized])) {
                    $skipped++;
                    $warnings[] = "Row {$row['row_num']}: duplicate of another row in this"
                        . " import for account \"{$accountName}\" — skipped.";
                    continue;
                }

                $seenThisImport[$accountId][$normalized] = true;

                $stmt->execute([
                    $accountId,
                    $row['body'],
                    $normalized,
                    $row['attributed_to'],
                    $row['image_filename'],
                    $row['is_recyclable'],
                    $row['internal_note'],
                    $userId,
                ]);
                $inserted++;
            }
        }

        $dbh->commit();
    } catch (Throwable) {
        $dbh->rollBack();
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'A database error occurred during import. No posts were saved. Please try again.',
        ];
        header('Location: ' . u('content', 'importForm'));
        exit;
    }

    // -----------------------------------------------------------------------
    // Error report file — written to system temp dir if any row-level errors
    // -----------------------------------------------------------------------

    $hasErrors = !empty($errors);
    if ($hasErrors) {
        $errorFile = tempnam(sys_get_temp_dir(), 'st_import_');
        file_put_contents($errorFile, implode("\n", $errors));
        $_SESSION['import_error_file'] = $errorFile;
    }

    $_SESSION['import_result'] = [
        'imported'   => $inserted,
        'skipped'    => $skipped,
        'failed'     => $failed,
        'warnings'   => $warnings,
        'has_errors' => $hasErrors,
    ];

    header('Location: ' . u('content', 'importForm'));
    exit;
}

/**
 * Import Errors — GET: streams the row-level error report as a text download.
 *
 * The report file path is stored in SESSION by importProcess(). The file is
 * deleted after streaming so it does not persist on the server.
 */
function importErrors(): void
{
    $errorFile = $_SESSION['import_error_file'] ?? '';

    if ($errorFile === '' || !is_file($errorFile)) {
        header('Location: ' . u('content', 'importForm'));
        exit;
    }

    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="import_errors.txt"');
    header('Cache-Control: no-store');

    readfile($errorFile);

    @unlink($errorFile);
    unset($_SESSION['import_error_file']);
    exit;
}

/**
 * Duplicates — GET: lists posts where body_normalized appears more than once
 * within the same account.
 *
 * Scoped to accessible accounts. Rows where body_normalized = '' (pre-existing
 * posts that have not yet passed through a write path) are excluded.
 *
 * Results are grouped in PHP: $groups[$accountId]['account_name'] and
 * $groups[$accountId]['posts'][$normalized][] = post row.
 */
function content_duplicates(): void
{
    global $dbh, $template;

    $accounts = content_accessibleAccounts();

    if (empty($accounts)) {
        $template->set('groups',    []);
        $template->set('csrfToken', csrf_token());
        return;
    }

    $accessibleIds = array_column($accounts, 'id');
    $inList        = implode(',', array_fill(0, count($accessibleIds), '?'));

    $stmt = $dbh->prepare(
        "SELECT p.id, p.body, p.attributed_to, p.is_recyclable, p.created_at,
                p.body_normalized, p.account_id, a.name AS account_name
           FROM posts p
           JOIN accounts a ON a.id = p.account_id
          WHERE p.account_id IN ($inList)
            AND p.is_active = 1
            AND p.body_normalized != ''
            AND p.body_normalized IN (
                SELECT body_normalized
                  FROM posts
                 WHERE account_id = p.account_id
                   AND is_active = 1
                   AND body_normalized != ''
                 GROUP BY body_normalized
                HAVING COUNT(*) > 1
            )
          ORDER BY p.account_id ASC, p.body_normalized ASC, p.created_at ASC"
    );
    $stmt->execute($accessibleIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by account_id → body_normalized → post rows
    $groups = [];
    foreach ($rows as $row) {
        $accountId  = (int) $row['account_id'];
        $normalized = (string) $row['body_normalized'];
        if (!isset($groups[$accountId])) {
            $groups[$accountId] = [
                'account_name' => (string) $row['account_name'],
                'posts'        => [],
            ];
        }
        $groups[$accountId]['posts'][$normalized][] = $row;
    }

    $template->set('groups',    $groups);
    $template->set('csrfToken', csrf_token());
}
