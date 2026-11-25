<?php
// app/Jobs/ReportJob.php — фоновая задача «построить отчёт по задачам и сохранить его в CSV-файл».
//
// Её ставит в очередь ReportApiController::create (задание 4.2 лабораторной работы №11). Данные задачи (payload): report_id — номер заявки в таблице report_requests.
// Файл сохраняется в storage/reports/report-<номер>.csv. Построение отчёта «занимает время» (REPORT_SECONDS, по умолчанию 2 секунды).

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Database;
use App\Models\ReportRequestRepository;

final class ReportJob
{
    public function handle(array $payload, string $jobId, int $attempt): void
    {
        $id = (int) $payload['report_id'];
        $requests = new ReportRequestRepository();

        $requests->markProcessing($id);                                  // статус processing: клиент видит, что работа началась
        try {
            Jobs::pause(config('queue')['report_seconds']);               // «долгое построение отчёта» (имитация)

            $rows = Database::connection()->query(
                'SELECT t.id, p.title AS project, t.title, t.status, t.priority, t.due_date
                 FROM tasks t JOIN projects p ON p.id = t.project_id
                 ORDER BY t.id'
            )->fetchAll();

            $fileName = 'report-' . $id . '.csv';
            $file = fopen(BASE_PATH . '/storage/reports/' . $fileName, 'w');
            fwrite($file, "\xEF\xBB\xBF");                               // метка UTF-8: без неё Excel покажет кириллицу «кракозябрами»
            fputcsv($file, ['id', 'project', 'title', 'status', 'priority', 'due_date'], ';');
            foreach ($rows as $row) {
                fputcsv($file, $row, ';');
            }
            fclose($file);

            $requests->markDone($id, $fileName, count($rows));           // статус done: файл готов
        } catch (\Throwable $e) {
            $requests->markFailed($id, $e->getMessage());                // статус failed: клиент увидит причину
            throw $e;                                                    // и сообщаем воркеру: задача не выполнена (он решит, повторять ли)
        }
    }
}
