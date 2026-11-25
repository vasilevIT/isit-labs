<?php
// bin/reindex.php — полная переиндексация: строит новый индекс из PostgreSQL и переключает на него псевдоним tasks.
// Запуск:  php bin/reindex.php
//          php bin/reindex.php --batch=200     # меньше документов в одной пачке (по умолчанию 500; если OpenSearch не хватает памяти)
// Когда нужна: первый запуск (индекса ещё нет), после загрузки большого числа задач (php bin/load-searchdata.php),
// после изменения структуры индекса (Search::mapping), если поиск «разошёлся» с базой (например, воркер был выключен).
// Пока идёт загрузка, поиск работает по старому индексу; псевдоним переключается одной неделимой операцией.

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

use App\Core\Search;

if (!Search::ping()) {
    echo 'OpenSearch не отвечает (' . config('search')['url'] . "). Проверьте: php bin/search-check.php\n";
    exit(1);
}
$options = getopt('', ['batch::']);
try {
    $result = Search::reindexAll(max(10, (int) ($options['batch'] ?? 500)));
} catch (Throwable $e) {
    echo 'Переиндексация не удалась: ' . $e->getMessage() . "\n";
    exit(1);
}
printf("проиндексировано задач: %d за %.1f с (%.0f задач в секунду)\n", $result['count'], $result['seconds'], $result['count'] / max(0.001, $result['seconds']));
echo 'новый индекс: ' . $result['index'] . ', псевдоним ' . Search::alias() . " переключён\n";
echo $result['removed'] ? 'удалены старые индексы: ' . implode(', ', $result['removed']) . "\n" : "старых индексов не было\n";
