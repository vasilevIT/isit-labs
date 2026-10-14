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

    // GET /api/projects
    public function index(): void
    {
        json_response($this->projects->all());
    }

    // GET /api/projects/2
    public function show(string $id): void
    {
        $project = $this->projects->find((int) $id);
        if ($project === null) {
            json_error('Проект не найден', 404);
        }
        json_response($project);
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
        $data = json_input();
        $errors = $this->validate($data);
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }

        $project = $this->projects->add($this->clean($data));
        header('Location: /api/projects/' . $project['id']);
        json_response($project, 201);
    }

    // PUT или PATCH /api/projects/2
    public function update(string $id): void
    {
        $project = $this->projects->find((int) $id);
        if ($project === null) {
            json_error('Проект не найден', 404);
        }

        $data = array_merge($project, json_input());
        $errors = $this->validate($data);
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }

        json_response($this->projects->update($project['id'], $this->clean($data)));
    }

    // DELETE /api/projects/2
    public function destroy(string $id): void
    {
        if ($this->projects->find((int) $id) === null) {
            json_error('Проект не найден', 404);
        }
        if ($this->tasks->byProject((int) $id)) {
            // Нельзя удалить проект, у которого есть задачи: 409 Conflict («конфликт с текущим состоянием»)
            json_error('Нельзя удалить проект: в нём есть задачи', 409);
        }
        $this->projects->delete((int) $id);
        no_content();
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
