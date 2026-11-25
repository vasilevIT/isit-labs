<?php
// bin/search-demo.php — поиск из консоли, без сервера и входа: удобно смотреть, как OpenSearch оценивает результаты.
// Запуск:
//     php bin/search-demo.php "форма"
//     php bin/search-demo.php "форсма"                 # с опечаткой
//     php bin/search-demo.php "портал" --status=new --limit=5
//     php bin/search-demo.php "отчёт" --project=2

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

use App\Core\Search;

$options = getopt('', ['status::', 'project::', 'limit::']);
$words = array_values(array_filter(array_slice($argv, 1), fn(string $a): bool => !str_starts_with($a, '--')));
$q = trim((string) ($words[0] ?? ''));
if ($q === '') {
    echo "Использование: php bin/search-demo.php \"слова для поиска\" [--status=done] [--project=1] [--limit=10]\n";
    exit(1);
}
if (!Search::ping()) {
    echo "OpenSearch не отвечает. Проверьте: php bin/search-check.php\n";
    exit(1);
}

try {
    $found = Search::search($q, (string) ($options['status'] ?? ''), isset($options['project']) ? (int) $options['project'] : null,
        (int) ($options['limit'] ?? 10));
} catch (Throwable $e) {
    echo 'Поиск не удался: ' . $e->getMessage() . "\n";
    exit(1);
}

printf("запрос «%s»: найдено %d, OpenSearch искал %d мс\n\n", $q, $found['total'], $found['took']);
if ($found['results'] === []) {
    echo "Ничего не найдено. Попробуйте другие слова.\n";
}
// <mark>слово</mark> в консоли показываем ярко-жёлтым
$mark = fn(string $s): string => str_replace(['<mark>', '</mark>'], ["\033[1;33m", "\033[0m"], htmlspecialchars_decode($s));
foreach ($found['results'] as $hit) {
    $h = (array) $hit['highlight'];
    printf("  оценка %-6s №%-4d %s  [%s, проект «%s»]\n", $hit['score'] ?? '-', $hit['id'], $mark($h['title'] ?? $hit['title']), $hit['status'], $hit['project_title']);
    if (isset($h['description'])) {
        echo '                     … ', $mark($h['description']), "\n";
    }
}
