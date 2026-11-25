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

        $report = $this->requests->create($user['id']);                  // заявка со статусом queued
        $jobId = Jobs::dispatch('report', ['report_id' => $report['id']]);
        if ($jobId === null) {                                           // очередь недоступна: заявка не будет выполнена, честно говорим об этом
            $this->requests->markFailed($report['id'], 'Очередь недоступна');
            json_error('Очередь недоступна, отчёт не поставлен в обработку', 503);
        }
        header('Location: /api/reports/' . $report['id']);               // где смотреть состояние заявки
        $report['status'] = $this->requests->find($report['id'])['status'];   // в режиме sync отчёт к этому моменту уже готов
        json_response($report, 202);
    }

    // GET /api/reports/5 — состояние заявки. Видеть её может автор или руководитель.
    public function show(string $id): void
    {
        $user = require_auth();
        $report = $this->requests->find((int) $id);
        if ($report === null) {
            json_error('Заявка на отчёт не найдена', 404);
        }
        if ($report['user_id'] !== $user['id'] && $user['role'] !== 'manager') {
            json_error('Недостаточно прав для этого действия', 403);     // чужие отчёты смотреть нельзя
        }
        json_response($report);
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
