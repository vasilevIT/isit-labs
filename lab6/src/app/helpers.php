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
