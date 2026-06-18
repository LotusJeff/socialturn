<?php
declare(strict_types=1);

/**
 * Queue management controller — Admins and authorized team members.
 *
 * Provides visibility and control over the scheduled_posts queue and the
 * post_history audit log. Does NOT trigger queue population — that is
 * cron-only per CLAUDE.md. Share Now reschedules an existing pending row
 * to NOW(); the next cron run (within 5 minutes) picks it up.
 *
 * Functions:
 *   index()    — status overview: pending/posted/failed counts per account
 *   view()     — pending queue for one account with remove and share-now
 *   history()  — post_history log for one account, newest first
 *   errors()   — failed post_history rows for one account with error messages
 *   remove()   — POST: delete a single pending scheduled_posts row
 *   flush()    — POST: delete all pending rows for an account
 *   sharenow() — POST: reschedule a pending row to NOW()
 */

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------

function queue_companyId(): int
{
    return (int) ($_SESSION['user']['company_id'] ?? $_SESSION['user']['companyid'] ?? 0);
}

function queue_isAdmin(): bool
{
    return (int) ($_SESSION['user']['type'] ?? 999) === 1;
}

/**
 * Returns all active accounts accessible to the current user, joined to
 * their connected platform. Admins get every account; team members only
 * get accounts listed in their users_accounts rows.
 */
