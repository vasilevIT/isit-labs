<?php
// app/Jobs/SearchIndexJob.php — фоновая задача «привести поисковую копию задач в соответствие с базой».
//
// Её ставят в очередь контроллеры (TODO 3.4), когда задачу создали, изменили или удалили, а проект переименовали.
// Данные задачи (payload): либо task_id — номер задачи, либо project_id — номер проекта, у которого сменилось название.
//
// Важная идея: в задачу кладётся не «что изменилось», а только «что проверить». Воркер сам смотрит в PostgreSQL, какова задача
// сейчас: есть — кладёт её в индекс, нет — удаляет из индекса. Поэтому порядок выполнения задач не важен, повтор безопасен,
// а если задача пришла с опозданием, индекс всё равно получает самую свежую версию (идемпотентность).

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Search;

final class SearchIndexJob
{
    public function handle(array $payload, string $jobId, int $attempt): void
    {
        if (isset($payload['project_id'])) {                           // проект переименовали: в документах всех его задач устарело project_title
            $docs = Search::projectDocuments((int) $payload['project_id']);
            if ($docs) {
                Search::bulk($docs, Search::alias());                  // обновляем все документы проекта одним запросом
                Search::request('POST', '/' . Search::alias() . '/_refresh');   // и делаем изменения видимыми для поиска
            }
            return;
        }

        $taskId = (int) $payload['task_id'];
        $doc = Search::taskDocument($taskId);                          // задача такой, какая она в базе СЕЙЧАС (или null, если её уже нет)
        if ($doc === null) {
            Search::deleteDocument($taskId);                           // задачи нет: убираем из поиска
        } else {
            Search::indexDocument($doc);                               // задача есть: кладём в поиск свежую версию
        }
    }
}
