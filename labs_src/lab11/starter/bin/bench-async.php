<?php
// bin/bench-async.php — сколько пользователь ждёт ответа: с очередью и без неё.
//
// Запуск (сервер и Redis должны работать; воркер нужен только для последнего замера, заказа отчёта, задание 5.1):
//     php bin/bench-async.php                       # по 5 запросов на режим
//     php bin/bench-async.php --n=10 --url=http://localhost:8000
//
// Скрипт под именем Игоря много раз меняет статус одной задачи (каждое изменение запускает уведомление).
//   Режим «sync»: заголовок «X-Queue-Mode: sync» просит сервер выполнить уведомление прямо в запросе (так было бы без очереди).
//   Режим «async»: обычная работа: задача кладётся в очередь, ответ приходит сразу.
// Задачи, накопившиеся в очереди во время замера, потом выполнит воркер (или bin/reset-db.php их удалит).

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

$options = getopt('', ['url::', 'n::']);
$base = rtrim((string) ($options['url'] ?? 'http://localhost:8000'), '/');
$n = max(2, (int) ($options['n'] ?? 5));

// Один запрос. Возвращает [время в миллисекундах, код ответа, заголовок X-Job-Id]
function request(string $method, string $url, ?string $token, ?array $body = null, array $extra = []): array
{
    $headers = ['Accept: application/json', 'Content-Type: application/json', ...$extra];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
    ]);
    $start = hrtime(true);
    $response = curl_exec($ch);
    $ms = (hrtime(true) - $start) / 1e6;
    if ($response === false) {
        fwrite(STDERR, "Не удалось подключиться к $url. Запущен ли сервер?\n");
        exit(2);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $head = substr($response, 0, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
    curl_close($ch);
    preg_match('/^x-job-id:\s*(\S+)/mi', $head, $m);
    return [$ms, $status, $m[1] ?? '-'];
}

// Вход под участником: возвращает токен
function login(string $base, string $email): ?string
{
    $login = json_decode((string) @file_get_contents($base . '/api/auth/login', false, stream_context_create(['http' => [
        'method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode(['email' => $email, 'password' => '123456']),
        'ignore_errors' => true,
    ]])), true);
    return $login['token'] ?? null;
}

$token = login($base, 'igor@example.com');
if ($token === null) {
    fwrite(STDERR, "Не удалось войти. Запущен ли сервер ($base) и загружена ли база (php bin/reset-db.php)?\n");
    exit(2);
}

echo "Сервер: $base. Запросов на режим: $n. «Отправка уведомления» занимает NOTIFY_SECONDS секунд (по умолчанию 2).\n\n";

$rows = [];
foreach (['sync' => ['X-Queue-Mode: sync'], 'async' => []] as $mode => $extra) {
    $times = [];
    for ($i = 0; $i < $n; $i++) {
        $status = $i % 2 === 0 ? 'done' : 'in_progress';             // каждый раз другой статус: иначе уведомления не будет
        [$ms, $code, $job] = request('PATCH', $base . '/api/tasks/2', $token, ['status' => $status], $extra);
        if ($code !== 200) {
            fwrite(STDERR, "PATCH вернул код $code\n");
            exit(2);
        }
        $times[] = $ms;
    }
    $rows[$mode] = $times;
    printf("режим %-5s: среднее %.0f мс, самый медленный %.0f мс, самый быстрый %.0f мс\n", $mode, array_sum($times) / $n, max($times), min($times));
}

$sync = array_sum($rows['sync']) / $n;
$async = array_sum($rows['async']) / $n;
echo "\n| Режим | Среднее время ответа, мс | Что происходит |\n|-------|-------------------------:|----------------|\n";
printf("| sync (без очереди) | %.0f | пользователь ждёт, пока отправится уведомление |\n", $sync);
printf("| async (с очередью) | %.0f | задача положена в очередь, ответ сразу; уведомление создаст воркер |\n", $async);
printf("\nОтвет с очередью быстрее в %.0f раз(а).\n", $sync / max(0.1, $async));

// TODO 5.1  Замер для второй операции. Добавьте сюда замер заказа отчёта (или своей операции): сколько миллисекунд сервер отвечает
//           на POST /api/reports и через сколько миллисекунд отчёт становится готовым (нужен запущенный воркер).
//             1) войдите под Анной: $manager = login($base, 'anna@example.com');
//             2) запомните время: $start = hrtime(true); отправьте POST /api/reports (curl или file_get_contents, как в функции request());
//                время ответа: (hrtime(true) - $start) / 1e6 миллисекунд; из ответа возьмите $report['id'];
//             3) в цикле с паузой usleep(200_000) спрашивайте GET /api/reports/<id>, пока status не станет done (или failed),
//                и напечатайте время от заказа до готовности.
//           ✔ Проверка: ответ на POST — десятки миллисекунд, готовность — около REPORT_SECONDS секунд (по умолчанию 2).
