<?php
declare(strict_types=1);

use SocialTurn\Services\GeneratedImageService;
use SocialTurn\Services\StorageService;

function images_companyId(): int
{
    return (int) ($_SESSION['user']['company_id'] ?? $_SESSION['user']['companyid'] ?? 0);
}

function index(): void
{
    global $dbh, $template;

    $companyId = images_companyId();

    $stmt = $dbh->prepare(
        "SELECT a.id, a.name, COUNT(pi.id) AS generated_count
           FROM accounts a
           JOIN posts p ON p.account_id = a.id
           JOIN post_images pi ON pi.post_id = p.id
          WHERE pi.image_source = 'generated'
            AND a.company_id = ?
            AND a.is_active = 1
          GROUP BY a.id, a.name
          ORDER BY a.name ASC"
    );
    $stmt->execute([$companyId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $template->set('accounts', $accounts);
}

function flush(): void
{
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . u('images'));
        exit;
    }

    $companyId = images_companyId();
    $accountId = (int) ($_POST['account_id'] ?? 0);

    // Verify account belongs to this company before deleting
    $stmt = $dbh->prepare(
        'SELECT id, name FROM accounts WHERE id = ? AND company_id = ? AND is_active = 1'
    );
    $stmt->execute([$accountId, $companyId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        header('Location: ' . u('images'));
        exit;
    }

    $service = new GeneratedImageService($dbh, new StorageService());
    $deleted  = $service->deleteForAccount($accountId);

    $noun = $deleted === 1 ? 'image' : 'images';
    $_SESSION['notification'] = [
        'type'    => 'success',
        'message' => "Flushed {$deleted} generated {$noun} for " . htmlspecialchars((string) $account['name'], ENT_QUOTES, 'UTF-8') . '.',
    ];
    header('Location: ' . u('images'));
    exit;
}
