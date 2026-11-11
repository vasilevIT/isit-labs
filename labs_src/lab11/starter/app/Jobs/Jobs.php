<?php
// app/Jobs/Jobs.php — реестр фоновых задач и их запуск (ГОТОВО: менять не нужно).
//
// Тип задачи (строка в очереди) связан с классом, который умеет её выполнять. Чтобы добавить новую операцию в фон,
// создайте класс с методом handle() и добавьте его в handlers().

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Queue;

final class Jobs
{
    // Какой класс выполняет задачу каждого типа.
    public static function handlers(): array
    {
        return [
            'notification' => NotificationJob::class,   // уведомление о смене статуса задачи
            'report'       => ReportJob::class,         // построение отчёта (CSV-файл)
            'demo'         => DemoJob::class,           // учебная задача для экспериментов (bin/queue-demo.php)
        ];
    }

    // Пауза на $seconds секунд: так имитируется медленная работа (отправка письма, построение отчёта). ГОТОВО.
    // Обычный sleep() прерывается сигналом остановки (Ctrl+C), а здесь пауза всегда досиживается до конца,
    // как настоящая работа, которую воркер доделывает перед выходом.
    public static function pause(float $seconds): void
    {
        $end = microtime(true) + $seconds;
        while (microtime(true) < $end) {
            usleep(50_000);
        }
    }

    // Выполняет задачу прямо сейчас. Воркер вызывает этот метод для каждой задачи из очереди.
    // Если тип неизвестен или обработчик бросил исключение, исключение летит дальше: воркер поймёт, что задача не выполнена.
    public static function run(array $job): void
    {
        $class = self::handlers()[$job['type']] ?? null;
        if ($class === null) {
            throw new \RuntimeException('Неизвестный тип задачи: ' . $job['type']);
        }
        (new $class())->handle($job['payload'], $job['id'], (int) ($job['attempts'] ?? 0) + 1);
    }

    // Отдаёт операцию фоновой обработке и возвращает номер задачи.
    //   Обычный режим (async):  задача кладётся в очередь, метод возвращается сразу; выполнит её воркер.
    //   Режим sync:             задача выполняется здесь же, пользователь ждёт (так приложение работало бы без очереди).
    //                           Включается QUEUE_MODE=sync или, при debug = true, заголовком запроса «X-Queue-Mode: sync»:
    //                           так можно сравнить время ответа с очередью и без неё, не перезапуская сервер.
    // Если очередь недоступна, метод возвращает null: операция пользователя уже выполнена, её нельзя откатывать из-за уведомления.
    public static function dispatch(string $type, array $payload): ?string
    {
        $sync = config('queue')['mode'] === 'sync'
            || (config('debug') && ($_SERVER['HTTP_X_QUEUE_MODE'] ?? '') === 'sync');
        if ($sync) {
            self::run(['id' => 'sync', 'type' => $type, 'payload' => $payload, 'attempts' => 0]);
            return 'sync';
        }
        try {
            return Queue::push($type, $payload);
        } catch (\Throwable $e) {
            error_log('Не удалось поставить задачу «' . $type . '» в очередь: ' . $e->getMessage());
            return null;
        }
    }
}
