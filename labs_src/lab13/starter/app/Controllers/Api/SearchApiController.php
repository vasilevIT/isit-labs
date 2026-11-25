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

        // TODO 4.2  Обработчик поиска. Параметры запроса уже разобраны (переменные $q, $status, $projectId, $limit, $offset).
        //           Нужно проверить запрос, выполнить поиск и подготовить ответ:
        //             1) если $q пуст — ответ 422: json_error('Ошибка проверки данных', 422, ['q' => 'Введите поисковый запрос']);
        //             2) $started = microtime(true); $found = $this->find($q, $status, $projectId, $limit, $offset);  (метод find() ниже,
        //                он вернёт ['engine' => 'opensearch', 'total' => число, 'results' => список]); время в миллисекундах:
        //                $ms = (int) round((microtime(true) - $started) * 1000);
        //             3) соберите ответ $payload: 'query' => $q, 'engine' => $found['engine'], 'total' => $found['total'], 'took_ms' => $ms,
        //                'message' => понятное сообщение, если ничего не найдено ($found['total'] === 0), иначе null, 'results' => $found['results'].
        //                Пустой результат — это не ошибка: код остаётся 200, но пользователю нужно понятное сообщение, а не пустой экран.
        //           (Отправка ответа и запись в журнал ниже уже готовы или будут в задании 4.4.)
        //           Подсказка:
        //              if ($q === '') { json_error('Ошибка проверки данных', 422, ['q' => 'Введите поисковый запрос']); }
        //              $started = microtime(true);
        //              $found = $this->find($q, $status, $projectId, $limit, $offset);
        //              $ms = (int) round((microtime(true) - $started) * 1000);
        //              $payload = ['query' => $q, 'engine' => $found['engine'], 'total' => $found['total'], 'took_ms' => $ms,
        //                  'message' => $found['total'] === 0 ? 'По запросу «' . $q . '» ничего не найдено...' : null, 'results' => $found['results']];
        //           ✔ Проверка: GET /api/search без q → 422; GET /api/search?q=форма → 200, results не пуст; GET /api/search?q=абракадабра → 200,
        //             results пуст, message объясняет почему.
        json_error('Поиск не написан (TODO 4.2)', 501);

        // TODO 4.4  Журнал поиска. Каждый поисковый запрос — это событие (лабораторная работа №12): по нему потом считают, что ищут
        //           чаще всего и что не находят (в лабораторной работе №14 это попадёт в аналитику). Запишите событие типа 'search':
        //              Events::log('search', 'Поиск: «' . mb_substr($q, 0, 100) . '» (найдено: ' . $found['total'] . ')', [
        //                  'details' => ['q' => mb_substr($q, 0, 200), 'found' => $found['total'], 'took_ms' => $ms, 'engine' => $found['engine']],
        //              ]);
        //           (длинный запрос обрезается: в журнал не должен попасть мегабайт текста). Запросы пользователей могут содержать личные
        //           данные: журнал читает руководитель, поэтому не записывайте лишнего.
        //           ✔ Проверка: после нескольких поисков GET /api/events?type=search под Анной показывает ваши запросы и число найденного.

        header('X-Total-Count: ' . $payload['total']);                     // всего найдено (ГОТОВО)
        header('X-Search-Engine: ' . $payload['engine']);                  // кто искал: opensearch или postgres
        json_response($payload);
    }

    // Выполняет поиск и возвращает ['engine' => 'opensearch'|'postgres', 'total' => ..., 'results' => [...]].
    private function find(string $q, string $status, ?int $projectId, int $limit, int $offset): array
    {
        // TODO 4.3  Запасной вариант. Поиск — удобство, а не основа системы: если OpenSearch недоступен (код исключения 503 из
        //           Search::request), пользователь не должен остаться без поиска. Тогда ищем в PostgreSQL: метод findInPostgres() готов.
        //             1) если в config('search')['enabled'] включён поиск, попробуйте
        //                return ['engine' => 'opensearch'] + Search::search($q, $status, $projectId, $limit, $offset);  в блоке try;
        //             2) поймайте \RuntimeException: если код не 503 — бросьте исключение дальше (throw $e: это ошибка, а не «нет связи»);
        //                если 503 — запишите причину в журнал ошибок PHP (error_log) и перейдите к запасному варианту;
        //             3) в конце верните $this->findInPostgres(...): в ответе будет engine = 'postgres'.
        //           Для проверки запасного варианта есть режим сравнения: заголовок запроса «X-Search-Mode: postgres» (работает при
        //           debug = true) сразу включает поиск через PostgreSQL:
        //              $forced = config('debug') && ($_SERVER['HTTP_X_SEARCH_MODE'] ?? '') === 'postgres';
        //              if (!$forced && config('search')['enabled']) { try { ... } catch (\RuntimeException $e) { ... } }
        //           ✔ Проверка: curl -si "http://localhost:8000/api/search?q=форм" -H "X-Search-Mode: postgres" -H "Authorization: ..." —
        //             заголовок X-Search-Engine: postgres; остановите OpenSearch (docker compose stop opensearch): поиск продолжает отвечать
        //             через PostgreSQL, после docker compose start opensearch снова engine = opensearch.
        return ['engine' => 'opensearch'] + Search::search($q, $status, $projectId, $limit, $offset);   // пока без запасного варианта: при недоступности OpenSearch будет ответ 503
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
