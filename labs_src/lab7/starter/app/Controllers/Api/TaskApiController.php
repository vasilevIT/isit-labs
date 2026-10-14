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

    // ---------- Часть 2. Напишите пять методов ----------
    // Готовы: конструктор ($this->tasks, $this->projects), clean() и validate() (изучите их).
    // Образец: app/Controllers/Api/UserApiController.php — там те же методы для участников.

    // GET /api/tasks  или  GET /api/tasks?status=done&project_id=1
    public function index(): void
    {
        // TODO 2.1  Список задач с необязательными фильтрами:
        //             $tasks = $this->tasks->all();
        //             $status = $_GET['status'] ?? '';
        //             if ($status !== '') {
        //                 $tasks = array_values(array_filter($tasks, fn(array $t): bool => $t['status'] === $status));
        //             }
        //             $projectId = $_GET['project_id'] ?? '';
        //             if ($projectId !== '') {
        //                 $tasks = array_values(array_filter($tasks, fn(array $t): bool => $t['project_id'] === (int) $projectId));
        //             }
        //             json_response($tasks);
        //           ✔ Проверка: GET /api/tasks — 7 задач; GET /api/tasks?status=done — 2; GET /api/tasks?project_id=2 — 3.
    }

    // GET /api/tasks/5
    public function show(string $id): void
    {
        // TODO 2.2  Одна задача или ошибка 404:
        //             $task = $this->tasks->find((int) $id);
        //             if ($task === null) {
        //                 json_error('Задача не найдена', 404);
        //             }
        //             json_response($task);
        //           ✔ Проверка: GET /api/tasks/3 — 200 и объект; GET /api/tasks/999 — 404 и {"error": ...}.
    }

    // POST /api/tasks
    public function store(): void
    {
        // TODO 2.3  Создание задачи:
        //             $data = json_input();                                                      // тело запроса -> массив
        //             $data += ['status' => 'new', 'priority' => 'medium', 'due_date' => null];  // значения по умолчанию
        //             $errors = $this->validate($data);
        //             if ($errors) {
        //                 json_error('Ошибка проверки данных', 422, $errors);
        //             }
        //             $task = $this->tasks->add($this->clean($data));
        //             header('Location: /api/tasks/' . $task['id']);                             // где искать новую задачу
        //             json_response($task, 201);                                                 // 201 Created
        //           ✔ Проверка: POST с телом {"title": "Проверить API", "project_id": 1} — 201 и задача с новым id;
        //             с телом {"title": "Да"} — 422 и список ошибок; с пустым телом — 400.
    }

    // PUT или PATCH /api/tasks/5
    public function update(string $id): void
    {
        // TODO 2.4  Изменение задачи. Присланные поля заменяют старые, остальные остаются:
        //             $task = $this->tasks->find((int) $id);
        //             if ($task === null) {
        //                 json_error('Задача не найдена', 404);
        //             }
        //             $data = array_merge($task, json_input());       // старые значения + присланные
        //             $errors = $this->validate($data);
        //             if ($errors) {
        //                 json_error('Ошибка проверки данных', 422, $errors);
        //             }
        //             json_response($this->tasks->update($task['id'], $this->clean($data)));
        //           ✔ Проверка: PATCH /api/tasks/3 с телом {"status": "done"} — 200, у задачи новый статус, остальные поля прежние;
        //             с телом {"priority": "urgent"} — 422.
    }

    // DELETE /api/tasks/5
    public function destroy(string $id): void
    {
        // TODO 2.5  Удаление задачи. Успех — код 204 и пустое тело:
        //             if (!$this->tasks->delete((int) $id)) {
        //                 json_error('Задача не найдена', 404);
        //             }
        //             no_content();
        //           ✔ Проверка: DELETE /api/tasks/7 — 204; повторный DELETE /api/tasks/7 — 404.
        //             (Перед проверкой убедитесь, что понимаете, какую задачу удаляете: данные сохраняются в storage/tasks.json.)
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
