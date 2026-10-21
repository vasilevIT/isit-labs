# Анализ и оптимизация SQL-запросов

Все результаты получены командой `\i /sql/06_optimization.sql` на PostgreSQL 16 после скриптов `01`–`05`
(500 007 задач, 1 000 005 комментариев, 1003 проекта, 204 участника).
`EXPLAIN ANALYZE` выполняет запрос по-настоящему и показывает план и фактическое время.
Время зависит от компьютера: важно, во сколько раз оно изменилось.

Как читать план (снизу вверх, от вложенных шагов к внешним):

| В плане | Что это значит |
|---------|----------------|
| `Seq Scan` / `Parallel Seq Scan` | полный перебор таблицы (параллельно в нескольких процессах) |
| `Rows Removed by Filter` | сколько строк прочитано зря и отброшено условием `WHERE` |
| `Index Scan` | поиск по индексу, затем чтение найденных строк |
| `Bitmap Index Scan` + `Bitmap Heap Scan` | по индексу собирается «карта» нужных страниц таблицы, затем они читаются по порядку |
| `Sort` | отдельная сортировка результата |
| `Limit` | остановка после нужного числа строк |
| `cost=...` | оценка планировщика в условных единицах (не миллисекунды) |
| `actual time=...` | фактическое время в миллисекундах |
| `Execution Time` | общее время выполнения запроса |

## Исходное состояние

До оптимизации у таблиц есть только индексы первичных ключей. PostgreSQL **не создаёт индексы для внешних ключей**
автоматически, поэтому `tasks.project_id`, `tasks.assignee_id` и `comments.task_id` ищутся перебором.

```
Индексы, которые есть до оптимизации (только первичные ключи и UNIQUE)
 tablename |   indexname
-----------+---------------
 comments  | comments_pkey
 tasks     | tasks_pkey
(2 rows)
```

## Запрос 1. Задачи проекта

Используется в `GET /api/projects/{id}/tasks` и на странице проекта.

```sql
SELECT id, title, status, due_date FROM tasks WHERE project_id = 500 ORDER BY id;
```

**До.** Перебор всех 500 тысяч строк, чтобы найти 503:

```
                                                         QUERY PLAN
-----------------------------------------------------------------------------------------------------------------------------
 Gather Merge  (cost=10244.14..10292.21 rows=412 width=53) (actual time=13.511..14.882 rows=492 loops=1)
   Workers Planned: 2
   Workers Launched: 2
   ->  Sort  (cost=9244.12..9244.64 rows=206 width=53) (actual time=10.686..10.690 rows=164 loops=3)
         Sort Key: id
         Sort Method: quicksort  Memory: 50kB
         Worker 0:  Sort Method: quicksort  Memory: 32kB
         Worker 1:  Sort Method: quicksort  Memory: 31kB
         ->  Parallel Seq Scan on tasks  (cost=0.00..9236.20 rows=206 width=53) (actual time=0.100..10.629 rows=164 loops=3)
               Filter: (project_id = 500)
               Rows Removed by Filter: 166505
 Planning Time: 0.049 ms
 Execution Time: 14.899 ms
(13 rows)
```

**Решение:** индекс на внешний ключ.

```sql
CREATE INDEX idx_tasks_project_id ON tasks (project_id);
```

**После.** База находит строки по индексу:

```
                                                              QUERY PLAN
---------------------------------------------------------------------------------------------------------------------------------------
 Sort  (cost=1563.62..1564.86 rows=495 width=53) (actual time=0.522..0.533 rows=492 loops=1)
   Sort Key: id
   Sort Method: quicksort  Memory: 64kB
   ->  Bitmap Heap Scan on tasks  (cost=8.26..1541.46 rows=495 width=53) (actual time=0.077..0.481 rows=492 loops=1)
         Recheck Cond: (project_id = 500)
         Heap Blocks: exact=472
         ->  Bitmap Index Scan on idx_tasks_project_id  (cost=0.00..8.13 rows=495 width=0) (actual time=0.040..0.040 rows=492 loops=1)
               Index Cond: (project_id = 500)
 Planning Time: 0.089 ms
 Execution Time: 0.556 ms
(10 rows)
```

