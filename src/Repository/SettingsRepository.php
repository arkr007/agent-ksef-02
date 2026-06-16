<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Database;
use PDO;

final class SettingsRepository
{
    public function __construct(private Database $database)
    {
    }

    public function database(): Database
    {
        return $this->database;
    }

    public function getAllAppSettings(): array
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT setting_key, setting_value_text, setting_value_json, is_encrypted
             FROM settings
             WHERE scope_type = :scope_type AND scope_id = :scope_id'
        );
        $stmt->execute([
            'scope_type' => 'app',
            'scope_id' => 0,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $settings = [];

        foreach ($rows as $row) {
            $settings[(string) $row['setting_key']] = $row;
        }

        return $settings;
    }

    public function saveText(string $key, ?string $value, bool $isEncrypted = false): void
    {
        if ($value === null) {
            $this->delete($key);

            return;
        }

        $stmt = $this->database->connection()->prepare(
            'INSERT INTO settings (scope_type, scope_id, setting_key, setting_value_text, setting_value_json, is_encrypted)
             VALUES (:scope_type, :scope_id, :setting_key, :setting_value_text, NULL, :is_encrypted)
             ON DUPLICATE KEY UPDATE
                setting_value_text = VALUES(setting_value_text),
                setting_value_json = VALUES(setting_value_json),
                is_encrypted = VALUES(is_encrypted),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'scope_type' => 'app',
            'scope_id' => 0,
            'setting_key' => $key,
            'setting_value_text' => $value,
            'is_encrypted' => $isEncrypted ? 1 : 0,
        ]);
    }

    public function delete(string $key): void
    {
        $stmt = $this->database->connection()->prepare(
            'DELETE FROM settings
             WHERE scope_type = :scope_type AND scope_id = :scope_id AND setting_key = :setting_key'
        );
        $stmt->execute([
            'scope_type' => 'app',
            'scope_id' => 0,
            'setting_key' => $key,
        ]);
    }
}
