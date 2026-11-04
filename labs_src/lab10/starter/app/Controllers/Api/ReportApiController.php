<?php
// app/Controllers/Api/ReportApiController.php — отчёты: GET /api/reports/summary

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Cache;
use App\Models\ReportRepository;

final class ReportApiController
{
    private ReportRepository $reports;

    public function __construct()
    {
        $this->reports = new ReportRepository();
    }

    // GET /api/reports/summary — сводка по проектам (доступна любому вошедшему участнику)
    public function summary(): void
    {
        // TODO 3.2  Отчёт по проектам (GET /api/reports/summary). Запрос читает все задачи и на большой таблице работает заметное время, а
        //           руководитель открывает отчёт часто: отличный кандидат. Оберните вызов $this->reports->summary() в Cache::remember()
        //           с ключом 'report:summary' и временем жизни 120 секунд. Ключ один, потому что отчёт не зависит от параметров.
        //           Подсказка:
        //              $data = Cache::remember('report:summary', 120, fn(): array => $this->reports->summary());
        //           ✔ Проверка: после php bin/load-bigdata.php сравните время: curl -s -o /dev/null -w "%{time_total}\n" http://localhost:8000/api/reports/summary -H "Authorization: Bearer ВАШ_ТОКЕН"
        //             — первый запрос (MISS) заметно дольше повторных (HIT).
        $data = $this->reports->summary();                  // пока считаем заново при каждом запросе
        json_response($data);
    }
}
