<?php
/**
 * Activity log view — rendered by accounts/accounts_log.
 *
 * Shows the last 48 hours of activity_log rows for the account's connected
 * platform. Events older than 48 hours are purged by cron and never appear.
 *
 * Template variables:
 *   $account      array   Account row (id, name, connected_platform_id)
 *   $rows         array   activity_log rows (id, event_type, message, context, created_at)
 *   $activeFilter string  Currently active event_type filter, or '' for all
 *   $eventTypes   array   All valid event_type ENUM values
 */

/**
 * Returns a Bootstrap badge class for an event_type value.
 */
function eventBadgeClass(string $type): string
{
    return match ($type) {
        'post_success'   => 'bg-success',
        'post_failure'   => 'bg-danger',
        'token_refresh'  => 'bg-warning text-dark',
        'token_verify'   => 'bg-info text-dark',
        'queue_populate' => 'bg-primary',
        default          => 'bg-secondary',
    };
}

/**
 * Returns a human-readable label for an event_type value.
 */
function eventLabel(string $type): string
{
    return match ($type) {
        'cron_run'       => 'Cron run',
        'post_success'   => 'Post success',
        'post_failure'   => 'Post failure',
        'token_refresh'  => 'Token refresh',
        'token_verify'   => 'Token verify',
        'queue_populate' => 'Queue populate',
        'connection_test'=> 'Connection test',
        default          => ucwords(str_replace('_', ' ', $type)),
    };
}

$count = count($rows);
?>
<div class="container py-4" style="max-width:900px">

    <div class="d-flex align-items-center mb-1 gap-3">
        <a href="<?= u('accounts') ?>" class="text-muted text-decoration-none">&larr; Workspaces</a>
        <h1 class="h3 mb-0">
            Activity Log &mdash; <?= htmlspecialchars((string) $account['name'], ENT_QUOTES, 'UTF-8') ?>
        </h1>
    </div>
    <p class="text-muted small mb-4">Last 48 hours &middot; purged automatically by cron</p>

    <!-- Filter bar -->
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="<?= u('accounts', 'accounts_log', ['id' => (int) $account['id']]) ?>"
           class="btn btn-sm <?= $activeFilter === '' ? 'btn-primary' : 'btn-outline-secondary' ?>">
            All
        </a>
        <?php foreach ($eventTypes as $type): ?>
        <a href="<?= u('accounts', 'accounts_log', ['id' => (int) $account['id'], 'event_type' => $type]) ?>"
           class="btn btn-sm <?= $activeFilter === $type ? 'btn-primary' : 'btn-outline-secondary' ?>">
            <?= htmlspecialchars(eventLabel($type), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($count > 0): ?>

    <!-- Row count summary -->
    <p class="text-muted small mb-3">
        <?php if ($activeFilter !== ''): ?>
            Showing <?= $count ?> <?= htmlspecialchars(eventLabel($activeFilter), ENT_QUOTES, 'UTF-8') ?>
            <?= $count === 1 ? 'event' : 'events' ?> in the last 48 hours
        <?php else: ?>
            Showing <?= $count ?> <?= $count === 1 ? 'event' : 'events' ?> in the last 48 hours
        <?php endif; ?>
    </p>

    <!-- Log table -->
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:160px">Time</th>
                    <th style="width:160px">Event</th>
                    <th>Message</th>
                    <th style="width:80px"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <?php $hasContext = !empty($row['context']) && $row['context'] !== 'null'; ?>
                <tr>
                    <td class="text-muted small text-nowrap">
                        <?= htmlspecialchars(datify((string) $row['created_at']), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td>
                        <span class="badge <?= eventBadgeClass((string) $row['event_type']) ?>">
                            <?= htmlspecialchars(eventLabel((string) $row['event_type']), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td class="small">
                        <?= htmlspecialchars((string) $row['message'], ENT_QUOTES, 'UTF-8') ?>

                        <?php if ($hasContext): ?>
                        <div class="collapse mt-2" id="ctx-<?= (int) $row['id'] ?>">
                            <pre class="bg-light border rounded p-2 small mb-0" style="max-height:200px;overflow-y:auto;white-space:pre-wrap;word-break:break-all"><?php
                                $decoded = json_decode((string) $row['context'], true);
                                echo htmlspecialchars(
                                    json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                            ?></pre>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($hasContext): ?>
                        <a class="btn btn-sm btn-outline-secondary"
                           data-bs-toggle="collapse"
                           href="#ctx-<?= (int) $row['id'] ?>"
                           role="button"
                           aria-expanded="false">
                            Details
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php else: ?>

    <!-- Empty state -->
    <div class="card text-center py-5">
        <div class="card-body">
            <h5 class="card-title text-muted">No activity in the last 48 hours</h5>
            <p class="card-text text-muted small mb-0">
                Events appear here when the cron job runs and processes posts for this workspace.
                <?php if ($activeFilter !== ''): ?>
                <br>Try removing the filter to see all event types.
                <?php endif; ?>
            </p>
            <?php if ($activeFilter !== ''): ?>
            <a href="<?= u('accounts', 'accounts_log', ['id' => (int) $account['id']]) ?>"
               class="btn btn-sm btn-outline-secondary mt-3">Clear filter</a>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

</div>
