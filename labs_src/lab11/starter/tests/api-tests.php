<?php
// tests/api-tests.php — автоматические тесты API (комплексная проверка серверной части: вход, права, кеш в Redis, очередь и воркер).
//
// Запуск (сервер должен быть запущен: php -S localhost:8000 -t public):
//     php tests/api-tests.php
//     php tests/api-tests.php http://localhost:8000     # другой адрес сервера
//     php tests/api-tests.php --skip-reports            # пропустить раздел 12 (отчёт), если вы выбрали для второй операции не отчёт
// Тесты сами запускают воркер (bin/worker.php --until-empty) и сами очищают очередь, поэтому отдельный воркер на время тестов
// лучше остановить: иначе он заберёт задачи раньше теста.
//
// Тест — это запрос и проверка ответа. Каждая проверка печатает PASS (прошла) или FAIL (не прошла) и пояснение.
// В начале база данных возвращается к исходному состоянию (bin/reset-db.php: заодно очищается кеш в Redis),
// поэтому тесты можно запускать сколько угодно раз. Тесты рассчитаны на небольшую базу (7 задач): после
// php bin/load-bigdata.php сначала выполните php bin/reset-db.php (тесты делают это сами в начале).
// Код завершения 0 — все проверки прошли, 1 — есть ошибки (удобно для автоматического запуска).

declare(strict_types=1);

$args = array_values(array_filter(array_slice($argv, 1), fn(string $a): bool => !str_starts_with($a, '--')));
$skipReports = in_array('--skip-reports', $argv, true);
$base = rtrim($args[0] ?? 'http://localhost:8000', '/');
$root = dirname(__DIR__);

