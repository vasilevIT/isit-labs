-- =====================================================================
-- Лабораторная работа №8. Часть 3. Проверка ссылочной целостности и ограничений
-- Каждый запрос ниже НАРУШАЕТ правило, и база должна его отклонить с ошибкой (ERROR).
-- Ошибка здесь — это успех: значит, ограничение работает.
-- Запуск в psql:  \i /sql/03_integrity.sql   (после 02_seed.sql)
-- \echo просто печатает строку, чтобы в выводе было видно, какая проверка идёт.
-- =====================================================================

-- ГОТОВО (образец)
\echo '--- 1. Задача в несуществующем проекте (FOREIGN KEY) ---'
INSERT INTO tasks (project_id, title) VALUES (99, 'Задача без проекта');
-- ожидаемый ответ: ERROR: ... violates foreign key constraint "tasks_project_id_fkey"

-- ГОТОВО (образец)
\echo '--- 2. Неизвестный статус задачи (CHECK) ---'
INSERT INTO tasks (project_id, title, status) VALUES (1, 'Проверить статус', 'finished');
-- ожидаемый ответ: ERROR: ... violates check constraint "tasks_status_check"

-- TODO 3.1  По образцу напишите ещё четыре проверки ограничений (для каждой \echo и один запрос):
--             3. задача с названием 'Да'                      -> tasks_title_check
--             4. задача без названия (title = NULL)           -> violates not-null constraint
--             5. участник с почтой anna@example.com           -> users_email_key (UNIQUE)
--             6. второй профиль у участника № 1               -> user_profiles_pkey (связь 1:1)
--           ✔ Проверка: при запуске файла каждая из проверок 1–6 даёт ERROR с нужным именем ограничения.


-- TODO 3.2  Проверка связи N:M: повесьте на задачу № 1 тег № 1 ещё раз.
--           ✔ Проверка: ERROR ... duplicate key value violates unique constraint "task_tags_pkey".


-- TODO 3.3  Проверка ON DELETE RESTRICT: попробуйте удалить проект № 1 (в нём есть задачи)
--           и участника № 1 (он руководитель проектов):
--             DELETE FROM projects WHERE id = 1;
--           ✔ Проверка: обе команды дают ERROR ... is still referenced from table ...


-- TODO 3.4  Проверка ON DELETE CASCADE и SET NULL. Такие удаления выполняются успешно,
--           поэтому обернём их в транзакцию и отменим, чтобы не потерять данные:
--             \echo '--- 10. Удаление задачи удаляет её комментарии и теги (CASCADE) ---'
--             BEGIN;
--             SELECT COUNT(*) AS comments_before FROM comments WHERE task_id = 2;
--             DELETE FROM tasks WHERE id = 2;
--             SELECT COUNT(*) AS comments_after FROM comments WHERE task_id = 2;
--             ROLLBACK;
--           Затем так же проверьте SET NULL: удалите участника № 4 и посмотрите assignee_id
--           у задач № 4 и № 5 (SELECT id, title, assignee_id FROM tasks WHERE id IN (4, 5);), затем ROLLBACK.
--           ✔ Проверка: comments_before > 0, comments_after = 0; у задач 4 и 5 assignee_id пустой;
--             после ROLLBACK команда SELECT COUNT(*) FROM tasks; по-прежнему возвращает 7.
