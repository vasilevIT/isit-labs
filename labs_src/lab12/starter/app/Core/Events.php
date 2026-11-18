<?php
// app/Core/Events.php — журнал событий приложения (лаборатория №12).
//
// Событие — запись о том, что произошло в системе: «Игорь вошёл», «Анна удалила задачу 5», «сервер вернул ошибку».
// Журнал — таблица events в PostgreSQL. По ней видно, что происходит с приложением, и из неё считается аналитика
// (кто и как часто работает, где бывают ошибки). Событие пишется в обработчике запроса сразу, в той же базе.
//
// Главное правило: журнал не должен ломать приложение. Если записать событие не удалось (база на секунду недоступна,
// ошибка в данных), ошибка уходит в журнал ошибок PHP (error_log), а запрос пользователя продолжает работать.
// Второе правило: в событие нельзя класть секреты (пароли, токены): журнал читают многие, а хранится он долго.

declare(strict_types=1);

namespace App\Core;

final class Events
{
    // Какие события бывают и к какой категории относятся (ГОТОВО).
    //   auth   — вход и выход,   data — изменение данных,   system — ошибки и сбои самого приложения
    public const TYPES = [
        'login'           => 'auth',     // успешный вход
        'logout'          => 'auth',     // выход
        'login_failed'    => 'auth',     // неудачная попытка входа
        'task_created'    => 'data',
        'task_updated'    => 'data',
        'task_deleted'    => 'data',
        'project_created' => 'data',
        'project_updated' => 'data',
        'project_deleted' => 'data',
        'server_error'    => 'system',   // ответ 500 или 503
    ];

    // Ключи, значения которых нельзя записывать в журнал (ГОТОВО: список; удаление ключей пишете вы в TODO 1.2).
    public const SECRET_KEYS = ['password', 'password_hash', 'token', 'authorization', 'secret'];

    // Номер запроса (ГОТОВО): 16 случайных символов, одинаковые у всех событий одного запроса.
    // Он же отдаётся клиенту в заголовке X-Request-Id: по нему событие в журнале связывают с конкретным запросом.
    public static function requestId(): string
    {
        static $id = null;
        return $id ??= bin2hex(random_bytes(8));
    }

    // Записывает событие в журнал.
    //   $type        — тип из Events::TYPES ('login', 'task_created', ...)
    //   $description — короткое описание словами: «Вход в систему: Анна Петрова»
    //   $options     — необязательные данные:
    //       'user_id'     — кто выполнил действие (если не указан, берётся вошедший участник из токена запроса)
    //       'entity_type' — над чем выполнено действие ('task', 'project', 'user')
    //       'entity_id'   — номер объекта
    //       'details'     — любые дополнительные данные (массив): они сохраняются как JSON; секретные ключи удаляются
    public static function log(string $type, string $description, array $options = []): void
    {
        // TODO 1.1  Запись события. Метод вставляет строку в таблицу events и НИКОГДА не бросает исключений:
        //             1) если $type нет среди ключей self::TYPES, запишите сообщение в error_log и сделайте return;
        //             2) кто выполнил действие: $userId = array_key_exists('user_id', $options) ? $options['user_id'] : (current_user()['id'] ?? null);
        //                (current_user() возвращает вошедшего участника по токену запроса или null);
        //             3) очистите дополнительные данные от секретов: $details = self::clean($options['details'] ?? []);  (метод clean вы пишете в TODO 1.2);
        //             4) выполните INSERT INTO events (type, category, user_id, entity_type, entity_id, description, details, ip, request_id)
        //                через Database::connection()->prepare(...)->execute([...]); значения:
        //                category = self::TYPES[$type];  details = json_encode($details === [] ? new \stdClass() : $details, JSON_UNESCAPED_UNICODE);
        //                ip = $_SERVER['REMOTE_ADDR'] ?? null;  request_id = self::requestId();  остальное берите из $options (?? null);
        //             5) весь код оберните в try { ... } catch (\Throwable $e) { error_log(...); }: ошибка записи не должна ронять запрос.
        //           Подсказка:
        //              try {
        //                  if (!isset(self::TYPES[$type])) { error_log('Журнал событий: неизвестный тип «' . $type . '»'); return; }
        //                  $userId = array_key_exists('user_id', $options) ? $options['user_id'] : (current_user()['id'] ?? null);
        //                  $details = self::clean($options['details'] ?? []);
        //                  $stmt = Database::connection()->prepare('INSERT INTO events (...) VALUES (:type, :category, ...)');
        //                  $stmt->execute(['type' => $type, 'category' => self::TYPES[$type], 'user_id' => $userId, ...]);
        //              } catch (\Throwable $e) {
        //                  error_log('Журнал событий: не удалось записать «' . $type . '»: ' . $e->getMessage());
        //              }
        //           ✔ Проверка: php bin/events-check.php — «записано: 1», строка события в таблице;
        //             docker compose exec db psql -U postgres -d taskapp -c "SELECT id, type, description FROM events"
    }

    // Удаляет из дополнительных данных секретные ключи (пароли, токены), в том числе во вложенных массивах.
    // Имена ключей сравниваются без учёта регистра: 'Password' и 'PASSWORD' тоже секретные.
    public static function clean(array $details): array
    {
        // TODO 1.2  Защита от секретов. Верните копию $details без секретных ключей (список в self::SECRET_KEYS):
        //             1) пройдите по массиву: foreach ($details as $key => $value);
        //             2) если ключ — строка и strtolower($key) есть в self::SECRET_KEYS, пропустите его (continue);
        //             3) если значение — массив, очистите и его: self::clean($value) (метод вызывает сам себя: это называется рекурсия);
        //             4) остальное добавьте в результат.
        //           Подсказка:
        //              $clean = [];
        //              foreach ($details as $key => $value) {
        //                  if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
        //                      continue;
        //                  }
        //                  $clean[$key] = is_array($value) ? self::clean($value) : $value;
        //              }
        //              return $clean;
        //           ✔ Проверка: php bin/events-check.php — «секретов в журнале нет»: ключи password и token (и вложенные) в записи отсутствуют.
        return $details;                                                // пока секреты не удаляются
    }
}
