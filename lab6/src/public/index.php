<?php
// public/index.php — единая точка входа (front controller).
// Веб-сервер отправляет сюда ВСЕ запросы, а дальше работает наш маршрутизатор.

declare(strict_types=1);

// Пути к каталогам проекта (нужны, чтобы подключать файлы независимо от того, откуда запущен сервер)
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

require APP_PATH . '/autoload.php';    // автоматическая загрузка классов
require APP_PATH . '/helpers.php';     // вспомогательные функции: e(), view(), redirect()

$router = new App\Core\Router();       // создаём маршрутизатор
require BASE_PATH . '/routes/web.php'; // регистрируем маршруты (файл использует переменную $router)

try {
    // Передаём маршрутизатору метод запроса (GET, POST) и адрес (/tasks/5?status=done)
    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (Throwable $e) {
    http_response_code(500);           // 500 — внутренняя ошибка сервера
    view('errors/500', ['message' => config('debug') ? $e->getMessage() : null]);
}
