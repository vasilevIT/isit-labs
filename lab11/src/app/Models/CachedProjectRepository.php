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
        return Cache::remember('projects:all', self::TTL, fn(): array => parent::all());
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
        Cache::forget('projects:all', 'report:summary');
    }
}
