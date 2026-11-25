<?php
// app/Core/Search.php — полнотекстовый поиск по задачам в OpenSearch (лекция 4.1).
//
// OpenSearch — отдельный сервер, который умеет искать по тексту: находит «форму» по запросу «форма», прощает опечатки,
// сортирует результаты по релевантности (насколько хорошо запись подходит запросу) и подсвечивает найденные слова.
// PostgreSQL остаётся главным хранилищем («источником правды»), а OpenSearch хранит только поисковую копию задач.
//
// Как это устроено:
//   • Приложение общается с OpenSearch по HTTP: отправляет JSON, получает JSON (метод request()).
//   • Данные хранятся в ИНДЕКСЕ (аналог таблицы). Документ — это JSON-копия одной задачи (вместе с названием её проекта).
//   • Индекс называется tasks_v<время>, а приложение обращается к нему по ПСЕВДОНИМУ (alias) tasks.
//     Псевдоним — это второе имя индекса. При полной переиндексации (reindexAll) строится новый индекс, а псевдоним
//     переключается на него одной неделимой операцией: поиск не прерывается ни на секунду.
//   • Копия обновляется при каждом изменении задачи фоновой задачей search_index (app/Jobs/SearchIndexJob.php).

declare(strict_types=1);

namespace App\Core;

final class Search
{
    // Имя псевдонима: приложение всегда обращается к нему (ГОТОВО)
    public static function alias(): string
    {
        return config('search')['index'];
    }

    // ---------------------------------------------------------------------
    // Часть 1. Подключение
    // ---------------------------------------------------------------------

