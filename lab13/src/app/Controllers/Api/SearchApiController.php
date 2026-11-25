<?php
// app/Controllers/Api/SearchApiController.php — полнотекстовый поиск по задачам: GET /api/search
//
//   GET /api/search?q=форма                          слова для поиска (обязательно)
//   GET /api/search?q=форма&status=in_progress       фильтр по статусу (new, in_progress, done)
//   GET /api/search?q=форма&project_id=1             фильтр по проекту
//   GET /api/search?q=форма&limit=10&offset=20       страница результатов (по умолчанию 10, не больше 100)
//
// Ответ: {"query": "форма", "engine": "opensearch", "total": 3, "took_ms": 4, "message": null, "results": [...]},
// в каждом результате: поля задачи, score (насколько запись подходит запросу) и highlight (фрагменты с <mark>).
// Заголовки ответа: X-Total-Count (всего найдено) и X-Search-Engine (opensearch или postgres).
// Каждый поисковый запрос записывается в журнал событий (тип search). Читать результаты может любой вошедший участник.

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Events;
use App\Core\Search;
use App\Models\ProjectRepository;
use App\Models\TaskRepository;

final class SearchApiController
{
    public function index(): void
    {
        // Параметры запроса (ГОТОВО)
        $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $status = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
        $projectId = is_string($_GET['project_id'] ?? null) && ctype_digit($_GET['project_id']) ? (int) $_GET['project_id'] : null;
        $limit = is_string($_GET['limit'] ?? null) && ctype_digit($_GET['limit']) ? min(max((int) $_GET['limit'], 1), 100) : 10;
        $offset = is_string($_GET['offset'] ?? null) && ctype_digit($_GET['offset']) ? (int) $_GET['offset'] : 0;
        if ($status !== '' && !in_array($status, ['new', 'in_progress', 'done'], true)) {
            json_error('Ошибка проверки данных', 422, ['status' => 'Статус: new, in_progress или done']);
        }

        if ($q === '') {
            json_error('Ошибка проверки данных', 422, ['q' => 'Введите поисковый запрос']);
        }

        $started = microtime(true);
        $found = $this->find($q, $status, $projectId, $limit, $offset);   // ['engine' => ..., 'total' => ..., 'results' => [...]]
        $ms = (int) round((microtime(true) - $started) * 1000);

        $payload = [
            'query'   => $q,
            'engine'  => $found['engine'],
            'total'   => $found['total'],
            'took_ms' => $ms,
            'message' => $found['total'] === 0 ? 'По запросу «' . $q . '» ничего не найдено. Попробуйте другие слова или уберите фильтры.' : null,
            'results' => $found['results'],
        ];

        Events::log('search', 'Поиск: «' . mb_substr($q, 0, 100) . '» (найдено: ' . $found['total'] . ')', [
            'details' => ['q' => mb_substr($q, 0, 200), 'found' => $found['total'], 'took_ms' => $ms, 'engine' => $found['engine'], 'status' => $status, 'project_id' => $projectId],
        ]);

        header('X-Total-Count: ' . $payload['total']);                     // всего найдено (ГОТОВО)
        header('X-Search-Engine: ' . $payload['engine']);                  // кто искал: opensearch или postgres
        json_response($payload);
    }

    // Выполняет поиск и возвращает ['engine' => 'opensearch'|'postgres', 'total' => ..., 'results' => [...]].
    private function find(string $q, string $status, ?int $projectId, int $limit, int $offset): array
    {
        // Режим сравнения: заголовок «X-Search-Mode: postgres» (при debug = true) принудительно включает поиск через PostgreSQL.
        $forced = config('debug') && ($_SERVER['HTTP_X_SEARCH_MODE'] ?? '') === 'postgres';
        if (!$forced && config('search')['enabled']) {
            try {
                return ['engine' => 'opensearch'] + Search::search($q, $status, $projectId, $limit, $offset);
            } catch (\RuntimeException $e) {
                if ($e->getCode() !== 503) {
                    throw $e;                                              // это ошибка запроса или кода, а не «нет связи»: прятать её нельзя
                }
                error_log('Поиск: OpenSearch недоступен, ищу в PostgreSQL: ' . $e->getMessage());
            }
        }
        return $this->findInPostgres($q, $status, $projectId, $limit, $offset);   // запасной вариант: поиск продолжает работать
    }

    // Запасной поиск в PostgreSQL (ГОТОВО): только по названию задачи (ILIKE), без основ слов, опечаток и подсветки.
    // Ответ имеет тот же вид, что у поиска в OpenSearch, поэтому клиент не замечает подмены.
    private function findInPostgres(string $q, string $status, ?int $projectId, int $limit, int $offset): array
    {
        $result = (new TaskRepository())->list($status, $projectId, $q, '', $limit, $offset);
        $projects = array_column((new ProjectRepository())->all(), 'title', 'id');
        $results = array_map(fn(array $t): array => [
            'id'            => $t['id'],
            'title'         => $t['title'],
            'description'   => $t['description'],
            'status'        => $t['status'],
            'priority'      => $t['priority'],
            'project_id'    => $t['project_id'],
            'project_title' => $projects[$t['project_id']] ?? '',
            'due_date'      => $t['due_date'],
            'score'         => null,                                       // PostgreSQL не оценивает релевантность
            'highlight'     => new \stdClass(),
        ], $result['rows']);
        return ['engine' => 'postgres', 'total' => $result['total'], 'results' => $results];
    }
}
