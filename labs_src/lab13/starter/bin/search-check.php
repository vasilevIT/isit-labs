<?php
// bin/search-check.php — проверка подключения приложения к OpenSearch и состояния поискового индекса.
// Запуск:  php bin/search-check.php      Код завершения: 0 — OpenSearch отвечает, 1 — нет.
// Проверяет задания 1.1 (request) и 1.2 (ping); индекс создаёт и наполняет php bin/reindex.php.

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

use App\Core\Search;

if (!extension_loaded('curl')) {
    echo "Не установлено расширение PHP curl.\n";
    exit(1);
}
$url = config('search')['url'];
if (!config('search')['enabled']) {
    echo "Поиск через OpenSearch выключен (SEARCH_ENABLED=0): работает запасной вариант на PostgreSQL.\n";
    exit(1);
}
if (!Search::ping()) {
    echo "OpenSearch не отвечает ($url).\n";
    echo "Проверьте: docker compose ps (контейнер opensearch стартует 20–60 секунд), docker compose up -d, переменную OPENSEARCH_PORT,\n";
    echo "а также, выполнено ли задание 1.1 (Search::request) и 1.2 (Search::ping) в app/Core/Search.php.\n";
    exit(1);
}
echo 'OpenSearch отвечает: версия ', Search::version(), " ($url)\n";

$health = Search::request('GET', '/_cluster/health')['json'] ?? [];
echo 'состояние кластера: ', $health['status'] ?? '?', " (green — всё хорошо; yellow — у одного узла нет копий индекса, для учёбы это нормально)\n";

$alias = Search::alias();
$r = Search::request('GET', '/_alias/' . $alias);
if ($r['status'] !== 200) {
    echo "псевдонима «{$alias}» ещё нет: индекс не создан. Выполните php bin/reindex.php\n";
    exit(0);
}
foreach (array_keys($r['json']) as $index) {
    $count = Search::request('GET', '/' . $index . '/_count')['json']['count'] ?? '?';
    echo "псевдоним «{$alias}» указывает на индекс {$index}: документов {$count}\n";
}
