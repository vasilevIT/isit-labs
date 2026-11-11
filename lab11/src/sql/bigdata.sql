-- =====================================================================
-- Лабораторная работа №10. Большой объём данных для измерения скорости
-- На 7 задачах любой запрос выполняется мгновенно и кеш не даёт заметного выигрыша.
-- Поэтому добавляем 49 проектов и 500 000 задач. Загрузка: php bin/load-bigdata.php (несколько секунд).
-- Внимание: после загрузки автотесты запускать не нужно без php bin/reset-db.php (они рассчитаны на 7 задач).
-- =====================================================================

SELECT setseed(0.42);                                   -- одинаковое «случайное» заполнение при каждом запуске

INSERT INTO projects (title, description)
SELECT 'Проект №' || i, 'Сгенерированный проект для измерений'
FROM generate_series(1, 49) AS i;

INSERT INTO tasks (project_id, title, description, status, priority, due_date, created_at)
SELECT p.ids[1 + floor(random() * cardinality(p.ids))::int],
       'Тестовая задача №' || g.i,
       '',
       CASE WHEN g.r < 0.60 THEN 'done'                 -- 60% выполнено
            WHEN g.r < 0.85 THEN 'in_progress'          -- 25% в работе
            ELSE 'new' END,                             -- 15% новых
       (ARRAY['low', 'medium', 'high'])[1 + floor(random() * 3)::int],
       DATE '2025-01-01' + floor(random() * 730)::int,
       TIMESTAMP '2025-01-01' + random() * INTERVAL '640 days'
FROM (SELECT i, random() AS r FROM generate_series(1, 500000) AS i) AS g,
     (SELECT array_agg(id) AS ids FROM projects) AS p;

ANALYZE;                                                -- обновить статистику для планировщика запросов
