<?php
// app/Controllers/Api/NotificationApiController.php — уведомления вошедшего участника: GET /api/notifications (ГОТОВО)
// Уведомления создаёт воркер (app/Jobs/NotificationJob.php); здесь они только читаются.

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\NotificationRepository;

final class NotificationApiController
{
    // GET /api/notifications — свои уведомления, новые сверху (чужие увидеть нельзя)
    public function index(): void
    {
        $user = require_auth();
        json_response((new NotificationRepository())->forUser($user['id']));
    }
}
