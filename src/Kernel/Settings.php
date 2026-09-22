<?php

declare(strict_types=1);

namespace Atelier\Kernel;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;
use Atelier\Support\Json;

/**
 * Paramètres dynamiques (modifiables depuis l'application) et préférences utilisateur,
 * stockés en base et sérialisés en JSON. Portée par module ("core" pour le noyau).
 */
final class Settings
{
    /** @var array<string, array<string, mixed>> cache par module */
    private array $cache = [];

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $name, mixed $default = null, string $moduleId = 'core'): mixed
    {
        $all = $this->allFor($moduleId);
        return array_key_exists($name, $all) ? $all[$name] : $default;
    }

    public function set(string $name, mixed $value, string $moduleId = 'core', ?int $userId = null): void
    {
        $encoded = Json::encode($value);
        $existing = $this->db->selectOne('SELECT id FROM settings WHERE module_id = :m AND name = :n', ['m' => $moduleId, 'n' => $name]);
        if ($existing !== null) {
            $this->db->update('settings', ['value' => $encoded, 'updated_by' => $userId, 'updated_at' => Clock::utc()], 'id = :id', ['id' => (int) $existing['id']]);
        } else {
            $this->db->insert('settings', ['module_id' => $moduleId, 'name' => $name, 'value' => $encoded, 'updated_by' => $userId, 'updated_at' => Clock::utc()]);
        }
        unset($this->cache[$moduleId]);
    }

    public function remove(string $name, string $moduleId = 'core'): void
    {
        $this->db->delete('settings', 'module_id = :m AND name = :n', ['m' => $moduleId, 'n' => $name]);
        unset($this->cache[$moduleId]);
    }

    /** @return array<string, mixed> */
    public function allFor(string $moduleId = 'core'): array
    {
        if (!isset($this->cache[$moduleId])) {
            $this->cache[$moduleId] = [];
            foreach ($this->db->select('SELECT name, value FROM settings WHERE module_id = :m', ['m' => $moduleId]) as $row) {
                $this->cache[$moduleId][(string) $row['name']] = $row['value'] === null ? null : Json::decode((string) $row['value']);
            }
        }
        return $this->cache[$moduleId];
    }

    // ----- Préférences utilisateur -----

    public function preference(int $userId, string $name, mixed $default = null, string $moduleId = 'core'): mixed
    {
        $row = $this->db->selectOne('SELECT value FROM user_preferences WHERE user_id = :u AND module_id = :m AND name = :n', ['u' => $userId, 'm' => $moduleId, 'n' => $name]);
        if ($row === null) {
            return $default;
        }
        return $row['value'] === null ? null : Json::decode((string) $row['value']);
    }

    public function setPreference(int $userId, string $name, mixed $value, string $moduleId = 'core'): void
    {
        $encoded = Json::encode($value);
        $existing = $this->db->selectOne('SELECT id FROM user_preferences WHERE user_id = :u AND module_id = :m AND name = :n', ['u' => $userId, 'm' => $moduleId, 'n' => $name]);
        if ($existing !== null) {
            $this->db->update('user_preferences', ['value' => $encoded, 'updated_at' => Clock::utc()], 'id = :id', ['id' => (int) $existing['id']]);
        } else {
            $this->db->insert('user_preferences', ['user_id' => $userId, 'module_id' => $moduleId, 'name' => $name, 'value' => $encoded, 'updated_at' => Clock::utc()]);
        }
    }

    /** @return array<string, mixed> */
    public function preferencesOf(int $userId, string $moduleId = 'core'): array
    {
        $result = [];
        foreach ($this->db->select('SELECT name, value FROM user_preferences WHERE user_id = :u AND module_id = :m', ['u' => $userId, 'm' => $moduleId]) as $row) {
            $result[(string) $row['name']] = $row['value'] === null ? null : Json::decode((string) $row['value']);
        }
        return $result;
    }
}
