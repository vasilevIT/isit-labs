<?php
// routes/web.php — таблица маршрутов приложения: «какой адрес — какой контроллер и метод».
// Переменная $router создаётся в public/index.php.

use App\Controllers\HomeController;
use App\Controllers\ProjectController;
use App\Controllers\TaskController;

$router->get('/', [HomeController::class, 'index']);

$router->get('/tasks', [TaskController::class, 'index']);
$router->get('/tasks/create', [TaskController::class, 'create']);   // ВАЖНО: раньше, чем /tasks/{id}
$router->post('/tasks', [TaskController::class, 'store']);
$router->get('/tasks/{id}', [TaskController::class, 'show']);

$router->get('/projects', [ProjectController::class, 'index']);
$router->get('/projects/{id}', [ProjectController::class, 'show']);
