<?php
/**
 * CSV import form — rendered by content/importForm.
 *
 * Template variables:
 *   $accounts      array        Accessible accounts (id, name, platform)
 *   $importResult  array|null   Result of the last import attempt, or null.
 *                               Keys: imported, skipped, failed, warnings[], has_errors
 *   $csrfToken     string
 */
?>
<div class="container py-4" style="max-width:720px">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0">Import Posts</h1>
        <a href="<?= BASE_URL ?>content" class="text-muted text-decoration-none">&larr; Back to Content Library</a>
    </div>

    <!-- ============================================================
         Result summary panel — shown only after a completed import
         ============================================================ -->
    <?php if ($importResult !== null): ?>
    <?php
        $resImported  = (int) ($importResult['imported']   ?? 0);
        $resFailed    = (int) ($importResult['failed']     ?? 0);
        $resSkipped   = (int) ($importResult['skipped']    ?? 0);
        $resWarnings  = (array) ($importResult['warnings'] ?? []);
        $resHasErrors = (bool) ($importResult['has_errors'] ?? false);
        $hasWarnings  = count($resWarnings) > 0;

        if ($resFailed > 0 || $resHasErrors) {
            $panelBorder = 'border-danger';
        } elseif ($hasWarnings) {
            $panelBorder = 'border-warning';
        } else {
            $panelBorder = 'border-success';
        }
    ?>
    <div class="card mb-4 <?= $panelBorder ?>" style="border-width:2px">
        <div class="card-body">

            <h5 class="card-title mb-3">Import Complete</h5>

            <?php if ($resImported > 0): ?>
            <p class="mb-1 text-success fw-semibold">
                <?= $resImported ?> <?= $resImported === 1 ? 'post' : 'posts' ?> imported successfully.
            </p>
            <?php endif; ?>

            <?php if ($resFailed > 0): ?>
            <p class="mb-1 text-danger">
                <?= $resFailed ?> <?= $resFailed === 1 ? 'row' : 'rows' ?> failed validation and <?= $resFailed === 1 ? 'was' : 'were' ?> not imported.
            </p>
            <?php endif; ?>

            <?php if ($resSkipped > 0): ?>
            <p class="mb-1 text-secondary">
                <?= $resSkipped ?> <?= $resSkipped === 1 ? 'row' : 'rows' ?> skipped — empty body or duplicate.
            </p>
            <?php endif; ?>

            <?php if ($hasWarnings): ?>
            <p class="mb-2 text-warning-emphasis">
                <?= count($resWarnings) ?> <?= count($resWarnings) === 1 ? 'warning' : 'warnings' ?>.
            </p>
            <details class="mb-2">
                <summary class="text-muted small" style="cursor:pointer">
                    Show <?= count($resWarnings) ?> <?= count($resWarnings) === 1 ? 'warning' : 'warnings' ?>
                </summary>
                <ul class="small mt-2 mb-0 ps-3">
                    <?php foreach ($resWarnings as $w): ?>
                    <li><?= htmlspecialchars((string) $w, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <?php endif; ?>

            <?php if ($resHasErrors): ?>
            <a href="<?= BASE_URL ?>content/importErrors"
               class="btn btn-sm btn-outline-danger mt-1">Download Error Report</a>
            <?php endif; ?>

            <?php if ($resImported === 0 && $resFailed === 0 && $resSkipped === 0): ?>
            <p class="mb-0 text-muted">No data rows were found in the file.</p>
            <?php endif; ?>

        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         Instructions
         ============================================================ -->
    <div class="card mb-4">
        <div class="card-body">
            <h6 class="card-title fw-semibold mb-2">Before you import</h6>
            <ul class="small mb-2">
                <li>CSV must be <strong>UTF-8 encoded</strong>. In Excel, save as &ldquo;CSV UTF-8 (comma delimited)&rdquo;.</li>
                <li>Required column: <code>body</code>. Optional: <code>attributed_to</code>, <code>image_filename</code>, <code>is_recyclable</code>, <code>internal_note</code>.</li>
                <li>Images must already exist in your <code>images/</code> directory. The CSV cannot upload images — reference filenames only.</li>
                <li>Character limits are enforced against the most restrictive selected platform (Twitter: 280, Instagram: 2,200, Facebook: 63,206). Rows over the limit are skipped and included in the error report.</li>
                <li>Maximum 5,000 data rows per import. Split larger sets into batches.</li>
                <li>Lines beginning with <code>#</code> are treated as comments and ignored.</li>
            </ul>
            <a href="<?= BASE_URL ?>content/importSample"
               class="small text-decoration-none">Download sample CSV</a>
        </div>
    </div>

    <!-- ============================================================
         Import form
         ============================================================ -->
    <form method="POST" action="<?= BASE_URL ?>content/importProcess"
          enctype="multipart/form-data">

        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="card mb-4">
            <div class="card-body">

                <!-- Account checkboxes -->
                <div class="mb-4">
                    <label class="form-label fw-semibold">
                        Accounts <span class="text-danger">*</span>
                    </label>
                    <div class="form-text mb-2">
                        Each CSV row creates one post per selected account.
                    </div>
                    <?php foreach ($accounts as $a): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox"
                               name="account_ids[]"
                               id="acct_<?= (int) $a['id'] ?>"
                               value="<?= (int) $a['id'] ?>">
                        <label class="form-check-label" for="acct_<?= (int) $a['id'] ?>">
                            <?= htmlspecialchars((string) $a['name'], ENT_QUOTES, 'UTF-8') ?>
                            <span class="text-muted">
                                (<?= htmlspecialchars(ucfirst((string) $a['platform']), ENT_QUOTES, 'UTF-8') ?>)
                            </span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Default recycle setting -->
                <div class="mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox"
                               id="is_recyclable_default" name="is_recyclable_default"
                               value="1" checked>
                        <label class="form-check-label fw-semibold" for="is_recyclable_default">
                            Recycle imported posts by default
                        </label>
                    </div>
                    <div class="form-text ms-4">
                        The <code>is_recyclable</code> column in your CSV overrides this setting per row.
                    </div>
                </div>

                <!-- File upload -->
                <div class="mb-0">
                    <label for="csv_file" class="form-label fw-semibold">
                        CSV file <span class="text-danger">*</span>
                    </label>
                    <input type="file" id="csv_file" name="csv_file"
                           class="form-control" style="max-width:400px"
                           accept=".csv,text/csv" required>
                    <div class="form-text">UTF-8 encoded CSV &mdash; maximum 5 MB, 5,000 rows.</div>
                </div>

            </div>
        </div>

        <div class="d-flex gap-2 align-items-center">
            <button type="submit" class="btn btn-primary">Import Posts</button>
            <a href="<?= BASE_URL ?>content" class="btn btn-outline-secondary">Cancel</a>
        </div>

    </form>

</div>
