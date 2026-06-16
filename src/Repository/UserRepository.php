<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Database;
use PDO;

final class UserRepository
{
    public function __construct(private Database $database)
    {
    }

    public function database(): Database
    {
        return $this->database;
    }

    public function countAll(): int
    {
        $stmt = $this->database->connection()->query('SELECT COUNT(*) FROM users');

        return (int) $stmt->fetchColumn();
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->database->connection()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->database->connection()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function create(string $email, string $displayName, string $passwordHash): int
    {
        $stmt = $this->database->connection()->prepare(
            'INSERT INTO users (email, password_hash, display_name, is_active) VALUES (:email, :password_hash, :display_name, 1)'
        );
        $stmt->execute([
            'email' => strtolower(trim($email)),
            'password_hash' => $passwordHash,
            'display_name' => trim($displayName),
        ]);

        return (int) $this->database->connection()->lastInsertId();
    }

    public function touchLastLogin(int $id): void
    {
        $stmt = $this->database->connection()->prepare(
            'UPDATE users SET last_login_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }
}
