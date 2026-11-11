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

// TODO 3.4  Корректная остановка. Если остановить воркер «грубо» посреди задачи, она останется в списке «в работе» и выполнится заново.
//           Лучше перехватить сигнал остановки: поставить флаг $running = false, и цикл закончится сам после текущей задачи:
//              if (function_exists('pcntl_async_signals')) {              // pcntl есть в Linux и macOS; в Windows его нет: там этот шаг пропустите
//                  pcntl_async_signals(true);                             // сигналы обрабатываются сразу
//                  $stop = function () use (&$running): void {
//                      $running = false;
//                      out('получен сигнал остановки: заканчиваю текущую задачу и выхожу');
//                  };
//                  pcntl_signal(SIGTERM, $stop);                          // kill <номер процесса>
//                  pcntl_signal(SIGINT, $stop);                           // Ctrl+C
//              }
//           ✔ Проверка: php bin/queue-demo.php --slow, запустите воркер, нажмите Ctrl+C во время задачи: воркер дождётся её конца
//             и выйдет (php bin/queue-stats.php — «в работе: 0»). Без этого шага задача останется «в работе».

out('воркер запущен (очередь ' . $queue . '). Остановить: Ctrl+C');

// Задачи, застрявшие в «в работе» после прошлого аварийного завершения, возвращаются в очередь (TODO 3.3 в Queue::recover)
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

    // TODO 3.1  Главный шаг цикла. У вас в руках задача $job (массив) и $raw (её исходная строка). Нужно выполнить её и подтвердить:
    //             1) Jobs::run($job);  — запускает обработчик по типу задачи (NotificationJob, ReportJob...);
    //             2) Queue::ack($raw, $queue);  — задача выполнена, убрать её из «в работе»;
    //             3) $done++ и строка в журнал: out(sprintf('#%s %s: готово за %.2f с', $job['id'], $job['type'], microtime(true) - $started));
    //           Весь шаг пишется в блоке try, а блок catch нужен для TODO 3.2 (ниже).
    //           Подсказка:
    //              try {
    //                  Jobs::run($job);
    //                  Queue::ack($raw, $queue);
    //                  $done++;
    //                  out(sprintf('#%s %s: готово за %.2f с', $job['id'], $job['type'], microtime(true) - $started));
    //              } catch (Throwable $e) {
    //                  ... TODO 3.2 ...
    //              }
    //           ✔ Проверка: поставьте задачу (php bin/queue-demo.php) и запустите воркер: «готово за 1.00 с»; php bin/queue-stats.php — везде 0.
    out('получена задача #' . $job['id'] . ', но её обработка не написана (TODO 3.1): она останется «в работе»');
    sleep(1);
    try {
    } catch (Throwable $e) {
        // TODO 3.2  (продолжение)  Ошибка в одной задаче не должна останавливать воркер. Поймайте её и передайте очереди:
        //              $result = Queue::fail($job, $raw, $e->getMessage(), $queue);   // 'retry' или 'dead'
        //           и напишите в журнал, что произошло:
        //              out(sprintf('#%s %s: ОШИБКА «%s» → %s', $job['id'], $job['type'], $e->getMessage(), $result === 'retry' ? 'повтор позже' : 'в «мёртвую» очередь'));
        //           (метод Queue::fail вы пишете в TODO 3.2 в app/Core/Queue.php)
    }

    if ($once) {
        break;
    }
}

out('воркер остановлен (выполнено задач: ' . $done . ')');
