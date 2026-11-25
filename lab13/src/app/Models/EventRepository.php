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

        $count = $pdo->prepare("SELECT COUNT(*) FROM events e $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $direction = $sort === 'time' ? 'ASC' : 'DESC';             // из ответа API: только 'time' или '-time'
        $stmt = $pdo->prepare(
            "SELECT e.id, e.occurred_at, e.type, e.category, e.user_id, u.name AS user_name,
                    e.entity_type, e.entity_id, e.description, e.details, e.ip, e.request_id
             FROM events e
             LEFT JOIN users u ON u.id = e.user_id
             $whereSql
             ORDER BY e.occurred_at $direction, e.id $direction
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['details'] = json_decode($row['details'], true);   // JSONB приходит строкой: превращаем в массив
        }
        return ['rows' => $rows, 'total' => $total];
    }

    // Собирает условия отбора. Возвращает [строка WHERE (или пустая), массив параметров].
    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (($filters['type'] ?? '') !== '') {
            $where[] = 'e.type = :type';
            $params['type'] = $filters['type'];
        }
        if (($filters['user_id'] ?? null) !== null) {
            $where[] = 'e.user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (($filters['from'] ?? '') !== '') {
            $where[] = 'e.occurred_at >= CAST(:from AS date)';          // с начала этого дня
            $params['from'] = $filters['from'];
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = 'e.occurred_at < CAST(:to AS date) + 1';         // до конца этого дня включительно (до начала следующего)
            $params['to'] = $filters['to'];
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }
}
