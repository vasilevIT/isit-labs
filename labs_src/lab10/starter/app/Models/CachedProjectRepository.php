<?php
// app/Models/CachedProjectRepository.php — проекты с кешем в Redis.
//
// Это «справочник»: проекты читают на каждой странице, а меняют редко, поэтому они — лучший кандидат для кеша.
// Класс наследует ProjectRepository и переопределяет только то, что нужно: остальные методы работают как раньше.

declare(strict_types=1);

namespace App\Models;

use App\Core\Cache;

final class CachedProjectRepository extends ProjectRepository
{
    private const TTL = 300;                                // 5 минут: на случай, если инвалидация где-то не сработает

    // Список всех проектов
    public function all(): array
    {
        // TODO 3.1  Первые данные для кеша: список проектов. Это «справочник»: его читают на каждой странице, а меняют редко. Два места:
        //             — CachedProjectRepository::all(): Cache::remember('projects:all', self::TTL, fn() => parent::all());
        //             — ProjectApiController, конструктор: вместо new ProjectRepository() создайте new CachedProjectRepository().
        //           Подсказка:
        //              return Cache::remember('projects:all', self::TTL, fn(): array => parent::all());
        //           ✔ Проверка: curl -i http://localhost:8000/api/projects -H "Authorization: Bearer ВАШ_ТОКЕН" два раза: в первом ответе X-Cache: MISS, во втором X-Cache: HIT;
        //             docker compose exec redis redis-cli GET taskapp:projects:all — JSON со списком; TTL taskapp:projects:all — не больше 300.
        return parent::all();
    }

    public function add(array $data): array
    {
        $project = parent::add($data);
        $this->invalidate();
        return $project;
    }

    public function update(int $id, array $data): ?array
    {
        $project = parent::update($id, $data);
        $this->invalidate();
        return $project;
    }

    public function delete(int $id): bool
    {
        $deleted = parent::delete($id);
        $this->invalidate();
        return $deleted;
    }

    // Проекты изменились: копия списка устарела. Отчёт показывает названия проектов, поэтому он устарел тоже.
    private function invalidate(): void
    {
        // TODO 4.2  То же для проектов: при добавлении, изменении и удалении проекта удалите из кеша список проектов и отчёт (в нём названия
        //           проектов): Cache::forget('projects:all', 'report:summary').
        //           Подсказка:
        //              Cache::forget('projects:all', 'report:summary');
        //           ✔ Проверка: GET /api/projects (HIT), создайте проект (POST /api/projects с телом {"title": "Новый проект"}), снова GET /api/projects:
        //             X-Cache: MISS и новый проект в списке.
    }
}
