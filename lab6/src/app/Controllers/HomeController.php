<?php
// app/Controllers/HomeController.php — контроллер главной страницы.

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ProjectRepository;
use App\Models\TaskRepository;

final class HomeController
{
    // GET /
    public function index(): void
    {
        $tasks = (new TaskRepository())->all();
        $projects = (new ProjectRepository())->all();

        // Считаем, сколько задач в каждом статусе
        $byStatus = ['new' => 0, 'in_progress' => 0, 'done' => 0];
        foreach ($tasks as $task) {
            $byStatus[$task['status']]++;
        }

        view('home', [
            'title'         => 'Главная',
            'total'         => count($tasks),
            'byStatus'      => $byStatus,
            'projectsCount' => count($projects),
        ]);
    }
}
