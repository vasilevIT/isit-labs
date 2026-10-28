<?php
// app/Controllers/Api/TaskApiController.php — REST API ресурса «задачи» (/api/tasks).
// Все адреса API защищены входом (public/index.php); удалять задачи может только руководитель.

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ProjectRepository;
use App\Models\TaskRepository;

final class TaskApiController
{
    private TaskRepository $tasks;
    private ProjectRepository $projects;

    public function __construct()
    {
        $this->tasks = new TaskRepository();
        $this->projects = new ProjectRepository();
    }

    // GET /api/tasks  или  GET /api/tasks?status=done&project_id=1&q=форма&sort=-id
    //   status, project_id — фильтры; q — поиск по названию; sort — сортировка (id, title, due_date; «-» перед именем — по убыванию).
    //   Сам отбор делает база данных: см. TaskRepository::search()
    public function index(): void
    {
        $status = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
        $projectId = is_string($_GET['project_id'] ?? null) && $_GET['project_id'] !== '' ? (int) $_GET['project_id'] : null;
        $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : '';

        json_response($this->tasks->search($status, $projectId, $q, $sort));
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

        json_response($this->tasks->update($task['id'], $this->clean($data)));
    }

    // DELETE /api/tasks/5 — только руководитель (manager)
    public function destroy(string $id): void
    {
        // TODO 2.3  Роли. Вошедший участник — не всё равно что «может всё». Правила (таблица в теории, раздел 8):
        //           удалять задачи, создавать/менять/удалять проекты и участников может только руководитель (manager).
        //           Для этого достаточно одной строки в начале метода: функция require_role() вернёт ответ 403, если роль не подходит.
        //           В контроллерах проектов и участников эта строка уже стоит (посмотрите ProjectApiController::store как образец).
        //           Добавьте её сюда, в TaskApiController::destroy.
        //           Подсказка:
        //              require_role('manager');                           // другая роль — ответ 403
        //           ✔ Проверка: войдите как igor@example.com (developer) и отправьте DELETE /api/tasks/1 — 403 {"error": "Недостаточно прав для этого действия"};
        //             войдите как anna@example.com (manager), создайте задачу (POST /api/tasks) и удалите её — 204.
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
