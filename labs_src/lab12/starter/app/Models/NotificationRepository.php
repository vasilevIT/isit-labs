<?php
// app/Models/NotificationRepository.php — уведомления участников (ГОТОВО: менять не нужно).

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class NotificationRepository
{
    // Уведомления одного участника, новые сверху.
    public function forUser(int $userId, int $limit = 50): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, task_id, message, created_at FROM notifications WHERE user_id = :user_id ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue('user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
