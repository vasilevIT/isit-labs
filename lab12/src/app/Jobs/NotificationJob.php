<?php
// app/Jobs/NotificationJob.php — фоновая задача «уведомить руководителей о смене статуса задачи».
//
// Её ставит в очередь TaskApiController::update (TODO 2.2), а выполняет воркер (bin/worker.php).
// Данные задачи (payload): task_id, title, old_status, new_status, actor_id, actor_name.
// В настоящей системе здесь отправлялось бы письмо или push; в учебной работе уведомление записывается в таблицу notifications,
// а медленная отправка имитируется паузой (NOTIFY_SECONDS, по умолчанию 2 секунды).

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Database;
use PDO;

final class NotificationJob
{
    // $payload — данные задачи, $jobId — номер задачи в очереди, $attempt — номер попытки (1, 2, 3...).
    public function handle(array $payload, string $jobId, int $attempt): void
    {
        Jobs::pause(config('queue')['notify_seconds']);          // «отправка письма» занимает время (ГОТОВО)

        $names = ['new' => 'Новая', 'in_progress' => 'В работе', 'done' => 'Готово'];
        $message = sprintf(
            '%s изменил(а) статус задачи «%s»: %s → %s',
            $payload['actor_name'], $payload['title'],
            $names[$payload['old_status']] ?? $payload['old_status'], $names[$payload['new_status']] ?? $payload['new_status']
        );

        $pdo = Database::connection();
        $managers = $pdo->prepare("SELECT id FROM users WHERE role = 'manager' AND id <> :actor");   // уведомляем руководителей, кроме автора изменения
        $managers->execute(['actor' => $payload['actor_id']]);

        // ON CONFLICT DO NOTHING: если задачу выполнят повторно (воркер упал после записи), второго такого же уведомления не будет.
        // Подзапрос (SELECT id FROM tasks ...) подставит NULL, если задачу успели удалить, и запись не упадёт.
        $insert = $pdo->prepare(
            'INSERT INTO notifications (job_id, user_id, task_id, message)
             VALUES (:job_id, :user_id, (SELECT id FROM tasks WHERE id = :task_id), :message)
             ON CONFLICT (job_id, user_id) DO NOTHING'
        );
        foreach ($managers->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $insert->execute(['job_id' => $jobId, 'user_id' => $userId, 'task_id' => $payload['task_id'], 'message' => $message]);
        }
    }
}
