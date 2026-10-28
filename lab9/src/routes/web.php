<?php
// routes/web.php — таблица маршрутов: «какой адрес — какой контроллер и метод».
// Переменная $router создаётся в public/index.php. Все маршруты готовы.

use App\Controllers\Api\AuthController;
use App\Controllers\Api\ProjectApiController;
use App\Controllers\Api\TaskApiController;
use App\Controllers\Api\UserApiController;
use App\Controllers\HomeController;

$router->get('/', [HomeController::class, 'index']);       // главная отправляет в клиентское приложение /client/

// ---------- Вход и выход ----------
$router->post('/api/auth/login', [AuthController::class, 'login']);     // единственный адрес API без входа
$router->get('/api/auth/me', [AuthController::class, 'me']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);

// ---------- REST API ----------
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