function queue_accessibleAccounts(): array
{
    global $dbh;

    $companyId = queue_companyId();

    if (queue_isAdmin()) {
        $stmt = $dbh->prepare(
            'SELECT a.id, a.name, a.is_posting, cp.platform, cp.platform_name
               FROM accounts a
               JOIN connected_platforms cp ON cp.id = a.connected_platform_id
              WHERE a.company_id = ? AND a.is_active = 1
              ORDER BY a.name ASC'
        );
        $stmt->execute([$companyId]);
    } else {
        $userId = (int) ($_SESSION['user']['loggedin'] ?? 0);
        $stmt = $dbh->prepare(
            'SELECT a.id, a.name, a.is_posting, cp.platform, cp.platform_name
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
 * Loads a single accessible account by ID. Verifies company ownership and
 * calls authorizeAccount(). Returns the account row or calls error404().
 */
function queue_loadAccount(int $accountId): array
{
    global $dbh;

    $companyId = queue_companyId();

    $stmt = $dbh->prepare(
        'SELECT a.id, a.name, a.is_posting, a.connected_platform_id,
                cp.platform, cp.platform_name, cp.platform_username,
                COALESCE(s.timezone, \'UTC\') AS timezone
           FROM accounts a
           JOIN connected_platforms cp ON cp.id = a.connected_platform_id
           LEFT JOIN account_schedules s ON s.account_id = a.id
          WHERE a.id = ? AND a.company_id = ? AND a.is_active = 1'
    );
    $stmt->execute([$accountId, $companyId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($account['id'])) {
        error404();
    }

    authorizeAccount($accountId);

    return $account;
}

// -----------------------------------------------------------------------
// Actions
// -----------------------------------------------------------------------

/**
 * Index — status overview showing pending/posted/failed counts for every
 * accessible account. Pending count links to view(); failed count links
 * to errors(); posted count links to history().
 */
function index(): void
{
    global $dbh, $template;

    $companyId = queue_companyId();
    $accounts  = queue_accessibleAccounts();

    if (empty($accounts)) {
        $template->set('rows',             []);
        $template->set('page',             1);
        $template->set('perPage',          50);
        $template->set('totalPages',       1);
        $template->set('totalItems',       0);
        $template->set('paginationParams', ['c' => 'queue', 'a' => 'index']);
        $template->set('csrfToken',        csrf_token());
        return;
    }

    $accessibleIds = array_column($accounts, 'id');
    $inList        = implode(',', array_fill(0, count($accessibleIds), '?'));
    $totalAccounts = count($accessibleIds);

    [$page, $perPage, $offset, $totalPages] = pagination_calc($totalAccounts);

    // Correlated subqueries keep this as a single round-trip.
    $stmt = $dbh->prepare(
        "SELECT a.id, a.name, a.is_posting, cp.platform, cp.platform_name,
                cp.is_active AS is_active, cp.token_expires_at,
                (SELECT COUNT(*)
                   FROM posts p
                  WHERE p.account_id = a.id
                    AND p.is_active = 1
                    AND p.is_recyclable = 1)    AS recycled_count,
                (SELECT COUNT(*)
                   FROM scheduled_posts sp
                   JOIN posts p ON p.id = sp.post_id
                  WHERE p.account_id = a.id
                    AND sp.status = 'pending')  AS pending_count,
                (SELECT COUNT(*)
                   FROM post_history ph
                   JOIN posts p ON p.id = ph.post_id
                  WHERE p.account_id = a.id
                    AND ph.status = 'posted'
                    AND ph.posted_at >= NOW() - INTERVAL 30 DAY) AS posted_count,
                (SELECT COUNT(*)
                   FROM post_history ph
                   JOIN posts p ON p.id = ph.post_id
                  WHERE p.account_id = a.id
                    AND ph.status = 'failed'
                    AND ph.posted_at >= NOW() - INTERVAL 30 DAY) AS failed_count
           FROM accounts a
           JOIN connected_platforms cp ON cp.id = a.connected_platform_id
          WHERE a.company_id = ? AND a.is_active = 1
            AND a.id IN ($inList)
          ORDER BY a.name ASC
          LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute(array_merge([$companyId], $accessibleIds));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $template->set('rows',             $rows);
    $template->set('page',             $page);
    $template->set('perPage',          $perPage);
    $template->set('totalPages',       $totalPages);
    $template->set('totalItems',       $totalAccounts);
    $template->set('paginationParams', ['c' => 'queue', 'a' => 'index']);
    $template->set('csrfToken',        csrf_token());
}

/**
 * View — pending queue for one account.
 *
 * Ordered by scheduled_time ASC so the next-due post is always at the top.
 * Accepts optional ?q= search against final_body (LIKE).
 * The count query is filter-aware so pagination math reflects the current
 * search; $pendingTotal drives both the subtitle and page calculation.
 */
function view(): void
{
    global $dbh, $template;

    $accountId = (int) ($_GET['id'] ?? 0);
    if ($accountId === 0) {
        error404();
    }

    $account   = queue_loadAccount($accountId);
    $companyId = queue_companyId();
    $search    = trim((string) ($_GET['q'] ?? ''));

    // Count — filter-aware so pagination math reflects current search
    $countConditions = ["a.id = ?", "a.company_id = ?", "sp.status = 'pending'"];
    $countParams     = [$accountId, $companyId];

    if ($search !== '') {
        $countConditions[] = 'sp.final_body LIKE ?';
        $countParams[]     = '%' . $search . '%';
    }

    $countWhere = implode(' AND ', $countConditions);
    $stmt = $dbh->prepare(
        "SELECT COUNT(*)
           FROM scheduled_posts sp
           JOIN posts p ON p.id = sp.post_id
           JOIN accounts a ON a.id = p.account_id
          WHERE $countWhere"
    );
    $stmt->execute($countParams);
    $pendingTotal = (int) $stmt->fetchColumn();

    [$page, $perPage, $offset, $totalPages] = pagination_calc($pendingTotal);

    // Data query — same conditions, paginated
    $stmt = $dbh->prepare(
        "SELECT sp.id, sp.scheduled_time, sp.final_body, sp.final_image_filenames,
                p.id AS post_id, p.attributed_to
           FROM scheduled_posts sp
           JOIN posts p ON p.id = sp.post_id
           JOIN accounts a ON a.id = p.account_id
          WHERE $countWhere
          ORDER BY sp.scheduled_time ASC
          LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($countParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $paginationParams = ['c' => 'queue', 'a' => 'view', 'id' => $accountId];
    if ($search !== '') {
        $paginationParams['q'] = $search;
    }

    $template->set('account',          $account);
    $template->set('rows',             $rows);
    $template->set('pendingTotal',     $pendingTotal);
    $template->set('search',           $search);
    $template->set('page',             $page);
    $template->set('perPage',          $perPage);
    $template->set('totalPages',       $totalPages);
    $template->set('totalItems',       $pendingTotal);
    $template->set('paginationParams', $paginationParams);
    $template->set('csrfToken',        csrf_token());
}

/**
 * History — post_history log for one account, newest first.
 *
 * Shows all statuses. Accepts ?q= search against body_snapshot.
 * $totalCount is filter-aware and drives both the tab badge and pagination.
 * $failedCount is always unfiltered (used only for the Errors tab badge).
 */
function history(): void
{
    global $dbh, $template;

    $accountId = (int) ($_GET['id'] ?? 0);
    if ($accountId === 0) {
        error404();
    }

    $account   = queue_loadAccount($accountId);
    $companyId = queue_companyId();
    $search    = trim((string) ($_GET['q'] ?? ''));

    // Total count — filter-aware for pagination and tab badge
    $countConditions = ['a.id = ?', 'a.company_id = ?'];
    $countParams     = [$accountId, $companyId];

    if ($search !== '') {
        $countConditions[] = 'ph.body_snapshot LIKE ?';
        $countParams[]     = '%' . $search . '%';
    }

    $countWhere = implode(' AND ', $countConditions);
    $stmt = $dbh->prepare(
        "SELECT COUNT(*)
           FROM post_history ph
           JOIN posts p ON p.id = ph.post_id
           JOIN accounts a ON a.id = p.account_id
          WHERE $countWhere"
    );
    $stmt->execute($countParams);
    $totalCount = (int) $stmt->fetchColumn();

    // Failed count for Errors tab badge — always unfiltered
    $stmt = $dbh->prepare(
        "SELECT COUNT(*)
           FROM post_history ph
           JOIN posts p ON p.id = ph.post_id
           JOIN accounts a ON a.id = p.account_id
          WHERE a.id = ? AND a.company_id = ? AND ph.status = 'failed'"
    );
    $stmt->execute([$accountId, $companyId]);
    $failedCount = (int) $stmt->fetchColumn();

    [$page, $perPage, $offset, $totalPages] = pagination_calc($totalCount);

    // Data query — same conditions, paginated
    $stmt = $dbh->prepare(
        "SELECT ph.id, ph.body_snapshot, ph.image_filenames, ph.platform_post_id,
                ph.status, ph.posted_at, ph.post_id
           FROM post_history ph
           JOIN posts p ON p.id = ph.post_id
           JOIN accounts a ON a.id = p.account_id
          WHERE $countWhere
          ORDER BY ph.posted_at DESC
          LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($countParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $paginationParams = ['c' => 'queue', 'a' => 'history', 'id' => $accountId];
    if ($search !== '') {
        $paginationParams['q'] = $search;
    }

    $template->set('account',          $account);
    $template->set('rows',             $rows);
    $template->set('totalCount',       $totalCount);
    $template->set('failedCount',      $failedCount);
    $template->set('search',           $search);
    $template->set('page',             $page);
    $template->set('perPage',          $perPage);
    $template->set('totalPages',       $totalPages);
    $template->set('totalItems',       $totalCount);
    $template->set('paginationParams', $paginationParams);
    $template->set('csrfToken',        csrf_token());
}

/**
 * Errors — failed post_history rows for one account.
 *
 * Search runs against both body_snapshot and error_message so operators
 * can find failures by platform error text as well as post content.
 * $totalCount is filter-aware and drives both the tab badge and pagination.
 */
function errors(): void
{
    global $dbh, $template;

    $accountId = (int) ($_GET['id'] ?? 0);
    if ($accountId === 0) {
        error404();
    }

    $account   = queue_loadAccount($accountId);
    $companyId = queue_companyId();
    $search    = trim((string) ($_GET['q'] ?? ''));

    // Total count — filter-aware for pagination and tab badge
    $countConditions = ["a.id = ?", "a.company_id = ?", "ph.status = 'failed'"];
    $countParams     = [$accountId, $companyId];

    if ($search !== '') {
        $countConditions[] = '(ph.body_snapshot LIKE ? OR ph.error_message LIKE ?)';
        $countParams[]     = '%' . $search . '%';
        $countParams[]     = '%' . $search . '%';
    }

    $countWhere = implode(' AND ', $countConditions);
    $stmt = $dbh->prepare(
        "SELECT COUNT(*)
           FROM post_history ph
           JOIN posts p ON p.id = ph.post_id
           JOIN accounts a ON a.id = p.account_id
          WHERE $countWhere"
    );
    $stmt->execute($countParams);
    $totalCount = (int) $stmt->fetchColumn();

    [$page, $perPage, $offset, $totalPages] = pagination_calc($totalCount);

    // Data query — same conditions, paginated
    $stmt = $dbh->prepare(
        "SELECT ph.id, ph.body_snapshot, ph.image_filenames,
                ph.error_message, ph.posted_at, ph.post_id
           FROM post_history ph
           JOIN posts p ON p.id = ph.post_id
           JOIN accounts a ON a.id = p.account_id
          WHERE $countWhere
          ORDER BY ph.posted_at DESC
          LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($countParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $paginationParams = ['c' => 'queue', 'a' => 'errors', 'id' => $accountId];
    if ($search !== '') {
        $paginationParams['q'] = $search;
    }

    $template->set('account',          $account);
    $template->set('rows',             $rows);
    $template->set('totalCount',       $totalCount);
    $template->set('search',           $search);
    $template->set('page',             $page);
    $template->set('perPage',          $perPage);
    $template->set('totalPages',       $totalPages);
    $template->set('totalItems',       $totalCount);
    $template->set('paginationParams', $paginationParams);
    $template->set('csrfToken',        csrf_token());
}

/**
 * Delete Error — POST: permanently deletes a single post_history row.
 *
 * The DELETE JOIN ensures the row belongs to an account this user controls.
 * Redirects back to queue/errors for the same account.
 */
function deleteError(): void
{
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . u('queue'));
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('queue'));
        exit;
    }

    $companyId = queue_companyId();
    $historyId = (int) ($_POST['id']         ?? 0);
    $accountId = (int) ($_POST['account_id'] ?? 0);

    if ($historyId === 0 || $accountId === 0) {
        header('Location: ' . u('queue'));
        exit;
    }

    authorizeAccount($accountId);

    $stmt = $dbh->prepare(
        "DELETE ph FROM post_history ph
           JOIN posts p ON p.id = ph.post_id
           JOIN accounts a ON a.id = p.account_id
          WHERE ph.id = ? AND a.id = ? AND a.company_id = ?"
    );
    $stmt->execute([$historyId, $accountId, $companyId]);

    header('Location: ' . u('queue', 'errors', ['id' => $accountId]));
    exit;
}

/**
 * Remove — POST: deletes a single pending scheduled_posts row.
 *
 * The DELETE JOIN ensures the row belongs to an account this user controls.
 * status = 'pending' in the WHERE prevents removing a row cron has already
 * claimed or posted. Redirects back preserving search, page, and per_page.
 */
function remove(): void
{
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . u('queue'));
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('queue'));
        exit;
    }

    $companyId       = queue_companyId();
    $scheduledPostId = (int) ($_POST['id']         ?? 0);
    $accountId       = (int) ($_POST['account_id'] ?? 0);
    $search          = trim((string) ($_POST['search']   ?? ''));
    $page            = max(1, (int) ($_POST['page']    ?? 1));
    $perPage         = in_array((int) ($_POST['per_page'] ?? 50), [25, 50, 100], true)
                        ? (int) $_POST['per_page'] : 50;

    if ($scheduledPostId === 0 || $accountId === 0) {
        header('Location: ' . u('queue'));
        exit;
    }

    authorizeAccount($accountId);

    $stmt = $dbh->prepare(
        "DELETE sp FROM scheduled_posts sp
           JOIN posts p ON p.id = sp.post_id
           JOIN accounts a ON a.id = p.account_id
          WHERE sp.id = ? AND a.id = ? AND a.company_id = ? AND sp.status = 'pending'"
    );
    $stmt->execute([$scheduledPostId, $accountId, $companyId]);

    if ($stmt->rowCount() === 0) {
        $_SESSION['notification'] = [
            'type'    => 'warning',
            'message' => 'Post could not be removed — it may have already been sent or claimed by cron.',
        ];
    }

    $params = ['id' => $accountId, 'per_page' => $perPage];
    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }
    header('Location: ' . u('queue', 'view', $params));
    exit;
}

/**
 * Flush — POST: deletes all pending scheduled_posts rows for one account.
 *
 * Returns the deleted count in the session notification so the operator
 * can confirm the flush worked as expected. The queue refills automatically
 * on the next cron run when pending depth falls below recycle_threshold.
 */
function queue_flush(): void
{
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . u('queue'));
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('queue'));
        exit;
    }

    $companyId = queue_companyId();
    $accountId = (int) ($_POST['account_id'] ?? 0);

    if ($accountId === 0) {
        header('Location: ' . u('queue'));
        exit;
    }

    authorizeAccount($accountId);

    $stmt = $dbh->prepare(
        "DELETE sp FROM scheduled_posts sp
           JOIN posts p ON p.id = sp.post_id
           JOIN accounts a ON a.id = p.account_id
          WHERE a.id = ? AND a.company_id = ? AND sp.status = 'pending' AND sp.source = 'queue'"
    );
    $stmt->execute([$accountId, $companyId]);
    $deleted = $stmt->rowCount();

    $_SESSION['notification'] = [
        'type'    => 'success',
        'message' => $deleted === 0
            ? 'No pending posts to flush.'
            : $deleted . ' pending ' . ($deleted === 1 ? 'post' : 'posts') . ' removed from the queue.',
    ];

    header('Location: ' . u('queue', 'view', ['id' => $accountId]));
    exit;
}

/**
 * Share Now — POST: reschedules a pending scheduled_posts row to NOW().
 *
 * Only acts on rows that are pending and not currently locked by a cron run.
 * The locked_at IS NULL guard prevents rescheduling a row cron is mid-processing.
 * The next cron cycle (within 5 minutes) will pick it up and post it.
 *
 * Does NOT create a new row. Does NOT touch the posts library or cascade the
 * queue. The existing final_body and final_image_filenames are sent as-is.
 * Redirects back preserving search, page, and per_page.
 */
function sharenow(): void
{
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . u('queue'));
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('queue'));
        exit;
    }

    $companyId       = queue_companyId();
    $scheduledPostId = (int) ($_POST['id']         ?? 0);
    $accountId       = (int) ($_POST['account_id'] ?? 0);
    $search          = trim((string) ($_POST['search']   ?? ''));
    $page            = max(1, (int) ($_POST['page']    ?? 1));
    $perPage         = in_array((int) ($_POST['per_page'] ?? 50), [25, 50, 100], true)
                        ? (int) $_POST['per_page'] : 50;

    if ($scheduledPostId === 0 || $accountId === 0) {
        header('Location: ' . u('queue'));
        exit;
    }

    authorizeAccount($accountId);

    $stmt = $dbh->prepare(
        "UPDATE scheduled_posts sp
           JOIN posts p ON p.id = sp.post_id
           JOIN accounts a ON a.id = p.account_id
            SET sp.scheduled_time = NOW()
          WHERE sp.id = ? AND a.id = ? AND a.company_id = ?
            AND sp.status = 'pending' AND sp.locked_at IS NULL"
    );
    $stmt->execute([$scheduledPostId, $accountId, $companyId]);

    if ($stmt->rowCount() === 1) {
        $_SESSION['notification'] = [
            'type'    => 'success',
            'message' => 'Post rescheduled — will publish within 5 minutes.',
        ];
    } else {
        $_SESSION['notification'] = [
            'type'    => 'warning',
            'message' => 'Could not reschedule — the post may have already been sent or is currently being processed.',
        ];
    }

    $params = ['id' => $accountId, 'per_page' => $perPage];
    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }
    header('Location: ' . u('queue', 'view', $params));
    exit;
}
