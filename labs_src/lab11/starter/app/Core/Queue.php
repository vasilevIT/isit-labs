<?php
// app/Core/Queue.php — очередь фоновых задач в Redis (лекция 3.2).
//
// Очередь — «список дел». Обработчик запроса кладёт в неё задачу и сразу отвечает пользователю,
// а отдельная программа (воркер, bin/worker.php) достаёт задачи по одной и выполняет.
//
// Как это устроено в Redis (все ключи начинаются с префикса taskapp:):
//
//   queue:default             список ожидающих задач. Новая задача кладётся слева (LPUSH), берётся справа (так задачи идут по порядку)
//   queue:default:processing  список задач «в работе». Взятая воркером задача не пропадает, а перекладывается сюда:
//                             если воркер упадёт, задача останется на виду, и её можно вернуть в очередь
//   queue:default:delayed     задачи, которые будут повторены позже (упорядочены по времени, когда их пора вернуть в очередь)
//   queue:default:dead        «мёртвая» очередь: задачи, которые не удалось выполнить за все попытки
//
// Задача — это JSON-строка:  {"id": "a1b2c3d4e5f60718", "type": "notification", "payload": {...}, "attempts": 0, "created_at": "..."}
//   id        номер задачи (по нему её ищут в журнале и защищаются от повторного выполнения)
//   type      тип: по нему воркер выбирает, какой код запускать (см. app/Jobs/Jobs.php)
//   payload   данные для обработки (номер задачи, адрес получателя и т. д.): только то, что нужно, а не вся запись из базы
//   attempts  сколько раз выполнение уже не удалось

declare(strict_types=1);

namespace App\Core;

use Redis;
use RedisException;

final class Queue
{
    private static ?Redis $redis = null;

    // Соединение с Redis (ГОТОВО). Если Redis недоступен, бросает исключение с кодом 503:
    // обработчик ошибок в public/index.php ответит клиенту «503 Service Unavailable».
    public static function redis(): Redis
    {
        if (self::$redis === null) {
            $c = config('queue');
            $redis = new Redis();
            try {
                $redis->connect($c['host'], $c['port'], $c['timeout']);
                $redis->setOption(Redis::OPT_PREFIX, $c['prefix']);                // ко всем ключам добавится «taskapp:»
                $redis->setOption(Redis::OPT_READ_TIMEOUT, $c['read_timeout']);    // иначе долгое ожидание задачи (BLMOVE) оборвалось бы
                $redis->ping();
            } catch (RedisException $e) {
                throw new \RuntimeException('Очередь недоступна: Redis не отвечает (' . $c['host'] . ':' . $c['port'] . ')', 503, $e);
            }
            self::$redis = $redis;
        }
        return self::$redis;
    }

    // Имя ключа очереди: key('default') → queue:default, key('default', 'dead') → queue:default:dead (ГОТОВО)
    private static function key(string $queue, string $part = ''): string
    {
        return 'queue:' . $queue . ($part === '' ? '' : ':' . $part);
    }

    // Кладёт задачу в очередь и возвращает её номер (ГОТОВО в части сборки задачи, постановку в очередь пишете вы).
    //   $type — тип задачи ('notification'), $payload — данные для обработки.
    public static function push(string $type, array $payload = [], string $queue = 'default'): string
    {
        // TODO 1.1  Постановка в очередь. Метод должен собрать задачу и положить её в список queue:default:
        //             1) соберите массив задачи: id (случайные 16 символов: bin2hex(random_bytes(8))), type, payload, attempts = 0, created_at = date('c');
        //             2) превратите массив в JSON-строку: json_encode($job, JSON_UNESCAPED_UNICODE);
        //             3) положите строку в начало списка командой LPUSH: self::redis()->lPush(self::key($queue), $json);
        //             4) верните номер задачи $job['id'].
        //           Подсказка:
        //              $job = [
        //                  'id'         => bin2hex(random_bytes(8)),
        //                  'type'       => $type,
        //                  'payload'    => $payload,
        //                  'attempts'   => 0,
        //                  'created_at' => date('c'),
        //              ];
        //              self::redis()->lPush(self::key($queue), json_encode($job, JSON_UNESCAPED_UNICODE));
        //              return $job['id'];
        //           ✔ Проверка: php bin/queue-check.php — «задач в очереди: 1» после постановки;
        //             redis-cli LLEN taskapp:queue:default — число 1 (или docker compose exec redis redis-cli LLEN taskapp:queue:default).
        return '';                                                      // пока в очередь ничего не кладётся
    }

