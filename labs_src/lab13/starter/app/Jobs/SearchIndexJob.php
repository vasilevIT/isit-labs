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
        // TODO 3.3  Обработка задачи «search_index». Метод выполняет воркер. В $payload одно из двух:
        //             • task_id — номер задачи: нужно привести её копию в индексе в соответствие с базой;
        //             • project_id — номер проекта, который переименовали: нужно обновить копии всех его задач.
        //           Для task_id:
        //             1) $doc = Search::taskDocument($taskId);  — какая задача сейчас в PostgreSQL (null, если её уже нет);
        //             2) если $doc === null — задачу удалили: Search::deleteDocument($taskId);
        //             3) иначе — Search::indexDocument($doc).
        //           Для project_id:
        //             1) $docs = Search::projectDocuments($projectId);  — документы всех задач проекта;
        //             2) если они есть: Search::bulk($docs, Search::alias()); затем Search::request('POST', '/' . Search::alias() . '/_refresh');
        //                (обновить видимость для поиска), и завершите метод (return).
        //           Почему не передавать в задачу сами данные? Пока задача лежит в очереди, запись может измениться ещё раз; воркер
        //           же всегда смотрит в базу, поэтому в индекс попадает самая свежая версия, а порядок задач и повторы не мешают.
        //           Подсказка:
        //              if (isset($payload['project_id'])) { ... return; }
        //              $taskId = (int) $payload['task_id'];
        //              $doc = Search::taskDocument($taskId);
        //              if ($doc === null) { Search::deleteDocument($taskId); } else { Search::indexDocument($doc); }
        //           ✔ Проверка: измените название задачи (PATCH /api/tasks/2 под Анной, после задания 3.4), выполните php bin/worker.php --until-empty:
        //             в журнале воркера «search_index: готово», curl "http://localhost:9200/tasks/_doc/2?pretty" показывает новое название.
        throw new \RuntimeException('Обработка задачи search_index не написана (TODO 3.3)', 501);
    }
}
