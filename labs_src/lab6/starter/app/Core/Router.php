<?php
// app/Core/Router.php — маршрутизатор: по методу и адресу запроса выбирает контроллер.

declare(strict_types=1);

namespace App\Core;

final class Router
{
    // Таблица маршрутов. Каждый маршрут — массив из трёх значений:
    //   метод (GET/POST), шаблон адреса (/tasks/{id}) и обработчик ([Класс::class, 'метод'])
    private array $routes = [];

    // Регистрация маршрутов: $router->get('/tasks', [TaskController::class, 'index']);  (готово)
    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    // ---------- Шаг 2. Напишите этот метод ----------
    private function add(string $method, string $path, array $handler): void
    {
        // TODO 2.1  Добавьте маршрут в таблицу $this->routes. Каждый маршрут — ассоциативный массив:
        //             $this->routes[] = ['method' => $method, 'path' => $path, 'handler' => $handler];
        //           ✔ Проверка: пока ничего не меняется, страница остаётся пустой (маршрутов ещё нет).
    }

    // Находит подходящий маршрут и передаёт управление контроллеру (ГОТОВО: изучите и разберитесь, как это работает)
    public function dispatch(string $method, string $uri): void
    {
        // Из /tasks/5?status=done берём только путь /tasks/5; завершающий «/» отбрасываем
        $path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/') ?: '/';

        // Маршруты проверяются по порядку: срабатывает первый подходящий
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;                                  // другой метод: пропускаем маршрут
            }
            $params = $this->match($route['path'], $path);
            if ($params === null) {
                continue;                                  // адрес не подходит: пробуем следующий маршрут
            }

            [$class, $action] = $route['handler'];         // например, TaskController::class и 'show'
            $controller = new $class();                    // создаём объект контроллера
            $controller->$action(...$params);              // вызываем его метод; значения {id} из адреса передаются как параметры
            return;                                        // маршрут найден и выполнен: дальше искать не нужно
        }

        // Сюда программа доходит, только если ни один маршрут не подошёл.
        // TODO 2.2  Покажите страницу 404:
        //             http_response_code(404);                         // код ответа «не найдено»
        //             view('errors/404', ['path' => $path]);           // готовый шаблон страницы
        //           ✔ Проверка: откройте любой адрес, например /nonexistent: должна появиться страница «404 — страница не найдена».
        //             Пока вы не выполнили TODO 2.3 (маршруты), страницу 404 покажет вообще любой адрес, даже /.
    }

    // Сравнивает шаблон маршрута с адресом (ГОТОВО, менять не нужно).
    //   match('/tasks/{id}', '/tasks/5')  вернёт ['id' => '5']
    //   match('/tasks', '/tasks')         вернёт []   (маршрут подошёл, параметров нет)
    //   match('/tasks', '/projects')      вернёт null (не подошёл)
    private function match(string $pattern, string $path): ?array
    {
        // {id} превращаем в кусочек регулярного выражения «любые символы, кроме /»
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        if (!preg_match($regex, $path, $matches)) {
            return null;
        }
        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);   // оставляем только именованные части
    }
}
