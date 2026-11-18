<?php
// app/Controllers/Api/DebugController.php — учебный адрес для проверки записи ошибок в журнал (ГОТОВО).
// GET /api/debug/error нарочно «ломается» (исключение): сервер должен ответить 500, а в журнале появится событие server_error.
// Работает только при debug = true в config/app.php; в рабочей системе адреса нет (ответ 404).

declare(strict_types=1);

namespace App\Controllers\Api;

final class DebugController
{
    public function error(): void
    {
        if (!config('debug')) {
            json_error('Адрес не найден', 404);
        }
        throw new \RuntimeException('Учебная ошибка: проверка записи в журнал');
    }
}