    // Берёт следующую задачу для обработки. Ждёт не дольше $timeout секунд; если задач нет, возвращает null.
    // Возвращает пару [массив задачи, исходная JSON-строка]: строка нужна, чтобы потом убрать задачу из списка «в работе» (ack).
    public static function reserve(string $queue = 'default', int $timeout = 2): ?array
    {
        // TODO 1.2  Получение задачи. Задачу нельзя просто достать из списка: если воркер упадёт сразу после этого, она пропадёт навсегда.
        //           Поэтому её не достают, а ПЕРЕКЛАДЫВАЮТ из очереди в список «в работе» одной неделимой командой BLMOVE
        //           (B — blocking: если очередь пуста, команда ждёт появления задачи до $timeout секунд):
        //             1) $raw = self::redis()->blmove(откуда, куда, 'RIGHT', 'LEFT', $timeout);
        //                откуда = self::key($queue), куда = self::key($queue, 'processing');
        //                берём справа (самую старую), кладём слева: так задачи идут по порядку;
        //             2) если $raw === false, задач не появилось: верните null;
        //             3) иначе верните [json_decode($raw, true), $raw].
        //           Подсказка:
        //              $raw = self::redis()->blmove(self::key($queue), self::key($queue, 'processing'), 'RIGHT', 'LEFT', $timeout);
        //              if ($raw === false) {
        //                  return null;
        //              }
        //              return [json_decode($raw, true), $raw];
        //           ✔ Проверка: php bin/queue-check.php — «взята задача ... в очереди: 0, в работе: 1».
        return null;                                                    // пока задач не берёт никто
    }

    // Сообщает, что задача выполнена: убирает её из списка «в работе».
    public static function ack(string $raw, string $queue = 'default'): void
    {
        // TODO 1.3  Подтверждение (ack). Обработанная задача должна исчезнуть из списка «в работе», иначе при следующем запуске
        //           воркера она будет считаться незавершённой и выполнится ещё раз. Удалите строку из списка командой LREM:
        //              self::redis()->lRem(self::key($queue, 'processing'), $raw, 1);     // 1 — удалить одно вхождение
        //           ✔ Проверка: php bin/queue-check.php — после ack «в работе: 0».
    }

    // Задача не выполнена (воркер поймал ошибку). Решает её судьбу и возвращает 'retry' (повторим позже) или 'dead' (отказались).
    //   $job — массив задачи, $raw — исходная строка (чтобы убрать задачу из «в работе»), $error — текст ошибки.
    public static function fail(array $job, string $raw, string $error, string $queue = 'default'): string
    {
        // TODO 3.2  Ошибки. Метод вызывается, когда обработка задачи завершилась исключением. Он должен решить, что с ней делать:
        //             1) увеличьте $job['attempts'] на 1 и запишите текст ошибки в $job['error']; соберите JSON: $json = json_encode($job, JSON_UNESCAPED_UNICODE);
        //             2) если попыток стало не меньше config('queue')['max_attempts'], задача «мёртвая»: положите её в список
        //                self::key($queue, 'dead') командой lPush и верните 'dead';
        //             3) иначе повторим позже: пауза $delay = config('queue')['backoff'] * (2 ** ($job['attempts'] - 1)) секунд (2, 4, 8...);
        //                положите задачу в отсортированное множество self::key($queue, 'delayed') командой
        //                zAdd(ключ, time() + $delay, $json): число — момент, когда задачу пора вернуть в очередь (возвращает её promote()); верните 'retry';
        //             4) в обоих случаях задачу нужно убрать из «в работе»: self::ack($raw, $queue). Делайте это ПОСЛЕ того,
        //                как положили её в delayed или dead: если процесс упадёт между шагами, задача останется в «в работе», а не пропадёт.
        //           Подсказка:
        //              $job['attempts']++;
        //              $job['error'] = $error;
        //              $json = json_encode($job, JSON_UNESCAPED_UNICODE);
        //              $redis = self::redis();
        //              if ($job['attempts'] >= config('queue')['max_attempts']) {
        //                  $redis->lPush(self::key($queue, 'dead'), $json);
        //                  $result = 'dead';
        //              } else {
        //                  $delay = config('queue')['backoff'] * (2 ** ($job['attempts'] - 1));
        //                  $redis->zAdd(self::key($queue, 'delayed'), time() + $delay, $json);
        //                  $result = 'retry';
        //              }
        //              self::ack($raw, $queue);
        //              return $result;
        //           ✔ Проверка: php bin/queue-demo.php --fail, затем php bin/worker.php --until-empty: три попытки и «в мёртвую очередь»;
        //             php bin/queue-stats.php — «мёртвых: 1».
        self::ack($raw, $queue);
        return 'dead';                                                  // пока любая ошибка — сразу выбрасываем задачу
    }

