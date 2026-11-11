<?php
// app/Jobs/ReportJob.php — фоновая задача «построить отчёт по задачам и сохранить его в CSV-файл».
//
// Её ставит в очередь ReportApiController::create (TODO 4.2). Данные задачи (payload): report_id — номер заявки в таблице report_requests.
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

        // TODO 4.1  Обработка отчёта. Воркер получает номер заявки и должен построить отчёт и сохранить результат:
        //             1) $requests->markProcessing($id): заявка получает статус processing (клиент видит, что работа началась);
        //             2) выберите данные: SELECT t.id, p.title AS project, t.title, t.status, t.priority, t.due_date FROM tasks t JOIN projects p ON p.id = t.project_id ORDER BY t.id
        //                (запрос выполняется через Database::connection()->query(...)->fetchAll());
        //             3) откройте файл fopen(BASE_PATH . '/storage/reports/report-' . $id . '.csv', 'w'), запишите метку UTF-8 "\xEF\xBB\xBF"
        //                (иначе Excel покажет кириллицу «кракозябрами»), заголовок и строки функцией fputcsv($file, $массив, ';'), закройте файл;
        //             4) $requests->markDone($id, 'report-' . $id . '.csv', count($rows)): статус done;
        //             5) весь код (шаги 2–4) оберните в try/catch (Throwable): при ошибке вызовите $requests->markFailed($id, $e->getMessage())
        //                и пробросьте исключение дальше (throw $e): воркер должен узнать, что задача не выполнена.
        //                (Паузу «долгого построения»: Jobs::pause(config('queue')['report_seconds']); поставьте перед шагом 2.)
        //           Подсказка:
        //              $requests->markProcessing($id);
        //              try {
        //                  Jobs::pause(config('queue')['report_seconds']);
        //                  $rows = Database::connection()->query('SELECT ... ORDER BY t.id')->fetchAll();
        //                  $fileName = 'report-' . $id . '.csv';
        //                  $file = fopen(BASE_PATH . '/storage/reports/' . $fileName, 'w');
        //                  fwrite($file, "\xEF\xBB\xBF");
        //                  fputcsv($file, ['id', 'project', 'title', 'status', 'priority', 'due_date'], ';');
        //                  foreach ($rows as $row) {
        //                      fputcsv($file, $row, ';');
        //                  }
        //                  fclose($file);
        //                  $requests->markDone($id, $fileName, count($rows));
        //              } catch (\Throwable $e) {
        //                  $requests->markFailed($id, $e->getMessage());
        //                  throw $e;
        //              }
        //           ✔ Проверка: POST /api/reports под Анной, запустите воркер, GET /api/reports/<номер>: status = done, rows_count = 7;
        //             файл storage/reports/report-<номер>.csv открывается в Excel.
    }
}
