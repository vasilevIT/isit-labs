-- =====================================================================
-- Лабораторная работа №8. Часть 4. CRUD-операции
-- Запуск в psql:  \i /sql/04_crud.sql   (после 02_seed.sql)
-- Рядом с каждым запросом указан адрес REST API из лабораторной работы №7,
-- который будет выполнять этот запрос, когда API подключится к базе.
-- =====================================================================

-- ---------------------------------------------------------------------
-- CREATE (INSERT) — ГОТОВО, образец
-- ---------------------------------------------------------------------
\echo '=== INSERT: создать задачу (POST /api/tasks) ==='
-- RETURNING возвращает созданную строку сразу, отдельный SELECT не нужен.
-- \gset сохраняет номер новой задачи в переменную psql :task_id — она нужна в запросах ниже.
INSERT INTO tasks (project_id, assignee_id, title, priority, due_date)
VALUES (1, 2, 'Подключить базу данных к API', 'high', '2026-11-15')
RETURNING id AS task_id \gset
SELECT id, project_id, assignee_id, title, status, priority, due_date
FROM tasks WHERE id = :task_id;

-- TODO 4.1  Добавьте новой задаче два тега одной командой (несколько строк в VALUES через запятую):
--             INSERT INTO task_tags (task_id, tag_id) VALUES (:task_id, 2), (:task_id, 4);
--           ✔ Проверка: INSERT 0 2.


-- ---------------------------------------------------------------------
-- READ (SELECT)
-- ---------------------------------------------------------------------

-- TODO 4.2  Список задач со статусом in_progress, отсортированный по сроку (GET /api/tasks?status=in_progress):
--             SELECT id, title, status, priority, due_date
--             FROM tasks
--             WHERE status = 'in_progress'
--             ORDER BY due_date;
--           И одна задача по номеру (GET /api/tasks/5): тот же SELECT с условием WHERE id = 5.
--           ✔ Проверка: две задачи (№ 2 и № 5); вторым запросом — одна строка.


-- TODO 4.3  Задачи с названием проекта и именем исполнителя. Таблицы объединяются через JOIN;
--           для исполнителя нужен LEFT JOIN, иначе задачи без исполнителя пропадут из результата:
--             SELECT t.id, t.title, p.title AS project, u.name AS assignee, t.status
--             FROM tasks t
--             JOIN projects p ON p.id = t.project_id
--             LEFT JOIN users u ON u.id = t.assignee_id
--             ORDER BY t.id;
--           ✔ Проверка: 8 строк; у задач № 3 и № 7 столбец assignee пустой.
--             Замените LEFT JOIN на JOIN и убедитесь, что этих двух задач в результате нет.


-- TODO 4.4  Задачи без исполнителя. Сравнение с NULL пишется через IS NULL, а не через «= NULL».
--           ✔ Проверка: задачи № 3 и № 7.


-- TODO 4.5  Связь N:M: теги каждой задачи одной строкой. Функция string_agg склеивает значения группы:
--             SELECT t.id, t.title, string_agg(g.name, ', ' ORDER BY g.name) AS tags
--             FROM tasks t
--             JOIN task_tags tt ON tt.task_id = t.id
--             JOIN tags g       ON g.id = tt.tag_id
--             GROUP BY t.id, t.title
--             ORDER BY t.id;
--           ✔ Проверка: у новой задачи теги «backend, срочно».


-- TODO 4.6  Отчёт руководителя: сколько всего задач и сколько незавершённых у каждого участника.
--           Подсказка: users LEFT JOIN tasks ON t.assignee_id = u.id, GROUP BY u.id, u.name,
--           COUNT(t.id) AS total, COUNT(t.id) FILTER (WHERE t.status <> 'done') AS open_tasks.
--           ✔ Проверка: 4 строки; у Анны 0 задач (без LEFT JOIN её бы не было в отчёте).


-- ---------------------------------------------------------------------
-- UPDATE
-- ---------------------------------------------------------------------

-- TODO 4.7  Переведите новую задачу в работу (PATCH /api/tasks/{id}).
--           Правило: перед UPDATE и DELETE выполните SELECT с тем же WHERE и убедитесь, что выбраны нужные строки.
--             UPDATE tasks SET status = 'in_progress' WHERE id = :task_id RETURNING id, title, status;
--           Затем измените у неё сразу два поля: priority = 'medium' и срок на неделю позже (due_date = due_date + 7).
--           ✔ Проверка: UPDATE 1 после каждой команды; срок стал 2026-11-22.


-- ---------------------------------------------------------------------
-- DELETE
-- ---------------------------------------------------------------------

-- TODO 4.8  Удалите новую задачу (DELETE /api/tasks/{id}) и убедитесь, что её теги удалились каскадом:
--             DELETE FROM tasks WHERE id = :task_id RETURNING id, title;
--             SELECT COUNT(*) AS tags_left FROM task_tags WHERE task_id = :task_id;
--           Выполните DELETE ещё раз.
--           ✔ Проверка: первый DELETE — 1 строка, tags_left = 0; повторный DELETE — DELETE 0 (в API это ответ 404).
