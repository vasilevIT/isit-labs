-- =====================================================================
-- Лабораторная работа №11. Структура базы данных PostgreSQL
-- Схема из лабораторной работы №9 плюс две таблицы для фоновых задач: уведомления и заявки на отчёт.
-- Выполняется командой php bin/reset-db.php (или в psql: \i /sql/schema.sql). Старые таблицы удаляются.
-- =====================================================================

SET client_min_messages = warning;   -- не показывать сообщения «таблицы ещё нет»
DROP TABLE IF EXISTS notifications, report_requests, auth_tokens, tasks, projects, users CASCADE;

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

-- Уведомления участникам. Их создаёт фоновая задача (воркер), а не обработчик запроса.
-- job_id — номер задачи в очереди. Пара (job_id, user_id) уникальна: если воркер упал после записи и задачу выполнили повторно,
-- второго такого же уведомления не появится (идемпотентность: повтор безопасен).
CREATE TABLE notifications (
    id          SERIAL PRIMARY KEY,
    job_id      VARCHAR(32) NOT NULL,
    user_id     INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    task_id     INTEGER REFERENCES tasks (id) ON DELETE SET NULL,
    message     TEXT NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (job_id, user_id)
);

-- Заявки на отчёт. Строку создаёт обработчик запроса (статус queued), а заполняет воркер: processing, затем done или failed.
CREATE TABLE report_requests (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    status       VARCHAR(20) NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'processing', 'done', 'failed')),
    file_name    VARCHAR(100),
    rows_count   INTEGER,
    error        TEXT,
    created_at   TIMESTAMP NOT NULL DEFAULT NOW(),
    finished_at  TIMESTAMP
);

-- Индексы на внешние ключи и столбцы поиска (лекция 2.2, лабораторная работа №8)
CREATE INDEX idx_tasks_project_id ON tasks (project_id);
CREATE INDEX idx_auth_tokens_user_id ON auth_tokens (user_id);
CREATE INDEX idx_notifications_user_id ON notifications (user_id);
