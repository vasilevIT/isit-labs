<?php
// bin/worker.php — воркер: отдельная программа, которая достаёт задачи из очереди и выполняет их (лекция 3.2).
//
// Запуск (в отдельном окне терминала, рядом с работающим сервером):
//     php bin/worker.php                      # работает, пока его не остановят (Ctrl+C)
//     php bin/worker.php --until-empty        # выполнить всё, что есть в очереди, и выйти (удобно для проверок и тестов)
//     php bin/worker.php --once               # выполнить одну задачу и выйти
//     php bin/worker.php --queue=default      # имя очереди (по умолчанию default)
// Если Redis или база запущены на других портах:  REDIS_PORT=6380 DB_PORT=5433 php bin/worker.php
//
// Воркер — обычный PHP-скрипт с бесконечным циклом: взять задачу → выполнить → подтвердить. Он не связан с веб-сервером:
// можно запустить несколько воркеров сразу (задачи между ними не дублируются), можно остановить и запустить заново.

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

use App\Core\Queue;
use App\Jobs\Jobs;

$options = getopt('', ['queue::', 'once', 'until-empty']);
$queue = (string) ($options['queue'] ?? 'default');
$once = isset($options['once']);
$untilEmpty = isset($options['until-empty']);

// Строка журнала воркера: время и текст (ГОТОВО)
function out(string $line): void
{
    echo '[' . date('H:i:s') . '] ' . $line . PHP_EOL;
}

$running = true;                                           // цикл работает, пока здесь true

if (function_exists('pcntl_async_signals')) {              // расширение pcntl есть в Linux и macOS; в Windows его нет (там воркер останавливают Ctrl+C «грубо»)
    pcntl_async_signals(true);                             // сигналы обрабатываются сразу, а не «когда-нибудь»
    $stop = function () use (&$running): void {
        $running = false;                                  // текущую задачу воркер доделает, а новую уже не возьмёт
        out('получен сигнал остановки: заканчиваю текущую задачу и выхожу');
    };
    pcntl_signal(SIGTERM, $stop);                          // «вежливая» остановка: kill <номер процесса>, systemd, Docker
    pcntl_signal(SIGINT, $stop);                           // Ctrl+C в терминале
}

out('воркер запущен (очередь ' . $queue . '). Остановить: Ctrl+C');

// Задачи, застрявшие в «в работе» после прошлого аварийного завершения, возвращаются в очередь (метод Queue::recover)
$recovered = Queue::recover($queue);
if ($recovered > 0) {
    out('возвращено в очередь задач, застрявших после сбоя: ' . $recovered);
}

$done = 0;
while ($running) {
    Queue::promote($queue);                                // вернуть в очередь задачи, у которых закончилась пауза перед повтором
    $reserved = Queue::reserve($queue, $untilEmpty ? 1 : 2);   // ждём задачу не дольше 1–2 секунд, чтобы проверять флаг остановки
    if ($reserved === null) {
        if ($untilEmpty && Queue::stats($queue)['delayed'] === 0) {
            break;                                         // очередь пуста и повторов не ожидается: выходим
        }
        continue;
    }
    [$job, $raw] = $reserved;
    $started = microtime(true);
    out(sprintf('#%s %s: начал (попытка %d)', $job['id'], $job['type'], $job['attempts'] + 1));

    try {
        Jobs::run($job);                                   // выполнить: Jobs находит класс по типу задачи и вызывает его handle()
        Queue::ack($raw, $queue);                          // успех: убрать из «в работе»
        $done++;
        out(sprintf('#%s %s: готово за %.2f с', $job['id'], $job['type'], microtime(true) - $started));
    } catch (Throwable $e) {
        $result = Queue::fail($job, $raw, $e->getMessage(), $queue);   // решает: повторить позже или отказаться
        if ($result === 'retry') {
            out(sprintf('#%s %s: ОШИБКА «%s» → повтор позже (попытка %d из %d)', $job['id'], $job['type'], $e->getMessage(), $job['attempts'] + 1, config('queue')['max_attempts']));
        } else {
            out(sprintf('#%s %s: ОШИБКА «%s» → попытки кончились, задача отправлена в «мёртвую» очередь', $job['id'], $job['type'], $e->getMessage()));
        }
    }

    if ($once) {
        break;
    }
}

out('воркер остановлен (выполнено задач: ' . $done . ')');