## Запрос 2. «Мои задачи в работе»

Список задач исполнителя с фильтром по статусу.

```sql
SELECT id, title, priority, due_date FROM tasks
WHERE assignee_id = 42 AND status = 'in_progress'
ORDER BY due_date;
```

**До:**

```
                                                         QUERY PLAN
-----------------------------------------------------------------------------------------------------------------------------
 Gather Merge  (cost=10766.09..10819.76 rows=460 width=52) (actual time=13.773..16.097 rows=573 loops=1)
   Workers Planned: 2
   Workers Launched: 2
   ->  Sort  (cost=9766.07..9766.64 rows=230 width=52) (actual time=11.324..11.330 rows=191 loops=3)
         Sort Key: due_date
         Sort Method: quicksort  Memory: 52kB
         Worker 0:  Sort Method: quicksort  Memory: 32kB
         Worker 1:  Sort Method: quicksort  Memory: 34kB
         ->  Parallel Seq Scan on tasks  (cost=0.00..9757.04 rows=230 width=52) (actual time=0.036..10.936 rows=191 loops=3)
               Filter: ((assignee_id = 42) AND ((status)::text = 'in_progress'::text))
               Rows Removed by Filter: 166478
 Planning Time: 0.045 ms
 Execution Time: 16.119 ms
(13 rows)
```

**Решение:** составной индекс по двум столбцам из `WHERE`. Первым идёт столбец с более точным условием:
у одного исполнителя около 0,5% задач, а статус `in_progress` у 25%.

```sql
CREATE INDEX idx_tasks_assignee_status ON tasks (assignee_id, status);
```

**После:**

```
                                                                 QUERY PLAN
--------------------------------------------------------------------------------------------------------------------------------------------
 Sort  (cost=1713.94..1715.32 rows=551 width=52) (actual time=0.776..0.793 rows=573 loops=1)
   Sort Key: due_date
   Sort Method: quicksort  Memory: 69kB
   ->  Bitmap Heap Scan on tasks  (cost=10.07..1688.85 rows=551 width=52) (actual time=0.106..0.701 rows=573 loops=1)
         Recheck Cond: ((assignee_id = 42) AND ((status)::text = 'in_progress'::text))
         Heap Blocks: exact=544
         ->  Bitmap Index Scan on idx_tasks_assignee_status  (cost=0.00..9.93 rows=551 width=0) (actual time=0.060..0.060 rows=573 loops=1)
               Index Cond: ((assignee_id = 42) AND ((status)::text = 'in_progress'::text))
 Planning Time: 0.160 ms
 Execution Time: 0.818 ms
(10 rows)
```

## Запрос 3. Первая страница новых задач по сроку

Список в API с фильтром, сортировкой и ограничением: `GET /api/tasks?status=new&sort=due_date&limit=20`.

```sql
SELECT id, title, due_date FROM tasks
WHERE status = 'new'
ORDER BY due_date
LIMIT 20;
```

**До.** Нужно найти все 75 тысяч новых задач, отсортировать их и только потом взять первые 20:

```
                                                              QUERY PLAN
---------------------------------------------------------------------------------------------------------------------------------------
 Limit  (cost=11072.41..11074.74 rows=20 width=47) (actual time=20.195..20.264 rows=20 loops=1)
   ->  Gather Merge  (cost=11072.41..18405.19 rows=62848 width=47) (actual time=20.193..20.261 rows=20 loops=1)
         Workers Planned: 2
         Workers Launched: 2
         ->  Sort  (cost=10072.38..10150.94 rows=31424 width=47) (actual time=16.568..16.568 rows=20 loops=3)
               Sort Key: due_date
               Sort Method: top-N heapsort  Memory: 27kB
               Worker 0:  Sort Method: top-N heapsort  Memory: 27kB
               Worker 1:  Sort Method: top-N heapsort  Memory: 27kB
               ->  Parallel Seq Scan on tasks  (cost=0.00..9236.20 rows=31424 width=47) (actual time=0.006..15.006 rows=25005 loops=3)
                     Filter: ((status)::text = 'new'::text)
                     Rows Removed by Filter: 141664
 Planning Time: 0.036 ms
 Execution Time: 20.272 ms
(14 rows)
```

