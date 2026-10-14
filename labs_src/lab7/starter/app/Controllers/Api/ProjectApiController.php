<?php
// app/Controllers/Api/ProjectApiController.php — REST API ресурса «проекты» (/api/projects).

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ProjectRepository;
use App\Models\TaskRepository;

final class ProjectApiController
{
    private ProjectRepository $projects;
    private TaskRepository $tasks;

    public function __construct()
    {
        $this->projects = new ProjectRepository();
        $this->tasks = new TaskRepository();
    }

    // ---------- Часть 3. Напишите пять методов (по образцу задач и участников) ----------
    // Готовы: конструктор ($this->projects, $this->tasks), tasks(), clean() и validate().

    // GET /api/projects
    public function index(): void
    {
        // TODO 3.1  Верните список всех проектов: json_response($this->projects->all());
        //           ✔ Проверка: GET /api/projects — массив из 3 проектов.
    }

    // GET /api/projects/2
    public function show(string $id): void
    {
        // TODO 3.2  Один проект или 404 с сообщением «Проект не найден» (как show у задач).
        //           ✔ Проверка: GET /api/projects/2 — 200; GET /api/projects/99 — 404.
    }

    // GET /api/projects/2/tasks — вложенный ресурс: задачи проекта
    public function tasks(string $id): void
    {
        if ($this->projects->find((int) $id) === null) {
            json_error('Проект не найден', 404);
        }
        json_response($this->tasks->byProject((int) $id));
    }

    // POST /api/projects
    public function store(): void
    {
        // TODO 3.3  Создание проекта по образцу TaskApiController::store:
        //             json_input() -> validate() -> при ошибках 422 -> $this->projects->add($this->clean($data))
        //             -> заголовок Location: /api/projects/{id} -> json_response($project, 201)
        //           Значений по умолчанию (как у задач) здесь не нужно.
        //           ✔ Проверка: POST с телом {"title": "Новый проект", "description": "Описание"} — 201;
        //             с телом {"title": "ab"} — 422.
    }

    // PUT или PATCH /api/projects/2
    public function update(string $id): void
    {
        // TODO 3.4  Изменение проекта по образцу TaskApiController::update (find -> 404 -> array_merge -> validate
        //           -> $this->projects->update(...)).
        //           ✔ Проверка: PATCH /api/projects/3 с телом {"description": "Новое описание"} — 200, название прежнее.
    }

    // DELETE /api/projects/2
    public function destroy(string $id): void
    {
        // TODO 3.5  Удаление проекта. Отличие от задач: проект, в котором есть задачи, удалять нельзя.
        //             1) если проекта нет — json_error('Проект не найден', 404);
        //             2) если $this->tasks->byProject((int) $id) не пуст — json_error('Нельзя удалить проект: в нём есть задачи', 409);
        //                (409 Conflict: запрос понятен, но противоречит текущему состоянию данных)
        //             3) иначе $this->projects->delete((int) $id); и no_content();
        //           ✔ Проверка: DELETE /api/projects/1 — 409; созданный вами в TODO 3.3 пустой проект удаляется кодом 204.
    }

    private function clean(array $data): array
    {
        return [
            'title'       => trim($data['title']),
            'description' => trim((string) ($data['description'] ?? '')),
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (!is_string($data['title'] ?? null) || mb_strlen(trim($data['title'])) < 3) {
            $errors['title'] = 'Название — не короче 3 символов';
        }
        if (isset($data['description']) && !is_string($data['description'])) {
            $errors['description'] = 'Описание должно быть строкой';
        }
        return $errors;
    }
}
