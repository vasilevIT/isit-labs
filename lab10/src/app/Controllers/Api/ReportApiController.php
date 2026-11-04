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
        $data = Cache::remember('report:summary', 120, fn(): array => $this->reports->summary());
        json_response($data);
    }
}
