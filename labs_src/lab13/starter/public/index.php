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

// Номер запроса: клиент получает его в заголовке X-Request-Id, а события журнала хранят тот же номер (ГОТОВО).
if (is_api_request()) {
    header('X-Request-Id: ' . App\Core\Events::requestId());
}

$router = new App\Core\Router();
require BASE_PATH . '/routes/web.php';

try {
    // Охранник: весь API, кроме входа, доступен только с действительным токеном.
    // Проверка стоит здесь, в одном месте, чтобы нельзя было забыть защитить новый адрес.
    if (is_api_request() && !is_public_api()) {
        require_auth();                                    // нет токена — ответ 401, дальше код не идёт
    }
    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (Throwable $e) {
    App\Core\Events::log('server_error', 'Ошибка сервера: ' . $e->getMessage(), [
        'details' => [
            'class'  => get_class($e),
            'file'   => basename($e->getFile()) . ':' . $e->getLine(),
            'method' => $_SERVER['REQUEST_METHOD'],
            'path'   => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),       // только путь: в строке запроса могут быть чужие данные
            'code'   => $e->getCode() === 503 ? 503 : 500,
        ],
    ]);
    if (is_api_request()) {
        if ($e->getCode() === 503) {                       // база данных недоступна (см. Database::connection)
            json_error($e->getMessage(), 503);
        }
        json_error(config('debug') ? $e->getMessage() : 'Внутренняя ошибка сервера', 500);
    }
    http_response_code(500);
    echo '<h1>500</h1><p>' . (config('debug') ? e($e->getMessage()) : 'Внутренняя ошибка сервера') . '</p>';
}
