# Документация REST API «Система управления задачами команды»

Базовый адрес: `http://localhost:8000`. Формат данных: JSON (UTF-8). Все адреса начинаются с `/api`.
Запуск: `cd lab7/src && php -S localhost:8000 -t public`.

## Сводная таблица

| Метод | URL | Назначение | Успешный код |
|-------|-----|------------|--------------|
| GET | `/api/tasks` | список задач (фильтры `status`, `project_id`) | 200 |
| GET | `/api/tasks/{id}` | задача по номеру | 200 |
| POST | `/api/tasks` | создать задачу | 201 |
| PUT, PATCH | `/api/tasks/{id}` | изменить задачу | 200 |
| DELETE | `/api/tasks/{id}` | удалить задачу | 204 |
| GET | `/api/projects` | список проектов | 200 |
| GET | `/api/projects/{id}` | проект по номеру | 200 |
| GET | `/api/projects/{id}/tasks` | задачи проекта | 200 |
| POST | `/api/projects` | создать проект | 201 |
| PUT, PATCH | `/api/projects/{id}` | изменить проект | 200 |
| DELETE | `/api/projects/{id}` | удалить проект (только без задач) | 204 |
| GET | `/api/users` | список участников | 200 |
| GET | `/api/users/{id}` | участник по номеру | 200 |
| POST | `/api/users` | добавить участника | 201 |
| PUT, PATCH | `/api/users/{id}` | изменить участника | 200 |
| DELETE | `/api/users/{id}` | удалить участника | 204 |

## Общие правила

- Тело запросов `POST`, `PUT`, `PATCH` — JSON-объект.
- Ошибки возвращаются в едином формате: `{"error": "текст"}`, а для ошибок проверки ещё и `"errors": {"поле": "текст"}`.
- `PUT` и `PATCH` обрабатываются одинаково: присланные поля заменяют старые, остальные остаются. Поле `id` изменить нельзя.

| Код | Когда |
|-----|-------|
| 400 | тело запроса — не корректный JSON-объект |
| 404 | нет записи или адреса |
| 405 | адрес есть, но не для этого метода (в заголовке `Allow` разрешённые) |
| 409 | удаление проекта, в котором есть задачи |
| 422 | данные не прошли проверку |

---

## Ресурс «Задачи»

Поля: `id` (число), `project_id` (число, существующий проект), `title` (строка, от 3 символов), `status` (`new`, `in_progress`, `done`), `priority` (`low`, `medium`, `high`), `due_date` (строка `ГГГГ-ММ-ДД` или `null`).

### GET /api/tasks — список задач

Параметры строки запроса (необязательные): `status` — фильтр по статусу; `project_id` — фильтр по проекту.

Запрос: `GET /api/tasks?status=done`

Ответ `200 OK`:
```json
[
    { "id": 1, "project_id": 1, "title": "Сверстать главную страницу", "status": "done", "priority": "high", "due_date": "2026-10-01" },
    { "id": 4, "project_id": 2, "title": "Спроектировать экран авторизации", "status": "done", "priority": "high", "due_date": "2026-09-28" }
]
```

### GET /api/tasks/{id} — задача по номеру

Запрос: `GET /api/tasks/3`

Ответ `200 OK`:
```json
{ "id": 3, "project_id": 1, "title": "Написать тексты для раздела «О нас»", "status": "new", "priority": "low", "due_date": "2026-10-15" }
```
Ошибка `404`: `{ "error": "Задача не найдена" }`

### POST /api/tasks — создать задачу

Тело (JSON): обязательные `title`, `project_id`; необязательные `status` (по умолчанию `new`), `priority` (по умолчанию `medium`), `due_date` (по умолчанию `null`).

Запрос:
```http
POST /api/tasks
Content-Type: application/json

{ "title": "Проверить API", "project_id": 1, "priority": "high" }
```
Ответ `201 Created`, заголовок `Location: /api/tasks/8`:
```json
{ "id": 8, "project_id": 1, "title": "Проверить API", "status": "new", "priority": "high", "due_date": null }
```
Ошибки: `400` (не JSON), `422`:
```json
{
    "error": "Ошибка проверки данных",
    "errors": {
        "title": "Название — не короче 3 символов",
        "project_id": "Проекта с таким номером нет",
        "status": "Статус: new, in_progress или done"
    }
}
```

### PUT, PATCH /api/tasks/{id} — изменить задачу

Тело (JSON): любые из полей задачи.

