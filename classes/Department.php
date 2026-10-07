<?php

class Department
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getAll(): array
    {
        $sql = "
            SELECT
                id,
                code,
                name,
                description,
                status,
                created_at
            FROM departments
            ORDER BY name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): bool
    {
        $sql = "
            INSERT INTO departments (
                code,
                name,
                description,
                status
            )
            VALUES (
                :code,
                :name,
                :description,
                :status
            )
        ";

        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?: null,
            'status' => $data['status']
        ]);
    }
    public function getActive(): array {
        $stmt = $this->pdo->prepare(
            "
            SELECT id, code, name, status
            FROM departments
            WHERE status = 'active'
            ORDER BY name ASC
            "
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
