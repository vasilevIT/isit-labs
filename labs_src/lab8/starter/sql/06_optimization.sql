-- =====================================================================
-- Лабораторная работа №8. Часть 5. Анализ и оптимизация SQL-запросов
-- Запуск в psql:  \i /sql/06_optimization.sql   (после 05_test_data.sql)
--
-- Для каждого частого запроса:
--   1) EXPLAIN ANALYZE без индекса  — смотрим план и время;
--   2) создаём индекс;
--   3) EXPLAIN ANALYZE с индексом   — сравниваем.
-- EXPLAIN ANALYZE действительно выполняет запрос и показывает фактическое время (Execution Time).
-- Время зависит от компьютера: важно не само число, а во сколько раз оно изменилось.
-- Запишите «до» и «после» каждого запроса в таблицу для README.
-- =====================================================================

-- Начинаем «с чистого листа»: удаляем индексы, если скрипт уже запускали.
-- Когда будете добавлять свои индексы, допишите их имена в этот список.
DROP INDEX IF EXISTS idx_tasks_project_id;
ANALYZE tasks;
ANALYZE comments;

\echo 'Индексы до оптимизации (только первичные ключи и UNIQUE):'
SELECT tablename, indexname FROM pg_indexes
WHERE tablename IN ('tasks', 'comments') ORDER BY tablename, indexname;


-- ---------------------------------------------------------------------
-- Запрос 1. Задачи проекта (GET /api/projects/{id}/tasks) — ГОТОВО, образец
-- ---------------------------------------------------------------------
\echo '=== Запрос 1. Задачи проекта: до ==='
EXPLAIN ANALYZE
SELECT id, title, status, due_date FROM tasks WHERE project_id = 500 ORDER BY id;
-- В плане: Parallel Seq Scan on tasks — перебор всей таблицы; Rows Removed by Filter — сколько строк прочитано зря.

-- PostgreSQL НЕ создаёт индекс для внешнего ключа автоматически (только для PRIMARY KEY и UNIQUE)
CREATE INDEX idx_tasks_project_id ON tasks (project_id);

\echo '=== Запрос 1. Задачи проекта: после индекса ==='
EXPLAIN ANALYZE
SELECT id, title, status, due_date FROM tasks WHERE project_id = 500 ORDER BY id;
-- В плане: Bitmap Index Scan on idx_tasks_project_id — строки найдены по индексу. Execution Time в десятки раз меньше.


-- TODO 5.1  Запрос 2. «Мои задачи в работе»: задачи исполнителя № 42 со статусом in_progress, по сроку:
--             SELECT id, title, priority, due_date FROM tasks
--             WHERE assignee_id = 42 AND status = 'in_progress'
--             ORDER BY due_date;
--           Выполните EXPLAIN ANALYZE до, создайте составной индекс по двум столбцам из WHERE
--             CREATE INDEX idx_tasks_assignee_status ON tasks (assignee_id, status);
--           и выполните EXPLAIN ANALYZE после. Не забудьте добавить имя индекса в DROP INDEX в начале файла.
--           ✔ Проверка: «до» — Seq Scan, «после» — Index Scan или Bitmap Index Scan on idx_tasks_assignee_status.


-- TODO 5.2  Запрос 3. Первая страница новых задач по сроку (GET /api/tasks?status=new&sort=due_date&limit=20):
--             SELECT id, title, due_date FROM tasks WHERE status = 'new' ORDER BY due_date LIMIT 20;
--           Подберите индекс сами: по какому столбцу фильтруем и по какому сортируем?
--           ✔ Проверка: в плане «после» нет шага Sort, есть Limit -> Index Scan; время меньше 1 мс.


-- TODO 5.3  Запрос 4. Комментарии к задаче № 2 с именами авторов (comments JOIN users, WHERE c.task_id = 2,
--           ORDER BY c.created_at). Какого индекса не хватает? Подсказка: внешний ключ comments.task_id.
--           ✔ Проверка: «до» — Seq Scan on comments по миллиону строк; «после» — Index Scan.


-- TODO 5.4  Когда индекс НЕ помогает. Выполните EXPLAIN ANALYZE для двух запросов и объясните в README,
--           почему в плане Seq Scan, хотя индексы есть:
--             SELECT COUNT(*) FROM tasks WHERE status = 'done';               -- такому условию подходит 60% строк
--             SELECT id, title FROM tasks WHERE title ILIKE '%задача №4999%'; -- поиск подстроки


-- ---------------------------------------------------------------------
-- Цена индексов: место на диске (ГОТОВО)
-- ---------------------------------------------------------------------
\echo '=== Размер индексов ==='
SELECT indexrelname AS index_name,
       pg_size_pretty(pg_relation_size(indexrelid)) AS size
FROM pg_stat_user_indexes
WHERE relname IN ('tasks', 'comments')
ORDER BY pg_relation_size(indexrelid) DESC;
