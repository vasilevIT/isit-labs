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
// Маршруты участников готовы: используйте их как образец.
// TODO 1.1  Зарегистрируйте шесть маршрутов для задач по образцу участников (замените users на tasks,
//           UserApiController на TaskApiController):
//             GET    /api/tasks          index
//             POST   /api/tasks          store
//             GET    /api/tasks/{id}     show
//             PUT    /api/tasks/{id}     update
//             PATCH  /api/tasks/{id}     update      (тот же метод, что и у PUT)
//             DELETE /api/tasks/{id}     destroy
//           ✔ Проверка: запрос GET /api/tasks вернёт пустой ответ (контроллер ещё не написан), а не ошибку 404.
//             Запрос DELETE /api/tasks даст 405: адрес есть, но для этого метода маршрута нет.

// TODO 1.2  То же для проектов (ProjectApiController, адрес /api/projects) + ещё один, седьмой маршрут:
//             GET    /api/projects/{id}/tasks    tasks      (задачи проекта; метод tasks() уже написан)
//           ✔ Проверка: GET /api/projects/2/tasks возвращает список из трёх задач.

$router->get('/api/users', [UserApiController::class, 'index']);
$router->post('/api/users', [UserApiController::class, 'store']);
$router->get('/api/users/{id}', [UserApiController::class, 'show']);
$router->put('/api/users/{id}', [UserApiController::class, 'update']);
$router->patch('/api/users/{id}', [UserApiController::class, 'update']);
$router->delete('/api/users/{id}', [UserApiController::class, 'destroy']);
