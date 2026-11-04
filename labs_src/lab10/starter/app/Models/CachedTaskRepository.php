<?php
// app/Models/CachedTaskRepository.php — задачи с кешем в Redis.
//
// Класс наследует TaskRepository: всё, что не переопределено здесь, работает как раньше.
// Схема «cache-aside»: сначала смотрим в кеш, при промахе берём из базы и кладём в кеш, а при любом изменении
// задач удаляем устаревшие копии (инвалидация).

declare(strict_types=1);

namespace App\Models;

use App\Core\Cache;

final class CachedTaskRepository extends TaskRepository
{
    private const TTL_ITEM = 60;                            // карточка задачи, секунд
    private const TTL_LIST = 60;                            // список задач, секунд

    // Карточка задачи. Ключ: tasks:item:5
    public function find(int $id): ?array
    {
        // TODO 3.3  Карточка задачи. Два места:
        //             — CachedTaskRepository::find(): ключ с номером задачи — "tasks:item:$id", время жизни self::TTL_ITEM,
        //               источник данных — parent::find($id);
        //             — TaskApiController, конструктор: вместо new TaskRepository() создайте new CachedTaskRepository().
        //           Если задачи нет, parent::find вернёт null, и remember не положит это в кеш: «пустоту» кешировать не нужно.
        //           Подсказка:
        //              return Cache::remember("tasks:item:$id", self::TTL_ITEM, fn(): ?array => parent::find($id));
        //           ✔ Проверка: GET /api/tasks/2 два раза: MISS, затем HIT; docker compose exec redis redis-cli GET taskapp:tasks:item:2 — JSON задачи.
        return parent::find($id);
    }

    // Список задач с фильтрами. Вариантов запроса много (статус, проект, поиск, сортировка, страница),
    // поэтому ключ строится из ВСЕХ параметров: у разных запросов разные ключи.
    // В ключ входит номер версии списков (tasks:version): когда задачи меняются, версия растёт,
    // и все старые списки разом перестают использоваться (см. invalidate()).
    public function list(string $status, ?int $projectId, string $q, string $sort, int $limit = 100, int $offset = 0): array
    {
        // TODO 3.4  Список задач. Вариантов запроса много: статус, проект, поиск, сортировка, страница. Поэтому ключ строится из всех параметров:
        //             "tasks:list:v" + номер версии + ":" + md5(json_encode([$status, $projectId, $q, $sort, $limit, $offset])).
        //           Номер версии берите из Cache::version('tasks:version'): он нужен для инвалидации (задание 4.1).
        //           В кеш кладите результат parent::list(...) целиком (в нём и строки, и общее число для заголовка X-Total-Count).
        //           Подсказка:
        //              $key = 'tasks:list:v' . Cache::version('tasks:version') . ':' . md5(json_encode([$status, $projectId, $q, $sort, $limit, $offset]));
        //              return Cache::remember($key, self::TTL_LIST, fn(): array => parent::list($status, $projectId, $q, $sort, $limit, $offset));
        //           ✔ Проверка: GET /api/tasks?q=форм два раза: MISS, HIT; другой запрос (?q=проект) снова MISS: у него другой ключ;
        //             в заголовке X-Total-Count число одинаковое при MISS и HIT; docker compose exec redis redis-cli --scan --pattern "taskapp:tasks:list:*" — ключи списков.
        return parent::list($status, $projectId, $q, $sort, $limit, $offset);
    }

    public function add(array $data): array
    {
        $task = parent::add($data);
        $this->invalidate();
        return $task;
    }

    public function update(int $id, array $data): ?array
    {
        $task = parent::update($id, $data);
        $this->invalidate($id);
        return $task;
    }

    public function delete(int $id): bool
    {
        $deleted = parent::delete($id);
        $this->invalidate($id);
        return $deleted;
    }

    // Задачи изменились: удаляем устаревшие копии.
    //   $id — номер изменённой задачи (для добавления не нужен: у новой задачи ещё нет карточки в кеше)
    private function invalidate(?int $id = null): void
    {
        // TODO 4.1  Инвалидация. Кеш — копия данных, и после изменения задачи она устаревает. Метод invalidate() вызывается при добавлении,
        //           изменении и удалении задач. Что в нём нужно сделать:
        //             — Cache::bump('tasks:version'): увеличить номер версии: ключи всех списков (в них входит версия) разом перестают
        //               использоваться, а старые Redis удалит сам по истечении времени жизни;
        //             — Cache::forget('report:summary'): отчёт считается по задачам и тоже устарел;
        //             — если передан $id, Cache::forget("tasks:item:$id"): удалить карточку этой задачи.
        //           Подсказка:
        //              Cache::bump('tasks:version');                       // все списки устарели: их ключи содержат номер версии
        //              Cache::forget('report:summary');                    // отчёт считается по задачам
        //              if ($id !== null) {
        //                  Cache::forget("tasks:item:$id");                // карточка этой задачи
        //              }
        //           ✔ Проверка: GET /api/tasks/2 (HIT), затем PATCH /api/tasks/2 с телом {"title": "Новое название"}, затем снова GET /api/tasks/2:
        //             X-Cache: MISS и новое название в ответе (без инвалидации вы увидели бы старое!);
        //             создайте задачу (POST /api/tasks) и запросите список: MISS и новая задача в списке; redis-cli GET taskapp:tasks:version — число выросло.
    }
}
