<?php
// tests/api-tests.php — автоматические тесты API (комплексная проверка серверной части).
//
// Запуск (сервер должен быть запущен: php -S localhost:8000 -t public):
//     php tests/api-tests.php
//     php tests/api-tests.php http://localhost:8000     # другой адрес сервера
//
// Тест — это запрос и проверка ответа. Каждая проверка печатает PASS (прошла) или FAIL (не прошла) и пояснение.
// В начале база данных возвращается к исходному состоянию (bin/reset-db.php), поэтому тесты можно запускать сколько угодно раз.
// Код завершения 0 — все проверки прошли, 1 — есть ошибки (удобно для автоматического запуска).

declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$root = dirname(__DIR__);

// ---------------------------------------------------------------------
// Инструменты теста (ГОТОВО)
// ---------------------------------------------------------------------

// Отправляет запрос. Возвращает ['status' => 200, 'headers' => [...], 'json' => [...], 'body' => '...'].
function call(string $method, string $path, ?array $body = null, ?string $token = null, ?string $rawBody = null): array
{
    global $base;
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $content = $rawBody ?? ($body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null);
    if ($content !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content ?? '',
        'ignore_errors' => true,                           // коды 4xx и 5xx — это тоже ответ, а не сбой
        'timeout' => 10,
    ]]);
    $text = @file_get_contents($base . $path, false, $context);
    if ($text === false) {
        fwrite(STDERR, "Не удалось подключиться к $base. Запущен ли сервер (php -S localhost:8000 -t public)?\n");
        exit(2);
    }
    $status = 0;
    $responseHeaders = [];
    foreach ($http_response_header as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
            $status = (int) $m[1];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }
    }
    return ['status' => $status, 'headers' => $responseHeaders, 'json' => json_decode($text, true), 'body' => $text];
}

// Входит в систему и возвращает токен.
function login(string $email, string $password = '123456'): string
{
    $r = call('POST', '/api/auth/login', ['email' => $email, 'password' => $password]);
    return $r['json']['token'] ?? '';
}

$passed = 0;
$failed = 0;

