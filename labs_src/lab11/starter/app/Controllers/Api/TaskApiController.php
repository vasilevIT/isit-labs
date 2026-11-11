<?php
// app/Controllers/Api/TaskApiController.php — REST API ресурса «задачи» (/api/tasks).
// Все адреса API защищены входом (public/index.php); удалять задачи может только руководитель.

declare(strict_types=1);

namespace App\Controllers\Api;

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

        // TODO 2.2  Постановка в очередь. Когда у задачи изменился статус, нужно уведомить руководителей, но не заставлять пользователя
        //           ждать отправки. Вместо отправки поставьте в очередь задачу «notification» и сразу отвечайте:
        //             1) сравните $updated['status'] и $task['status'] (старая запись у вас уже есть в $task): если не совпали, статус изменился;
        //             2) $actor = current_user();  — кто изменил (участник, отправивший запрос);
        //             3) $jobId = Jobs::dispatch('notification', [...данные...]): метод кладёт задачу в очередь и возвращает её номер.
        //                В данные (payload) кладите только нужное: task_id, title, old_status, new_status, actor_id, actor_name;
        //             4) если $jobId не null, добавьте заголовок ответа header('X-Job-Id: ' . $jobId);
        //           Подсказка:
        //              if ($updated['status'] !== $task['status']) {
        //                  $actor = current_user();
        //                  $jobId = Jobs::dispatch('notification', [
        //                      'task_id'    => $updated['id'],
        //                      'title'      => $updated['title'],
        //                      'old_status' => $task['status'],
        //                      'new_status' => $updated['status'],
        //                      'actor_id'   => $actor['id'],
        //                      'actor_name' => $actor['name'],
        //                  ]);
        //                  if ($jobId !== null) {
        //                      header('X-Job-Id: ' . $jobId);
        //                  }
        //              }
        //           ✔ Проверка: PATCH /api/tasks/2 {"status": "done"} отвечает сразу, в ответе есть заголовок X-Job-Id;
        //             php bin/queue-stats.php — «ожидают: 1». Без воркера уведомления не появятся: это нормально, их создаст воркер (шаг 3).

        json_response($updated);
    }

    // DELETE /api/tasks/5 — только руководитель (manager)
    public function destroy(string $id): void
    {
        require_role('manager');                           // другая роль — ответ 403
        if (!$this->tasks->delete((int) $id)) {
            json_error('Задача не найдена', 404);
        }
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
