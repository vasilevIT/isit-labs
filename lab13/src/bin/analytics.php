<?php
// bin/analytics.php — выполняет SQL-запросы из sql/analytics.sql и печатает результаты таблицами (ГОТОВО: менять не нужно).
// Запуск:  php bin/analytics.php            # все запросы
//          php bin/analytics.php --json     # результат в формате JSON (для тестов и других программ)
// Файл sql/analytics.sql разбит на запросы строками вида «-- @query название»; строки-комментарии внутри запроса пропускаются.
// Код завершения: 0 — все запросы выполнены, 1 — какой-то запрос не написан или упал (подсказка, какой именно, будет в выводе).

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

$json = in_array('--json', $argv, true);
$text = file_get_contents(BASE_PATH . '/sql/analytics.sql');

// Разбираем файл на запросы
$queries = [];
$current = null;
foreach (explode("\n", $text) as $line) {
    if (preg_match('/^--\s*@query\s+(.+)$/', $line, $m)) {
        $current = trim($m[1]);
        $queries[$current] = '';
    } elseif ($current !== null && !str_starts_with(ltrim($line), '--')) {
        $queries[$current] .= $line . "\n";
    }
}

try {
    $pdo = App\Core\Database::connection();
} catch (Throwable $e) {
    fwrite(STDERR, 'База данных недоступна: ' . $e->getMessage() . "\n");
    exit(1);
}

$results = [];
$problems = 0;
foreach ($queries as $title => $sql) {
    $sql = trim($sql);
    if ($sql === '') {
        $results[$title] = null;
        $problems++;
        if (!$json) {
            echo "\n$title\n  (запрос не написан: найдите TODO под этим заголовком в sql/analytics.sql)\n";
        }
        continue;
    }
    try {
        $rows = $pdo->query(rtrim($sql, ';'))->fetchAll();
    } catch (Throwable $e) {
        $results[$title] = null;
        $problems++;
        if (!$json) {
            echo "\n$title\n  ОШИБКА SQL: " . trim(explode("\n", $e->getMessage())[0]) . "\n";
        }
        continue;
    }
    $results[$title] = $rows;
    if ($json) {
        continue;
    }
    echo "\n$title\n";
    if (!$rows) {
        echo "  (пусто)\n";
        continue;
    }
    $cols = array_keys($rows[0]);
    $width = [];
    foreach ($cols as $c) {
        $width[$c] = max(mb_strlen($c), ...array_map(fn($r) => mb_strlen((string) $r[$c]), $rows));
    }
    $pad = fn(string $s, int $w) => $s . str_repeat(' ', max(0, $w - mb_strlen($s)));
    echo '  ' . implode('  ', array_map(fn($c) => $pad($c, $width[$c]), $cols)) . "\n";
    echo '  ' . implode('  ', array_map(fn($c) => str_repeat('-', $width[$c]), $cols)) . "\n";
    foreach ($rows as $r) {
        echo '  ' . implode('  ', array_map(fn($c) => $pad((string) $r[$c], $width[$c]), $cols)) . "\n";
    }
}
if ($json) {
    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
exit($problems === 0 ? 0 : 1);
