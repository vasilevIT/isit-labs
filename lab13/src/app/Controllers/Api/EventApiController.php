<?php
// app/Controllers/Api/EventApiController.php — просмотр журнала событий: GET /api/events (ГОТОВО: менять не нужно).
//
// Журнал видит только руководитель (в нём чужие действия и адреса). Параметры (все необязательные):
//   type     тип события (login, task_updated, ...)          user_id  номер участника
//   from, to период, даты ГГГГ-ММ-ДД (обе границы включительно)
//   sort     time (старые сверху) или -time (новые сверху, по умолчанию)
//   limit    сколько записей вернуть (по умолчанию 50, не больше 500),  offset — сколько пропустить
// Общее число подходящих записей — в заголовке X-Total-Count. Неверный параметр: 422 с перечнем ошибок.

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Events;
use App\Models\EventRepository;

final class EventApiController
{
    public function index(): void
    {
        require_role('manager');                           // другая роль: 403

        $errors = [];
        $type = is_string($_GET['type'] ?? null) ? trim($_GET['type']) : '';
        if ($type !== '' && !isset(Events::TYPES[$type])) {
            $errors['type'] = 'Неизвестный тип события. Допустимые: ' . implode(', ', array_keys(Events::TYPES));
        }
        $userId = null;
        if (isset($_GET['user_id']) && $_GET['user_id'] !== '') {
            if (is_string($_GET['user_id']) && ctype_digit($_GET['user_id'])) {
                $userId = (int) $_GET['user_id'];
            } else {
                $errors['user_id'] = 'Номер участника должен быть числом';
            }
        }
        $dates = [];
        foreach (['from', 'to'] as $name) {
            $value = is_string($_GET[$name] ?? null) ? trim($_GET[$name]) : '';
            if ($value === '') {
                $dates[$name] = '';
            } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $dates[$name] = $value;
            } else {
                $errors[$name] = 'Дата должна быть в формате ГГГГ-ММ-ДД';
            }
        }
        $sort = is_string($_GET['sort'] ?? null) && $_GET['sort'] !== '' ? $_GET['sort'] : '-time';
        if (!in_array($sort, ['time', '-time'], true)) {
            $errors['sort'] = 'Сортировка: time или -time';
        }
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }

        $limit = is_string($_GET['limit'] ?? null) && ctype_digit($_GET['limit']) ? min(max((int) $_GET['limit'], 1), 500) : 50;
        $offset = is_string($_GET['offset'] ?? null) && ctype_digit($_GET['offset']) ? (int) $_GET['offset'] : 0;

        $result = (new EventRepository())->search(['type' => $type, 'user_id' => $userId, 'from' => $dates['from'], 'to' => $dates['to']], $sort, $limit, $offset);
        header('X-Total-Count: ' . $result['total']);
        json_response($result['rows']);
    }
}
