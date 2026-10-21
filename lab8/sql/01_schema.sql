-- =====================================================================
-- Лабораторная работа №8. Структура базы данных
-- Предметная область: «Система управления задачами команды» (вариант 0)
-- Запуск в psql:  \i /sql/01_schema.sql
-- Скрипт можно выполнять повторно: сначала удаляются старые таблицы.
-- =====================================================================

SET client_min_messages = warning;   -- не показывать сообщения «таблицы ещё нет»
DROP TABLE IF EXISTS task_tags, tags, comments, tasks, projects, user_profiles, users CASCADE;

-- ---------------------------------------------------------------------
-- Участники команды
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(255) NOT NULL UNIQUE,
    role        VARCHAR(20)  NOT NULL DEFAULT 'developer'
                CHECK (role IN ('manager', 'developer', 'tester')),
    created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------------
-- Профиль участника. Связь 1:1 с users:
-- первичный ключ одновременно является внешним ключом,
-- поэтому у одного участника может быть не больше одного профиля.
-- ---------------------------------------------------------------------
CREATE TABLE user_profiles (
    user_id     INTEGER PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
    position    VARCHAR(100),
    phone       VARCHAR(20),
    telegram    VARCHAR(50) UNIQUE
);

-- ---------------------------------------------------------------------
-- Проекты. Связь 1:N с users (у руководителя много проектов).
-- Руководителя нельзя удалить, пока у него есть проекты (RESTRICT).
-- ---------------------------------------------------------------------
CREATE TABLE projects (
    id          SERIAL PRIMARY KEY,
    title       VARCHAR(150) NOT NULL UNIQUE,
    description TEXT,
    owner_id    INTEGER      NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
    created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------------
-- Задачи — основная сущность.
-- 1:N с projects: проект с задачами удалить нельзя (как ответ 409 в API лаб. 7).
-- 1:N с users: при удалении участника задача остаётся без исполнителя.
-- ---------------------------------------------------------------------
CREATE TABLE tasks (
    id          SERIAL PRIMARY KEY,
    project_id  INTEGER      NOT NULL REFERENCES projects (id) ON DELETE RESTRICT,
    assignee_id INTEGER      REFERENCES users (id) ON DELETE SET NULL,
    title       VARCHAR(200) NOT NULL CHECK (char_length(title) >= 3),
    status      VARCHAR(20)  NOT NULL DEFAULT 'new'
                CHECK (status IN ('new', 'in_progress', 'done')),
    priority    VARCHAR(10)  NOT NULL DEFAULT 'medium'
                CHECK (priority IN ('low', 'medium', 'high')),
    due_date    DATE,
    created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------------
-- Комментарии к задачам. 1:N с tasks и 1:N с users.
-- Удалили задачу — удалились и её комментарии (CASCADE).
-- ---------------------------------------------------------------------
CREATE TABLE comments (
    id          SERIAL PRIMARY KEY,
    task_id     INTEGER   NOT NULL REFERENCES tasks (id) ON DELETE CASCADE,
    author_id   INTEGER   NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
    body        TEXT      NOT NULL CHECK (char_length(body) > 0),
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------------
-- Теги и связь N:M «задача — тег» через связующую таблицу.
-- Составной первичный ключ не даёт повесить один тег на задачу дважды.
-- ---------------------------------------------------------------------
CREATE TABLE tags (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE task_tags (
    task_id     INTEGER NOT NULL REFERENCES tasks (id) ON DELETE CASCADE,
    tag_id      INTEGER NOT NULL REFERENCES tags (id)  ON DELETE CASCADE,
    PRIMARY KEY (task_id, tag_id)
);
