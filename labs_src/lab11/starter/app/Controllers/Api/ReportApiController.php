<?php
// app/Controllers/Api/ReportApiController.php — отчёты:
//   GET  /api/reports/summary         сводка по проектам (быстрая, с кешем)
//   POST /api/reports                 заказать отчёт в виде CSV-файла: строится в фоне, ответ приходит сразу (код 202)
//   GET  /api/reports/{id}            узнать состояние заявки: queued, processing, done или failed
//   GET  /api/reports/{id}/download   скачать готовый файл

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Cache;
use App\Jobs\Jobs;
use App\Models\ReportRepository;
use App\Models\ReportRequestRepository;

final class ReportApiController
{
    private ReportRepository $reports;
    private ReportRequestRepository $requests;

    public function __construct()
    {
        $this->reports = new ReportRepository();
        $this->requests = new ReportRequestRepository();
    }

    // GET /api/reports/summary — сводка по проектам (доступна любому вошедшему участнику)
    public function summary(): void
    {
        $data = Cache::remember('report:summary', 120, fn(): array => $this->reports->summary());
        json_response($data);
    }

    // POST /api/reports — заказать отчёт. Построение занимает время, поэтому ответ приходит сразу (код 202 Accepted:
    // «запрос принят, но ещё не выполнен»), а файл строит воркер. Клиент периодически спрашивает GET /api/reports/{id}.
    public function create(): void
    {
        $user = require_auth();

        // TODO 4.2  Заказ отчёта. Обработчик запроса не строит отчёт, а только оформляет заявку и отдаёт её воркеру:
        //             1) $report = $this->requests->create($user['id']);  — строка в таблице report_requests со статусом queued;
        //             2) $jobId = Jobs::dispatch('report', ['report_id' => $report['id']]);  — задача в очередь;
        //             3) если $jobId === null, очередь недоступна: пометьте заявку $this->requests->markFailed($report['id'], 'Очередь недоступна')
        //                и ответьте json_error('Очередь недоступна, отчёт не поставлен в обработку', 503);
        //             4) header('Location: /api/reports/' . $report['id']);  и верните заявку с кодом 202: json_response($report, 202).
        //           Код 202 Accepted означает «запрос принят, но ещё не выполнен» (не 200 и не 201: файла пока нет).
        //           ✔ Проверка: POST /api/reports под Анной → 202, в теле status = queued, заголовок Location; php bin/queue-stats.php — «ожидают: 1».
        json_error('Не реализовано (TODO 4.2)', 501);
    }

    // GET /api/reports/5 — состояние заявки. Видеть её может автор или руководитель.
    public function show(string $id): void
    {
        $user = require_auth();
        // TODO 4.3  Состояние заявки. Клиент после POST периодически спрашивает, готов ли отчёт. Метод должен:
        //             1) найти заявку: $report = $this->requests->find((int) $id); не найдена — json_error('Заявка на отчёт не найдена', 404);
        //             2) проверить права: заявку может смотреть её автор ($report['user_id'] === $user['id']) или руководитель
        //                ($user['role'] === 'manager'); иначе json_error('Недостаточно прав для этого действия', 403);
        //             3) вернуть заявку: json_response($report).
        //           ✔ Проверка: до запуска воркера status = queued, после — done; чужую заявку (Игорь смотрит заявку Марии) сервер не отдаёт (403).
        json_error('Не реализовано (TODO 4.3)', 501);
    }

    // GET /api/reports/5/download — скачать готовый CSV-файл (ГОТОВО)
    public function download(string $id): void
    {
        $user = require_auth();
        $report = $this->requests->find((int) $id);
        if ($report === null) {
            json_error('Заявка на отчёт не найдена', 404);
        }
        if ($report['user_id'] !== $user['id'] && $user['role'] !== 'manager') {
            json_error('Недостаточно прав для этого действия', 403);
        }
        if ($report['status'] !== 'done') {
            json_error('Отчёт ещё не готов (статус: ' . $report['status'] . ')', 409);   // 409: запрос не подходит к текущему состоянию
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $report['file_name'] . '"');
        readfile(BASE_PATH . '/storage/reports/' . $report['file_name']);
        exit;
    }
}
