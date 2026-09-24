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
    public function __construct(private readonly Database $db, private readonly ?AttachmentService $attachments = null)
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

    /**
     * Signale la mise en corbeille d'informations d'un module.
     *
     * À appeler par le module à chaque suppression logique, y compris pour les éléments qu'il
     * masque par cascade (opérations d'un compte, interventions d'un équipement…). Tags,
     * éléments liés, Explorateur et sélecteurs les écartent alors, sans rien effacer : la
     * restauration les fait réapparaître tels quels.
     *
     * @param string|list<string> $localKeys
     */
    public function trash(string $datasetCode, string|array $localKeys): void
    {
        $this->setTrashed($datasetCode, $localKeys, Clock::utc());
    }

    /**
     * Signale la restauration d'informations mises en corbeille.
     *
     * @param string|list<string> $localKeys
     */
    public function restore(string $datasetCode, string|array $localKeys): void
    {
        $this->setTrashed($datasetCode, $localKeys, null);
    }

    public function isTrashed(string $datasetCode, string $localKey): bool
    {
        $row = $this->find($datasetCode, $localKey);
        return $row !== null && $row['trashed_at'] !== null;
    }

    /** @param string|list<string> $localKeys */
    private function setTrashed(string $datasetCode, string|array $localKeys, ?string $when): void
    {
        $keys = array_values(array_unique(array_map('strval', (array) $localKeys)));
        // Par lots : une cascade (toutes les opérations d'un compte) peut compter des milliers de clés.
        foreach (array_chunk($keys, 500) as $chunk) {
            $params = ['d' => $datasetCode, 'w' => $when];
            $placeholders = [];
            foreach ($chunk as $i => $key) {
                $placeholders[] = ':k' . $i;
                $params['k' . $i] = $key;
            }
            // Une date de mise en corbeille déjà posée est conservée : elle date le premier retrait.
            $guard = $when === null ? '' : ' AND trashed_at IS NULL';
            $this->db->execute(
                'UPDATE info_registry SET trashed_at = :w WHERE dataset_code = :d AND local_key IN (' . implode(', ', $placeholders) . ')' . $guard,
                $params
            );
        }
    }

    /**
     * Retire définitivement une information du registre, avec ses pièces jointes.
     *
     * La clé étrangère des pièces jointes est ON DELETE SET NULL : sans cette purge, chaque
     * suppression définitive (page, projet, note, intervention…) laissait des fichiers sans
     * propriétaire, invisibles dans l'interface et jamais effacés du disque.
     */
    public function unregister(string $datasetCode, string $localKey): void
    {
        $info = $this->find($datasetCode, $localKey);
        if ($info === null) {
            return;
        }
        if ($this->attachments !== null) {
            foreach ($this->attachments->allFor((string) $info['id']) as $attachment) {
                $this->attachments->purge((string) $attachment['id']);
            }
        }
        $this->db->delete('info_registry', 'id = :id', ['id' => $info['id']]);
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
            'SELECT * FROM info_registry WHERE ' . $this->db->lower('label') . ' LIKE :term AND trashed_at IS NULL AND dataset_code IN (' . implode(', ', $placeholders) . ') ORDER BY label LIMIT ' . $limit,
            $params
        );
    }
}
