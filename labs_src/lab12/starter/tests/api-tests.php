<?php
// tests/api-tests.php — автоматические тесты API (комплексная проверка серверной части: вход, права, кеш в Redis, очередь и воркер, журнал событий).
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
    for ($attempt = 1; $text === false && $attempt <= 2; $attempt++) {      // встроенный сервер PHP изредка обрывает соединение: пробуем ещё раз
        usleep(300_000);
        $text = @file_get_contents($base . $path, false, $context);
    }
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
Queue::flush();
call('PATCH', '/api/tasks/3', ['title' => 'Новое название без смены статуса'], $developer);
check('правка без смены статуса не ставит задачу в очередь', Queue::stats()['waiting'] === 0, 'в очереди ' . Queue::stats()['waiting']);

$before = Queue::stats();
call('PATCH', '/api/tasks/3', ['status' => 'done'], $developer);
check('смена статуса ставит ровно одну задачу', Queue::stats()['waiting'] === $before['waiting'] + 1);
runWorker();
$notes = rows(call('GET', '/api/notifications', null, $manager));
check('в тексте уведомления есть название задачи', str_contains($notes[0]['message'] ?? '', 'Новое название без смены статуса'));
Queue::flush();

// ---------------------------------------------------------------------
section('14. Журнал событий: запись (шаги 1–2)');
// ---------------------------------------------------------------------
$db = App\Core\Database::connection();
$countEvents = fn(string $type): int => (int) $db->query("SELECT COUNT(*) FROM events WHERE type = " . $db->quote($type))->fetchColumn();
$lastEvent = fn(string $type): array => $db->query("SELECT *, details::text AS details_text FROM events WHERE type = " . $db->quote($type) . " ORDER BY id DESC LIMIT 1")->fetch() ?: [];
$userId = fn(string $email): int => (int) $db->query("SELECT id FROM users WHERE email = " . $db->quote($email))->fetchColumn();

$secret = 'Секрет-для-проверки-журнала-777';
$before = $countEvents('login_failed');
$r = call('POST', '/api/auth/login', ['email' => 'igor@example.com', 'password' => $secret]);
expectStatus('вход с неверным паролем', 401, $r);
$e = $lastEvent('login_failed');
check('неудачный вход записан в журнал (login_failed)', $countEvents('login_failed') === $before + 1, 'событий было ' . $before . ', стало ' . $countEvents('login_failed'));
check('в событии есть email и причина, а пароля нет нигде в записи', str_contains($e['details_text'] ?? '', 'igor@example.com') && str_contains($e['details_text'] ?? '', 'wrong_password') && !str_contains(json_encode($e, JSON_UNESCAPED_UNICODE), $secret));
check('номер запроса в событии совпадает с заголовком ответа X-Request-Id', ($e['request_id'] ?? '') !== '' && ($e['request_id'] ?? '') === ($r['headers']['x-request-id'] ?? 'нет'), 'в событии «' . ($e['request_id'] ?? '') . '», в заголовке «' . ($r['headers']['x-request-id'] ?? '') . '»');
check('участник известен (Игорь) — user_id заполнен, категория auth', (int) ($e['user_id'] ?? 0) === $userId('igor@example.com') && ($e['category'] ?? '') === 'auth');
call('POST', '/api/auth/login', ['email' => 'nobody@example.com', 'password' => $secret]);
$e = $lastEvent('login_failed');
check('неизвестный email: событие записано, user_id пустой', str_contains($e['details_text'] ?? '', 'unknown_email') && $e['user_id'] === null);

$before = $countEvents('login');
$r = call('POST', '/api/auth/login', ['email' => 'igor@example.com', 'password' => '123456']);
$igor = $r['json']['token'] ?? '';
$e = $lastEvent('login');
check('успешный вход записан (login), user_id — Игорь', $countEvents('login') === $before + 1 && (int) ($e['user_id'] ?? 0) === $userId('igor@example.com'));
check('в событии входа нет токена и хеша пароля', $igor !== '' && !str_contains(json_encode($e, JSON_UNESCAPED_UNICODE), $igor) && !str_contains(json_encode($e), '$2y$'));
$before = $countEvents('logout');
expectStatus('выход', 204, call('POST', '/api/auth/logout', null, $igor));
$e = $lastEvent('logout');
check('выход записан (logout), user_id — Игорь', $countEvents('logout') === $before + 1 && (int) ($e['user_id'] ?? 0) === $userId('igor@example.com'));

