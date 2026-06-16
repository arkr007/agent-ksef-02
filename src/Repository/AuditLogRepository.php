<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Database;
use PDO;

final class AuditLogRepository
{
    public function __construct(private Database $database)
    {
    }

    public function database(): Database
    {
        return $this->database;
    }

    public function log(
        string $action,
        ?int $userId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $context = [],
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): int {
        $stmt = $this->database->connection()->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent, context_json)
             VALUES (:user_id, :action, :entity_type, :entity_id, :ip_address, :user_agent, :context_json)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => $ipAddress !== '' ? $ipAddress : null,
            'user_agent' => $userAgent !== '' ? substr((string) $userAgent, 0, 255) : null,
            'context_json' => $context !== [] ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);

        return (int) $this->database->connection()->lastInsertId();
    }

    public function recent(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->database->connection()->prepare(
            'SELECT id, user_id, action, entity_type, entity_id, ip_address, user_agent, context_json, created_at
             FROM audit_logs
             ORDER BY id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
