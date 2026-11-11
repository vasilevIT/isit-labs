<?php
// app/Models/TaskRepository.php — модель задач (таблица tasks). Базовые операции унаследованы от PgRepository.

declare(strict_types=1);

namespace App\Models;

class TaskRepository extends PgRepository
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

    // Страница списка задач и общее число подходящих задач.
    //   $status    — 'new', 'in_progress', 'done' или '' (любой)
    //   $projectId — номер проекта или null (любой)
    //   $q         — часть названия или '' (без поиска)
    //   $sort      — 'id', '-id', 'title', 'due_date' (с «-» — по убыванию) или '' (по возрастанию номера)
    //   $limit, $offset — сколько строк взять и сколько пропустить (постраничный вывод)
    // Возвращает ['rows' => [...задачи страницы...], 'total' => число всех подходящих задач].
    // Два запроса к базе (строки и подсчёт) — именно из-за них список задач стоит кешировать.
    public function list(string $status, ?int $projectId, string $q, string $sort, int $limit = 100, int $offset = 0): array
    {
        $where = ' WHERE 1 = 1';
        $params = [];
        if ($status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }
        if ($projectId !== null) {
            $where .= ' AND project_id = :project_id';
            $params['project_id'] = $projectId;
        }
        if ($q !== '') {
            // ILIKE — поиск без учёта регистра; знаки % и _ экранируем, чтобы они искались как обычные символы
            $where .= ' AND title ILIKE :q';
            $params['q'] = '%' . addcslashes($q, '%_\\') . '%';
        }

        // Имя столбца в ORDER BY нельзя передать параметром, поэтому проверяем его по списку допустимых
        $field = ltrim($sort, '-');
        if (!in_array($field, ['id', 'title', 'due_date'], true)) {
            $field = 'id';
        }
        $order = " ORDER BY $field " . (str_starts_with($sort, '-') ? 'DESC' : 'ASC') . ', id';

        $stmt = $this->pdo()->prepare("SELECT {$this->columns} FROM tasks" . $where . $order . ' LIMIT :limit OFFSET :offset');
        $stmt->execute($params + ['limit' => $limit, 'offset' => $offset]);
        $rows = $stmt->fetchAll();

        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM tasks' . $where);
        $stmt->execute($params);

        return ['rows' => $rows, 'total' => (int) $stmt->fetchColumn()];
    }
}
