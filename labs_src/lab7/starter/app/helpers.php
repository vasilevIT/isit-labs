<?php
// app/helpers.php — небольшие вспомогательные функции для всего приложения.

declare(strict_types=1);

// Читает настройку из config/app.php: config('name'), config('debug')
function config(string $key): mixed
{
    static $config = null;                                 // файл читается один раз
    $config ??= require BASE_PATH . '/config/app.php';
    return $config[$key] ?? null;
}

// Безопасный вывод текста в HTML: заменяет символы < > & " на безопасные коды.
// Защита от XSS: данные пользователя в шаблонах всегда выводим через e(...), а не напрямую.
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Показывает страницу: шапка + шаблон + подвал.
// view('tasks/index', ['tasks' => $tasks]) подключает app/Views/tasks/index.php,
// а внутри шаблона будет доступна переменная $tasks.
function view(string $template, array $data = []): void
{
    extract($data);                                        // ключи массива становятся переменными
    require APP_PATH . '/Views/layout/header.php';
    require APP_PATH . '/Views/' . $template . '.php';
    require APP_PATH . '/Views/layout/footer.php';
}

// Перенаправляет браузер на другой адрес и завершает работу скрипта.
function redirect(string $path): never
{
    header('Location: ' . $path);                          // 302: «ваша страница теперь по этому адресу»
    exit;
}

// Показывает страницу «Не найдено» с кодом ответа 404 и завершает работу скрипта.
function notFound(): never
{
    http_response_code(404);
    view('errors/404', ['path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)]);
    exit;
}

// ---------- Помощники для REST API (ГОТОВО) ----------

// Запрос адресован API (адрес начинается с /api)?
function is_api_request(): bool
{
    return str_starts_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/api');
}

// Отправляет ответ в формате JSON с нужным кодом и завершает работу скрипта.
//   json_response($task)        — код 200
//   json_response($task, 201)   — код 201 Created
function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// Ответ об ошибке: {"error": "...", "errors": {поле: сообщение}}.
//   json_error('Задача не найдена', 404);
//   json_error('Ошибка проверки данных', 422, ['title' => 'Название — не короче 3 символов']);
function json_error(string $message, int $status, array $errors = []): never
{
    $body = ['error' => $message];
    if ($errors) {
        $body['errors'] = $errors;
    }
    json_response($body, $status);
}

// Ответ без тела: код 204 No Content (используется после удаления).
function no_content(): never
{
    http_response_code(204);
    exit;
}

// Читает тело запроса (JSON) и превращает его в массив.
// Если тело пустое или это не JSON-объект, отвечает кодом 400 и завершает работу.
function json_input(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        json_error('Тело запроса должно быть корректным JSON-объектом', 400);
    }
    return $data;
}