    // Отправляет запрос OpenSearch и возвращает ['status' => код ответа, 'json' => разобранный ответ или null].
    //   $method — 'GET', 'PUT', 'POST', 'DELETE';  $path — адрес внутри OpenSearch, например '/tasks/_search';
    //   $body   — тело: массив превращается в JSON; строка отправляется как есть (так передаётся пакет для _bulk, см. bulk());
    //   $query  — параметры адреса, например ['refresh' => 'wait_for'].
    // Если OpenSearch не отвечает (не запущен, неверный порт), метод бросает исключение с кодом 503: так же ведёт себя Queue::redis().
    public static function request(string $method, string $path, array|string|null $body = null, array $query = []): array
    {
        $c = config('search');
        $url = $c['url'] . $path . ($query ? '?' . http_build_query($query) : '');

        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: ' . (is_string($body) ? 'application/x-ndjson' : 'application/json');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,                    // вернуть ответ строкой, а не печатать
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => $c['connect_timeout'],   // недоступный сервер не должен «вешать» запрос
            CURLOPT_TIMEOUT        => $c['timeout'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $text = curl_exec($ch);
        if ($text === false) {                                 // нет связи: сервер выключен, неверный адрес или порт
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Поиск недоступен: OpenSearch не отвечает (' . $c['url'] . '): ' . $error, 503);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'json' => json_decode($text, true)];
    }

    // Отвечает ли OpenSearch: true — да, false — нет.
    public static function ping(): bool
    {
        try {
            $r = self::request('GET', '/');                    // корневой адрес возвращает название кластера и версию
            return $r['status'] === 200 && isset($r['json']['version']['number']);
        } catch (\RuntimeException $e) {
            return false;                                      // нет связи: поиск недоступен
        }
    }

    // Версия OpenSearch для вывода в скриптах (ГОТОВО).
    public static function version(): string
    {
        try {
            return (string) (self::request('GET', '/')['json']['version']['number'] ?? '?');
        } catch (\RuntimeException $e) {
            return '?';
        }
    }

    // ---------------------------------------------------------------------
    // Часть 2. Индекс и пакетная загрузка
    // ---------------------------------------------------------------------

    // Устройство индекса: какие поля есть у документа и как OpenSearch должен их обрабатывать.
    // Возвращает массив, который отправляется в OpenSearch при создании индекса.
    public static function mapping(): array
    {
        return [
            'settings' => [
                'number_of_shards'   => 1,                     // один узел и мало данных: одного «осколка» хватает
                'number_of_replicas' => 0,                     // копий на других узлах нет (узел один)
            ],
            'mappings' => [
                'properties' => [
                    'id'            => ['type' => 'integer'],  // номер задачи (по нему упорядочиваем результаты с одинаковой релевантностью)
                    'title'         => [
                        'type'     => 'text',                  // text: текст разбирается на слова и ищется по словам
                        'analyzer' => 'russian',               // анализатор russian: приводит слова к основе («формы» → «форм») и отбрасывает «и», «в», «на»
                        'fields'   => ['raw' => ['type' => 'keyword', 'ignore_above' => 256]],   // title.raw: строка целиком (для сортировки)
                    ],
                    'description'   => ['type' => 'text', 'analyzer' => 'russian'],
                    'project_title' => ['type' => 'text', 'analyzer' => 'russian'],     // название проекта: задачу можно найти по проекту
                    'project_id'    => ['type' => 'integer'],  // фильтр: число
                    'status'        => ['type' => 'keyword'],  // keyword: значение целиком, без разбора на слова (фильтры, группировки)
                    'priority'      => ['type' => 'keyword'],
                    'due_date'      => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
                    'created_at'    => ['type' => 'date'],
                ],
            ],
        ];
    }

    // Создаёт новый пустой индекс с нашей структурой (ГОТОВО).
    public static function createIndex(string $name): void
    {
        $r = self::request('PUT', '/' . $name, self::mapping());
        if ($r['status'] !== 200) {
            throw new \RuntimeException('Не удалось создать индекс «' . $name . '»: ' . ($r['json']['error']['reason'] ?? 'код ' . $r['status']), 502);
        }
    }

    // Загружает пачку документов одним запросом (операция _bulk). Загружать по одному документу тысячи раз слишком долго.
    //   $docs  — список документов, $index — имя индекса (или псевдонима), куда кладём.
    public static function bulk(array $docs, string $index): void
    {
        $lines = [];
        foreach ($docs as $doc) {
            $lines[] = json_encode(['index' => ['_id' => $doc['id']]]);                 // строка 1: что делаем и с каким номером
            $lines[] = json_encode($doc, JSON_UNESCAPED_UNICODE);                       // строка 2: сам документ
        }
        $params = $index === self::alias() ? ['require_alias' => 'true'] : [];
        $r = self::request('POST', '/' . $index . '/_bulk', implode("\n", $lines) . "\n", $params);   // формат NDJSON: каждая строка — отдельный JSON, в конце перевод строки
        if ($r['status'] !== 200 || !empty($r['json']['errors'])) {                      // errors = true: часть документов не принята
            $reason = 'код ' . $r['status'];
            foreach ($r['json']['items'] ?? [] as $item) {
                if (isset($item['index']['error'])) {
                    $reason = $item['index']['error']['reason'] ?? $reason;
                    break;
                }
            }
            throw new \RuntimeException('Пакетная загрузка не удалась: ' . $reason, 502);
        }
    }

    // Полная переиндексация (ГОТОВО): строит НОВЫЙ индекс из PostgreSQL и переключает на него псевдоним, старый удаляет.
    // Пока идёт загрузка, поиск работает по старому индексу; переключение псевдонима — одна неделимая операция.
    // Возвращает ['index' => имя нового индекса, 'count' => сколько задач, 'seconds' => время, 'removed' => старые индексы].
    public static function reindexAll(int $batchSize = 500): array
    {
        $started = microtime(true);
        $new = self::alias() . '_v' . (int) (microtime(true) * 1000);   // например tasks_v1731500000000
        self::createIndex($new);

        try {
            // На время загрузки отключаем автоматическое обновление индекса (refresh): так загрузка идёт быстрее и тратит меньше памяти.
            self::request('PUT', '/' . $new . '/_settings', ['index' => ['refresh_interval' => '-1']]);
            $count = 0;
            $batch = [];
            $lastId = 0;
            $stmt = Database::connection()->prepare(self::SELECT . ' WHERE t.id > :last ORDER BY t.id LIMIT ' . $batchSize);
            do {
                $stmt->execute(['last' => $lastId]);
                $rows = $stmt->fetchAll();                               // следующая пачка: все задачи с номером больше последнего
                if ($rows) {
                    self::bulk(array_map([self::class, 'document'], $rows), $new);
                    $lastId = (int) end($rows)['id'];
                    $count += count($rows);
                }
            } while (count($rows) === $batchSize);
            self::request('PUT', '/' . $new . '/_settings', ['index' => ['refresh_interval' => '1s']]);   // вернуть обычный режим обновления
            self::request('POST', '/' . $new . '/_refresh');             // и сразу сделать загруженное видимым для поиска

            $old = [];                                                   // индексы, на которые псевдоним указывал до сих пор
            $removed = [];                                               // индексы, которые мы удалили
            $r = self::request('GET', '/_alias/' . self::alias());
            if ($r['status'] === 200) {
                $old = array_keys($r['json']);
            }
            $actions = [['add' => ['index' => $new, 'alias' => self::alias()]]];
            foreach ($old as $name) {
                $actions[] = ['remove' => ['index' => $name, 'alias' => self::alias()]];
            }
            $r = self::request('POST', '/_aliases', ['actions' => $actions]);   // все действия выполняются одной неделимой операцией
            if ($r['status'] !== 200) {
                throw new \RuntimeException('Не удалось переключить псевдоним: ' . ($r['json']['error']['reason'] ?? 'код ' . $r['status']), 502);
            }
            // Старые индексы больше не нужны: удаляем и те, на которые указывал псевдоним, и «осиротевшие» (остались после сбоев)
            $r = self::request('GET', '/_cat/indices/' . self::alias() . '_v*?format=json');
            $leftovers = $r['status'] === 200 ? array_column($r['json'], 'index') : [];
            foreach (array_unique(array_merge($old, $leftovers)) as $name) {
                if ($name !== $new) {
                    self::request('DELETE', '/' . $name);
                    $removed[] = $name;
                }
            }
        } catch (\Throwable $e) {
            try {
                self::request('DELETE', '/' . $new);                     // ничего не вышло: убираем недостроенный индекс
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }
        return ['index' => $new, 'count' => $count, 'seconds' => microtime(true) - $started, 'removed' => $removed];
    }

    // ---------------------------------------------------------------------
    // Часть 3. Индексация: перенос данных из PostgreSQL в OpenSearch
    // ---------------------------------------------------------------------

    // Документ для OpenSearch из строки базы (ГОТОВО). В строке есть и название проекта (project_title).
    public static function document(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'title'         => $row['title'],
            'description'   => $row['description'],
            'project_id'    => (int) $row['project_id'],
            'project_title' => $row['project_title'],
            'status'        => $row['status'],
            'priority'      => $row['priority'],
            'due_date'      => $row['due_date'],                       // 'ГГГГ-ММ-ДД' или null
            'created_at'    => date('c', strtotime((string) $row['created_at'])),
        ];
    }

    private const SELECT = 'SELECT t.id, t.project_id, t.title, t.description, t.status, t.priority, t.due_date, t.created_at, p.title AS project_title
                            FROM tasks t JOIN projects p ON p.id = t.project_id';

    // Документ одной задачи по её номеру: данные берутся из PostgreSQL ПРЯМО СЕЙЧАС. null, если задачи уже нет (ГОТОВО).
    public static function taskDocument(int $taskId): ?array
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE t.id = :id');
        $stmt->execute(['id' => $taskId]);
        $row = $stmt->fetch();
        return $row ? self::document($row) : null;
    }

    // Документы всех задач одного проекта (ГОТОВО): нужны, когда проект переименовали.
    public static function projectDocuments(int $projectId): array
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE t.project_id = :id ORDER BY t.id');
        $stmt->execute(['id' => $projectId]);
        return array_map([self::class, 'document'], $stmt->fetchAll());
    }

