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
        // TODO 3.1  Подготовьте данные для главной страницы и покажите шаблон app/Views/home.php:
        //
        //   1) получите все задачи и все проекты через модели (готовые классы):
        //        $tasks = (new TaskRepository())->all();
        //        $projects = (new ProjectRepository())->all();
        //
        //   2) посчитайте задачи по статусам циклом foreach:
        //        $byStatus = ['new' => 0, 'in_progress' => 0, 'done' => 0];
        //        foreach ($tasks as $task) {
        //            $byStatus[$task['status']]++;     // увеличиваем счётчик нужного статуса
        //        }
        //
        //   3) передайте данные в шаблон функцией view(): первый аргумент — имя шаблона (без .php),
        //      второй — массив «имя переменной => значение»:
        //        view('home', [
        //            'title'         => 'Главная',               // заголовок страницы (используется в шапке)
        //            'total'         => count($tasks),
        //            'byStatus'      => $byStatus,
        //            'projectsCount' => count($projects),
        //        ]);
        //
        //   ✔ Проверка: откройте / — должны появиться четыре карточки с числами. Сумма карточек «новых», «в работе»
        //     и «выполнено» должна равняться числу «всего задач».
    }
}
