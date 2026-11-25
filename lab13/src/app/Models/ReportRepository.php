<?php
// app/Models/ReportRepository.php — отчёты для руководителя.

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class ReportRepository
{
    // Сводка по проектам: сколько задач в каждом статусе и сколько просрочено.
    // Запрос читает ВСЕ задачи, поэтому на большой таблице он выполняется заметное время: хороший кандидат для кеша.
    public function summary(): array
    {
        return Database::connection()->query("
            SELECT p.id AS project_id,
                   p.title,
                   COUNT(t.id)                                                          AS total,
                   COUNT(t.id) FILTER (WHERE t.status = 'new')                          AS new,
                   COUNT(t.id) FILTER (WHERE t.status = 'in_progress')                  AS in_progress,
                   COUNT(t.id) FILTER (WHERE t.status = 'done')                         AS done,
                   COUNT(t.id) FILTER (WHERE t.status <> 'done' AND t.due_date < CURRENT_DATE) AS overdue
            FROM projects p
            LEFT JOIN tasks t ON t.project_id = p.id
            GROUP BY p.id, p.title
            ORDER BY p.id
        ")->fetchAll();
    }
}
