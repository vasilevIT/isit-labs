<?php
// app/Models/EventRepository.php — чтение журнала событий (таблица events).
// Запись событий делает Events::log; здесь только поиск по журналу для API (GET /api/events).

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class EventRepository
{
    // Ищет события.
    //   $filters — условия (все необязательные): type, user_id, from (дата ГГГГ-ММ-ДД), to (дата ГГГГ-ММ-ДД)
    //   $sort    — 'time' (старые сверху) или '-time' (новые сверху)
    //   $limit, $offset — страница: сколько записей взять и сколько пропустить
    // Возвращает ['rows' => [...события...], 'total' => сколько событий подходит под условия (всего, не только на странице)].
    // Каждое событие содержит имя участника (user_name; NULL, если участник неизвестен) и details в виде массива.
    public function search(array $filters, string $sort, int $limit, int $offset): array
    {
        [$whereSql, $params] = $this->buildWhere($filters);       // условия отбора (TODO 3.2)
        $pdo = Database::connection();

        // TODO 3.1  Список событий с сортировкой и страницами (пока без фильтров: условия $whereSql появятся в TODO 3.2).
        //             1) общее число: SELECT COUNT(*) FROM events e $whereSql → $total = (int) $count->fetchColumn();
        //             2) сами события: SELECT e.id, e.occurred_at, e.type, e.category, e.user_id, u.name AS user_name,
        //                e.entity_type, e.entity_id, e.description, e.details, e.ip, e.request_id
        //                FROM events e LEFT JOIN users u ON u.id = e.user_id $whereSql
        //                ORDER BY e.occurred_at $direction, e.id $direction LIMIT :limit OFFSET :offset
        //                $direction = $sort === 'time' ? 'ASC' : 'DESC'  (LEFT JOIN: у события может не быть участника);
        //             3) значения подставляйте параметрами, а не склеивайте в строку: сначала bindValue для каждого из $params
        //                (целые числа — PDO::PARAM_INT), затем bindValue('limit', $limit, PDO::PARAM_INT) и bindValue('offset', ...);
        //             4) столбец details приходит строкой JSON: превратите в массив json_decode($row['details'], true);
        //             5) верните ['rows' => $rows, 'total' => $total].
        //           Подсказка:
        //              $count = $pdo->prepare("SELECT COUNT(*) FROM events e $whereSql");
        //              $count->execute($params);
        //              $total = (int) $count->fetchColumn();
        //              $direction = $sort === 'time' ? 'ASC' : 'DESC';
        //              $stmt = $pdo->prepare("SELECT e.id, ... FROM events e LEFT JOIN users u ON u.id = e.user_id $whereSql ORDER BY e.occurred_at $direction, e.id $direction LIMIT :limit OFFSET :offset");
        //              foreach ($params as $name => $value) {
        //                  $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        //              }
        //              $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        //              $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        //              $stmt->execute();
        //           ✔ Проверка: GET /api/events под Анной (руководитель) возвращает события, самые новые первыми; в заголовке X-Total-Count — общее число;
        //             ?sort=time — старые первыми; ?limit=2&offset=2 — вторая страница из двух записей.
        return ['rows' => [], 'total' => 0];                             // пока журнал через API пуст
    }

    // Собирает условия отбора. Возвращает [строка WHERE (или пустая), массив параметров].
    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        // TODO 3.2  Фильтры. Для каждого непустого условия из $filters добавьте кусок SQL в $where и значение в $params
        //           (значения всегда параметрами, а не склейкой строк: так нельзя внедрить свой SQL):
        //             type     →  e.type = :type
        //             user_id  →  e.user_id = :user_id       (значение приведите к числу: (int) $filters['user_id'])
        //             from     →  e.occurred_at >= CAST(:from AS date)        (с начала этого дня)
        //             to       →  e.occurred_at < CAST(:to AS date) + 1       (до конца этого дня включительно)
        //           Подсказка:
        //              if (($filters['type'] ?? '') !== '') {
        //                  $where[] = 'e.type = :type';
        //                  $params['type'] = $filters['type'];
        //              }
        //              if (($filters['user_id'] ?? null) !== null) {
        //                  $where[] = 'e.user_id = :user_id';
        //                  $params['user_id'] = (int) $filters['user_id'];
        //              }
        //              ... так же для from и to ...
        //           ✔ Проверка: ?type=login — только входы; ?user_id=2 — только события Игоря; ?from=2026-11-01&to=2026-11-07 — за неделю;
        //             условия можно сочетать: ?type=task_updated&user_id=2; X-Total-Count показывает число подходящих записей.

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }
}
