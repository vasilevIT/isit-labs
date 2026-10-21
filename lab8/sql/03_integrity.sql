-- =====================================================================
-- Лабораторная работа №8. Проверка ссылочной целостности и ограничений
-- Каждый запрос ниже НАРУШАЕТ правило, и база должна его отклонить.
-- Запуск в psql:  \i /sql/03_integrity.sql   (после 02_seed.sql)
-- Данные после скрипта не меняются: ошибочные запросы ничего не записывают,
-- а проверки каскадов выполняются внутри транзакции с ROLLBACK.
-- =====================================================================

\echo '--- 1. Задача в несуществующем проекте (FOREIGN KEY) ---'
INSERT INTO tasks (project_id, title) VALUES (99, 'Задача без проекта');

\echo '--- 2. Неизвестный статус задачи (CHECK) ---'
INSERT INTO tasks (project_id, title, status) VALUES (1, 'Проверить статус', 'finished');

\echo '--- 3. Слишком короткое название (CHECK) ---'
INSERT INTO tasks (project_id, title) VALUES (1, 'Да');

\echo '--- 4. Задача без названия (NOT NULL) ---'
INSERT INTO tasks (project_id, title) VALUES (1, NULL);

\echo '--- 5. Повтор почты участника (UNIQUE) ---'
INSERT INTO users (name, email) VALUES ('Анна Двойник', 'anna@example.com');

\echo '--- 6. Второй профиль у одного участника (связь 1:1, PRIMARY KEY) ---'
INSERT INTO user_profiles (user_id, position) VALUES (1, 'Второй профиль');

\echo '--- 7. Один и тот же тег у задачи дважды (связь N:M, составной PRIMARY KEY) ---'
INSERT INTO task_tags (task_id, tag_id) VALUES (1, 1);

\echo '--- 8. Удаление проекта, в котором есть задачи (ON DELETE RESTRICT) ---'
DELETE FROM projects WHERE id = 1;

\echo '--- 9. Удаление руководителя, у которого есть проекты (ON DELETE RESTRICT) ---'
DELETE FROM users WHERE id = 1;

\echo '--- 10. Удаление задачи удаляет её комментарии и теги (ON DELETE CASCADE) ---'
BEGIN;
SELECT
    (SELECT COUNT(*) FROM comments  WHERE task_id = 2) AS comments_before,
    (SELECT COUNT(*) FROM task_tags WHERE task_id = 2) AS tags_before;
DELETE FROM tasks WHERE id = 2;
SELECT
    (SELECT COUNT(*) FROM comments  WHERE task_id = 2) AS comments_after,
    (SELECT COUNT(*) FROM task_tags WHERE task_id = 2) AS tags_after;
ROLLBACK;

\echo '--- 11. Удаление исполнителя оставляет задачи без исполнителя (ON DELETE SET NULL) ---'
BEGIN;
DELETE FROM users WHERE id = 4;
SELECT id, title, assignee_id FROM tasks WHERE id IN (4, 5) ORDER BY id;
ROLLBACK;