**Решение:** индекс, в котором строки уже упорядочены по `(status, due_date)`.

```sql
CREATE INDEX idx_tasks_status_due_date ON tasks (status, due_date);
```

**После.** Шага `Sort` больше нет: база читает первые 20 записей индекса и останавливается (`Limit`):

```
                                                                   QUERY PLAN
-------------------------------------------------------------------------------------------------------------------------------------------------
 Limit  (cost=0.42..7.14 rows=20 width=47) (actual time=0.023..0.043 rows=20 loops=1)
   ->  Index Scan using idx_tasks_status_due_date on tasks  (cost=0.42..25342.29 rows=75418 width=47) (actual time=0.022..0.041 rows=20 loops=1)
         Index Cond: ((status)::text = 'new'::text)
 Planning Time: 0.110 ms
 Execution Time: 0.050 ms
(5 rows)
```

## Запрос 4. Комментарии к задаче

Страница задачи: комментарии с именами авторов.

```sql
SELECT c.created_at, u.name, c.body
FROM comments c JOIN users u ON u.id = c.author_id
WHERE c.task_id = 2
ORDER BY c.created_at;
```

**До.** Перебор миллиона комментариев ради двух:

```
                                                                 QUERY PLAN
---------------------------------------------------------------------------------------------------------------------------------------------
 Gather Merge  (cost=16523.91..16524.14 rows=2 width=60) (actual time=40.326..41.972 rows=2 loops=1)
   Workers Planned: 2
   Workers Launched: 2
   ->  Sort  (cost=15523.89..15523.89 rows=1 width=60) (actual time=37.348..37.349 rows=1 loops=3)
         Sort Key: c.created_at
         Sort Method: quicksort  Memory: 25kB
         Worker 0:  Sort Method: quicksort  Memory: 25kB
         Worker 1:  Sort Method: quicksort  Memory: 25kB
         ->  Nested Loop  (cost=0.15..15523.88 rows=1 width=60) (actual time=24.221..37.335 rows=1 loops=3)
               ->  Parallel Seq Scan on comments c  (cost=0.00..15518.36 rows=1 width=44) (actual time=24.218..37.331 rows=1 loops=3)
                     Filter: (task_id = 2)
                     Rows Removed by Filter: 333334
               ->  Memoize  (cost=0.15..5.51 rows=1 width=24) (actual time=0.003..0.003 rows=1 loops=2)
                     Cache Key: c.author_id
                     Cache Mode: logical
                     Hits: 0  Misses: 2  Evictions: 0  Overflows: 0  Memory Usage: 1kB
                     ->  Index Scan using users_pkey on users u  (cost=0.14..5.50 rows=1 width=24) (actual time=0.001..0.001 rows=1 loops=2)
                           Index Cond: (id = c.author_id)
 Planning Time: 0.145 ms
 Execution Time: 41.985 ms
(20 rows)
```

**Решение:**

```sql
CREATE INDEX idx_comments_task_id ON comments (task_id);
```

**После:**

