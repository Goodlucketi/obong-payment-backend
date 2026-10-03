<?php

declare(strict_types=1);

namespace Obong\Payment\Core;

use PDO;

final class Authenticator
{
    public static function authenticate(PDO $db, Request $request, array $options): ?array
    {
        if (!$options) {
            return null;
        }

        $token = $request->bearerToken();
        if (!$token) {
            throw new HttpException('Authentication required.', 401);
        }

        $statement = $db->prepare(
            'SELECT actor_type, actor_id FROM api_tokens WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP()'
        );
        $statement->execute([hash('sha256', $token)]);
        $tokenRow = $statement->fetch();
        if (!$tokenRow) {
            throw new HttpException('Invalid or expired token.', 401);
        }

        $table = $tokenRow['actor_type'] === 'STUDENT' ? 'students' : 'administrators';
        $statusColumn = $tokenRow['actor_type'] === 'STUDENT' ? 'account_status' : 'status';
        $statement = $db->prepare("SELECT * FROM {$table} WHERE id = ? AND {$statusColumn} = 'ACTIVE'");
        $statement->execute([$tokenRow['actor_id']]);
        $record = $statement->fetch();
        if (!$record) {
            throw new HttpException('Account is inactive.', 403);
        }

        $db->prepare('UPDATE api_tokens SET last_used_at = UTC_TIMESTAMP() WHERE token_hash = ?')
            ->execute([hash('sha256', $token)]);

        $actor = [
            'type' => $tokenRow['actor_type'],
            'id' => $record['id'],
            'role' => $tokenRow['actor_type'] === 'STUDENT' ? 'STUDENT' : $record['role'],
            'name' => $tokenRow['actor_type'] === 'STUDENT'
                ? trim($record['first_name'] . ' ' . $record['other_names'] . ' ' . $record['surname'])
                : $record['name'],
            'record' => $record,
        ];

        if (isset($options['type']) && $actor['type'] !== $options['type']) {
            throw new HttpException('Forbidden.', 403);
        }
        if (isset($options['roles']) && !in_array($actor['role'], $options['roles'], true)) {
            throw new HttpException('Forbidden.', 403);
        }

        return $actor;
    }
}