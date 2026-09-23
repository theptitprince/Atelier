<?php

declare(strict_types=1);

namespace Atelier\Shared;

use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Response;
use Atelier\Kernel\Config;
use Atelier\Persistence\Database;
use Atelier\Support\Clock;
use Atelier\Support\Files;
use Atelier\Support\Str;

/**
 * Pièces jointes : fichiers stockés hors du répertoire public sous un nom interne imprévisible,
 * chiffrés au repos (AES-256-GCM, voir FileCrypto) lorsque le chiffrement est activé,
 * métadonnées en base. Contrôle du type (finfo), de la taille, de l'extension et des quotas.
 * Le téléchargement passe toujours par PHP après contrôle des ACL par l'appelant ; le contenu
 * est déchiffré à la volée, sans jamais écrire le clair sur disque.
 */
final class AttachmentService
{
    /** Familles de fichiers utilisées pour les filtres : code => [libellé, motifs MIME]. */
    public const KINDS = [
        'image' => ['Images', ['image/%']],
        'pdf' => ['PDF', ['application/pdf']],
        'document' => ['Bureautique', ['application/vnd.%', 'application/msword', 'application/vnd.ms-%']],
        'text' => ['Texte et CSV', ['text/%']],
        'other' => ['Autres', []],
    ];

    /** Types pouvant être affichés dans le navigateur (sinon téléchargement forcé). */
    public const INLINE_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/plain'];

    /** @var array<string, string> colonne de tri autorisée => expression SQL */
    private const SORTS = [
        'created_at' => 'a.created_at',
        'original_name' => 'a.original_name',
        'size' => 'a.size',
        'uploader' => 'u.username',
        'deleted_at' => 'a.deleted_at',
        'downloads' => 'a.downloads',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly ?FileCrypto $crypto = null,
    ) {
    }

    public function directory(): string
    {
        return $this->config->path('attachments');
    }

    public function isEncryptionEnabled(): bool
    {
        return $this->crypto !== null;
    }

    public function crypto(): ?FileCrypto
    {
        return $this->crypto;
    }

    // ----- Enregistrement -----

    /**
     * Enregistre un fichier téléversé ($_FILES[x]) et le rattache à une information.
     *
     * @param array<string, mixed> $upload
     * @return array<string, mixed> métadonnées enregistrées
     */
    public function store(array $upload, ?string $infoId, ?int $userId, ?string $description = null, ?string $label = null): array
    {
        $this->assertUploadOk($upload);
        $tmp = (string) $upload['tmp_name'];
        $originalName = Files::sanitizeFilename((string) ($upload['name'] ?? 'fichier'));
        $size = (int) ($upload['size'] ?? filesize($tmp));

        $this->assertSizeAllowed($size, $userId);
        $this->assertTypeAllowed($originalName, $tmp);
        $mime = $this->detectMime($tmp);

        $id = Str::random(16);
        $encrypted = $this->crypto !== null;
        $relative = Clock::now()->format('Y/m') . '/' . $id . ($encrypted ? '.' . FileCrypto::EXTENSION : '.bin');
        $target = $this->directory() . '/' . $relative;
        Files::ensureDirectory(dirname($target));

        $sha256 = hash_file('sha256', $tmp) ?: '';
        if ($encrypted) {
            $this->crypto->encryptFile($tmp, $target);
            @unlink($tmp);
        } else {
            $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $target) : rename($tmp, $target);
            if (!$moved) {
                throw new \RuntimeException('Impossible d’enregistrer le fichier joint.');
            }
        }

