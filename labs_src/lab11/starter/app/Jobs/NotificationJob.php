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

        // TODO 2.1  Обработка задачи. Метод выполняет воркер, а не обработчик запроса. Он должен записать уведомления в таблицу notifications
        //           для каждого руководителя (role = 'manager'), кроме того, кто изменил статус ($payload['actor_id']):
        //             1) соберите текст: $message = $payload['actor_name'] . ' изменил(а) статус задачи «' . $payload['title'] . '»: '
        //                . $payload['old_status'] . ' → ' . $payload['new_status'];  (можно красивее: заменить new/in_progress/done на русские названия);
        //             2) выберите руководителей: SELECT id FROM users WHERE role = 'manager' AND id <> :actor;
        //             3) для каждого выполните INSERT INTO notifications (job_id, user_id, task_id, message) VALUES (:job_id, :user_id, :task_id, :message)
        //                с ON CONFLICT (job_id, user_id) DO NOTHING в конце: пара (job_id, user_id) уникальна, поэтому при ПОВТОРНОМ выполнении
        //                одной и той же задачи (воркер упал после записи) второго такого же уведомления не появится. Это называется идемпотентность.
        //           Подсказка:
        //              $pdo = Database::connection();                                  // подключение к PostgreSQL (как в репозиториях)
        //              $managers = $pdo->prepare("SELECT id FROM users WHERE role = 'manager' AND id <> :actor");
        //              $managers->execute(['actor' => $payload['actor_id']]);
        //              $insert = $pdo->prepare(
        //                  'INSERT INTO notifications (job_id, user_id, task_id, message)
        //                   VALUES (:job_id, :user_id, :task_id, :message)
        //                   ON CONFLICT (job_id, user_id) DO NOTHING'
        //              );
        //              foreach ($managers->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        //                  $insert->execute(['job_id' => $jobId, 'user_id' => $userId, 'task_id' => $payload['task_id'], 'message' => $message]);
        //              }
        //           ✔ Проверка: измените статус задачи под Игорем (PATCH /api/tasks/2), запустите php bin/worker.php --until-empty,
        //             затем GET /api/notifications под Анной: в списке появилось уведомление.
    }
}
