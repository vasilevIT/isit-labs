<?php
// app/Models/TaskRepository.php — модель задач (таблица tasks). Базовые операции унаследованы от PgRepository.

declare(strict_types=1);

namespace App\Models;

final class TaskRepository extends PgRepository
{
    protected string $table = 'tasks';
    protected string $columns = 'id, project_id, title, description, status, priority, due_date';

    // Задачи одного проекта
    public function byProject(int $projectId): array
    {
        $stmt = $this->pdo()->prepare("SELECT {$this->columns} FROM tasks WHERE project_id = :project_id ORDER BY id");
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    // Список задач с фильтрами: статус, проект, поиск по части названия, сортировка.
    //   $status   — 'new', 'in_progress', 'done' или '' (любой)
    //   $projectId — номер проекта или null (любой)
    //   $q        — часть названия или '' (без поиска)
    //   $sort     — 'id', '-id', 'title', 'due_date' (с «-» — по убыванию) или '' (по возрастанию номера)
    public function search(string $status, ?int $projectId, string $q, string $sort): array
    {
        $sql = "SELECT {$this->columns} FROM tasks WHERE 1 = 1";
        $params = [];

        if ($status !== '') {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }
        if ($projectId !== null) {
            $sql .= ' AND project_id = :project_id';
            $params['project_id'] = $projectId;
        }
        if ($q !== '') {
            // ILIKE — поиск без учёта регистра. Знаки % и _ внутри LIKE — «любые символы»: экранируем их,
            // чтобы пользователь, введший «%», искал именно знак процента
            $sql .= ' AND title ILIKE :q';
            $params['q'] = '%' . addcslashes($q, '%_\\') . '%';
        }

        // Имя столбца в ORDER BY нельзя передать параметром, поэтому проверяем его по списку допустимых
        $field = ltrim($sort, '-');
        if (!in_array($field, ['id', 'title', 'due_date'], true)) {
            $field = 'id';
        }
        $sql .= " ORDER BY $field " . (str_starts_with($sort, '-') ? 'DESC' : 'ASC') . ', id';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
