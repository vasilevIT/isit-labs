-- =====================================================================
-- Лабораторная работа №12. SQL-запросы для анализа журнала событий (таблица events).
-- Запуск всех запросов: php bin/analytics.php  (или по одному в psql:
--   docker compose exec db psql -U postgres -d taskapp, затем вставить текст запроса).
-- Перед запуском наполните журнал данными: php bin/seed-events.php (20 000 событий за 30 дней).
-- Каждый запрос начинается со строки «-- @query название» и заканчивается точкой с запятой.
-- Время в таблице хранится как TIMESTAMPTZ; «день» считается по времени сервера базы данных (в Docker это UTC).
-- =====================================================================

-- @query 0. Пример (ГОТОВО): сколько событий и какая доля ошибок по дням (последние 7 дней)
SELECT occurred_at::date                                              AS day,
       COUNT(*)                                                       AS total,
       COUNT(*) FILTER (WHERE type = 'server_error')                  AS errors,
       ROUND(100.0 * COUNT(*) FILTER (WHERE type = 'server_error') / COUNT(*), 2) AS error_percent
FROM events
WHERE occurred_at >= now() - interval '7 days'
GROUP BY day
ORDER BY day;

-- @query 1. Количество событий каждого типа (от самых частых)
SELECT type, COUNT(*) AS total
FROM events
GROUP BY type
ORDER BY total DESC;

-- @query 2. Пять самых активных участников (сколько действий выполнил каждый)
SELECT u.name, u.role, COUNT(*) AS actions
FROM events e
JOIN users u ON u.id = e.user_id
GROUP BY u.id, u.name, u.role
ORDER BY actions DESC
LIMIT 5;

-- @query 3. Количество событий по дням за последние 14 дней
SELECT occurred_at::date AS day, COUNT(*) AS total
FROM events
WHERE occurred_at >= now() - interval '14 days'
GROUP BY day
ORDER BY day;

-- @query 4. Распределение событий по категориям (число и доля в процентах)
SELECT category,
       COUNT(*) AS total,
       ROUND(100.0 * COUNT(*) / SUM(COUNT(*)) OVER (), 1) AS percent
FROM events
GROUP BY category
ORDER BY total DESC;

-- @query 5. Свой запрос (задание 5.1): например, активность по часам суток
SELECT EXTRACT(HOUR FROM occurred_at)::int AS hour, COUNT(*) AS total
FROM events
GROUP BY hour
ORDER BY hour;