$manager = login('anna@example.com');
$annaId = $userId('anna@example.com');
$r = call('POST', '/api/tasks', ['title' => 'Задача для журнала', 'project_id' => 1], $manager);
$taskId = $r['json']['id'] ?? 0;
$e = $lastEvent('task_created');
check('создание задачи записано: task_created, номер задачи, автор — Анна', ($e['entity_type'] ?? '') === 'task' && (int) ($e['entity_id'] ?? 0) === $taskId && (int) ($e['user_id'] ?? 0) === $annaId && ($e['category'] ?? '') === 'data');
call('PATCH', "/api/tasks/$taskId", ['status' => 'done', 'priority' => 'high'], $manager);
$e = $lastEvent('task_updated');
$changed = json_decode($e['details_text'] ?? '{}', true)['changed'] ?? [];
check('изменение записано: task_updated, в details перечислены изменённые поля (status и priority)', (int) ($e['entity_id'] ?? 0) === $taskId && in_array('status', $changed, true) && in_array('priority', $changed, true) && !in_array('title', $changed, true), json_encode($changed));
expectStatus('удаление задачи', 204, call('DELETE', "/api/tasks/$taskId", null, $manager));
$e = $lastEvent('task_deleted');
check('удаление записано: task_deleted, в details название удалённой задачи', (int) ($e['entity_id'] ?? 0) === $taskId && str_contains($e['details_text'] ?? '', 'Задача для журнала'));
$failedDelete = $countEvents('task_deleted');
call('DELETE', "/api/tasks/$taskId", null, $manager);
check('неудавшееся действие (удаление уже удалённой задачи, 404) событием не записывается', $countEvents('task_deleted') === $failedDelete);

$r = call('POST', '/api/projects', ['title' => 'Проект для журнала'], $manager);
$projectId = $r['json']['id'] ?? 0;
check('создание проекта записано (project_created)', (int) ($lastEvent('project_created')['entity_id'] ?? 0) === $projectId);
call('PATCH', "/api/projects/$projectId", ['description' => 'Новое описание'], $manager);
check('изменение проекта записано (project_updated)', (int) ($lastEvent('project_updated')['entity_id'] ?? 0) === $projectId);
call('DELETE', "/api/projects/$projectId", null, $manager);
check('удаление проекта записано (project_deleted)', (int) ($lastEvent('project_deleted')['entity_id'] ?? 0) === $projectId);

$before = $countEvents('server_error');
$r = call('GET', '/api/debug/error', null, $manager);
expectStatus('учебная ошибка сервера', 500, $r);
$e = $lastEvent('server_error');
check('ошибка записана (server_error): категория system, участник — тот, кто делал запрос (Анна), в details путь запроса', $countEvents('server_error') === $before + 1 && ($e['category'] ?? '') === 'system' && (int) ($e['user_id'] ?? 0) === $annaId && str_contains($e['details_text'] ?? '', '/api/debug/error'));
check('у ответа 500 тоже есть X-Request-Id, и он совпадает с событием', ($r['headers']['x-request-id'] ?? '') === ($e['request_id'] ?? 'нет'));

