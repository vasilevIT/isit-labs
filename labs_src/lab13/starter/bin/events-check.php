<?php
// bin/events-check.php — проверка журнала событий без приложения (ГОТОВО: менять не нужно).
// Запуск:  php bin/events-check.php     Код завершения: 0 — запись работает и секретов в журнале нет, 1 — есть проблемы.
// Проверяет задания 1.1 (Events::log) и 1.2 (Events::clean). Проверочные записи после проверки удаляются.

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

use App\Core\Database;
use App\Core\Events;

try {
    $pdo = Database::connection();
    $marker = 'Проверка журнала (events-check)';
    $pdo->prepare('DELETE FROM events WHERE description = :d')->execute(['d' => $marker]);

    Events::log('login', $marker, ['user_id' => null, 'details' => [
        'note' => 'проверка', 'password' => 'секретный-пароль-123', 'nested' => ['Token' => 'секретный-токен-456', 'ok' => 1],
    ]]);

    $stmt = $pdo->prepare('SELECT id, type, category, details::text AS details, request_id FROM events WHERE description = :d');
    $stmt->execute(['d' => $marker]);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    echo 'База данных недоступна: ' . $e->getMessage() . "\n";
    exit(1);
}

$ok = true;
echo 'записано: ' . count($rows) . "\n";
if (count($rows) !== 1) {
    echo "запись события не появилась (задание 1.1 не выполнено или в error_log есть сообщение об ошибке)\n";
    $ok = false;
} else {
    $row = $rows[0];
    echo "строка: id={$row['id']}, тип={$row['type']}, категория={$row['category']}, номер запроса={$row['request_id']}\n";
    echo "details: {$row['details']}\n";
    $leak = str_contains($row['details'], 'секретный-пароль-123') || str_contains($row['details'], 'секретный-токен-456');
    echo $leak ? "СЕКРЕТЫ ПОПАЛИ В ЖУРНАЛ (задание 1.2 не выполнено)\n" : "секретов в журнале нет\n";
    $ok = $ok && !$leak && str_contains($row['details'], '"ok": 1');
}

$pdo->prepare('DELETE FROM events WHERE description = :d')->execute(['d' => $marker]);
echo $ok ? "Журнал работает.\n" : "Есть ошибки: проверьте задания 1.1 и 1.2 в app/Core/Events.php.\n";
exit($ok ? 0 : 1);
