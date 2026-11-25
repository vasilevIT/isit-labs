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
        // TODO 1.1  Запрос к OpenSearch. OpenSearch — обычный HTTP-сервер, поэтому запрос делается так же, как curl в терминале.
        //           Метод должен собрать адрес, отправить запрос и вернуть код ответа вместе с разобранным JSON:
        //             1) адрес: $c = config('search'); $url = $c['url'] . $path; если есть $query, добавьте '?' . http_build_query($query);
        //             2) $ch = curl_init($url); настройки через curl_setopt_array:
        //                CURLOPT_CUSTOMREQUEST => $method (GET, PUT...), CURLOPT_RETURNTRANSFER => true (вернуть ответ строкой),
        //                CURLOPT_HTTPHEADER => заголовки, CURLOPT_CONNECTTIMEOUT => $c['connect_timeout'], CURLOPT_TIMEOUT => $c['timeout'];
        //             3) заголовки: 'Accept: application/json' и, если есть тело, 'Content-Type: application/json' (для строки — 'application/x-ndjson');
        //             4) тело (если есть): CURLOPT_POSTFIELDS => массив превращается в JSON (json_encode с JSON_UNESCAPED_UNICODE), строка отправляется как есть;
        //             5) $text = curl_exec($ch); если вернулось false, связи нет: бросьте
        //                new \RuntimeException('Поиск недоступен: OpenSearch не отвечает (' . $c['url'] . '): ' . curl_error($ch), 503);
        //             6) иначе верните ['status' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'json' => json_decode($text, true)].
        //           Подсказка:
        //              $c = config('search');
        //              $url = $c['url'] . $path . ($query ? '?' . http_build_query($query) : '');
        //              $headers = ['Accept: application/json'];
        //              if ($body !== null) {
        //                  $headers[] = 'Content-Type: ' . (is_string($body) ? 'application/x-ndjson' : 'application/json');
        //              }
        //              $ch = curl_init($url);
        //              curl_setopt_array($ch, [
        //                  CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        //                  CURLOPT_CONNECTTIMEOUT => $c['connect_timeout'], CURLOPT_TIMEOUT => $c['timeout'],
        //              ]);
        //              if ($body !== null) {
        //                  curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE));
        //              }
        //              $text = curl_exec($ch);
        //              if ($text === false) { ... throw ..., 503 }
        //              $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        //              curl_close($ch);
        //              return ['status' => $status, 'json' => json_decode($text, true)];
        //           ✔ Проверка: curl http://localhost:9200 показывает версию OpenSearch; php bin/search-check.php начнёт получать ответ
        //             (пока без задания 1.2 он скажет «OpenSearch не отвечает»).
        throw new \RuntimeException('Поиск недоступен: метод Search::request() ещё не написан (TODO 1.1)', 503);
    }

    // Отвечает ли OpenSearch: true — да, false — нет.
    public static function ping(): bool
    {
        // TODO 1.2  Проверка связи. Отправьте запрос GET / (корневой адрес OpenSearch): живой сервер отвечает кодом 200 и JSON с версией
        //           {"name": "...", "version": {"number": "2.17.1"}, ...}. Метод должен вернуть true, если код 200 и версия есть,
        //           и false, если связи нет (исключение 503 из request() нужно поймать: проверка не должна падать).
        //           Подсказка:
        //              try {
        //                  $r = self::request('GET', '/');
        //                  return $r['status'] === 200 && isset($r['json']['version']['number']);
        //              } catch (\RuntimeException $e) {
        //                  return false;
        //              }
        //           ✔ Проверка: php bin/search-check.php — «OpenSearch отвечает: версия 2.17.1»;
        //             OPENSEARCH_PORT=1 php bin/search-check.php — понятное сообщение «OpenSearch не отвечает» и код завершения 1.
        return false;
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
        // TODO 2.1  Структура индекса (mapping). OpenSearch сам умеет угадывать типы полей, но для хорошего поиска нужно задать их явно.
        //           Верните массив из двух частей: 'settings' и 'mappings' => ['properties' => [...]].
        //             settings:  'number_of_shards' => 1, 'number_of_replicas' => 0   (узел один, копии не нужны);
        //             поля (properties):
        //               id            integer
        //               title         text с анализатором russian; ещё подполе 'raw' типа keyword (строка целиком, для сортировки)
        //               description   text с анализатором russian
        //               project_title text с анализатором russian   (по названию проекта тоже можно искать)
        //               project_id    integer
        //               status        keyword                       (значение целиком: по нему фильтруют, а не ищут)
        //               priority      keyword
        //               due_date      date с форматом yyyy-MM-dd
        //               created_at    date
        //           Тип text — для полей, по которым ищут слова; keyword — для значений, которые сравнивают целиком (статус, приоритет).
        //           Анализатор russian приводит слова к основе: «форма», «формы», «формой» превращаются в «форм», и поиск находит их все.
        //           Подсказка:
        //              return [
        //                  'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0],
        //                  'mappings' => ['properties' => [
        //                      'id'     => ['type' => 'integer'],
        //                      'title'  => ['type' => 'text', 'analyzer' => 'russian', 'fields' => ['raw' => ['type' => 'keyword', 'ignore_above' => 256]]],
        //                      'description'   => ['type' => 'text', 'analyzer' => 'russian'],
        //                      'project_title' => ['type' => 'text', 'analyzer' => 'russian'],
        //                      'project_id'    => ['type' => 'integer'],
        //                      'status'        => ['type' => 'keyword'],
        //                      'priority'      => ['type' => 'keyword'],
        //                      'due_date'      => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        //                      'created_at'    => ['type' => 'date'],
        //                  ]],
        //              ];
        //           ✔ Проверка: php bin/reindex.php создаёт индекс и перекладывает в него задачи (нужно ещё задание 2.2, bulk), затем
        //             curl "http://localhost:9200/tasks/_mapping?pretty": виден ваш mapping. Проверка анализатора:
        //             curl -s -X POST "http://localhost:9200/tasks/_analyze" -H "Content-Type: application/json" -d '{"analyzer":"russian","text":"формы"}'
        throw new \RuntimeException('Структура индекса не описана (TODO 2.1)', 501);
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
        // TODO 2.2  Пакетная загрузка (_bulk). Тело запроса — не один JSON, а строки (формат NDJSON): на каждый документ две строки,
        //           в конце обязательно перевод строки:
        //              {"index": {"_id": 5}}                       ← что сделать и с каким номером
        //              {"id": 5, "title": "...", ...}              ← сам документ
        //           Метод должен:
        //             1) собрать массив строк $lines: для каждого документа json_encode(['index' => ['_id' => $doc['id']]]) и
        //                json_encode($doc, JSON_UNESCAPED_UNICODE);
        //             2) отправить POST /<индекс>/_bulk, тело implode("\n", $lines) . "\n" (строка: request() отправит её как NDJSON);
        //                если индекс — это псевдоним (self::alias()), добавьте параметр 'require_alias' => 'true' (как в 3.1);
        //             3) успех: код 200 и в ответе 'errors' === false. Если errors = true, часть документов не принята: найдите в
        //                $r['json']['items'] первую запись с ошибкой ($item['index']['error']['reason']) и бросьте исключение (502).
        //           Подсказка:
        //              $lines = [];
        //              foreach ($docs as $doc) {
        //                  $lines[] = json_encode(['index' => ['_id' => $doc['id']]]);
        //                  $lines[] = json_encode($doc, JSON_UNESCAPED_UNICODE);
        //              }
        //              $params = $index === self::alias() ? ['require_alias' => 'true'] : [];
        //              $r = self::request('POST', '/' . $index . '/_bulk', implode("\n", $lines) . "\n", $params);
        //              if ($r['status'] !== 200 || !empty($r['json']['errors'])) { throw new \RuntimeException(..., 502); }
        //           ✔ Проверка: php bin/reindex.php — «проиндексировано задач: 7» (нужны задания 1.1, 2.1 и это);
        //             curl "http://localhost:9200/tasks/_count?pretty" — "count": 7.
        throw new \RuntimeException('Пакетная загрузка не написана (TODO 2.2)', 501);
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
        // TODO 3.1  Добавление документа. Документ задачи кладётся в индекс запросом PUT /tasks/_doc/<номер>: если документ с таким
        //           номером уже есть, он заменяется (поэтому повтор безопасен).
        //             1) $r = self::request('PUT', '/' . self::alias() . '/_doc/' . $doc['id'], $doc, [...параметры...]);
        //                параметры: 'refresh' => 'wait_for' (подождать, пока документ станет виден поиску: иначе он появится в поиске
        //                примерно через секунду) и 'require_alias' => 'true' (если индекса ещё нет, это ошибка, а не молчаливое
        //                создание индекса со случайной структурой);
        //             2) код ответа 200 (заменён) или 201 (создан) — успех; иначе бросьте исключение:
        //                throw new \RuntimeException('Не удалось проиндексировать задачу №' . $doc['id'] . ': ' . ($r['json']['error']['reason'] ?? 'код ' . $r['status']), 502);
        //           Подсказка:
        //              $r = self::request('PUT', '/' . self::alias() . '/_doc/' . $doc['id'], $doc, ['refresh' => 'wait_for', 'require_alias' => 'true']);
        //              if (!in_array($r['status'], [200, 201], true)) {
        //                  throw new \RuntimeException(...);
        //              }
        //           ✔ Проверка: создайте задачу (POST /api/tasks), запустите php bin/worker.php --until-empty (после заданий 3.3 и 3.4):
        //             curl "http://localhost:9200/tasks/_doc/<номер>?pretty" показывает документ.
        throw new \RuntimeException('Добавление документа не написано (TODO 3.1)', 501);
    }

    // Удаляет документ задачи из индекса. Если документа уже нет, это не ошибка (повтор безопасен).
    public static function deleteDocument(int $taskId): void
    {
        // TODO 3.2  Удаление документа. Запрос DELETE /tasks/_doc/<номер> (параметр 'refresh' => 'wait_for', как в 3.1).
        //           Ответ 200 — документ удалён, 404 — его и не было. Оба случая успешны (цель «в индексе этой задачи нет» достигнута):
        //           так удаление, как и добавление, можно безопасно повторять. Любой другой код — исключение (502).
        //           Подсказка:
        //              $r = self::request('DELETE', '/' . self::alias() . '/_doc/' . $taskId, null, ['refresh' => 'wait_for']);
        //              if (!in_array($r['status'], [200, 404], true)) {
        //                  throw new \RuntimeException('Не удалось удалить задачу №' . $taskId . ' из поиска: код ' . $r['status'], 502);
        //              }
        //           ✔ Проверка: удалите задачу (DELETE /api/tasks/<номер> под Анной), выполните воркер: curl "http://localhost:9200/tasks/_doc/<номер>"
        //             отвечает "found": false.
        throw new \RuntimeException('Удаление документа не написано (TODO 3.2)', 501);
    }

    // ---------------------------------------------------------------------
    // Часть 4. Поиск
    // ---------------------------------------------------------------------

    // Ищет задачи. Возвращает ['total' => сколько всего найдено, 'took' => сколько миллисекунд искал OpenSearch, 'results' => [...]].
    //   $q — слова для поиска;  $status, $projectId — необязательные фильтры;  $limit, $offset — страница результатов.
    public static function search(string $q, string $status = '', ?int $projectId = null, int $limit = 10, int $offset = 0): array
    {
        // TODO 4.1  Запрос поиска. Запрос к OpenSearch — это JSON, который описывает, что искать. Метод собирает его, отправляет
        //           POST /tasks/_search и возвращает результат в нужном виде.
        //             1) фильтры ($filter — массив): если $status не пуст, добавьте ['term' => ['status' => $status]];
        //                если $projectId не null, добавьте ['term' => ['project_id' => $projectId]];
        //             2) тело запроса:
        //                'from' => $offset, 'size' => $limit, 'track_total_hits' => true (точное число найденных),
        //                'query' => ['bool' => ['must' => [[ 'multi_match' => [
        //                       'query' => $q,
        //                       'fields' => ['title^3', 'project_title^2', 'description'],    // ^3: совпадение в названии втрое важнее
        //                       'fuzziness' => 'AUTO' ]]],                                      // прощать опечатки
        //                   'filter' => $filter]],
        //                'sort' => [['_score' => 'desc'], ['id' => 'asc']]   // сначала самые релевантные; при равенстве — по номеру
        //             3) подсветка найденных слов: 'highlight' => ['encoder' => 'html', 'pre_tags' => ['<mark>'], 'post_tags' => ['</mark>'],
        //                'fields' => ['title' => ['number_of_fragments' => 0], 'project_title' => ['number_of_fragments' => 0],
        //                             'description' => ['number_of_fragments' => 1, 'fragment_size' => 150]]]
        //                ('encoder' => 'html' экранирует HTML в тексте: иначе название «<script>...» попало бы на страницу как код);
        //             4) $r = self::request('POST', '/' . self::alias() . '/_search', $body); код не 200 — исключение (502);
        //             5) верните ['total' => (int) $r['json']['hits']['total']['value'], 'took' => (int) $r['json']['took'],
        //                'results' => array_map([self::class, 'formatHit'], $r['json']['hits']['hits'])]  (formatHit готов).
        //           Подсказка: тело запроса можно собрать целиком и вывести print_r(json_encode($body, JSON_PRETTY_PRINT)): так видно, что уходит в OpenSearch.
        //           ✔ Проверка: php bin/search-demo.php "форма" — найдена задача «Подключить форму обратной связи» (слово «форму» тоже найдено:
        //             анализатор russian приводит слова к основе), слова выделены <mark>; php bin/search-demo.php "форсма" — найдена та же задача (опечатка).
        throw new \RuntimeException('Запрос поиска не написан (TODO 4.1)', 501);
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
