<?php
// app/Controllers/Api/AuthController.php — вход и выход: /api/auth/login, /api/auth/me, /api/auth/logout.

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Events;
use App\Models\UserRepository;

final class AuthController
{
    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    // POST /api/auth/login  с телом {"email": "...", "password": "..."}
    // Успех: 200 и {"token": "...", "expires_at": "...", "user": {...}}. Неверные данные: 401.
    public function login(): void
    {
        $data = json_input();

        $errors = [];
        if (!is_string($data['email'] ?? null) || trim($data['email']) === '') {
            $errors['email'] = 'Введите email';
        }
        if (!is_string($data['password'] ?? null) || $data['password'] === '') {
            $errors['password'] = 'Введите пароль';
        }
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }

        // Одно и то же сообщение для «нет такого email» и «неверный пароль»: так нельзя узнать, какие адреса зарегистрированы
        $user = $this->users->findByEmail(trim($data['email']));
        if ($user === null || !password_verify($data['password'], $user['password_hash'])) {
            Events::log('login_failed', 'Неудачная попытка входа: ' . trim($data['email']), [
                'user_id'     => $user['id'] ?? null,                            // известен, только если такой email есть
                'entity_type' => 'user',
                'entity_id'   => $user['id'] ?? null,
                'details'     => ['email' => trim($data['email']), 'reason' => $user === null ? 'unknown_email' : 'wrong_password'],   // пароль в журнал не пишем!
            ]);
            json_error('Неверный email или пароль', 401);
        }

        $session = Auth::issueToken($user['id']);
        Events::log('login', 'Вход в систему: ' . $user['name'], [
            'user_id'     => $user['id'],                                        // токена в запросе ещё нет, поэтому участника передаём сами
            'entity_type' => 'user',
            'entity_id'   => $user['id'],
            'details'     => ['email' => $user['email'], 'role' => $user['role']],
        ]);
        json_response([
            'token'      => $session['token'],
            'expires_at' => $session['expires_at'],
            'user'       => public_user($user),
        ]);
    }

    // GET /api/auth/me — кто сейчас вошёл. Ответ: 200 и запись участника (без хеша пароля).
    public function me(): void
    {
        json_response(public_user(require_auth()));
    }

    // POST /api/auth/logout — выход: токен перестаёт действовать. Ответ: 204.
    public function logout(): void
    {
        $token = bearer_token();
        if ($token !== null) {
            $user = current_user();                                              // кто выходит: определить нужно ДО отзыва токена
            if ($user !== null) {
                Events::log('logout', 'Выход из системы: ' . $user['name'], ['user_id' => $user['id'], 'entity_type' => 'user', 'entity_id' => $user['id']]);
            }
            Auth::revoke($token);
        }
        no_content();
    }
}
