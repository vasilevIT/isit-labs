<?php
// app/Models/TaskRepository.php — модель задач. Все операции (all, find, add, update, delete)
// унаследованы от JsonRepository; здесь только имя файла и одна дополнительная выборка.

declare(strict_types=1);

namespace App\Models;

final class TaskRepository extends JsonRepository
{
    protected string $name = 'tasks';                      // файл storage/tasks.json

    // Задачи одного проекта
    public function byProject(int $projectId): array
    {
        return array_values(array_filter(
            $this->all(),
            fn(array $task): bool => $task['project_id'] === $projectId
        ));
    }
}
