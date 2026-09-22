<?php

declare(strict_types=1);

namespace Atelier\Shared;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;
use Atelier\Support\Str;

/**
 * Registre commun des informations : attribue un identifiant global et stable (UUID) à une
 * donnée d'un module (jeu de données + clé locale) afin de pouvoir la tagger, la relier ou
 * lui joindre des fichiers depuis n'importe quel module autorisé.
 */
final class InfoRegistry
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Retourne l'identifiant global d'une information, en le créant si nécessaire.
     */
    public function register(string $datasetCode, string $localKey, ?string $label = null, ?int $userId = null): string
    {
        $existing = $this->find($datasetCode, $localKey);
        if ($existing !== null) {
            if ($label !== null && $label !== $existing['label']) {
                $this->db->update('info_registry', ['label' => $label], 'id = :id', ['id' => $existing['id']]);
            }
            return (string) $existing['id'];
        }
        $id = Str::uuid();
        $moduleId = explode('.', $datasetCode, 2)[0];
        $this->db->insert('info_registry', [
            'id' => $id,
            'dataset_code' => $datasetCode,
            'module_id' => $moduleId,
            'local_key' => $localKey,
            'label' => $label,
            'created_by' => $userId,
            'created_at' => Clock::utc(),
        ]);
        return $id;
    }

    /** @return array<string, mixed>|null */
    public function find(string $datasetCode, string $localKey): ?array
    {
        return $this->db->selectOne('SELECT * FROM info_registry WHERE dataset_code = :d AND local_key = :k', ['d' => $datasetCode, 'k' => $localKey]);
    }

    /** @return array<string, mixed>|null */
    public function get(string $infoId): ?array
    {
        return $this->db->selectOne('SELECT * FROM info_registry WHERE id = :id', ['id' => $infoId]);
    }

    public function updateLabel(string $infoId, ?string $label): void
    {
        $this->db->update('info_registry', ['label' => $label], 'id = :id', ['id' => $infoId]);
    }

    /** Supprime l'entrée (et par cascade ses tags, relations ; les pièces jointes sont détachées). */
    public function unregister(string $datasetCode, string $localKey): void
    {
        $this->db->delete('info_registry', 'dataset_code = :d AND local_key = :k', ['d' => $datasetCode, 'k' => $localKey]);
    }

    /**
     * Recherche d'informations par libellé, restreinte aux jeux de données fournis (partagés et autorisés).
     *
     * @param list<string> $datasetCodes
     * @return list<array<string, mixed>>
     */
    public function search(string $term, array $datasetCodes, int $limit = 20): array
    {
        if ($datasetCodes === []) {
            return [];
        }
        $placeholders = [];
        $params = ['term' => '%' . mb_strtolower($term, 'UTF-8') . '%'];
        foreach ($datasetCodes as $i => $code) {
            $placeholders[] = ':d' . $i;
            $params['d' . $i] = $code;
        }
        return $this->db->select(
            'SELECT * FROM info_registry WHERE ' . $this->db->lower('label') . ' LIKE :term AND dataset_code IN (' . implode(', ', $placeholders) . ') ORDER BY label LIMIT ' . $limit,
            $params
        );
    }
}
