-- =====================================================================
-- Лабораторная работа №8. Часть 1. Структура базы данных
-- Пример: «Система управления задачами команды»
-- Запуск в psql:  \i /sql/01_schema.sql
-- Скрипт можно выполнять повторно: сначала удаляются старые таблицы.
-- После каждого TODO запускайте скрипт заново и выполняйте «✔ Проверку».
-- =====================================================================

SET client_min_messages = warning;   -- не показывать сообщения «таблицы ещё нет»
DROP TABLE IF EXISTS task_tags, tags, comments, tasks, projects, user_profiles, users CASCADE;

-- ---------------------------------------------------------------------
-- Участники команды (ГОТОВО, образец)
-- SERIAL       — целое число, номер присваивается автоматически (1, 2, 3, ...)
-- PRIMARY KEY  — первичный ключ: уникальный и непустой номер строки
-- NOT NULL     — поле обязательно
-- UNIQUE       — значение не повторяется
-- DEFAULT      — значение по умолчанию, если его не указали
-- CHECK        — значение должно удовлетворять условию
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(255) NOT NULL UNIQUE,
    role        VARCHAR(20)  NOT NULL DEFAULT 'developer'
                CHECK (role IN ('manager', 'developer', 'tester')),
    created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- TODO 1.1  Профиль участника — связь «один к одному» с users.
--           Первичный ключ таблицы одновременно является внешним ключом на users,
--           поэтому у одного участника может быть не больше одного профиля:
--             CREATE TABLE user_profiles (
--                 user_id     INTEGER PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
--                 position    VARCHAR(100),
--                 phone       VARCHAR(20),
--                 telegram    VARCHAR(50) UNIQUE
--             );
--           ✔ Проверка: \d user_profiles показывает "user_profiles_pkey" PRIMARY KEY и
--             "user_profiles_user_id_fkey" FOREIGN KEY ... REFERENCES users(id) ON DELETE CASCADE.


-- TODO 1.2  Проекты — связь «один ко многим» с users (у руководителя много проектов).
--           REFERENCES users (id) — внешний ключ: в owner_id можно записать только номер существующего участника.
--           ON DELETE RESTRICT — участника нельзя удалить, пока у него есть проекты.
--             CREATE TABLE projects (
--                 id          SERIAL PRIMARY KEY,
--                 title       VARCHAR(150) NOT NULL UNIQUE,
--                 description TEXT,
--                 owner_id    INTEGER      NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
--                 created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
--             );
--           ✔ Проверка: \d projects — в разделе Foreign-key constraints есть projects_owner_id_fkey.


-- TODO 1.3  Задачи — основная сущность. Поля те же, что в REST API лабораторной работы №7,
--           плюс исполнитель assignee_id и дата создания. Напишите таблицу сами по описанию:
--             id           SERIAL, первичный ключ
--             project_id   INTEGER, обязательный, ссылка на projects (id); проект с задачами удалять нельзя (ON DELETE RESTRICT)
--             assignee_id  INTEGER, необязательный, ссылка на users (id); при удалении участника — ON DELETE SET NULL
--             title        VARCHAR(200), обязательный, не короче 3 символов: CHECK (char_length(title) >= 3)
--             status       VARCHAR(20), обязательный, по умолчанию 'new', только 'new', 'in_progress', 'done'
--             priority     VARCHAR(10), обязательный, по умолчанию 'medium', только 'low', 'medium', 'high'
--             due_date     DATE, необязательный
--             created_at   TIMESTAMP, обязательный, по умолчанию NOW()
--           Образец ограничения CHECK со списком значений — поле role в таблице users выше.
--           ✔ Проверка: \d tasks — 8 столбцов, 3 ограничения CHECK, 2 внешних ключа
--             (tasks_project_id_fkey ... ON DELETE RESTRICT, tasks_assignee_id_fkey ... ON DELETE SET NULL).


-- TODO 1.4  Комментарии к задачам: id, task_id, author_id, body (TEXT, не пустой), created_at.
--           Удалили задачу — её комментарии должны удалиться вместе с ней (ON DELETE CASCADE);
--           участника, у которого есть комментарии, удалять нельзя (ON DELETE RESTRICT).
--           ✔ Проверка: \d comments — два внешних ключа: на tasks (CASCADE) и на users (RESTRICT).


-- ---------------------------------------------------------------------
-- Теги (ГОТОВО)
-- ---------------------------------------------------------------------
CREATE TABLE tags (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(50) NOT NULL UNIQUE
);

-- TODO 1.5  Связь «многие ко многим» задач и тегов. Одна ячейка не может хранить список «1, 3, 5»,
--           поэтому создаётся связующая таблица: одна строка = одна пара «задача — тег».
--           Составной первичный ключ PRIMARY KEY (task_id, tag_id) не даст повесить тег на задачу дважды.
--             CREATE TABLE task_tags (
--                 task_id     INTEGER NOT NULL REFERENCES tasks (id) ON DELETE CASCADE,
--                 tag_id      INTEGER NOT NULL REFERENCES tags (id)  ON DELETE CASCADE,
--                 PRIMARY KEY (task_id, tag_id)
--             );
--           ✔ Проверка: \dt показывает 7 таблиц: comments, projects, tags, task_tags, tasks, user_profiles, users.