    // Кладёт в индекс один документ (или заменяет, если документ с таким номером уже есть).
    // Повторный вызов с теми же данными ничего не портит: такая операция называется идемпотентной.
    public static function indexDocument(array $doc): void
    {
        // PUT /tasks/_doc/5: документ с номером 5. require_alias — «работай только через псевдоним»: если индекса ещё нет, это ошибка,
        // а не молчаливое создание индекса со случайной структурой. refresh=wait_for — дождаться, пока документ станет виден поиску.
        $r = self::request('PUT', '/' . self::alias() . '/_doc/' . $doc['id'], $doc, ['refresh' => 'wait_for', 'require_alias' => 'true']);
        if (!in_array($r['status'], [200, 201], true)) {       // 201 — документ создан, 200 — заменён
            throw new \RuntimeException('Не удалось проиндексировать задачу №' . $doc['id'] . ': ' . ($r['json']['error']['reason'] ?? 'код ' . $r['status']), 502);
        }
    }

    // Удаляет документ задачи из индекса. Если документа уже нет, это не ошибка (повтор безопасен).
    public static function deleteDocument(int $taskId): void
    {
        $r = self::request('DELETE', '/' . self::alias() . '/_doc/' . $taskId, null, ['refresh' => 'wait_for']);
        if (!in_array($r['status'], [200, 404], true)) {       // 404 — документа не было: цель достигнута
            throw new \RuntimeException('Не удалось удалить задачу №' . $taskId . ' из поиска: ' . ($r['json']['error']['reason'] ?? 'код ' . $r['status']), 502);
        }
    }

