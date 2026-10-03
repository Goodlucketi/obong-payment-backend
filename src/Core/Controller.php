<?php

declare(strict_types=1);

namespace Obong\Payment\Core;

use PDO;

abstract class Controller
{
    public function __construct(protected readonly PDO $db)
    {
    }

    protected function one(string $sql, array $params = []): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    protected function all(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    protected function run(string $sql, array $params = []): int
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    protected function idFromPublicId(string|int|null $value, string $prefix): int
    {
        $value = (string) $value;
        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (preg_match('/^' . preg_quote($prefix, '/') . '_(\d+)$/', $value, $matches)) {
            return (int) $matches[1];
        }

        throw new HttpException('Invalid resource ID.', 422);
    }

    protected function audit(?array $actor, string $action, string $entity, ?string $reference = null, array $metadata = []): void
    {
        $actorType = $actor['type'] ?? 'SYSTEM';
        $adminId = $actorType === 'ADMIN' ? (int) $actor['id'] : null;
        $this->run(
            'INSERT INTO audit_logs (administrator_id, actor_type, actor_id, actor_name, actor_role, action, entity_type, entity_reference, ip_address, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $adminId ?: null,
                $actorType,
                isset($actor['id']) ? (string) $actor['id'] : null,
                $actor['name'] ?? 'System',
                $actor['role'] ?? $actorType,
                $action,
                $entity,
                $reference,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    }

    protected function requireActor(?array $actor, ?string $type = null): array
    {
        if (!$actor || ($type !== null && $actor['type'] !== $type)) {
            throw new HttpException('Unauthorized.', 401);
        }
        return $actor;
    }
}