// Подключаем код приложения: тесту нужны база данных и Redis (чтобы «состарить» токены и заглянуть в кеш)
define('BASE_PATH', $root);
define('APP_PATH', $root . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

// ---------------------------------------------------------------------
// Инструменты теста (ГОТОВО)
// ---------------------------------------------------------------------

// Отправляет запрос. Возвращает ['status' => 200, 'headers' => [...], 'json' => [...], 'body' => '...'].
function call(string $method, string $path, ?array $body = null, ?string $token = null, ?string $rawBody = null, array $extraHeaders = []): array
{
    global $base;
    $headers = ['Accept: application/json', ...$extraHeaders];
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

// Запускает воркер, который выполняет всё, что есть в очереди, и выходит (ГОТОВО). Паузы «отправки» и повторов сокращены до нуля,
// чтобы тесты шли быстро. Возвращает журнал воркера (его вывод).
function runWorker(): string
{
    global $root;
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/worker.php') . ' --until-empty';
    $env = array_merge(getenv(), ['NOTIFY_SECONDS' => '0', 'REPORT_SECONDS' => '0', 'QUEUE_BACKOFF' => '0']);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    $log = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return $log;
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
section('8. Кеширование в Redis');
// ---------------------------------------------------------------------
$redis = App\Core\Cache::redis();                          // то же соединение, что у приложения (ключи с префиксом taskapp:)
if ($redis === null) {
    check('Redis доступен тесту (php bin/redis-check.php)', false, 'не удалось подключиться к Redis');
} else {
    $manager = login('anna@example.com');                  // раздел 6 «состарил» токены
    $hit = fn(array $r): string => $r['headers']['x-cache'] ?? '-';   // значение заголовка X-Cache: HIT, MISS или BYPASS
    $sum = fn(array $r): int => array_sum(array_column(rows($r), 'total'));
    App\Core\Cache::flushApp();

    // --- справочник проектов
    $a = call('GET', '/api/projects', null, $manager);
    $b = call('GET', '/api/projects', null, $manager);
    check('проекты: первый запрос — MISS, второй — HIT', $hit($a) === 'MISS' && $hit($b) === 'HIT', 'получено ' . $hit($a) . ', ' . $hit($b));
    check('проекты: данные из кеша совпадают с данными из базы', $a['json'] === $b['json'] && count(rows($a)) === 3);
    $ttl = $redis->ttl('projects:all');
    check('у ключа projects:all есть время жизни: больше 0 и не больше 300 с', $ttl > 0 && $ttl <= 300, "ttl = $ttl");
    expectStatus('кеш не отменяет проверку входа: без токена', 401, call('GET', '/api/projects'));

    // --- карточка задачи и инвалидация при изменении
    $a = call('GET', '/api/tasks/2', null, $manager);
    $b = call('GET', '/api/tasks/2', null, $manager);
    check('карточка: первый запрос — MISS, второй — HIT', $hit($a) === 'MISS' && $hit($b) === 'HIT', 'получено ' . $hit($a) . ', ' . $hit($b));
    call('PATCH', '/api/tasks/2', ['title' => 'Название после изменения'], $manager);
    $c = call('GET', '/api/tasks/2', null, $manager);
    check('после PATCH карточка пересоздана (MISS) и содержит новое название', $hit($c) === 'MISS' && ($c['json']['title'] ?? '') === 'Название после изменения', 'получено ' . $hit($c) . ', ' . ($c['json']['title'] ?? ''));

    // --- список задач
    $a = call('GET', '/api/tasks?sort=-id', null, $manager);
    $b = call('GET', '/api/tasks?sort=-id', null, $manager);
    check('список: первый запрос — MISS, второй — HIT', $hit($a) === 'MISS' && $hit($b) === 'HIT', 'получено ' . $hit($a) . ', ' . $hit($b));
    check('X-Total-Count одинаков при MISS и HIT и равен 7', ($a['headers']['x-total-count'] ?? '') === '7' && ($b['headers']['x-total-count'] ?? '') === '7');
    $r1 = call('GET', '/api/tasks?q=' . rawurlencode('экран'), null, $manager);
    $r2 = call('GET', '/api/tasks?q=' . rawurlencode('уведомл'), null, $manager);
    check('разные запросы списка имеют разные ключи (оба — MISS)', $hit($r1) === 'MISS' && $hit($r2) === 'MISS' && $r1['json'] !== $r2['json']);

    // --- отчёт и инвалидация при добавлении задачи
    $a = call('GET', '/api/reports/summary', null, $manager);
    $b = call('GET', '/api/reports/summary', null, $manager);
    check('отчёт: первый запрос — MISS, второй — HIT', $hit($a) === 'MISS' && $hit($b) === 'HIT', 'получено ' . $hit($a) . ', ' . $hit($b));
    $before = $sum($a);
    $created = call('POST', '/api/tasks', ['title' => 'Задача для проверки кеша', 'project_id' => 1], $manager);
    $newId = $created['json']['id'] ?? 0;
    $list = call('GET', '/api/tasks?sort=-id', null, $manager);
    check('после POST список пересоздан (MISS), новая задача — первая', $hit($list) === 'MISS' && (rows($list)[0]['id'] ?? 0) === $newId);
    $report = call('GET', '/api/reports/summary', null, $manager);
    check('после POST отчёт пересчитан (MISS): задач стало на одну больше', $hit($report) === 'MISS' && $sum($report) === $before + 1, "было $before, стало " . $sum($report));
    check('в Redis появилась версия списков tasks:version', (int) $redis->get('tasks:version') >= 1);

    // --- удаление и проекты
    expectStatus('DELETE задачи', 204, call('DELETE', "/api/tasks/$newId", null, $manager));
    $gone = call('GET', "/api/tasks/$newId", null, $manager);
    expectStatus('после DELETE карточка не «залипла» в кеше', 404, $gone);
    call('POST', '/api/projects', ['title' => 'Проект для проверки кеша'], $manager);
    $projects = call('GET', '/api/projects', null, $manager);
    check('после POST проекта список проектов пересоздан (MISS) и содержит новый', $hit($projects) === 'MISS' && count(rows($projects)) === 4);

    // --- режим измерений и срок жизни
    $bypass = call('GET', '/api/projects', null, $manager, null, ['X-Cache-Mode: off']);
    check('заголовок X-Cache-Mode: off обходит кеш (BYPASS)', $hit($bypass) === 'BYPASS', 'получено ' . $hit($bypass));
    call('GET', '/api/projects', null, $manager);
    $redis->expire('projects:all', 1);                     // «состарим» ключ: через секунду Redis его удалит
    sleep(2);
    $after = call('GET', '/api/projects', null, $manager);
    check('по истечении времени жизни ключ удалён, следующий запрос — MISS', $hit($after) === 'MISS', 'получено ' . $hit($after));
}

// ---------------------------------------------------------------------
section('9. Свои проверки кеша');
// ---------------------------------------------------------------------
$deleted = call('POST', '/api/tasks', ['title' => 'Задача для удаления', 'project_id' => 2], $manager);
$deletedId = $deleted['json']['id'] ?? 0;
call('GET', "/api/tasks/$deletedId", null, $manager);      // попала в кеш
call('DELETE', "/api/tasks/$deletedId", null, $manager);
expectStatus('после удаления задача не отдаётся из кеша', 404, call('GET', "/api/tasks/$deletedId", null, $manager));

$p = call('GET', '/api/tasks?project_id=2', null, $manager);
$q = call('GET', '/api/tasks?project_id=3', null, $manager);
check('списки разных проектов не смешиваются', count(rows($p)) === 3 && count(rows($q)) === 1);

call('PATCH', '/api/projects/1', ['description' => 'Изменённое описание'], $manager);
$reportAfter = call('GET', '/api/reports/summary', null, $manager);
check('после изменения проекта отчёт пересчитан (MISS)', ($reportAfter['headers']['x-cache'] ?? '') === 'MISS');

call('GET', '/api/projects', null, $manager);              // снова положили проекты в кеш
$ttlList = $redis->ttl('projects:all');
check('у списка проектов в Redis есть время жизни', $ttlList > 0 && $ttlList <= 300, "ttl = $ttlList");

// ---------------------------------------------------------------------
section('10. Очередь: уведомление при смене статуса (шаги 1–3)');
// ---------------------------------------------------------------------
use App\Core\Queue;

$manager = login('anna@example.com');                      // токены разделов 6–7 могли быть отозваны: входим заново
$developer = login('igor@example.com');
Queue::flush();                                            // начинаем с пустой очереди
$started = microtime(true);
$r = call('PATCH', '/api/tasks/2', ['status' => 'done'], $developer);   // Игорь завершает задачу № 2
$ms = (microtime(true) - $started) * 1000;
expectStatus('PATCH статуса задачи', 200, $r);
check('ответ пришёл быстро (меньше 1,5 с), хотя «отправка уведомления» занимает 2 с', $ms < 1500, round($ms) . ' мс');
check('в ответе есть заголовок X-Job-Id (16 символов)', strlen($r['headers']['x-job-id'] ?? '') === 16, 'получено «' . ($r['headers']['x-job-id'] ?? '') . '»');
check('задача лежит в очереди', Queue::stats()['waiting'] === 1, 'в очереди ' . Queue::stats()['waiting']);
check('уведомления ещё нет: его создаст воркер', count(rows(call('GET', '/api/notifications', null, $manager))) === 0);

$log = runWorker();
$s = Queue::stats();
check('воркер выполнил задачу: очередь, «в работе» и «мёртвые» пусты', $s['waiting'] === 0 && $s['processing'] === 0 && $s['dead'] === 0, json_encode($s));
$notes = rows(call('GET', '/api/notifications', null, $manager));
check('руководитель (Анна) получила уведомление', count($notes) === 1 && str_contains($notes[0]['message'] ?? '', 'Игорь'), 'уведомлений: ' . count($notes));
check('автор изменения (Игорь) уведомление не получил', count(rows(call('GET', '/api/notifications', null, $developer))) === 0);
expectStatus('GET /api/notifications без токена', 401, call('GET', '/api/notifications'));

$r = call('PATCH', '/api/tasks/2', ['status' => 'in_progress'], $developer, null, ['X-Queue-Mode: sync']);
check('режим sync: задача выполнена прямо в запросе (X-Job-Id: sync)', ($r['headers']['x-job-id'] ?? '') === 'sync', 'получено «' . ($r['headers']['x-job-id'] ?? '') . '»');
check('режим sync: очередь пуста, а уведомление уже есть', Queue::stats()['waiting'] === 0 && count(rows(call('GET', '/api/notifications', null, $manager))) === 2);

// ---------------------------------------------------------------------
section('11. Воркер: ошибки, повторы и восстановление после сбоя (шаг 3)');
// ---------------------------------------------------------------------
Queue::flush();
Queue::push('no_such_type', ['x' => 1]);                   // задача неизвестного типа: воркер не сможет её выполнить
$log = runWorker();
$s = Queue::stats();
check('неудачная задача после всех попыток ушла в «мёртвую» очередь', $s['dead'] === 1 && $s['waiting'] === 0 && $s['processing'] === 0 && $s['delayed'] === 0, json_encode($s));
$dead = Queue::dead()[0] ?? [];
check('в «мёртвой» задаче записаны число попыток (3) и текст ошибки', ($dead['attempts'] ?? 0) === 3 && str_contains($dead['error'] ?? '', 'Неизвестный тип'), json_encode($dead, JSON_UNESCAPED_UNICODE));
check('воркер не остановился из-за ошибки и записал повторы в журнал', substr_count($log, 'повтор позже') === 2 && str_contains($log, 'воркер остановлен'));

Queue::flush();
Queue::push('demo', ['seconds' => 0, 'succeed_on' => 3]);   // «нестабильная» задача: получается только с третьей попытки
$log = runWorker();
$s = Queue::stats();
check('нестабильная задача выполнилась с третьей попытки (в мёртвую очередь не попала)',
    substr_count($log, 'готово за') === 1 && $s['dead'] === 0 && $s['waiting'] === 0 && $s['delayed'] === 0 && $s['processing'] === 0, json_encode($s));

Queue::flush();
Queue::push('demo', ['seconds' => 0]);
Queue::reserve('default', 1);                              // «воркер взял задачу и умер»: она остаётся в списке «в работе»
$s = Queue::stats();
check('после «падения воркера» задача осталась в списке «в работе»', $s['processing'] === 1 && $s['waiting'] === 0, json_encode($s));
$log = runWorker();
$s = Queue::stats();
check('новый воркер вернул её в очередь и выполнил', str_contains($log, 'возвращено в очередь') && str_contains($log, 'готово за') && $s['processing'] === 0 && $s['waiting'] === 0 && $s['dead'] === 0, json_encode($s));

putenv('NOTIFY_SECONDS=0');                                // не ждать 2 секунды в проверке ниже
$payload = ['task_id' => 2, 'title' => 'Проверка', 'old_status' => 'new', 'new_status' => 'done', 'actor_id' => 2, 'actor_name' => 'Игорь'];
(new App\Jobs\NotificationJob())->handle($payload, 'testjob00000001', 1);
(new App\Jobs\NotificationJob())->handle($payload, 'testjob00000001', 2);   // та же задача выполнена ПОВТОРНО (например, после сбоя)
$count = (int) App\Core\Database::connection()->query("SELECT COUNT(*) FROM notifications WHERE job_id = 'testjob00000001'")->fetchColumn();
check('идемпотентность: повторное выполнение не создаёт второго уведомления', $count === 1, "уведомлений: $count");

// ---------------------------------------------------------------------
if ($skipReports) {
    echo "\n12. Отчёт: пропущен (--skip-reports)\n";
} else {
    section('12. Отчёт в фоне: POST → 202 → воркер → готовый файл (шаг 4)');
    // -----------------------------------------------------------------
    Queue::flush();
    $r = call('POST', '/api/reports', [], $manager);
    expectStatus('POST /api/reports', 202, $r);
    check('в ответе есть Location', str_starts_with($r['headers']['location'] ?? '', '/api/reports/'));
    check('статус новой заявки — queued', ($r['json']['status'] ?? '') === 'queued', 'получено «' . ($r['json']['status'] ?? '') . '»');
    check('задача на построение отчёта лежит в очереди', Queue::stats()['waiting'] === 1);
    $reportId = $r['json']['id'] ?? 0;

    $r = call('GET', "/api/reports/$reportId", null, $manager);
    check('до запуска воркера статус заявки — queued', $r['status'] === 200 && ($r['json']['status'] ?? '') === 'queued');
    expectStatus('скачать неготовый отчёт', 409, call('GET', "/api/reports/$reportId/download", null, $manager));

    runWorker();
    $r = call('GET', "/api/reports/$reportId", null, $manager);
    $total = (int) App\Core\Database::connection()->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
    check('после воркера статус — done, число строк равно числу задач', ($r['json']['status'] ?? '') === 'done' && ($r['json']['rows_count'] ?? -1) === $total, json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    $file = call('GET', "/api/reports/$reportId/download", null, $manager);
    expectStatus('скачивание готового отчёта', 200, $file);
    check('файл — CSV: есть строка заголовка и по строке на каждую задачу',
        str_contains($file['body'], 'id;project;title;status;priority;due_date') && count(array_filter(explode("\n", trim($file['body'])))) === $total + 1);

    $mine = call('POST', '/api/reports', [], $developer);
    $mineId = $mine['json']['id'] ?? 0;
    expectStatus('чужую заявку на отчёт смотреть нельзя (Игорь смотрит заявку Анны)', 403, call('GET', "/api/reports/$reportId", null, $developer));
    expectStatus('руководитель смотрит заявку подчинённого', 200, call('GET', "/api/reports/$mineId", null, $manager));
    expectStatus('несуществующая заявка', 404, call('GET', '/api/reports/9999', null, $manager));
    expectStatus('POST /api/reports без токена', 401, call('POST', '/api/reports', []));
    Queue::flush();
}

// ---------------------------------------------------------------------
section('13. Свои проверки очереди');
// ---------------------------------------------------------------------
// TODO 5.2  Свои проверки. Добавьте не меньше двух своих проверок очереди, например:
//             • правка названия БЕЗ смены статуса не ставит задачу в очередь (Queue::stats()['waiting'] остаётся прежним);
//             • после смены статуса и запуска воркера в тексте уведомления есть название задачи;
//             • для своей операции (вариант B или C): задача ставится, воркер выполняет её, результат виден в базе или в API.
//           Используйте готовые инструменты: call(), check(), expectStatus(), runWorker() и Queue::stats().
//           Подсказка:
//              Queue::flush();
//              call('PATCH', '/api/tasks/3', ['title' => 'Новое название без смены статуса'], $developer);
//              check('правка без смены статуса не ставит задачу в очередь', Queue::stats()['waiting'] === 0);

// ---------------------------------------------------------------------
echo "\nИтого: $passed прошло, $failed не прошло\n";
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/reset-db.php') . ' > ' . $devNull);   // убираем за собой
exit($failed === 0 ? 0 : 1);
