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
    //   auth   — вход и выход,   data — изменение данных,   system — ошибки и сбои самого приложения,   search — поисковые запросы
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
        'search'          => 'search',   // поисковый запрос (лабораторная работа №13)
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
        try {
            if (!isset(self::TYPES[$type])) {
                error_log('Журнал событий: неизвестный тип «' . $type . '»');
                return;                                                  // неизвестный тип не записываем, но и не падаем
            }
            $userId = array_key_exists('user_id', $options) ? $options['user_id'] : (current_user()['id'] ?? null);
            $details = self::clean($options['details'] ?? []);

            $stmt = Database::connection()->prepare(
                'INSERT INTO events (type, category, user_id, entity_type, entity_id, description, details, ip, request_id)
                 VALUES (:type, :category, :user_id, :entity_type, :entity_id, :description, :details, :ip, :request_id)'
            );
            $stmt->execute([
                'type'        => $type,
                'category'    => self::TYPES[$type],
                'user_id'     => $userId,
                'entity_type' => $options['entity_type'] ?? null,
                'entity_id'   => $options['entity_id'] ?? null,
                'description' => $description,
                'details'     => json_encode($details === [] ? new \stdClass() : $details, JSON_UNESCAPED_UNICODE),
                'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,         // из консоли (воркер, скрипты) адреса нет: будет NULL
                'request_id'  => self::requestId(),
            ]);
        } catch (\Throwable $e) {
            error_log('Журнал событий: не удалось записать «' . $type . '»: ' . $e->getMessage());   // запрос пользователя продолжается
        }
    }

    // Удаляет из дополнительных данных секретные ключи (пароли, токены), в том числе во вложенных массивах.
    // Имена ключей сравниваются без учёта регистра: 'Password' и 'PASSWORD' тоже секретные.
    public static function clean(array $details): array
    {
        $clean = [];
        foreach ($details as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                continue;                                                // секретный ключ: пропускаем вместе со значением
            }
            $clean[$key] = is_array($value) ? self::clean($value) : $value;   // вложенные массивы чистим так же
        }
        return $clean;
    }
}