```
                                                                     QUERY PLAN
-----------------------------------------------------------------------------------------------------------------------------------------------------
 Sort  (cost=22.88..22.89 rows=3 width=60) (actual time=0.111..0.112 rows=2 loops=1)
   Sort Key: c.created_at
   Sort Method: quicksort  Memory: 25kB
   ->  Hash Join  (cost=16.51..22.86 rows=3 width=60) (actual time=0.087..0.107 rows=2 loops=1)
         Hash Cond: (u.id = c.author_id)
         ->  Seq Scan on users u  (cost=0.00..5.04 rows=204 width=24) (actual time=0.004..0.014 rows=204 loops=1)
         ->  Hash  (cost=16.48..16.48 rows=3 width=44) (actual time=0.078..0.078 rows=2 loops=1)
               Buckets: 1024  Batches: 1  Memory Usage: 9kB
               ->  Index Scan using idx_comments_task_id on comments c  (cost=0.42..16.48 rows=3 width=44) (actual time=0.072..0.073 rows=2 loops=1)
                     Index Cond: (task_id = 2)
 Planning Time: 0.261 ms
 Execution Time: 0.127 ms
(12 rows)
```

## Запрос 5. Когда индекс не помогает

**5а. Отчёт по всем проектам.** Чтобы посчитать задачи каждого проекта, нужно прочитать все строки,
поэтому планировщик выбирает перебор, хотя индекс по `project_id` уже есть:

```
                                                                  QUERY PLAN
-----------------------------------------------------------------------------------------------------------------------------------------------
 Finalize GroupAggregate  (cost=11858.78..12117.90 rows=1003 width=20) (actual time=39.907..42.035 rows=1003 loops=1)
   Group Key: project_id
   ->  Gather Merge  (cost=11858.78..12092.83 rows=2006 width=20) (actual time=39.904..41.812 rows=3009 loops=1)
         Workers Planned: 2
         Workers Launched: 2
         ->  Sort  (cost=10858.75..10861.26 rows=1003 width=20) (actual time=37.612..37.637 rows=1003 loops=3)
               Sort Key: project_id
               Sort Method: quicksort  Memory: 72kB
               Worker 0:  Sort Method: quicksort  Memory: 72kB
               Worker 1:  Sort Method: quicksort  Memory: 72kB
               ->  Partial HashAggregate  (cost=10798.72..10808.75 rows=1003 width=20) (actual time=37.456..37.515 rows=1003 loops=3)
                     Group Key: project_id
                     Batches: 1  Memory Usage: 193kB
                     Worker 0:  Batches: 1  Memory Usage: 193kB
                     Worker 1:  Batches: 1  Memory Usage: 193kB
                     ->  Parallel Seq Scan on tasks  (cost=0.00..8715.36 rows=208336 width=10) (actual time=0.006..14.891 rows=166669 loops=3)
 Planning Time: 0.120 ms
 Execution Time: 42.068 ms
(18 rows)
```

**5б. Поиск по части названия.** Обычный индекс (B-tree) упорядочивает значения с начала строки и не умеет
искать подстроку `%...%`. Для такого поиска нужен специальный индекс (расширение `pg_trgm`, задание повышенной сложности):

```
                                                      QUERY PLAN
-----------------------------------------------------------------------------------------------------------------------
 Gather  (cost=1000.00..10241.20 rows=50 width=43) (actual time=11.605..114.144 rows=111 loops=1)
   Workers Planned: 2
   Workers Launched: 2
   ->  Parallel Seq Scan on tasks  (cost=0.00..9236.20 rows=21 width=43) (actual time=40.892..111.223 rows=37 loops=3)
         Filter: ((title)::text ~~* '%задача №4999%'::text)
         Rows Removed by Filter: 166632
 Planning Time: 1.444 ms
 Execution Time: 114.154 ms
(8 rows)
```

**5в. Условие, которому подходит большинство строк.** Статус `done` у 60% задач. Индекс `(status, due_date)` есть,
но переходить по нему к 300 тысячам строк дороже, чем перебрать таблицу подряд, и планировщик его не использует:

