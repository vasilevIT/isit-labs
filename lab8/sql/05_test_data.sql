-- =====================================================================
-- Лабораторная работа №8. Большой объём тестовых данных для оптимизации
-- На 7 задачах любой запрос выполняется мгновенно, индексы не видны.
-- Поэтому генерируем 200 участников, 1000 проектов, 500 000 задач и 1 000 000 комментариев.
-- Запуск в psql:  \i /sql/05_test_data.sql   (после 02_seed.sql; занимает 10–30 секунд)
-- =====================================================================

\timing on

-- одинаковое «случайное» заполнение при каждом запуске
SELECT setseed(0.42);

INSERT INTO users (name, email, role)
SELECT 'Участник ' || i,
       'user' || i || '@example.com',
       (ARRAY['developer', 'developer', 'tester', 'manager'])[1 + floor(random() * 4)::int]
FROM generate_series(1, 200) AS i;

INSERT INTO projects (title, description, owner_id)
SELECT 'Проект №' || i, 'Сгенерированный проект для проверки производительности', 1
FROM generate_series(1, 1000) AS i;

-- Номера существующих проектов и участников собираем в массивы и выбираем из них случайно:
-- так внешние ключи всегда указывают на существующие строки.
WITH p AS (SELECT array_agg(id) AS ids FROM projects),
     u AS (SELECT array_agg(id) AS ids FROM users)
INSERT INTO tasks (project_id, assignee_id, title, status, priority, due_date, created_at)
SELECT p.ids[1 + floor(random() * cardinality(p.ids))::int],
       CASE WHEN random() < 0.1 THEN NULL                          -- 10% задач без исполнителя
            ELSE u.ids[1 + floor(random() * cardinality(u.ids))::int] END,
       'Тестовая задача №' || g.i,
       CASE WHEN g.r < 0.60 THEN 'done'                             -- 60% выполнено
            WHEN g.r < 0.85 THEN 'in_progress'                      -- 25% в работе
            ELSE 'new' END,                                         -- 15% новых
       (ARRAY['low', 'medium', 'high'])[1 + floor(random() * 3)::int],
       DATE '2025-01-01' + floor(random() * 730)::int,
       TIMESTAMP '2025-01-01' + random() * INTERVAL '640 days'
-- random() для статуса считается в подзапросе g: так он свой у каждой строки
FROM (SELECT i, random() AS r FROM generate_series(1, 500000) AS i) AS g, p, u;

-- номера задач тоже берём из массива: после скриптов 03 и 04 в нумерации есть пропуски
WITH t AS (SELECT array_agg(id) AS ids FROM tasks),
     u AS (SELECT array_agg(id) AS ids FROM users)
INSERT INTO comments (task_id, author_id, body, created_at)
SELECT t.ids[1 + floor(random() * cardinality(t.ids))::int],
       u.ids[1 + floor(random() * cardinality(u.ids))::int],
       'Комментарий №' || i,
       TIMESTAMP '2025-01-01' + random() * INTERVAL '640 days'
FROM generate_series(1, 1000000) AS i, t, u;

-- обновить статистику, по которой планировщик выбирает способ выполнения запроса
ANALYZE;

\timing off

SELECT 'users' AS table_name, COUNT(*) FROM users
UNION ALL SELECT 'projects', COUNT(*) FROM projects
UNION ALL SELECT 'tasks',    COUNT(*) FROM tasks
UNION ALL SELECT 'comments', COUNT(*) FROM comments;
