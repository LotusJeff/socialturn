<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Generated Images</h2>
    </div>

    <?php if (empty($accounts)): ?>
        <p class="text-muted">No generated images on file.</p>
    <?php else: ?>
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Account</th>
                    <th class="text-end">Generated Images</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $a): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $a['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-end"><?= (int) $a['generated_count'] ?></td>
                        <td class="text-end">
                            <form method="post" action="<?= u('images', 'flush') ?>"
                                  onsubmit="return confirm('Delete all <?= (int) $a['generated_count'] ?> generated image<?= (int) $a['generated_count'] === 1 ? '' : 's' ?> for <?= htmlspecialchars(addslashes((string) $a['name']), ENT_QUOTES, 'UTF-8') ?>? They will regenerate on the next cron run.')">
                                <input type="hidden" name="account_id" value="<?= (int) $a['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Flush</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
