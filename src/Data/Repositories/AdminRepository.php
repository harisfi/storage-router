<?php

declare(strict_types=1);

namespace App\Data\Repositories;

use PDO;

final class AdminRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM admins WHERE username = :username');
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM admins WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(string $username, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (:username, :hash)');
        $stmt->execute([':username' => $username, ':hash' => $passwordHash]);
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    }
}
