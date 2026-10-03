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

    protected function idFromPublicId(string|int|null $value, string $prefix): string
    {
        $value = (string) $value;
        if (preg_match('/^' . preg_quote($prefix, '/') . '_([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/i', $value, $matches)) {
            return strtolower($matches[1]);
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            return strtolower($value);
        }

        throw new HttpException('Invalid resource ID.', 422);
    }

    protected function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    protected function audit(?array $actor, string $action, string $entity, ?string $reference = null, array $metadata = []): void
    {
        $actorType = $actor['type'] ?? 'SYSTEM';
        $adminId = $actorType === 'ADMIN' ? $actor['id'] : null;
        $this->run(
            'INSERT INTO audit_logs (id, administrator_id, actor_type, actor_id, actor_name, actor_role, action, entity_type, entity_reference, ip_address, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->newId(),
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