Запрос: `PATCH /api/tasks/8` с телом `{ "status": "done" }`

Ответ `200 OK`:
```json
{ "id": 8, "project_id": 1, "title": "Проверить API", "status": "done", "priority": "high", "due_date": null }
```
Ошибки: `404`, `400`, `422`.

### DELETE /api/tasks/{id} — удалить задачу

Запрос: `DELETE /api/tasks/8`. Ответ `204 No Content` (тела нет). Повторный запрос: `404`, `{ "error": "Задача не найдена" }`.

---

## Ресурс «Проекты»

Поля: `id`, `title` (строка, от 3 символов), `description` (строка, необязательное).

### GET /api/projects — список проектов

Ответ `200 OK`:
```json
[
    { "id": 1, "title": "Сайт компании", "description": "Разработка корпоративного сайта" },
    { "id": 2, "title": "Мобильное приложение", "description": "Приложение для клиентов" },
    { "id": 3, "title": "Внутренний портал", "description": "Портал для сотрудников" }
]
```

### GET /api/projects/{id} — проект по номеру

Запрос: `GET /api/projects/2`. Ответ `200 OK`: `{ "id": 2, "title": "Мобильное приложение", "description": "Приложение для клиентов" }`. Ошибка `404`: `{ "error": "Проект не найден" }`.

### GET /api/projects/{id}/tasks — задачи проекта

Запрос: `GET /api/projects/2/tasks`. Ответ `200 OK`: массив задач проекта 2 (формат как у `/api/tasks`). Ошибка `404`, если проекта нет.

### POST /api/projects — создать проект

Запрос: `POST /api/projects` с телом `{ "title": "Новый проект", "description": "Описание" }`.
Ответ `201 Created`, `Location: /api/projects/4`: `{ "id": 4, "title": "Новый проект", "description": "Описание" }`.
Ошибки: `400`, `422` (`{"error": "Ошибка проверки данных", "errors": {"title": "Название — не короче 3 символов"}}`).

### PUT, PATCH /api/projects/{id} — изменить проект

Запрос: `PATCH /api/projects/4` с телом `{ "description": "Новое описание" }`.
Ответ `200 OK`: `{ "id": 4, "title": "Новый проект", "description": "Новое описание" }`. Ошибки: `404`, `400`, `422`.

### DELETE /api/projects/{id} — удалить проект

Запрос: `DELETE /api/projects/4`. Ответ `204 No Content`.
Ошибки: `404`; `409` если у проекта есть задачи: `{ "error": "Нельзя удалить проект: в нём есть задачи" }` (пример: `DELETE /api/projects/1`).

---

## Ресурс «Участники»

Поля: `id`, `name` (строка, от 2 символов), `email` (корректный адрес), `role` (`manager`, `developer`, `tester`).

### GET /api/users — список

Ответ `200 OK`:
```json
[
    { "id": 1, "name": "Анна Петрова", "email": "anna@example.com", "role": "manager" },
    { "id": 2, "name": "Игорь Смирнов", "email": "igor@example.com", "role": "developer" },
    { "id": 3, "name": "Мария Волкова", "email": "maria@example.com", "role": "tester" }
]
```

### GET /api/users/{id} — участник по номеру

Запрос: `GET /api/users/2`. Ответ `200 OK`: `{ "id": 2, "name": "Игорь Смирнов", "email": "igor@example.com", "role": "developer" }`. Ошибка `404`: `{ "error": "Участник не найден" }`.

### POST /api/users — добавить участника

Тело: `name`, `email`, `role` (все обязательные).
Запрос: `POST /api/users` с телом `{ "name": "Олег Орлов", "email": "oleg@example.com", "role": "developer" }`.
Ответ `201 Created`, `Location: /api/users/4`: `{ "id": 4, "name": "Олег Орлов", "email": "oleg@example.com", "role": "developer" }`. Ошибки: `400`, `422`.

### PUT, PATCH /api/users/{id} — изменить участника

Запрос: `PATCH /api/users/4` с телом `{ "role": "tester" }`. Ответ `200 OK`: запись с новой ролью. Ошибки: `404`, `400`, `422` (например, для `{"email": "bad"}`: `"email": "Некорректный адрес электронной почты"`).

### DELETE /api/users/{id} — удалить участника

Запрос: `DELETE /api/users/4`. Ответ `204 No Content`. Ошибка `404`.
Запрос `DELETE /api/users` (без номера) даёт `405 Method Not Allowed` и заголовок `Allow: GET, POST`.
