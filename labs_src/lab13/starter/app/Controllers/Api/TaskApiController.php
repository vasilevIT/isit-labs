<?php
// app/Controllers/Api/TaskApiController.php — REST API ресурса «задачи» (/api/tasks).
// Все адреса API защищены входом (public/index.php); удалять задачи может только руководитель.

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Events;
use App\Jobs\Jobs;
use App\Models\CachedTaskRepository;
use App\Models\ProjectRepository;
use App\Models\TaskRepository;

final class TaskApiController
{
    private TaskRepository $tasks;
    private ProjectRepository $projects;

    public function __construct()
    {
        $this->tasks = new CachedTaskRepository();          // задачи с кешем (вместо обычного TaskRepository)
        $this->projects = new ProjectRepository();
    }

    // GET /api/tasks  или  GET /api/tasks?status=done&project_id=1&q=форма&sort=-id&limit=50&offset=100
    //   status, project_id — фильтры; q — поиск по названию; sort — сортировка (id, title, due_date; «-» — по убыванию);
    //   limit (по умолчанию 100, не больше 500) и offset — постраничный вывод. Общее число задач — в заголовке X-Total-Count.
    public function index(): void
    {
        $status = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
        $projectId = is_string($_GET['project_id'] ?? null) && $_GET['project_id'] !== '' ? (int) $_GET['project_id'] : null;
        $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : '';
        $limit = is_string($_GET['limit'] ?? null) && ctype_digit($_GET['limit']) ? min(max((int) $_GET['limit'], 1), 500) : 100;
        $offset = is_string($_GET['offset'] ?? null) && ctype_digit($_GET['offset']) ? (int) $_GET['offset'] : 0;

        $result = $this->tasks->list($status, $projectId, $q, $sort, $limit, $offset);
        header('X-Total-Count: ' . $result['total']);
        json_response($result['rows']);
    }

    // GET /api/tasks/5
    public function show(string $id): void
    {
        $task = $this->tasks->find((int) $id);
        if ($task === null) {
            json_error('Задача не найдена', 404);
        }
        json_response($task);
    }

    // POST /api/tasks
    public function store(): void
    {
        $data = json_input();
        $data += ['description' => '', 'status' => 'new', 'priority' => 'medium', 'due_date' => null];   // значения по умолчанию

        $errors = $this->validate($data);
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }

