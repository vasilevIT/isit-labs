-- =====================================================================
-- Лабораторная работа №9. Структура базы данных PostgreSQL
-- Часть схемы из лабораторной работы №8, нужная приложению, плюс пароли участников и таблица токенов.
-- Выполняется командой php bin/reset-db.php (или в psql: \i /sql/schema.sql). Старые таблицы удаляются.
-- =====================================================================

SET client_min_messages = warning;   -- не показывать сообщения «таблицы ещё нет»
DROP TABLE IF EXISTS auth_tokens, tasks, projects, users CASCADE;

-- Участники команды. Пароль хранится только в виде хеша (password_hash из PHP).
CREATE TABLE users (
    id             SERIAL PRIMARY KEY,
    name           VARCHAR(100) NOT NULL,
    email          VARCHAR(255) NOT NULL UNIQUE,
    role           VARCHAR(20)  NOT NULL DEFAULT 'developer'
                   CHECK (role IN ('manager', 'developer', 'tester')),
    password_hash  VARCHAR(255) NOT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE TABLE projects (
    id           SERIAL PRIMARY KEY,
    title        VARCHAR(150) NOT NULL,
    description  TEXT NOT NULL DEFAULT '',
    created_at   TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Задачи. Проект с задачами удалить нельзя (RESTRICT): в API это ответ 409.
CREATE TABLE tasks (
    id           SERIAL PRIMARY KEY,
    project_id   INTEGER NOT NULL REFERENCES projects (id) ON DELETE RESTRICT,
    title        VARCHAR(200) NOT NULL CHECK (char_length(title) >= 3),
    description  TEXT NOT NULL DEFAULT '',
    status       VARCHAR(20) NOT NULL DEFAULT 'new' CHECK (status IN ('new', 'in_progress', 'done')),
    priority     VARCHAR(10) NOT NULL DEFAULT 'medium' CHECK (priority IN ('low', 'medium', 'high')),
    due_date     DATE,
    created_at   TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Токены входа. Хранится не сам токен, а его хеш (SHA-256): утечка таблицы не даёт войти в систему.
-- При удалении участника его токены удаляются вместе с ним (CASCADE).
CREATE TABLE auth_tokens (
    token_hash  CHAR(64) PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    expires_at  TIMESTAMPTZ NOT NULL
);

-- Индексы на внешние ключи и столбцы поиска (лекция 2.2, лабораторная работа №8)
CREATE INDEX idx_tasks_project_id ON tasks (project_id);
CREATE INDEX idx_auth_tokens_user_id ON auth_tokens (user_id);
