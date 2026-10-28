<?php
// public/index.php — единая точка входа (front controller).
// Веб-сервер отправляет сюда все запросы, кроме файлов клиента из public/client/.

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

// CORS: для клиента с другого адреса. Браузер перед «сложным» запросом присылает пробный запрос OPTIONS (preflight)
// и спрашивает, можно ли; отвечаем 204 с разрешающими заголовками (ГОТОВО).
if (is_api_request()) {
    send_cors_headers();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        no_content();
    }
}

$router = new App\Core\Router();
require BASE_PATH . '/routes/web.php';

try {
    // TODO 2.2  «Охранник» API. Сейчас любой может читать и менять данные без входа. Все адреса API, кроме входа, должны требовать токен.
    //           Проверка делается один раз, до маршрутизатора: если запрос к API и адрес не публичный — require_auth().
    //           Функции is_api_request(), is_public_api() и require_auth() уже написаны в app/helpers.php.
    //           Подсказка:
    //              // Охранник: весь API, кроме входа, доступен только с действительным токеном.
    //              // Проверка стоит здесь, в одном месте, чтобы нельзя было забыть защитить новый адрес.
    //              if (is_api_request() && !is_public_api()) {
    //                  require_auth();                                    // нет токена — ответ 401, дальше код не идёт
    //              }
    //           ✔ Проверка: curl -i http://localhost:8000/api/tasks — 401 и {"error": "Требуется авторизация"};
    //             тот же запрос с заголовком Authorization: Bearer ВАШ_ТОКЕН — 200; вход (POST /api/auth/login) по-прежнему работает без токена.
    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (Throwable $e) {
    if (is_api_request()) {
        if ($e->getCode() === 503) {                       // база данных недоступна (см. Database::connection)
            json_error($e->getMessage(), 503);
        }
        json_error(config('debug') ? $e->getMessage() : 'Внутренняя ошибка сервера', 500);
    }
    http_response_code(500);
    echo '<h1>500</h1><p>' . (config('debug') ? e($e->getMessage()) : 'Внутренняя ошибка сервера') . '</p>';
}