        $task = $this->tasks->add($this->clean($data));
        Events::log('task_created', 'Создана задача «' . $task['title'] . '»', [
            'entity_type' => 'task',
            'entity_id'   => $task['id'],
            'details'     => ['project_id' => $task['project_id'], 'title' => $task['title']],
        ]);
        // TODO 3.4  Поиск должен знать о каждом изменении задач. После создания задачи поставьте в очередь задачу «search_index»:
        //              Jobs::dispatch('search_index', ['task_id' => $task['id']]);
        //           (так же, как уведомление в лабораторной работе №11: пользователь не ждёт, копию в OpenSearch обновит воркер).
        //           Такая же строка нужна в трёх местах: здесь (создание), в update() (изменение), в destroy() (удаление), а также в
        //           ProjectApiController::update() для переименования проекта, но там вместо task_id передаётся project_id.
        //           ✔ Проверка: создайте задачу «Тест поиска» (POST /api/tasks): php bin/queue-stats.php — «ожидают: 1»; после воркера
        //             GET /api/search?q=тест находит её.
        header('Location: /api/tasks/' . $task['id']);
        json_response($task, 201);
    }

    // PUT или PATCH /api/tasks/5
    public function update(string $id): void
    {
        $task = $this->tasks->find((int) $id);
        if ($task === null) {
            json_error('Задача не найдена', 404);
        }

        $data = array_merge($task, json_input());
        $errors = $this->validate($data);
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }

        $updated = $this->tasks->update($task['id'], $this->clean($data));

        if ($updated['status'] !== $task['status']) {          // уведомляем только о смене статуса, а не о любой правке
            $actor = current_user();
            $jobId = Jobs::dispatch('notification', [          // в очередь кладём только нужное: номера и тексты, а не целые записи
                'task_id'    => $updated['id'],
                'title'      => $updated['title'],
                'old_status' => $task['status'],
                'new_status' => $updated['status'],
                'actor_id'   => $actor['id'],
                'actor_name' => $actor['name'],
            ]);
            if ($jobId !== null) {
                header('X-Job-Id: ' . $jobId);                 // номер задачи в очереди: удобно искать в журнале воркера
            }
        }

        $changed = [];                                                            // какие поля изменились
        foreach (['project_id', 'title', 'description', 'status', 'priority', 'due_date'] as $field) {
            if (($task[$field] ?? null) != ($updated[$field] ?? null)) {
                $changed[] = $field;
            }
        }
        Events::log('task_updated', 'Изменена задача №' . $updated['id'] . ' (' . ($changed ? implode(', ', $changed) : 'без изменений') . ')', [
            'entity_type' => 'task',
            'entity_id'   => $updated['id'],
            'details'     => ['changed' => $changed, 'status' => [$task['status'], $updated['status']]],
        ]);
        // TODO 3.4  (продолжение)  изменение задачи: если изменилось хотя бы одно поле ($changed не пуст), поставьте в очередь
        //           Jobs::dispatch('search_index', ['task_id' => $updated['id']]);

        json_response($updated);
    }

    // DELETE /api/tasks/5 — только руководитель (manager)
    public function destroy(string $id): void
    {
        require_role('manager');                           // другая роль — ответ 403
        $task = $this->tasks->find((int) $id);             // название нужно для журнала: после удаления его уже не найти
        if (!$this->tasks->delete((int) $id)) {
            json_error('Задача не найдена', 404);
        }
        Events::log('task_deleted', 'Удалена задача №' . (int) $id . ($task ? ' «' . $task['title'] . '»' : ''), [
            'entity_type' => 'task',
            'entity_id'   => (int) $id,
            'details'     => ['title' => $task['title'] ?? null],
        ]);
        // TODO 3.4  (продолжение)  удаление задачи: Jobs::dispatch('search_index', ['task_id' => (int) $id]);
        //           Воркер сам увидит, что задачи в базе уже нет, и удалит её копию из индекса.
        no_content();
    }

    // Оставляет только известные поля (лишние, присланные клиентом, отбрасываются)
    private function clean(array $data): array
    {
        return [
            'project_id'  => (int) $data['project_id'],
            'title'       => trim($data['title']),
            'description' => trim((string) ($data['description'] ?? '')),
            'status'      => $data['status'],
            'priority'    => $data['priority'],
            'due_date'    => $data['due_date'],
        ];
    }

    // Проверка данных: массив ошибок «поле => сообщение»
    private function validate(array $data): array
    {
        $errors = [];
        if (!is_string($data['title'] ?? null) || mb_strlen(trim($data['title'])) < 3) {
            $errors['title'] = 'Название — не короче 3 символов';
        }
        if (isset($data['description']) && (!is_string($data['description']) || mb_strlen($data['description']) > 1000)) {
            $errors['description'] = 'Описание — строка не длиннее 1000 символов';
        }
        if (!is_int($data['project_id'] ?? null) || $this->projects->find($data['project_id']) === null) {
            $errors['project_id'] = 'Проекта с таким номером нет';
        }
        if (!in_array($data['status'] ?? null, ['new', 'in_progress', 'done'], true)) {
            $errors['status'] = 'Статус: new, in_progress или done';
        }
        if (!in_array($data['priority'] ?? null, ['low', 'medium', 'high'], true)) {
            $errors['priority'] = 'Приоритет: low, medium или high';
        }
        $due = $data['due_date'] ?? null;
        if ($due !== null && (!is_string($due) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due))) {
            $errors['due_date'] = 'Дата в формате ГГГГ-ММ-ДД или null';
        }
        return $errors;
    }
}
