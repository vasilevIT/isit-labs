<?php
// app/Controllers/ProjectController.php — контроллер проектов: список и просмотр проекта с его задачами.
// Напишите его САМОСТОЯТЕЛЬНО по образцу TaskController (методы index и show устроены так же).

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ProjectRepository;
use App\Models\TaskRepository;

final class ProjectController
{
    // GET /projects
    public function index(): void
    {
        // TODO 3.4  Покажите список проектов: получите все проекты ((new ProjectRepository())->all())
        //             и передайте их в шаблон projects/index под именем 'projects' (и 'title' => 'Проекты').
        //             ✔ Проверка: /projects — карточки проектов.
    }

    // GET /projects/2
    public function show(string $id): void
    {
        // TODO 3.5  Покажите проект и его задачи:
        //               1) найдите проект: (new ProjectRepository())->find((int) $id)
        //               2) если проекта нет (null) — вызовите notFound();
        //               3) задачи проекта возвращает метод (new TaskRepository())->byProject($project['id'])
        //               4) передайте в шаблон projects/show три значения: 'title' (название проекта),
        //                  'project' (проект) и 'tasks' (его задачи).
        //             ✔ Проверка: /projects/2 — проект и список его задач; /projects/99 — страница 404.
    }
}
