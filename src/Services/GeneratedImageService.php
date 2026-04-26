<?php
declare(strict_types=1);

namespace SocialTurn\Services;

use PDO;

class GeneratedImageService
{
    public function __construct(
        private readonly PDO $dbh,
        private readonly StorageService $storage
    ) {}

    /**
     * Delete all generated images for an account.
     *
     * Fetches every post_images row where image_source='generated' for posts
     * belonging to $accountId, calls StorageService::delete() on each file,
     * then removes all matching rows in a single DELETE JOIN.
     *
     * Returns the number of post_images rows deleted.
     */
    public function deleteForAccount(int $accountId): int
    {
        $stmt = $this->dbh->prepare(
            "SELECT pi.image_filename
               FROM post_images pi
               JOIN posts p ON pi.post_id = p.id
              WHERE p.account_id = ? AND pi.image_source = 'generated'"
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if (!empty($row['image_filename'])) {
                $this->storage->delete((string) $row['image_filename']);
            }
        }

        $deleteStmt = $this->dbh->prepare(
            "DELETE pi FROM post_images pi
               JOIN posts p ON pi.post_id = p.id
              WHERE p.account_id = ? AND pi.image_source = 'generated'"
        );
        $deleteStmt->execute([$accountId]);

        return $deleteStmt->rowCount();
    }
}
