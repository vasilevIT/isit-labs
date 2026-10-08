<?php
// app/Controllers/ProjectController.php — контроллер проектов: список и просмотр проекта с его задачами.

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ProjectRepository;
use App\Models\TaskRepository;

final class ProjectController
{
    // GET /projects
    public function index(): void
    {
        view('projects/index', [
            'title'    => 'Проекты',
            'projects' => (new ProjectRepository())->all(),
        ]);
    }

    // GET /projects/2
    public function show(string $id): void
    {
        $project = (new ProjectRepository())->find((int) $id);
        if ($project === null) {
            notFound();
        }

        view('projects/show', [
            'title'   => $project['title'],
            'project' => $project,
            'tasks'   => (new TaskRepository())->byProject($project['id']),
        ]);
    }
}
