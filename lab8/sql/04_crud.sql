-- =====================================================================
-- Лабораторная работа №8. CRUD-операции
-- Запуск в psql:  \i /sql/04_crud.sql   (после 02_seed.sql)
-- Рядом с каждым запросом указан адрес REST API из лабораторной работы №7,
-- который будет выполнять этот запрос, когда API подключится к базе.
-- =====================================================================

-- ---------------------------------------------------------------------
-- CREATE (INSERT)
-- ---------------------------------------------------------------------

\echo '=== INSERT: создать задачу (POST /api/tasks) ==='
-- RETURNING возвращает созданную строку сразу, отдельный SELECT не нужен.
-- \gset сохраняет номер новой задачи в переменную psql :task_id для следующих запросов.
INSERT INTO tasks (project_id, assignee_id, title, priority, due_date)
VALUES (1, 2, 'Подключить базу данных к API', 'high', '2026-11-15')
RETURNING id AS task_id, title, status, priority, due_date \gset
SELECT id, project_id, assignee_id, title, status, priority, due_date
FROM tasks WHERE id = :task_id;

\echo '=== INSERT: несколько строк одной командой — теги новой задачи (N:M) ==='
INSERT INTO task_tags (task_id, tag_id) VALUES (:task_id, 2), (:task_id, 4);

\echo '=== INSERT: создать проект (POST /api/projects) ==='
INSERT INTO projects (title, description, owner_id)
VALUES ('Чат-бот поддержки', 'Бот для ответов на частые вопросы', 1)
RETURNING id, title, owner_id;

-- ---------------------------------------------------------------------
-- READ (SELECT)
-- ---------------------------------------------------------------------

\echo '=== SELECT: список задач с фильтром по статусу (GET /api/tasks?status=in_progress) ==='
SELECT id, title, status, priority, due_date
FROM tasks
WHERE status = 'in_progress'
ORDER BY due_date;

\echo '=== SELECT: одна задача (GET /api/tasks/{id}) ==='
SELECT id, project_id, assignee_id, title, status, priority, due_date
FROM tasks
WHERE id = 5;

\echo '=== SELECT + JOIN: задачи с названием проекта и именем исполнителя ==='
-- LEFT JOIN, потому что у части задач исполнителя нет: обычный JOIN их бы потерял
SELECT t.id, t.title, p.title AS project, u.name AS assignee, t.status
FROM tasks t
JOIN projects p ON p.id = t.project_id
LEFT JOIN users u ON u.id = t.assignee_id
ORDER BY t.id;

\echo '=== SELECT: задачи проекта (GET /api/projects/2/tasks) ==='
SELECT id, title, status
FROM tasks
WHERE project_id = 2
ORDER BY id;

\echo '=== SELECT: задачи без исполнителя (IS NULL, а не = NULL) ==='
SELECT id, title FROM tasks WHERE assignee_id IS NULL;

\echo '=== SELECT + N:M: теги каждой задачи одной строкой ==='
SELECT t.id, t.title, string_agg(g.name, ', ' ORDER BY g.name) AS tags
FROM tasks t
JOIN task_tags tt ON tt.task_id = t.id
JOIN tags g       ON g.id = tt.tag_id
GROUP BY t.id, t.title
ORDER BY t.id;

\echo '=== SELECT + 1:1: участники и их профили ==='
SELECT u.name, u.role, pr.position, pr.telegram
FROM users u
LEFT JOIN user_profiles pr ON pr.user_id = u.id
ORDER BY u.id;

\echo '=== SELECT + GROUP BY: загрузка участников (отчёт руководителя) ==='
SELECT u.name,
       COUNT(t.id)                                    AS total,
       COUNT(t.id) FILTER (WHERE t.status <> 'done')  AS open_tasks
FROM users u
LEFT JOIN tasks t ON t.assignee_id = u.id
GROUP BY u.id, u.name
ORDER BY open_tasks DESC, u.name;

\echo '=== SELECT: комментарии к задаче с авторами ==='
SELECT c.created_at::date AS date, u.name AS author, c.body
FROM comments c
JOIN users u ON u.id = c.author_id
WHERE c.task_id = 2
ORDER BY c.created_at;

-- ---------------------------------------------------------------------
-- UPDATE
-- ---------------------------------------------------------------------

\echo '=== UPDATE: перевести новую задачу в работу (PATCH /api/tasks/{id}) ==='
-- Перед UPDATE и DELETE полезно выполнить SELECT с тем же WHERE,
-- чтобы убедиться, что изменятся именно нужные строки.
UPDATE tasks
SET status = 'in_progress'
WHERE id = :task_id
RETURNING id, title, status;

\echo '=== UPDATE: несколько полей сразу (PUT /api/tasks/{id}) ==='
UPDATE tasks
SET priority = 'medium', due_date = due_date + 7
WHERE id = :task_id
RETURNING id, priority, due_date;

\echo '=== UPDATE: назначить исполнителя всем задачам без исполнителя в проекте 1 ==='
UPDATE tasks
SET assignee_id = 4
WHERE project_id = 1 AND assignee_id IS NULL
RETURNING id, title, assignee_id;

-- ---------------------------------------------------------------------
-- DELETE
-- ---------------------------------------------------------------------

\echo '=== DELETE: удалить задачу (DELETE /api/tasks/{id}); теги удалятся каскадом ==='
DELETE FROM tasks WHERE id = :task_id RETURNING id, title;
SELECT COUNT(*) AS tags_left FROM task_tags WHERE task_id = :task_id;

\echo '=== DELETE: удалить пустой проект (DELETE /api/projects/{id}) ==='
DELETE FROM projects WHERE title = 'Чат-бот поддержки' RETURNING id, title;

\echo '=== DELETE: повторное удаление — 0 строк (в API это ответ 404) ==='
DELETE FROM tasks WHERE id = :task_id;
