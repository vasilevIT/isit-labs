<?php
// app/Controllers/Api/TaskApiController.php — REST API ресурса «задачи» (/api/tasks).

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

    // GET /api/tasks  или  GET /api/tasks?status=done&project_id=1
    public function index(): void
    {
        $tasks = $this->tasks->all();

        $status = $_GET['status'] ?? '';
        if ($status !== '') {
            $tasks = array_values(array_filter($tasks, fn(array $t): bool => $t['status'] === $status));
        }
        $projectId = $_GET['project_id'] ?? '';
        if ($projectId !== '') {
            $tasks = array_values(array_filter($tasks, fn(array $t): bool => $t['project_id'] === (int) $projectId));
        }

        json_response($tasks);
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
        $data += ['status' => 'new', 'priority' => 'medium', 'due_date' => null];   // значения по умолчанию

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

    // DELETE /api/tasks/5
    public function destroy(string $id): void
    {
        if (!$this->tasks->delete((int) $id)) {
            json_error('Задача не найдена', 404);
        }
        no_content();
    }

    // Оставляет только известные поля (лишние, присланные клиентом, отбрасываются)
    private function clean(array $data): array
    {
        return [
            'project_id' => (int) $data['project_id'],
            'title'      => trim($data['title']),
            'status'     => $data['status'],
            'priority'   => $data['priority'],
            'due_date'   => $data['due_date'],
        ];
    }

    // Проверка данных: массив ошибок «поле => сообщение»
    private function validate(array $data): array
    {
        $errors = [];
        if (!is_string($data['title'] ?? null) || mb_strlen(trim($data['title'])) < 3) {
            $errors['title'] = 'Название — не короче 3 символов';
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
