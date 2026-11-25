<?php
// app/Models/UserRepository.php — модель участников команды (таблица users).
// Здесь есть password_hash: наружу (клиенту) запись отдаётся только через public_user().

declare(strict_types=1);

namespace App\Models;

final class UserRepository extends PgRepository
{
    protected string $table = 'users';
    protected string $columns = 'id, name, email, role, password_hash';

    // Участник по адресу электронной почты (регистр букв не важен) или null
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT {$this->columns} FROM users WHERE lower(email) = lower(:email)");
        $stmt->execute(['email' => $email]);
        return $stmt->fetch() ?: null;
    }
}
