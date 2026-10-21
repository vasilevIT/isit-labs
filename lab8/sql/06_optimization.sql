-- =====================================================================
-- Лабораторная работа №8. Анализ и оптимизация SQL-запросов
-- Запуск в psql:  \i /sql/06_optimization.sql   (после 05_test_data.sql)
--
-- Для каждого частого запроса:
--   1) EXPLAIN ANALYZE без индекса  — смотрим план и время;
--   2) создаём индекс;
--   3) EXPLAIN ANALYZE с индексом   — сравниваем.
-- EXPLAIN ANALYZE действительно выполняет запрос и показывает фактическое время.
-- Время зависит от компьютера: важно не само число, а во сколько раз оно изменилось.
-- =====================================================================

-- Начинаем «с чистого листа»: удаляем индексы, если скрипт уже запускали.
DROP INDEX IF EXISTS idx_tasks_project_id, idx_tasks_assignee_status,
                     idx_tasks_status_due_date, idx_comments_task_id;
ANALYZE tasks;
ANALYZE comments;

\echo '=================================================================='
\echo 'Индексы, которые есть до оптимизации (только первичные ключи и UNIQUE)'
\echo '=================================================================='
SELECT tablename, indexname FROM pg_indexes
WHERE tablename IN ('tasks', 'comments') ORDER BY tablename, indexname;


\echo '=================================================================='
\echo 'Запрос 1. Задачи проекта (GET /api/projects/{id}/tasks)'
\echo '=================================================================='
\echo '--- до: индекса по project_id нет ---'
EXPLAIN ANALYZE
SELECT id, title, status, due_date FROM tasks WHERE project_id = 500 ORDER BY id;

-- PostgreSQL НЕ создаёт индекс для внешнего ключа автоматически (только для PRIMARY KEY и UNIQUE)
CREATE INDEX idx_tasks_project_id ON tasks (project_id);

\echo '--- после: CREATE INDEX idx_tasks_project_id ON tasks (project_id) ---'
EXPLAIN ANALYZE
SELECT id, title, status, due_date FROM tasks WHERE project_id = 500 ORDER BY id;


\echo '=================================================================='
\echo 'Запрос 2. «Мои задачи в работе» (GET /api/tasks?assignee_id=42&status=in_progress)'
\echo '=================================================================='
\echo '--- до ---'
EXPLAIN ANALYZE
SELECT id, title, priority, due_date FROM tasks
WHERE assignee_id = 42 AND status = 'in_progress'
ORDER BY due_date;

-- составной индекс: сначала столбец с самым точным условием (исполнитель), затем статус
CREATE INDEX idx_tasks_assignee_status ON tasks (assignee_id, status);

\echo '--- после: CREATE INDEX idx_tasks_assignee_status ON tasks (assignee_id, status) ---'
EXPLAIN ANALYZE
SELECT id, title, priority, due_date FROM tasks
WHERE assignee_id = 42 AND status = 'in_progress'
ORDER BY due_date;


\echo '=================================================================='
\echo 'Запрос 3. Первая страница новых задач по сроку (GET /api/tasks?status=new&sort=due_date&limit=20)'
\echo '=================================================================='
\echo '--- до ---'
EXPLAIN ANALYZE
SELECT id, title, due_date FROM tasks
WHERE status = 'new'
ORDER BY due_date
LIMIT 20;

-- индекс хранит строки уже отсортированными по (status, due_date):
-- базе достаточно прочитать первые 20 записей, сортировка не нужна
CREATE INDEX idx_tasks_status_due_date ON tasks (status, due_date);

\echo '--- после: CREATE INDEX idx_tasks_status_due_date ON tasks (status, due_date) ---'
EXPLAIN ANALYZE
SELECT id, title, due_date FROM tasks
WHERE status = 'new'
ORDER BY due_date
LIMIT 20;


\echo '=================================================================='
\echo 'Запрос 4. Комментарии к задаче с авторами (страница задачи)'
\echo '=================================================================='
\echo '--- до ---'
EXPLAIN ANALYZE
SELECT c.created_at, u.name, c.body
FROM comments c JOIN users u ON u.id = c.author_id
WHERE c.task_id = 2
ORDER BY c.created_at;

CREATE INDEX idx_comments_task_id ON comments (task_id);

\echo '--- после: CREATE INDEX idx_comments_task_id ON comments (task_id) ---'
EXPLAIN ANALYZE
SELECT c.created_at, u.name, c.body
FROM comments c JOIN users u ON u.id = c.author_id
WHERE c.task_id = 2
ORDER BY c.created_at;


\echo '=================================================================='
\echo 'Запрос 5. Когда индекс НЕ помогает'
\echo '=================================================================='
\echo '--- 5а. Отчёт по всем проектам: нужно прочитать все строки, индекс не используется ---'
EXPLAIN ANALYZE
SELECT project_id, COUNT(*) AS total, COUNT(*) FILTER (WHERE status = 'done') AS done
FROM tasks
GROUP BY project_id;

\echo '--- 5б. Поиск по части названия: обычный индекс (B-tree) не умеет искать «%слово%» ---'
EXPLAIN ANALYZE
SELECT id, title FROM tasks WHERE title ILIKE '%задача №4999%';

\echo '--- 5в. Условие «status = done» подходит 60% строк: перебрать таблицу дешевле, чем идти по индексу ---'
EXPLAIN ANALYZE
SELECT COUNT(*) FROM tasks WHERE status = 'done';


\echo '=================================================================='
\echo 'Цена индексов: место на диске'
\echo '=================================================================='
SELECT indexrelname AS index_name,
       pg_size_pretty(pg_relation_size(indexrelid)) AS size
FROM pg_stat_user_indexes
WHERE relname IN ('tasks', 'comments')
ORDER BY pg_relation_size(indexrelid) DESC;

SELECT pg_size_pretty(pg_relation_size('tasks'))        AS tasks_table,
       pg_size_pretty(pg_indexes_size('tasks'))         AS tasks_indexes;


\echo '=================================================================='
\echo 'Цена индексов: вставка 50 000 задач без дополнительных индексов и с ними'
\echo '(внутри транзакции с ROLLBACK, чтобы не менять данные)'
\echo '=================================================================='
-- проекты 1–3 и участники 2–4 из 02_seed.sql точно существуют (скрипт 04 удаляет только то, что сам создал)
\echo '--- с тремя дополнительными индексами на tasks ---'
BEGIN;
EXPLAIN ANALYZE
INSERT INTO tasks (project_id, assignee_id, title, status, due_date)
SELECT 1 + i % 3, 2 + i % 3, 'Вставка №' || i, 'new', DATE '2026-01-01' + i % 365
FROM generate_series(1, 50000) AS i;
ROLLBACK;

\echo '--- без них (индексы удаляются внутри транзакции и возвращаются после ROLLBACK) ---'
BEGIN;
DROP INDEX idx_tasks_project_id, idx_tasks_assignee_status, idx_tasks_status_due_date;
EXPLAIN ANALYZE
INSERT INTO tasks (project_id, assignee_id, title, status, due_date)
SELECT 1 + i % 3, 2 + i % 3, 'Вставка №' || i, 'new', DATE '2026-01-01' + i % 365
FROM generate_series(1, 50000) AS i;
ROLLBACK;
