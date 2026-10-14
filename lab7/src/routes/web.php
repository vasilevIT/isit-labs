<?php
// routes/web.php — таблица маршрутов приложения: «какой адрес — какой контроллер и метод».
// Переменная $router создаётся в public/index.php.

use App\Controllers\Api\ProjectApiController;
use App\Controllers\Api\TaskApiController;
use App\Controllers\Api\UserApiController;
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

// ---------- REST API (все адреса начинаются с /api, ответы в формате JSON) ----------
$router->get('/api/tasks', [TaskApiController::class, 'index']);
$router->post('/api/tasks', [TaskApiController::class, 'store']);
$router->get('/api/tasks/{id}', [TaskApiController::class, 'show']);
$router->put('/api/tasks/{id}', [TaskApiController::class, 'update']);
$router->patch('/api/tasks/{id}', [TaskApiController::class, 'update']);
$router->delete('/api/tasks/{id}', [TaskApiController::class, 'destroy']);

$router->get('/api/projects', [ProjectApiController::class, 'index']);
$router->post('/api/projects', [ProjectApiController::class, 'store']);
$router->get('/api/projects/{id}', [ProjectApiController::class, 'show']);
$router->put('/api/projects/{id}', [ProjectApiController::class, 'update']);
$router->patch('/api/projects/{id}', [ProjectApiController::class, 'update']);
$router->delete('/api/projects/{id}', [ProjectApiController::class, 'destroy']);
$router->get('/api/projects/{id}/tasks', [ProjectApiController::class, 'tasks']);

$router->get('/api/users', [UserApiController::class, 'index']);
$router->post('/api/users', [UserApiController::class, 'store']);
$router->get('/api/users/{id}', [UserApiController::class, 'show']);
$router->put('/api/users/{id}', [UserApiController::class, 'update']);
$router->patch('/api/users/{id}', [UserApiController::class, 'update']);
$router->delete('/api/users/{id}', [UserApiController::class, 'destroy']);
