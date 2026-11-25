<?php
// bin/load-searchdata.php — добавляет в базу много задач с «живыми» названиями и описаниями (sql/searchdata.sql) и очищает кеш.
// Запуск:  php bin/load-searchdata.php          # 100 000 задач
//          php bin/load-searchdata.php 20000    # другое число задач
// После загрузки перестройте поисковый индекс:  php bin/reindex.php
// Вернуть небольшую базу:  php bin/reset-db.php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

$count = max(1, (int) ($argv[1] ?? 100000));
$pdo = App\Core\Database::connection();
$start = microtime(true);
$pdo->exec(str_replace(':count', (string) $count, file_get_contents(BASE_PATH . '/sql/searchdata.sql')));
$total = (int) $pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
printf("загружено за %.1f с, задач в базе: %d\n", microtime(true) - $start, $total);

App\Core\Cache::flushApp();
echo "кеш очищен. Теперь перестройте индекс поиска: php bin/reindex.php\n";
