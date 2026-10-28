<?php
// app/Models/PgRepository.php — общий родитель всех моделей: работа с таблицей PostgreSQL.
// Пять операций над данными: all, find, add, update, delete. Они заменяют JsonRepository из лабораторной работы №7:
// контроллеры вызывают те же методы и не замечают, что данные теперь лежат в базе.
// Конкретная модель только говорит, как называется её таблица и какие столбцы отдавать наружу.
//
// Важно: значения ВСЕГДА передаются параметрами (:имя), а не склеиваются в текст запроса (защита от SQL-инъекций).
// Имена таблицы и столбцов подставляются в текст, потому что параметром их передать нельзя; они берутся из кода,
// а не от пользователя.

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

abstract class PgRepository
{
    protected string $table;                               // имя таблицы, например 'tasks'
    protected string $columns = 'id';                      // какие столбцы отдавать наружу (без секретных и служебных)

    protected function pdo(): PDO
    {
        return Database::connection();
    }

    // Все записи по порядку номеров
    public function all(): array
    {
        return $this->pdo()->query("SELECT {$this->columns} FROM {$this->table} ORDER BY id")->fetchAll();
    }

    // Запись по номеру или null, если такой нет
    public function find(int $id): ?array
    {
        // TODO 1.1  Операции над данными. В PgRepository готовы all(), add() и update(); допишите find() и delete().
        //             find($id)   — prepare('SELECT ... WHERE id = :id'), execute(['id' => $id]), fetch(); fetch() вернёт false, если строки нет, тогда верните null;
        //             delete($id) — prepare('DELETE ... WHERE id = :id'), execute, rowCount() > 0 («удалена ли хоть одна строка»).
        //           Значения всегда передаются параметрами (:id), а не склеиваются с текстом запроса: так работает защита от SQL-инъекций (лекция 2.2).
        //           Имя таблицы и список столбцов уже лежат в $this->table и $this->columns.
        //           Подсказка:
        //              $stmt = $this->pdo()->prepare("SELECT {$this->columns} FROM {$this->table} WHERE id = :id");
        //              $stmt->execute(['id' => $id]);
        //              return $stmt->fetch() ?: null;                     // fetch() вернёт false, если строки нет
        //           ✔ Проверка: curl http://localhost:8000/api/projects/2 — {"id": 2, "title": "Мобильное приложение", ...}; /api/projects/99 — 404;
        //             создайте задачу (POST /api/tasks, тело {"title":"Тест БД","project_id":1}) и удалите её (DELETE /api/tasks/НОМЕР) — 204, повторно — 404.
        return null;
    }

    // Добавляет запись. Возвращает созданную запись (RETURNING отдаёт строку с присвоенным номером).
    public function add(array $data): array
    {
        $names = array_keys($data);
        $sql = "INSERT INTO {$this->table} (" . implode(', ', $names) . ') VALUES ('
             . implode(', ', array_map(fn(string $n): string => ':' . $n, $names))
             . ") RETURNING {$this->columns}";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($data);
        return $stmt->fetch();
    }

    // Заменяет поля записи на присланные. Возвращает новую запись или null, если такой записи нет.
    public function update(int $id, array $data): ?array
    {
        $sets = array_map(fn(string $n): string => "$n = :$n", array_keys($data));
        $stmt = $this->pdo()->prepare(
            "UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE id = :record_id RETURNING {$this->columns}"
        );
        $stmt->execute($data + ['record_id' => $id]);
        return $stmt->fetch() ?: null;
    }

    // Удаляет запись. Возвращает true, если запись была.
    public function delete(int $id): bool
    {
        // TODO 1.1  (продолжение)  то же здесь (для delete).
        //           Подсказка:
        //              $stmt = $this->pdo()->prepare("DELETE FROM {$this->table} WHERE id = :id");
        //              $stmt->execute(['id' => $id]);
        //              return $stmt->rowCount() > 0;                      // сколько строк удалено: 0 — такой записи не было
        return false;
    }
}
