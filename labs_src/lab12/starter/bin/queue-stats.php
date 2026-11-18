<?php
// bin/queue-stats.php — показывает, что сейчас в очереди (ГОТОВО: менять не нужно).
// Запуск:  php bin/queue-stats.php            # сколько задач в каждом списке
//          php bin/queue-stats.php --dead     # и содержимое «мёртвой» очереди (какие задачи и почему не выполнились)
// То же можно посмотреть в Redis: redis-cli LLEN taskapp:queue:default и redis-cli LRANGE taskapp:queue:default 0 -1

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

use App\Core\Queue;

try {
    $s = Queue::stats();
} catch (Throwable $e) {
    echo $e->getMessage() . "\n";
    exit(1);
}
echo "ожидают:      {$s['waiting']}\n";
echo "в работе:     {$s['processing']}\n";
echo "ждут повтора: {$s['delayed']}\n";
echo "мёртвых:      {$s['dead']}\n";

if (in_array('--dead', $argv, true)) {
    foreach (Queue::dead() as $job) {
        echo "\n#{$job['id']} {$job['type']}, попыток: {$job['attempts']}, ошибка: " . ($job['error'] ?? '?') . "\n";
        echo '  данные: ' . json_encode($job['payload'], JSON_UNESCAPED_UNICODE) . "\n";
    }
}
