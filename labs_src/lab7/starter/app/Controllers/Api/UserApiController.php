<?php
// app/Controllers/Api/UserApiController.php — REST API ресурса «участники» (/api/users).
// ГОТОВЫЙ ПРИМЕР: изучите его перед тем, как писать контроллеры задач и проектов.
// Каждый метод — одна операция: список, одна запись, создание, изменение, удаление.

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\UserRepository;

final class UserApiController
{
    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    // GET /api/users — список. Ответ: 200 и массив записей.
    public function index(): void
    {
        json_response($this->users->all());
    }

    // GET /api/users/2 — одна запись. Нет такой записи: 404.
    public function show(string $id): void
    {
        $user = $this->users->find((int) $id);
        if ($user === null) {
            json_error('Участник не найден', 404);
        }
        json_response($user);
    }

    // POST /api/users — создание. Данные приходят в теле запроса в формате JSON.
    // Ответ: 201 Created, заголовок Location с адресом новой записи и сама запись.
    public function store(): void
    {
        $data = json_input();
        $errors = $this->validate($data);
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }

        $user = $this->users->add([
            'name'  => trim($data['name']),
            'email' => trim($data['email']),
            'role'  => $data['role'],
        ]);
        header('Location: /api/users/' . $user['id']);
        json_response($user, 201);
    }

    // PUT или PATCH /api/users/2 — изменение. Присланные поля заменяют старые, остальные остаются.
    public function update(string $id): void
    {
        $user = $this->users->find((int) $id);
        if ($user === null) {
            json_error('Участник не найден', 404);
        }

        $data = array_merge($user, json_input());          // старые значения + присланные
        $errors = $this->validate($data);
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }

        $updated = $this->users->update($user['id'], [
            'name'  => trim($data['name']),
            'email' => trim($data['email']),
            'role'  => $data['role'],
        ]);
        json_response($updated);
    }

    // DELETE /api/users/2 — удаление. Ответ: 204 без тела.
    public function destroy(string $id): void
    {
        if (!$this->users->delete((int) $id)) {
            json_error('Участник не найден', 404);
        }
        no_content();
    }

    // Проверка данных. Возвращает массив ошибок «поле => сообщение»; пустой массив — всё хорошо.
    private function validate(array $data): array
    {
        $errors = [];
        if (!is_string($data['name'] ?? null) || mb_strlen(trim($data['name'])) < 2) {
            $errors['name'] = 'Имя — не короче 2 символов';
        }
        if (!is_string($data['email'] ?? null) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Некорректный адрес электронной почты';
        }
        if (!in_array($data['role'] ?? null, ['manager', 'developer', 'tester'], true)) {
            $errors['role'] = 'Роль: manager, developer или tester';
        }
        return $errors;
    }
}