    // Возвращает в очередь задачи, у которых прошла пауза перед повтором (ГОТОВО). Воркер вызывает его на каждом шаге цикла.
    public static function promote(string $queue = 'default'): int
    {
        $redis = self::redis();
        $moved = 0;
        foreach ($redis->zRangeByScore(self::key($queue, 'delayed'), '-inf', (string) time()) as $raw) {
            if ($redis->zRem(self::key($queue, 'delayed'), $raw) > 0) {   // убрать из delayed смог один воркер: он и вернёт в очередь
                $redis->lPush(self::key($queue), $raw);
                $moved++;
            }
        }
        return $moved;
    }

    // Возвращает в очередь задачи, застрявшие в «в работе» (вызывается при запуске воркера).
    // Такие задачи остаются, если воркер был остановлен «жёстко» (kill -9, отключили питание) в середине работы.
    // Возвращает, сколько задач вернули. Вызывайте метод, когда работает ОДИН воркер: живой воркер другого процесса
    // тоже держит задачу «в работе», и её нельзя забирать (в рабочих системах для этого задают срок выполнения).
    public static function recover(string $queue = 'default'): int
    {
        // TODO 3.3  Восстановление после сбоя. Перекладывайте задачи из списка «в работе» обратно в очередь по одной командой LMOVE,
        //           пока список не опустеет, и посчитайте, сколько задач вернули:
        //              $count = 0;
        //              while (self::redis()->lMove(self::key($queue, 'processing'), self::key($queue), 'LEFT', 'RIGHT') !== false) {
        //                  $count++;                                  // LMOVE вернёт false, когда в списке больше ничего нет
        //              }
        //              return $count;
        //           ✔ Проверка: php bin/queue-demo.php --slow, запустите воркер и в другом окне остановите его «жёстко»: kill -9 <номер процесса>;
        //             php bin/queue-stats.php — «в работе: 1»; снова запустите воркер: «возвращено в очередь: 1», задача выполнена.
        return 0;
    }

    // Сколько задач в каждом списке (ГОТОВО).
    public static function stats(string $queue = 'default'): array
    {
        $redis = self::redis();
        return [
            'waiting'    => (int) $redis->lLen(self::key($queue)),
            'processing' => (int) $redis->lLen(self::key($queue, 'processing')),
            'delayed'    => (int) $redis->zCard(self::key($queue, 'delayed')),
            'dead'       => (int) $redis->lLen(self::key($queue, 'dead')),
        ];
    }

    // Задачи из «мёртвой» очереди (ГОТОВО): для просмотра в bin/queue-stats.php.
    public static function dead(string $queue = 'default'): array
    {
        return array_map(fn(string $raw): array => json_decode($raw, true), self::redis()->lRange(self::key($queue, 'dead'), 0, -1));
    }

    // Удаляет все списки очереди (ГОТОВО): для bin/reset-db.php и тестов.
    public static function flush(string $queue = 'default'): void
    {
        self::redis()->del(self::key($queue), self::key($queue, 'processing'), self::key($queue, 'delayed'), self::key($queue, 'dead'));
    }
}