// Одна проверка: $ok — условие, $name — что проверяем, $details — что получили (печатается при ошибке).
function check(string $name, bool $ok, string $details = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  \033[32mPASS\033[0m  $name\n";
    } else {
        $failed++;
        echo "  \033[31mFAIL\033[0m  $name" . ($details !== '' ? "  ($details)" : '') . "\n";
    }
}

// Проверка кода ответа: expectStatus('POST /api/tasks без токена', 401, $response)
function expectStatus(string $name, int $expected, array $r): void
{
    check($name . ' → ' . $expected, $r['status'] === $expected, 'получен код ' . $r['status']);
}

function section(string $title): void
{
    echo "\n$title\n";
}

// Список записей из ответа. Если сервер ответил ошибкой (объект {"error": ...}), вернёт пустой массив,
// чтобы тест просто не прошёл, а не завершился аварийно.
function rows(array $r): array
{
    return is_array($r['json']) && array_is_list($r['json']) ? $r['json'] : [];
}

// Возвращаем исходные данные в базе (вывод скрипта не нужен: в Windows он уходит в NUL, в Linux и macOS — в /dev/null)
$devNull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/reset-db.php') . ' > ' . $devNull);

$manager = login('anna@example.com');                      // руководитель
$developer = login('igor@example.com');                    // разработчик

// ---------------------------------------------------------------------
section('1. Вход в систему');
// ---------------------------------------------------------------------
$r = call('POST', '/api/auth/login', ['email' => 'anna@example.com', 'password' => '123456']);
expectStatus('вход с верными данными', 200, $r);
check('в ответе есть токен из 64 символов', strlen($r['json']['token'] ?? '') === 64);
check('в ответе есть участник с ролью manager', ($r['json']['user']['role'] ?? '') === 'manager');
check('в ответе НЕТ хеша пароля', !str_contains($r['body'], 'password_hash') && !str_contains($r['body'], '$2y$'));

$wrongPassword = call('POST', '/api/auth/login', ['email' => 'anna@example.com', 'password' => 'неверный']);
expectStatus('вход с неверным паролем', 401, $wrongPassword);
$unknownEmail = call('POST', '/api/auth/login', ['email' => 'nobody@example.com', 'password' => '123456']);
expectStatus('вход с несуществующей почтой', 401, $unknownEmail);
check('сообщения для неверного пароля и несуществующей почты одинаковы',
    ($wrongPassword['json']['error'] ?? 'a') === ($unknownEmail['json']['error'] ?? 'b'));

$r = call('POST', '/api/auth/login', ['email' => '', 'password' => '']);
expectStatus('вход с пустыми полями', 422, $r);
check('ошибки 422 указывают поля email и password', isset($r['json']['errors']['email'], $r['json']['errors']['password']));
expectStatus('вход с телом «abc» (не JSON)', 400, call('POST', '/api/auth/login', null, null, 'abc'));
expectStatus('GET на адрес входа', 405, call('GET', '/api/auth/login'));

// ---------------------------------------------------------------------
section('2. Защита адресов API');
// ---------------------------------------------------------------------
expectStatus('GET /api/tasks без токена', 401, call('GET', '/api/tasks'));
expectStatus('POST /api/tasks без токена', 401, call('POST', '/api/tasks', ['title' => 'Без входа', 'project_id' => 1]));
expectStatus('GET /api/tasks с неверным токеном', 401, call('GET', '/api/tasks', null, str_repeat('a', 64)));
expectStatus('GET /api/tasks с действительным токеном', 200, call('GET', '/api/tasks', null, $manager));
$r = call('GET', '/api/auth/me', null, $developer);
expectStatus('GET /api/auth/me', 200, $r);
check('/api/auth/me возвращает вошедшего участника', ($r['json']['email'] ?? '') === 'igor@example.com');
expectStatus('несуществующий адрес с токеном', 404, call('GET', '/api/unknown', null, $manager));
$r = call('DELETE', '/api/users', null, $manager);
expectStatus('DELETE /api/users (нет такого маршрута)', 405, $r);
check('в ответе 405 есть заголовок Allow', isset($r['headers']['allow']));

// ---------------------------------------------------------------------
section('3. Роли и права (manager может всё, developer не удаляет и не управляет проектами)');
// ---------------------------------------------------------------------
$created = call('POST', '/api/tasks', ['title' => 'Задача для проверки прав', 'project_id' => 1], $developer);
expectStatus('developer создаёт задачу', 201, $created);
$id = $created['json']['id'] ?? 0;
expectStatus('developer удаляет задачу', 403, call('DELETE', "/api/tasks/$id", null, $developer));
expectStatus('developer создаёт проект', 403, call('POST', '/api/projects', ['title' => 'Чужой проект'], $developer));
expectStatus('developer удаляет проект', 403, call('DELETE', '/api/projects/3', null, $developer));
expectStatus('developer создаёт участника', 403, call('POST', '/api/users', ['name' => 'Хакер', 'email' => 'h@example.com', 'password' => '123456', 'role' => 'manager'], $developer));
$r = call('GET', '/api/users', null, $developer);
expectStatus('developer смотрит список участников', 200, $r);
check('в списке участников НЕТ хешей паролей', !str_contains($r['body'], 'password_hash'));
expectStatus('manager удаляет задачу', 204, call('DELETE', "/api/tasks/$id", null, $manager));

// ---------------------------------------------------------------------
section('4. Задачи: полный цикл (создание → поиск → изменение → удаление)');
// ---------------------------------------------------------------------
$r = call('POST', '/api/tasks', ['title' => 'Проверить интеграцию', 'description' => 'Сквозной сценарий', 'project_id' => 2, 'priority' => 'high', 'due_date' => '2026-12-01'], $manager);
expectStatus('создание задачи', 201, $r);
check('в ответе есть Location', str_starts_with($r['headers']['location'] ?? '', '/api/tasks/'));
check('статус по умолчанию — new', ($r['json']['status'] ?? '') === 'new');
check('описание сохранилось', ($r['json']['description'] ?? '') === 'Сквозной сценарий');
$id = $r['json']['id'] ?? 0;

$r = call('GET', '/api/tasks?q=' . rawurlencode('ИНТЕГРАЦИЮ'), null, $manager);   // русские буквы в адресе кодируются
check('поиск по части названия без учёта регистра находит задачу', count(rows($r)) === 1 && (rows($r)[0]['id'] ?? 0) === $id);
$r = call('GET', '/api/tasks?q=' . rawurlencode("' OR 1=1 --"), null, $manager);
check('SQL-инъекция в поиске не срабатывает (ничего не найдено, запрос не сломан)', $r['status'] === 200 && rows($r) === [] && is_array($r['json']));
$r = call('GET', '/api/tasks?q=' . rawurlencode('%'), null, $manager);
check('знак % в поиске ищется как обычный символ, а не «любые символы»', $r['status'] === 200 && rows($r) === [] && is_array($r['json']));
$r = call('GET', '/api/tasks?sort=-id', null, $manager);
check('sort=-id: новая задача первая', (rows($r)[0]['id'] ?? 0) === $id);
$r = call('GET', '/api/tasks?status=done', null, $manager);
check('фильтр status=done оставляет только выполненные', count(rows($r)) > 0 && count(array_filter(rows($r), fn($t) => $t['status'] !== 'done')) === 0);

$r = call('PATCH', "/api/tasks/$id", ['status' => 'done'], $developer);
expectStatus('PATCH: отметить выполненной', 200, $r);
check('изменился только статус', ($r['json']['status'] ?? '') === 'done' && ($r['json']['title'] ?? '') === 'Проверить интеграцию');

$r = call('POST', '/api/tasks', ['title' => 'Да', 'project_id' => 77, 'status' => 'x'], $manager);
expectStatus('создание с неверными данными', 422, $r);
check('в ответе 422 перечислены поля title, project_id, status',
    isset($r['json']['errors']['title'], $r['json']['errors']['project_id'], $r['json']['errors']['status']));

expectStatus('удаление задачи', 204, call('DELETE', "/api/tasks/$id", null, $manager));
expectStatus('повторное удаление', 404, call('DELETE', "/api/tasks/$id", null, $manager));
expectStatus('GET удалённой задачи', 404, call('GET', "/api/tasks/$id", null, $manager));

// ---------------------------------------------------------------------
section('5. Проекты и участники');
// ---------------------------------------------------------------------
expectStatus('удаление проекта с задачами', 409, call('DELETE', '/api/projects/1', null, $manager));
$r = call('POST', '/api/users', ['name' => 'Новый Сотрудник', 'email' => 'new@example.com', 'password' => 'secret1', 'role' => 'tester'], $manager);
expectStatus('manager создаёт участника', 201, $r);
check('в ответе нет пароля и хеша', !isset($r['json']['password']) && !isset($r['json']['password_hash']));
expectStatus('участник с повторной почтой', 409, call('POST', '/api/users', ['name' => 'Двойник', 'email' => 'NEW@example.com', 'password' => 'secret1', 'role' => 'tester'], $manager));
expectStatus('участник со слабым паролем', 422, call('POST', '/api/users', ['name' => 'Слабый', 'email' => 'weak@example.com', 'password' => '123', 'role' => 'tester'], $manager));
check('новый участник входит со своим паролем', strlen(login('new@example.com', 'secret1')) === 64);

// ---------------------------------------------------------------------
section('6. Завершение сессии и срок действия токена');
// ---------------------------------------------------------------------
$token = login('maria@example.com');
expectStatus('токен работает до выхода', 200, call('GET', '/api/tasks', null, $token));
expectStatus('POST /api/auth/logout', 204, call('POST', '/api/auth/logout', null, $token));
expectStatus('после выхода токен не работает', 401, call('GET', '/api/tasks', null, $token));

$token = login('maria@example.com');
// «Состарим» все токены прямо в базе: срок вышел час назад (тест подключается к той же базе, что и сервер)
define('BASE_PATH', $root);
define('APP_PATH', $root . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';
try {
    App\Core\Database::connection()->exec("UPDATE auth_tokens SET expires_at = now() - interval '1 hour'");
} catch (Throwable $e) {
    check('подключение к базе данных из теста', false, $e->getMessage());
}
expectStatus('токен с истёкшим сроком', 401, call('GET', '/api/tasks', null, $token));

// ---------------------------------------------------------------------
section('7. Дополнительные проверки');
// ---------------------------------------------------------------------
$manager = login('anna@example.com');                      // предыдущий раздел «состарил» все токены: входим заново
$tester = login('maria@example.com');

$r = call('GET', '/api/projects/2/tasks', null, $manager);
expectStatus('задачи проекта 2', 200, $r);
check('у проекта 2 три задачи', count(rows($r)) === 3);
$r = call('GET', '/api/tasks?project_id=2', null, $manager);
check('фильтр project_id=2 возвращает три задачи', count(rows($r)) === 3);
expectStatus('PATCH несуществующей задачи', 404, call('PATCH', '/api/tasks/9999', ['status' => 'done'], $manager));
expectStatus('вход с почтой в другом регистре (ANNA@example.com)', 200, call('POST', '/api/auth/login', ['email' => 'ANNA@example.com', 'password' => '123456']));
expectStatus('tester не может удалять задачи', 403, call('DELETE', '/api/tasks/1', null, $tester));
$titles = array_column(rows(call('GET', '/api/tasks?sort=title', null, $manager)), 'title');
$sorted = $titles;
usort($sorted, fn(string $a, string $b): int => $a <=> $b);
check('sort=title: названия идут по порядку', $titles === $sorted);

// ---------------------------------------------------------------------
echo "\nИтого: $passed прошло, $failed не прошло\n";
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/reset-db.php') . ' > ' . $devNull);   // убираем за собой
exit($failed === 0 ? 0 : 1);
