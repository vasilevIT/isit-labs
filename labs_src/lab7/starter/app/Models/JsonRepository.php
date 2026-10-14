<?php
// app/Models/JsonRepository.php — общий родитель всех моделей: хранение данных в JSON-файле.
// Здесь собраны пять операций над данными: all, find, add, update, delete (ГОТОВО: менять не нужно).
// Конкретная модель только говорит, как называется её файл: protected string $name = 'tasks';

declare(strict_types=1);

namespace App\Models;

abstract class JsonRepository
{
    protected string $name;                                // имя файла без .json, например 'tasks'

    // Все записи (массив ассоциативных массивов)
    public function all(): array
    {
        return json_decode(file_get_contents($this->file()), true) ?? [];
    }

    // Запись по номеру или null, если такой нет
    public function find(int $id): ?array
    {
        foreach ($this->all() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        return null;
    }

    // Добавляет запись, присваивает ей новый номер. Возвращает созданную запись.
    public function add(array $data): array
    {
        $items = $this->all();
        $ids = array_column($items, 'id');
        $item = ['id' => ($ids ? max($ids) : 0) + 1] + $data;
        $items[] = $item;
        $this->save($items);
        return $item;
    }

    // Заменяет запись с номером $id на $data (номер остаётся прежним). Возвращает новую запись или null.
    public function update(int $id, array $data): ?array
    {
        $items = $this->all();
        foreach ($items as $i => $item) {
            if ($item['id'] === $id) {
                $items[$i] = ['id' => $id] + $data;
                $this->save($items);
                return $items[$i];
            }
        }
        return null;
    }

    // Удаляет запись. Возвращает true, если запись была.
    public function delete(int $id): bool
    {
        $items = $this->all();
        $left = array_values(array_filter($items, fn(array $item): bool => $item['id'] !== $id));
        if (count($left) === count($items)) {
            return false;
        }
        $this->save($left);
        return true;
    }

    private function file(): string
    {
        return BASE_PATH . '/storage/' . $this->name . '.json';
    }

    private function save(array $items): void
    {
        file_put_contents(
            $this->file(),
            json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