    // ---------------------------------------------------------------------
    // Часть 4. Поиск
    // ---------------------------------------------------------------------

    // Ищет задачи. Возвращает ['total' => сколько всего найдено, 'took' => сколько миллисекунд искал OpenSearch, 'results' => [...]].
    //   $q — слова для поиска;  $status, $projectId — необязательные фильтры;  $limit, $offset — страница результатов.
    public static function search(string $q, string $status = '', ?int $projectId = null, int $limit = 10, int $offset = 0): array
    {
        $filter = [];                                                  // фильтры не влияют на релевантность: они только отсеивают
        if ($status !== '') {
            $filter[] = ['term' => ['status' => $status]];
        }
        if ($projectId !== null) {
            $filter[] = ['term' => ['project_id' => $projectId]];
        }

        $body = [
            'from'             => $offset,
            'size'             => $limit,
            'track_total_hits' => true,                                // считать точное число найденных (по умолчанию считает до 10 000)
            'query' => ['bool' => [
                'must' => [[
                    'multi_match' => [                                 // искать запрос сразу в нескольких полях
                        'query'     => $q,
                        'fields'    => ['title^3', 'project_title^2', 'description'],   // ^3 — совпадение в названии втрое важнее, чем в описании
                        'fuzziness' => 'AUTO',                         // прощать опечатки: «форсма» найдёт «форма»
                    ],
                ]],
                'filter' => $filter,
            ]],
            'sort' => [['_score' => 'desc'], ['id' => 'asc']],         // сначала самые релевантные; при равенстве — по номеру
            'highlight' => [                                           // подсветка: найденные слова оборачиваются в <mark>
                'encoder'   => 'html',                                 // экранировать HTML в тексте: иначе название «<script>...» попало бы на страницу как код
                'pre_tags'  => ['<mark>'],
                'post_tags' => ['</mark>'],
                'fields'    => [
                    'title'         => ['number_of_fragments' => 0],   // название показываем целиком
                    'project_title' => ['number_of_fragments' => 0],
                    'description'   => ['number_of_fragments' => 1, 'fragment_size' => 150],   // из описания — один подходящий кусок
                ],
            ],
        ];
        $r = self::request('POST', '/' . self::alias() . '/_search', $body);
        if ($r['status'] !== 200) {
            throw new \RuntimeException('Поиск вернул ошибку: ' . ($r['json']['error']['reason'] ?? 'код ' . $r['status']), 502);
        }
        return [
            'total'   => (int) $r['json']['hits']['total']['value'],
            'took'    => (int) $r['json']['took'],
            'results' => array_map([self::class, 'formatHit'], $r['json']['hits']['hits']),
        ];
    }

    // Один найденный документ в виде, удобном клиенту (ГОТОВО): поля задачи, оценка релевантности и подсвеченные фрагменты.
    public static function formatHit(array $hit): array
    {
        $doc = $hit['_source'];
        return [
            'id'            => $doc['id'],
            'title'         => $doc['title'],
            'description'   => $doc['description'],
            'status'        => $doc['status'],
            'priority'      => $doc['priority'],
            'project_id'    => $doc['project_id'],
            'project_title' => $doc['project_title'],
            'due_date'      => $doc['due_date'],
            'score'         => isset($hit['_score']) ? round((float) $hit['_score'], 3) : null,   // насколько запись подходит запросу
            'highlight'     => (object) array_map(fn(array $parts): string => implode(' … ', $parts), $hit['highlight'] ?? []),
        ];
    }
}