```
                                                               QUERY PLAN
----------------------------------------------------------------------------------------------------------------------------------------
 Finalize Aggregate  (cost=10548.51..10548.52 rows=1 width=8) (actual time=22.833..24.308 rows=1 loops=1)
   ->  Gather  (cost=10548.29..10548.50 rows=2 width=8) (actual time=22.202..24.304 rows=3 loops=1)
         Workers Planned: 2
         Workers Launched: 2
         ->  Partial Aggregate  (cost=9548.29..9548.30 rows=1 width=8) (actual time=21.145..21.145 rows=1 loops=3)
               ->  Parallel Seq Scan on tasks  (cost=0.00..9236.20 rows=124835 width=0) (actual time=0.004..17.186 rows=100111 loops=3)
                     Filter: ((status)::text = 'done'::text)
                     Rows Removed by Filter: 66558
 Planning Time: 0.047 ms
 Execution Time: 24.320 ms
(10 rows)
```

## Цена индексов

Индексы занимают место и замедляют запись: при каждой вставке, изменении и удалении базе нужно обновить все индексы таблицы.

```
Цена индексов: место на диске
        index_name         |  size
---------------------------+---------
 comments_pkey             | 21 MB
 idx_comments_task_id      | 16 MB
 tasks_pkey                | 11 MB
 idx_tasks_assignee_status | 3608 kB
 idx_tasks_project_id      | 3576 kB
 idx_tasks_status_due_date | 3488 kB
(6 rows)

 tasks_table | tasks_indexes
-------------+---------------
 52 MB       | 21 MB
(1 row)
```

Вставка 50 000 задач с тремя дополнительными индексами и без них (обе вставки отменены через `ROLLBACK`):

```
                                                             QUERY PLAN
------------------------------------------------------------------------------------------------------------------------------------
 Insert on tasks  (cost=0.00..2250.00 rows=0 width=0) (actual time=194.794..194.795 rows=0 loops=1)
   ->  Function Scan on generate_series i  (cost=0.00..2250.00 rows=50000 width=538) (actual time=1.865..18.093 rows=50000 loops=1)
 Planning Time: 0.179 ms
 Trigger for constraint tasks_project_id_fkey: time=98.233 calls=50000
 Trigger for constraint tasks_assignee_id_fkey: time=103.689 calls=50000
 Execution Time: 399.114 ms
(6 rows)
```

```
                                                             QUERY PLAN
------------------------------------------------------------------------------------------------------------------------------------
 Insert on tasks  (cost=0.00..2250.00 rows=0 width=0) (actual time=94.745..94.745 rows=0 loops=1)
   ->  Function Scan on generate_series i  (cost=0.00..2250.00 rows=50000 width=538) (actual time=1.118..20.929 rows=50000 loops=1)
 Planning Time: 0.054 ms
 Trigger for constraint tasks_project_id_fkey: time=98.448 calls=50000
 Trigger for constraint tasks_assignee_id_fkey: time=103.491 calls=50000
 Execution Time: 299.241 ms
(6 rows)
```

Сама запись строк (`Insert on tasks`) с индексами заняла 194,8 мс, без них 94,7 мс, то есть примерно вдвое дольше.
Ещё около 200 мс в обоих случаях уходит на проверку внешних ключей (строки `Trigger for constraint`).

## Итог

| № | Запрос | Индекс | До, мс | После, мс | Ускорение |
|---|--------|--------|-------:|----------:|----------:|
| 1 | задачи проекта | `(project_id)` | 14,899 | 0,556 | ×27 |
| 2 | мои задачи в работе | `(assignee_id, status)` | 16,119 | 0,818 | ×20 |
| 3 | первые 20 новых задач по сроку | `(status, due_date)` | 20,272 | 0,050 | ×405 |
| 4 | комментарии к задаче | `comments (task_id)` | 41,985 | 0,127 | ×331 |

Выводы:

- индексы нужны на внешние ключи и на столбцы, по которым часто фильтруют (`WHERE`) и сортируют (`ORDER BY`);
- составной индекс полезен, когда в запросе условие сразу по нескольким столбцам или условие плюс сортировка;
- индекс не помогает, если запрос читает большую часть таблицы или ищет подстроку `%...%`;
- каждый индекс занимает место (здесь 3,5 МБ на 500 тысяч строк) и замедляет запись, поэтому индексы создаются
  только для реально частых запросов и проверяются командой `EXPLAIN ANALYZE`.
