<?php
// bin/events-tail.php — показывает последние события журнала прямо из базы, без токена (ГОТОВО: менять не нужно).
// Запуск:  php bin/events-tail.php                       # 10 последних событий
//          php bin/events-tail.php --limit=20 --type=login --category=auth --user=2
// Параметры необязательные и сочетаются. «tail» (хвост) — так называют просмотр последних строк файла или журнала.

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

$o = getopt('', ['limit::', 'type::', 'category::', 'user::']);
$where = [];
$params = [];
foreach (['type' => 'e.type', 'category' => 'e.category', 'user' => 'e.user_id'] as $name => $column) {
    if (isset($o[$name]) && $o[$name] !== '') {
        $where[] = "$column = :$name";
        $params[$name] = $o[$name];
    }
}
$limit = max(1, (int) ($o['limit'] ?? 10));

try {
    $stmt = App\Core\Database::connection()->prepare(
        'SELECT e.id, e.occurred_at, e.type, COALESCE(u.name, \'—\') AS who, e.description, e.details::text AS details, e.request_id
         FROM events e LEFT JOIN users u ON u.id = e.user_id ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
         ORDER BY e.occurred_at DESC, e.id DESC LIMIT ' . $limit
    );
    $stmt->execute($params);
    $rows = array_reverse($stmt->fetchAll());                 // новые внизу, как в журнале на экране
} catch (Throwable $e) {
    echo 'Не удалось прочитать журнал: ' . $e->getMessage() . "\n";
    exit(1);
}
if (!$rows) {
    echo "Событий нет. Выполните действие в приложении или php bin/seed-events.php\n";
    exit(0);
}
foreach ($rows as $r) {
    printf("#%-6d %s  %-15s %-14s %s\n", $r['id'], substr($r['occurred_at'], 0, 19), $r['type'], $r['who'], $r['description']);
    if ($r['details'] !== '{}') {
        echo '        details: ' . $r['details'] . '   запрос: ' . ($r['request_id'] ?? '—') . "\n";
    }
}
