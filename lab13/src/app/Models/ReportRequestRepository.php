<?php
// app/Models/ReportRequestRepository.php — заявки на отчёт (таблица report_requests) (ГОТОВО: менять не нужно).
// Жизнь заявки: queued (создана, ждёт воркера) → processing (воркер взял) → done (файл готов) или failed (ошибка).

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class ReportRequestRepository
{
    // Создаёт заявку со статусом queued и возвращает её.
    public function create(int $userId): array
    {
        $stmt = Database::connection()->prepare('INSERT INTO report_requests (user_id) VALUES (:user_id) RETURNING *');
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetch();
    }

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM report_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    // Воркер начал работу над заявкой.
    public function markProcessing(int $id): void
    {
        $stmt = Database::connection()->prepare("UPDATE report_requests SET status = 'processing', error = NULL WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    // Отчёт построен: имя файла и число строк.
    public function markDone(int $id, string $fileName, int $rows): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE report_requests SET status = 'done', file_name = :file, rows_count = :rows, finished_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $id, 'file' => $fileName, 'rows' => $rows]);
    }

    // Отчёт построить не удалось.
    public function markFailed(int $id, string $error): void
    {
        $stmt = Database::connection()->prepare("UPDATE report_requests SET status = 'failed', error = :error, finished_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $id, 'error' => $error]);
    }
}