$total = (int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn();
$oldLog = ini_set('error_log', $devNull);                     // сообщение об ошибке записи в этой проверке ожидаемо: в вывод тестов его не пускаем
App\Core\Events::log('no_such_type', 'неизвестный тип');
ini_set('error_log', (string) $oldLog);
check('неизвестный тип события не записывается и не роняет приложение', (int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn() === $total);
App\Core\Events::log('login', 'Проверка очистки секретов', ['user_id' => null, 'details' => ['password' => 'пароль-П1', 'a' => ['TOKEN' => 'токен-Т1', 'b' => 2]]]);
$e = $lastEvent('login');
check('секретные ключи (password, token, в том числе вложенные и в любом регистре) в журнал не попадают', ($e['description'] ?? '') === 'Проверка очистки секретов' && !str_contains($e['details_text'], 'пароль-П1') && !str_contains($e['details_text'], 'токен-Т1') && str_contains($e['details_text'], '"b": 2'), $e['details_text'] ?? '');
$wrong = $db->query("SELECT type, category FROM events GROUP BY type, category")->fetchAll();
$bad = array_filter($wrong, fn($row) => (App\Core\Events::TYPES[$row['type']] ?? null) !== $row['category']);
check('у каждого типа события правильная категория', $wrong !== [] && $bad === []);

// ---------------------------------------------------------------------
section('15. Журнал событий: просмотр через API (шаг 3)');
// ---------------------------------------------------------------------
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/seed-events.php') . ' --count=3000 --days=30 --clear > ' . $devNull);
$manager = login('anna@example.com');
$developer = login('igor@example.com');
$day = fn(string $sql): string => (string) $db->query("SELECT ($sql)::text")->fetchColumn();   // даты берём у самой базы: так совпадают границы дней
$ev = fn(string $query, ?string $token = null): array => call('GET', '/api/events' . $query, null, $token ?? $manager);

expectStatus('GET /api/events без токена', 401, call('GET', '/api/events'));
expectStatus('GET /api/events под разработчиком (журнал только для руководителя)', 403, call('GET', '/api/events', null, $developer));
$r = $ev('?limit=5');
expectStatus('GET /api/events под руководителем', 200, $r);
check('limit=5 вернул пять записей', count(rows($r)) === 5, 'получено ' . count(rows($r)));
check('в заголовке X-Total-Count — общее число событий в журнале', (int) ($r['headers']['x-total-count'] ?? -1) === (int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn());
$times = array_column(rows($r), 'occurred_at');
$sorted = $times; rsort($sorted);
check('по умолчанию новые события сверху', $times === $sorted && count($times) === 5);
$asc = array_column(rows($ev('?limit=5&sort=time')), 'occurred_at');
$ascSorted = $asc; sort($ascSorted);
check('sort=time: старые сверху', $asc !== [] && $asc === $ascSorted && $asc[0] < ($times[0] ?? ''));
check('у события есть имя участника, категория и details в виде объекта', isset(rows($r)[0]['user_name'], rows($r)[0]['category']) && is_array(rows($r)[0]['details']) && !str_contains($r['body'], 'password_hash'));

$r = $ev('?type=login&limit=500');
$dbCount = (int) $db->query("SELECT COUNT(*) FROM events WHERE type = 'login'")->fetchColumn();
check('фильтр type=login: только входы, общее число совпадает с базой', rows($r) !== [] && count(array_filter(rows($r), fn($x) => $x['type'] !== 'login')) === 0 && (int) $r['headers']['x-total-count'] === $dbCount, 'в базе ' . $dbCount . ', в заголовке ' . ($r['headers']['x-total-count'] ?? '?'));
$igorId = $userId('igor@example.com');
$r = $ev("?user_id=$igorId&limit=500");
$dbCount = (int) $db->query("SELECT COUNT(*) FROM events WHERE user_id = $igorId")->fetchColumn();
check('фильтр user_id: только события этого участника, число совпадает с базой', rows($r) !== [] && count(array_filter(rows($r), fn($x) => $x['user_id'] !== $igorId)) === 0 && (int) $r['headers']['x-total-count'] === $dbCount);
$from = $day('current_date - 5');
$to = $day('current_date - 3');
$r = $ev("?from=$from&to=$to&limit=500");
$dbCount = (int) $db->query("SELECT COUNT(*) FROM events WHERE occurred_at >= '$from'::date AND occurred_at < '$to'::date + 1")->fetchColumn();
$inside = array_filter(rows($r), fn($x) => substr($x['occurred_at'], 0, 10) >= $from && substr($x['occurred_at'], 0, 10) <= $to);
check('фильтр по периоду from–to (обе даты включительно)', rows($r) !== [] && count($inside) === count(rows($r)) && (int) $r['headers']['x-total-count'] === $dbCount, "в базе $dbCount, в заголовке " . ($r['headers']['x-total-count'] ?? '?'));
$r = $ev("?type=task_updated&user_id=$igorId&from=$from");
$dbCount = (int) $db->query("SELECT COUNT(*) FROM events WHERE type = 'task_updated' AND user_id = $igorId AND occurred_at >= '$from'::date")->fetchColumn();
check('фильтры можно сочетать', (int) $r['headers']['x-total-count'] === $dbCount && $dbCount > 0);
$r = $ev('?type=login&user_id=999999');
check('нет подходящих событий: пустой список и X-Total-Count: 0 (не ошибка)', $r['status'] === 200 && rows($r) === [] && ($r['headers']['x-total-count'] ?? '') === '0');

$all = array_column(rows($ev('?limit=20')), 'id');
$page = array_column(rows($ev('?limit=10&offset=10')), 'id');
check('постраничный вывод: limit=10&offset=10 — это записи 11–20 общего списка', count($page) === 10 && $page === array_slice($all, 10, 10));
check('limit больше 500 урезается до 500', count(rows($ev('?limit=100000'))) === 500);

$r = $ev('?type=wrong');
check('неизвестный тип события: 422 и ошибка в поле type', $r['status'] === 422 && isset($r['json']['errors']['type']));
$r = $ev('?from=2026-13-45');
check('несуществующая дата: 422 и ошибка в поле from', $r['status'] === 422 && isset($r['json']['errors']['from']));
check('неверная сортировка: 422', $ev('?sort=id')['status'] === 422);
check('номер участника не число: 422', $ev('?user_id=abc')['status'] === 422);
$r = $ev('?type=' . rawurlencode("login' OR '1'='1"));
check('SQL-инъекция в параметре type не срабатывает (422)', $r['status'] === 422);
$r = $ev('?from=' . rawurlencode("2026-01-01'; DROP TABLE events; --"));
check('SQL-инъекция в параметре from не срабатывает, таблица на месте', $r['status'] === 422 && (int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn() > 0);

// ---------------------------------------------------------------------
section('16. Журнал событий: аналитические запросы (шаг 4)');
// ---------------------------------------------------------------------
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/analytics.php') . ' --json 2>&1');
$analytics = json_decode($out, true);
check('bin/analytics.php --json выполнился и вернул шесть запросов', is_array($analytics) && count($analytics) === 6, substr($out, 0, 120));
$analytics = is_array($analytics) ? $analytics : [];
$q = function (int $n) use ($analytics): ?array {
    foreach ($analytics as $title => $result) {
        if (str_starts_with($title, "$n. ")) {
            return $result;
        }
    }
    return null;
};
$totalEvents = (int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn();
check('запросы 1–4 написаны (не пустые) и выполняются без ошибок', $q(1) !== null && $q(2) !== null && $q(3) !== null && $q(4) !== null);
$byType = $q(1) ?? [];
check('1. по типам: сумма равна числу событий, типы от частых к редким', $byType !== [] && array_sum(array_column($byType, 'total')) === $totalEvents && array_column($byType, 'total') === (function () use ($byType) { $c = array_column($byType, 'total'); rsort($c); return $c; })());
$top = $q(2) ?? [];
$dbTop = $db->query('SELECT u.name, COUNT(*) AS n FROM events e JOIN users u ON u.id = e.user_id GROUP BY u.id, u.name ORDER BY n DESC LIMIT 1')->fetch();
check('2. самые активные: не больше пяти, первым идёт участник с наибольшим числом действий', $top !== [] && count($top) <= 5 && ($top[0]['name'] ?? '') === $dbTop['name'] && (int) $top[0]['actions'] === (int) $dbTop['n'], json_encode($top[0] ?? [], JSON_UNESCAPED_UNICODE));
$days = $q(3) ?? [];
$dbDays = (int) $db->query("SELECT COUNT(*) FROM events WHERE occurred_at >= now() - interval '14 days'")->fetchColumn();
$dates = array_column($days, 'day');
$datesSorted = $dates; sort($datesSorted);
check('3. по дням: сумма равна числу событий за 14 дней, даты по возрастанию', $days !== [] && array_sum(array_column($days, 'total')) === $dbDays && $dates === $datesSorted, 'сумма ' . array_sum(array_column($days, 'total')) . ', в базе ' . $dbDays);
$cat = $q(4) ?? [];
$names = array_column($cat, 'category');
sort($names);
check('4. категории: auth, data и system; сумма долей около 100 %', $names === ['auth', 'data', 'system'] && abs(array_sum(array_column($cat, 'percent')) - 100) < 0.5, json_encode($cat));
$err = $q(0) ?? [];
check('0. (пример) доля ошибок по дням — число от 0 до 100', $err !== [] && count(array_filter($err, fn($x) => $x['error_percent'] < 0 || $x['error_percent'] > 100)) === 0);

// ---------------------------------------------------------------------
section('17. Свои проверки журнала');
// ---------------------------------------------------------------------
// TODO 5.2  Свои проверки. Добавьте не меньше двух своих проверок журнала, например:
//             • изменение одного поля (PATCH с одним полем) записывает в details.changed только это поле;
//             • руководитель видит в журнале событие своего входа (GET /api/events?type=login&user_id=...);
//             • у всех событий server_error категория system и в details есть путь запроса;
//             • для расширения «Повышенной сложности»: новое событие вашего типа записывается и находится по фильтру.
//           Используйте готовые инструменты: call(), check(), expectStatus(), $db (подключение к базе), $lastEvent('тип'), $countEvents('тип').
//           Подсказка:
//              call('PATCH', '/api/tasks/3', ['priority' => 'high'], $manager);
//              $changed = json_decode($lastEvent('task_updated')['details_text'], true)['changed'];
//              check('в details.changed только priority', $changed === ['priority']);

// ---------------------------------------------------------------------
echo "\nИтого: $passed прошло, $failed не прошло\n";
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/reset-db.php') . ' > ' . $devNull);   // убираем за собой
exit($failed === 0 ? 0 : 1);
