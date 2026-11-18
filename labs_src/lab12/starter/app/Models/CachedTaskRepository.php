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
        return Cache::remember("tasks:item:$id", self::TTL_ITEM, fn(): ?array => parent::find($id));
    }

    // Список задач с фильтрами. Вариантов запроса много (статус, проект, поиск, сортировка, страница),
    // поэтому ключ строится из ВСЕХ параметров: у разных запросов разные ключи.
    // В ключ входит номер версии списков (tasks:version): когда задачи меняются, версия растёт,
    // и все старые списки разом перестают использоваться (см. invalidate()).
    public function list(string $status, ?int $projectId, string $q, string $sort, int $limit = 100, int $offset = 0): array
    {
        $key = 'tasks:list:v' . Cache::version('tasks:version') . ':' . md5(json_encode([$status, $projectId, $q, $sort, $limit, $offset]));
        return Cache::remember($key, self::TTL_LIST, fn(): array => parent::list($status, $projectId, $q, $sort, $limit, $offset));
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
        Cache::bump('tasks:version');                       // все списки устарели: их ключи содержат номер версии
        Cache::forget('report:summary');                    // отчёт считается по задачам
        if ($id !== null) {
            Cache::forget("tasks:item:$id");                // карточка этой задачи
        }
    }
}