        $record = [
            'id' => $id,
            'info_id' => $infoId,
            'original_name' => $originalName,
            'mime' => $mime,
            'size' => $size,
            'sha256' => $sha256,
            'storage_path' => $relative,
            'uploaded_by' => $userId,
            'created_at' => Clock::utc(),
            'deleted_at' => null,
            'cipher' => $encrypted ? FileCrypto::CIPHER : null,
            'key_id' => $encrypted ? $this->crypto->keyId() : null,
            'description' => $description !== null && $description !== '' ? $description : null,
            'downloads' => 0,
            'last_downloaded_at' => null,
            'label' => self::cleanLabel($label),
        ];
        $this->db->insert('attachments', $record);
        return $record;
    }

    /** Nom d'affichage : libellé libre s'il existe, sinon nom de fichier d'origine. @param array<string, mixed> $attachment */
    public static function displayName(array $attachment): string
    {
        $label = trim((string) ($attachment['label'] ?? ''));
        return $label !== '' ? $label : (string) ($attachment['original_name'] ?? 'fichier');
    }

    public static function cleanLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }
        if (!mb_check_encoding($label, 'UTF-8')) {
            throw ValidationException::single('label', 'Le nom doit être encodé en UTF-8.');
        }
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);
        return $label === '' ? null : mb_substr($label, 0, 200, 'UTF-8');
    }

    /** Modifie le nom d'affichage seul. */
    public function setLabel(string $id, ?string $label): void
    {
        $this->db->update('attachments', ['label' => self::cleanLabel($label)], 'id = :id', ['id' => $id]);
    }

    /**
     * Enregistre un contenu produit par l'application (données de démonstration, exports conservés).
     *
     * @return array<string, mixed>
     */
    public function storeContent(string $content, string $name, ?string $infoId, ?int $userId, ?string $description = null): array
    {
        $tmpDir = $this->config->path('tmp');
        Files::ensureDirectory($tmpDir);
        $tmp = $tmpDir . '/upload-' . Str::random(8) . '.tmp';
        if (file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException('Impossible d’écrire le fichier temporaire.');
        }
        try {
            return $this->store(['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK], $infoId, $userId, $description);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    // ----- Lecture -----

    /** @return array<string, mixed>|null */
    public function find(string $id, bool $includeDeleted = false): ?array
    {
        $row = $this->db->selectOne($this->selectSql() . ' WHERE a.id = :id', ['id' => $id]);
        if ($row === null || (!$includeDeleted && $row['deleted_at'] !== null)) {
            return null;
        }
        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function listFor(string $infoId): array
    {
        return $this->db->select($this->selectSql() . ' WHERE a.info_id = :i AND a.deleted_at IS NULL ORDER BY a.created_at DESC', ['i' => $infoId]);
    }

    public function countFor(string $infoId): int
    {
        return $this->db->count('SELECT COUNT(*) FROM attachments WHERE info_id = :i AND deleted_at IS NULL', ['i' => $infoId]);
    }

    /**
     * Liste paginée avec filtres : uploaded_by (int), deleted (bool), search, kind (clé de KINDS),
     * linked (bool : rattachée ou orpheline), info_id.
     *
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $filters, int $page, int $perPage, string $sort = 'created_at', string $direction = 'desc'): array
    {
        [$where, $params] = $this->where($filters);
        $total = $this->db->count('SELECT COUNT(*) FROM attachments a LEFT JOIN users u ON u.id = a.uploaded_by LEFT JOIN info_registry r ON r.id = a.info_id WHERE ' . $where, $params);
        $column = self::SORTS[$sort] ?? self::SORTS['created_at'];
        $dir = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, min(500, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->select($this->selectSql() . " WHERE $where ORDER BY $column $dir, a.created_at DESC LIMIT $perPage OFFSET $offset", $params);
        return ['rows' => $rows, 'total' => $total];
    }

    public static function isSortable(string $column): bool
    {
        return isset(self::SORTS[$column]);
    }

    /** Famille d'un type MIME (clé de KINDS). */
    public static function kindOf(string $mime): string
    {
        foreach (self::KINDS as $kind => [, $patterns]) {
            foreach ($patterns as $pattern) {
                $prefix = rtrim($pattern, '%');
                if ($pattern === $mime || (str_ends_with($pattern, '%') && str_starts_with($mime, $prefix))) {
                    return $kind;
                }
            }
        }
        return 'other';
    }

    /**
     * Statistiques : nombre et volume des fichiers actifs, en corbeille, par utilisateur.
     *
     * @return array{count: int, size: int, trashed: int, trashed_size: int}
     */
    public function stats(?int $userId = null): array
    {
        $where = $userId === null ? '1 = 1' : 'uploaded_by = :u';
        $params = $userId === null ? [] : ['u' => $userId];
        $active = $this->db->selectOne("SELECT COUNT(*) AS c, COALESCE(SUM(size), 0) AS s FROM attachments WHERE $where AND deleted_at IS NULL", $params) ?? ['c' => 0, 's' => 0];
        $trashed = $this->db->selectOne("SELECT COUNT(*) AS c, COALESCE(SUM(size), 0) AS s FROM attachments WHERE $where AND deleted_at IS NOT NULL", $params) ?? ['c' => 0, 's' => 0];
        return ['count' => (int) $active['c'], 'size' => (int) $active['s'], 'trashed' => (int) $trashed['c'], 'trashed_size' => (int) $trashed['s']];
    }

    // ----- Téléchargement -----

    /** Réponse de téléchargement (l'appelant a déjà vérifié les ACL) ; déchiffrement à la volée. */
    public function download(string $id, bool $inline = false): Response
    {
        $attachment = $this->find($id);
        if ($attachment === null) {
            throw new NotFoundException('Pièce jointe introuvable.');
        }
        $path = $this->absolutePath($attachment);
        if (!is_file($path)) {
            throw new NotFoundException('Le fichier de la pièce jointe est absent du stockage.');
        }
        $safeInline = $inline && in_array($attachment['mime'], self::INLINE_MIMES, true);
        $name = (string) $attachment['original_name'];
        $mime = (string) $attachment['mime'];
        if ($attachment['cipher'] !== null) {
            $crypto = $this->requireCrypto();
            return Response::stream(
                static function ($out) use ($crypto, $path): void {
                    $crypto->decryptToStream($path, $out);
                },
                $name,
                $mime,
                (int) $attachment['size'],
                $safeInline
            );
        }
        return Response::file($path, $name, $mime, $safeInline);
    }

    /** Contenu en clair d'une pièce jointe (vérifications, traitements internes). */
    public function contents(string $id): string
    {
        $attachment = $this->find($id, true);
        if ($attachment === null) {
            throw new NotFoundException('Pièce jointe introuvable.');
        }
        $path = $this->absolutePath($attachment);
        if (!is_file($path)) {
            throw new NotFoundException('Le fichier de la pièce jointe est absent du stockage.');
        }
        if ($attachment['cipher'] !== null) {
            return $this->requireCrypto()->decryptToString($path);
        }
        return (string) file_get_contents($path);
    }

    /**
     * Vérifie l'intégrité d'une pièce jointe : présence du fichier, authentification du chiffré
     * et correspondance de l'empreinte SHA-256 enregistrée.
     *
     * @return array{ok: bool, message: string}
     */
    public function verify(string $id): array
    {
        $attachment = $this->find($id, true);
        if ($attachment === null) {
            return ['ok' => false, 'message' => 'Pièce jointe inconnue.'];
        }
        $path = $this->absolutePath($attachment);
        if (!is_file($path)) {
            return ['ok' => false, 'message' => 'Fichier absent du stockage.'];
        }
        try {
            $content = $this->contents($id);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        if (strlen($content) !== (int) $attachment['size']) {
            return ['ok' => false, 'message' => 'Taille incohérente avec les métadonnées.'];
        }
        if (!hash_equals((string) $attachment['sha256'], hash('sha256', $content))) {
            return ['ok' => false, 'message' => 'Empreinte SHA-256 différente : contenu altéré.'];
        }
        return ['ok' => true, 'message' => $attachment['cipher'] !== null ? 'Fichier chiffré authentifié, empreinte conforme.' : 'Empreinte conforme (fichier non chiffré).'];
    }

    public function recordDownload(string $id): void
    {
        $this->db->execute('UPDATE attachments SET downloads = downloads + 1, last_downloaded_at = :n WHERE id = :id', ['n' => Clock::utc(), 'id' => $id]);
    }

    // ----- Modification -----

    /** Rattache (ou détache avec null) la pièce jointe à une information du registre. */
    public function attach(string $id, ?string $infoId): void
    {
        $this->db->update('attachments', ['info_id' => $infoId], 'id = :id', ['id' => $id]);
    }

    public function rename(string $id, string $originalName, ?string $description, ?string $label = null): void
    {
        if ($label !== null) {
            $this->setLabel($id, $label);
        }
        $originalName = Files::sanitizeFilename($originalName);
        $this->db->update('attachments', ['original_name' => $originalName, 'description' => $description !== null && $description !== '' ? $description : null], 'id = :id', ['id' => $id]);
    }

    /** Suppression logique (corbeille) ; la purge physique est réalisée par la maintenance. */
    public function softDelete(string $id): void
    {
        $this->db->update('attachments', ['deleted_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    public function restore(string $id): void
    {
        $this->db->update('attachments', ['deleted_at' => null], 'id = :id', ['id' => $id]);
    }

    /** Suppression physique immédiate (fichier et métadonnées). */
    public function purge(string $id): bool
    {
        $attachment = $this->find($id, true);
        if ($attachment === null) {
            return false;
        }
        $path = $this->absolutePath($attachment);
        if (is_file($path) && !@unlink($path)) {
            return false;
        }
        // Le fichier lui-même peut être inscrit au registre commun (tags, relations) : on retire cette entrée.
        $this->db->delete('info_registry', 'dataset_code = :d AND local_key = :k', ['d' => 'attachments.file', 'k' => $id]);
        $this->db->delete('attachments', 'id = :id', ['id' => $id]);
        return true;
    }

    /** Purge physique des pièces jointes supprimées depuis plus de $days jours. */
    public function purgeDeleted(int $days): int
    {
        $limit = Clock::utc(Clock::now()->modify("-{$days} days"));
        $rows = $this->db->select('SELECT id FROM attachments WHERE deleted_at IS NOT NULL AND deleted_at < :l', ['l' => $limit]);
        $count = 0;
        foreach ($rows as $row) {
            if ($this->purge((string) $row['id'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Chiffre les fichiers encore stockés en clair (migration après activation du chiffrement).
     *
     * @return array{encrypted: int, skipped: int, errors: list<string>}
     */
    public function encryptExisting(): array
    {
        $crypto = $this->requireCrypto();
        $result = ['encrypted' => 0, 'skipped' => 0, 'errors' => []];
        foreach ($this->db->select('SELECT id, storage_path, sha256 FROM attachments WHERE cipher IS NULL') as $row) {
            $source = $this->directory() . '/' . $row['storage_path'];
            if (!is_file($source)) {
                $result['errors'][] = $row['id'] . ' : fichier absent';
                continue;
            }
            if (FileCrypto::isEncryptedFile($source)) {
                $result['skipped']++;
                continue;
            }
            if (hash_file('sha256', $source) !== $row['sha256']) {
                $result['errors'][] = $row['id'] . ' : empreinte différente, fichier non chiffré';
                continue;
            }
            $relative = preg_replace('/\.bin$/', '', (string) $row['storage_path']) . '.' . FileCrypto::EXTENSION;
            $target = $this->directory() . '/' . $relative;
            try {
                $crypto->encryptFile($source, $target);
                $this->db->update('attachments', ['storage_path' => $relative, 'cipher' => FileCrypto::CIPHER, 'key_id' => $crypto->keyId()], 'id = :id', ['id' => $row['id']]);
                @unlink($source);
                $result['encrypted']++;
            } catch (\Throwable $e) {
                $result['errors'][] = $row['id'] . ' : ' . $e->getMessage();
            }
        }
        return $result;
    }

    /** @return list<array<string, mixed>> toutes les pièces jointes (corbeille comprise), pour la maintenance */
    public function all(): array
    {
        return $this->db->select('SELECT id, original_name, size, sha256, storage_path, cipher, key_id, deleted_at FROM attachments ORDER BY created_at');
    }

    // ----- Quotas -----

    public function usageOfUser(int $userId): int
    {
        return (int) $this->db->scalar('SELECT COALESCE(SUM(size), 0) FROM attachments WHERE uploaded_by = :u AND deleted_at IS NULL', ['u' => $userId]);
    }

    public function usageTotal(): int
    {
        return (int) $this->db->scalar('SELECT COALESCE(SUM(size), 0) FROM attachments WHERE deleted_at IS NULL');
    }

    public function maxFileSize(): int
    {
        return $this->config->int('attachments.max_file_size');
    }

    public function maxPerUser(): int
    {
        return $this->config->int('attachments.max_per_user');
    }

    public function maxTotal(): int
    {
        return $this->config->int('attachments.max_total');
    }

    /** @return list<string> */
    public function allowedMimes(): array
    {
        return array_values(array_map('strval', $this->config->array('attachments.allowed_mime')));
    }

    // ----- Interne -----

    private function selectSql(): string
    {
        return 'SELECT a.*, u.username AS uploader, u.display_name AS uploader_name,
                       r.label AS info_label, r.dataset_code AS info_dataset, r.module_id AS info_module, r.local_key AS info_key
                FROM attachments a
                LEFT JOIN users u ON u.id = a.uploaded_by
                LEFT JOIN info_registry r ON r.id = a.info_id';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(array $filters): array
    {
        $where = [];
        $params = [];
        $where[] = !empty($filters['deleted']) ? 'a.deleted_at IS NOT NULL' : 'a.deleted_at IS NULL';
        if (isset($filters['uploaded_by'])) {
            $where[] = 'a.uploaded_by = :uploaded_by';
            $params['uploaded_by'] = (int) $filters['uploaded_by'];
        }
        if (isset($filters['info_id']) && $filters['info_id'] !== '') {
            $where[] = 'a.info_id = :info_id';
            $params['info_id'] = (string) $filters['info_id'];
        }
        if (isset($filters['linked'])) {
            $where[] = $filters['linked'] ? 'a.info_id IS NOT NULL' : 'a.info_id IS NULL';
        }
        // Dossiers virtuels : 'root' = fichiers non rangés, un identifiant = ce dossier seul, folder_ids = dossier et sous-dossiers.
        if (isset($filters['folder_id']) && $filters['folder_id'] !== '') {
            if ($filters['folder_id'] === 'root') {
                $where[] = 'a.folder_id IS NULL';
            } else {
                $where[] = 'a.folder_id = :folder_id';
                $params['folder_id'] = (int) $filters['folder_id'];
            }
        }
        if (!empty($filters['folder_ids']) && is_array($filters['folder_ids'])) {
            $placeholders = [];
            foreach (array_values($filters['folder_ids']) as $i => $folderId) {
                $placeholders[] = ':fid' . $i;
                $params['fid' . $i] = (int) $folderId;
            }
            $where[] = 'a.folder_id IN (' . implode(', ', $placeholders) . ')';
        }
        // Tag partagé porté par le fichier lui-même (le fichier est inscrit au registre sous attachments.file / son identifiant).
        if (!empty($filters['tag'])) {
            $where[] = "EXISTS (SELECT 1 FROM info_registry fr INNER JOIN info_tags fit ON fit.info_id = fr.id INNER JOIN tags ft ON ft.id = fit.tag_id
                        WHERE fr.dataset_code = 'attachments.file' AND fr.local_key = a.id AND ft.scope = 'shared' AND ft.normalized = :ftag)";
            $params['ftag'] = \Atelier\Support\Str::normalizeTag((string) $filters['tag']);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(' . $this->db->lower('a.original_name') . ' LIKE :search OR ' . $this->db->lower("COALESCE(a.label, '')") . ' LIKE :search OR ' . $this->db->lower("COALESCE(a.description, '')") . ' LIKE :search OR ' . $this->db->lower("COALESCE(r.label, '')") . ' LIKE :search)';
            $params['search'] = '%' . mb_strtolower($search, 'UTF-8') . '%';
        }
        $kind = (string) ($filters['kind'] ?? '');
        if ($kind !== '' && isset(self::KINDS[$kind])) {
            $patterns = self::KINDS[$kind][1];
            if ($patterns === []) {
                // "Autres" : aucun motif des autres familles
                $exclusions = [];
                foreach (self::KINDS as $other => [, $otherPatterns]) {
                    foreach ($otherPatterns as $i => $pattern) {
                        $key = 'kx_' . $other . '_' . $i;
                        $exclusions[] = 'a.mime LIKE :' . $key;
                        $params[$key] = $pattern;
                    }
                }
                $where[] = 'NOT (' . implode(' OR ', $exclusions) . ')';
            } else {
                $conditions = [];
                foreach ($patterns as $i => $pattern) {
                    $conditions[] = 'a.mime LIKE :kind_' . $i;
                    $params['kind_' . $i] = $pattern;
                }
                $where[] = '(' . implode(' OR ', $conditions) . ')';
            }
        }
        return [implode(' AND ', $where), $params];
    }

    /** @param array<string, mixed> $attachment */
    private function absolutePath(array $attachment): string
    {
        return $this->directory() . '/' . $attachment['storage_path'];
    }

    private function requireCrypto(): FileCrypto
    {
        if ($this->crypto === null) {
            throw new \RuntimeException('Le chiffrement des pièces jointes est désactivé alors que ce fichier est chiffré : réactivez attachments.encryption.');
        }
        return $this->crypto;
    }

    /** @param array<string, mixed> $upload */
    private function assertUploadOk(array $upload): void
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille maximale autorisée.',
                UPLOAD_ERR_PARTIAL => 'Le fichier n’a été que partiellement téléversé.',
                UPLOAD_ERR_NO_FILE => 'Aucun fichier reçu.',
                default => 'Le téléversement a échoué.',
            };
            throw ValidationException::single('file', $message);
        }
        if (!is_file((string) ($upload['tmp_name'] ?? ''))) {
            throw ValidationException::single('file', 'Fichier temporaire introuvable.');
        }
    }

    private function assertSizeAllowed(int $size, ?int $userId): void
    {
        $max = $this->maxFileSize();
        if ($size <= 0) {
            throw ValidationException::single('file', 'Le fichier est vide.');
        }
        if ($size > $max) {
            throw ValidationException::single('file', 'Le fichier dépasse la taille maximale de ' . Str::humanSize($max) . '.');
        }
        if ($userId !== null && $this->usageOfUser($userId) + $size > $this->maxPerUser()) {
            throw ValidationException::single('file', 'Votre quota de pièces jointes (' . Str::humanSize($this->maxPerUser()) . ') serait dépassé.');
        }
        if ($this->usageTotal() + $size > $this->maxTotal()) {
            throw ValidationException::single('file', 'Le quota global de pièces jointes est atteint.');
        }
    }

    private function assertTypeAllowed(string $originalName, string $tmp): void
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($extension, $this->config->array('attachments.forbidden_extensions'), true)) {
            throw ValidationException::single('file', 'Ce type de fichier est refusé (exécutables, scripts et archives).');
        }
        $mime = $this->detectMime($tmp);
        if (!in_array($mime, $this->allowedMimes(), true)) {
            throw ValidationException::single('file', 'Type de fichier non autorisé : ' . $mime);
        }
    }

    private function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path) ?: 'application/octet-stream';
        // Les CSV sont détectés comme text/plain : on accepte les deux selon l'extension.
        return $mime === 'text/plain' ? 'text/plain' : $mime;
    }
}
