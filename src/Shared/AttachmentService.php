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
 * métadonnées en base. Contrôle du type (finfo), de la taille, de l'extension et des quotas.
 * Le téléchargement passe toujours par PHP après contrôle des ACL par l'appelant.
 */
final class AttachmentService
{
    public function __construct(private readonly Database $db, private readonly Config $config)
    {
    }

    public function directory(): string
    {
        return $this->config->path('attachments');
    }

    /**
     * Enregistre un fichier téléversé ($_FILES[x]) et le rattache à une information.
     *
     * @param array<string, mixed> $upload
     * @return array<string, mixed> métadonnées enregistrées
     */
    public function store(array $upload, ?string $infoId, ?int $userId): array
    {
        $this->assertUploadOk($upload);
        $tmp = (string) $upload['tmp_name'];
        $originalName = Files::sanitizeFilename((string) ($upload['name'] ?? 'fichier'));
        $size = (int) ($upload['size'] ?? filesize($tmp));

        $this->assertSizeAllowed($size, $userId);

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($extension, $this->config->array('attachments.forbidden_extensions'), true)) {
            throw ValidationException::single('file', 'Ce type de fichier est refusé (exécutables, scripts et archives).');
        }
        $mime = $this->detectMime($tmp);
        if (!in_array($mime, $this->config->array('attachments.allowed_mime'), true)) {
            throw ValidationException::single('file', 'Type de fichier non autorisé : ' . $mime);
        }

        $id = Str::random(16);
        $relative = Clock::now()->format('Y/m') . '/' . $id . '.bin';
        $target = $this->directory() . '/' . $relative;
        Files::ensureDirectory(dirname($target));

        $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $target) : rename($tmp, $target);
        if (!$moved) {
            throw new \RuntimeException('Impossible d’enregistrer le fichier joint.');
        }

        $record = [
            'id' => $id,
            'info_id' => $infoId,
            'original_name' => $originalName,
            'mime' => $mime,
            'size' => $size,
            'sha256' => hash_file('sha256', $target) ?: '',
            'storage_path' => $relative,
            'uploaded_by' => $userId,
            'created_at' => Clock::utc(),
            'deleted_at' => null,
        ];
        $this->db->insert('attachments', $record);
        return $record;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id, bool $includeDeleted = false): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM attachments WHERE id = :id', ['id' => $id]);
        if ($row === null || (!$includeDeleted && $row['deleted_at'] !== null)) {
            return null;
        }
        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function listFor(string $infoId): array
    {
        return $this->db->select(
            'SELECT a.*, u.username AS uploader FROM attachments a LEFT JOIN users u ON u.id = a.uploaded_by WHERE a.info_id = :i AND a.deleted_at IS NULL ORDER BY a.created_at DESC',
            ['i' => $infoId]
        );
    }

    /** Réponse de téléchargement (l'appelant a déjà vérifié les ACL). */
    public function download(string $id, bool $inline = false): Response
    {
        $attachment = $this->find($id);
        if ($attachment === null) {
            throw new NotFoundException('Pièce jointe introuvable.');
        }
        $path = $this->directory() . '/' . $attachment['storage_path'];
        if (!is_file($path)) {
            throw new NotFoundException('Le fichier de la pièce jointe est absent du stockage.');
        }
        $safeInline = $inline && in_array($attachment['mime'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/plain'], true);
        return Response::file($path, (string) $attachment['original_name'], (string) $attachment['mime'], $safeInline);
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

    /** Purge physique des pièces jointes supprimées depuis plus de $days jours. */
    public function purgeDeleted(int $days): int
    {
        $limit = Clock::utc(Clock::now()->modify("-{$days} days"));
        $rows = $this->db->select('SELECT id, storage_path FROM attachments WHERE deleted_at IS NOT NULL AND deleted_at < :l', ['l' => $limit]);
        $count = 0;
        foreach ($rows as $row) {
            $path = $this->directory() . '/' . $row['storage_path'];
            if (!is_file($path) || @unlink($path)) {
                $this->db->delete('attachments', 'id = :id', ['id' => $row['id']]);
                $count++;
            }
        }
        return $count;
    }

    public function usageOfUser(int $userId): int
    {
        return (int) $this->db->scalar('SELECT COALESCE(SUM(size), 0) FROM attachments WHERE uploaded_by = :u AND deleted_at IS NULL', ['u' => $userId]);
    }

    public function usageTotal(): int
    {
        return (int) $this->db->scalar('SELECT COALESCE(SUM(size), 0) FROM attachments WHERE deleted_at IS NULL');
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
        $max = $this->config->int('attachments.max_file_size');
        if ($size <= 0) {
            throw ValidationException::single('file', 'Le fichier est vide.');
        }
        if ($size > $max) {
            throw ValidationException::single('file', 'Le fichier dépasse la taille maximale de ' . Str::humanSize($max) . '.');
        }
        if ($userId !== null && $this->usageOfUser($userId) + $size > $this->config->int('attachments.max_per_user')) {
            throw ValidationException::single('file', 'Votre quota de pièces jointes (' . Str::humanSize($this->config->int('attachments.max_per_user')) . ') serait dépassé.');
        }
        if ($this->usageTotal() + $size > $this->config->int('attachments.max_total')) {
            throw ValidationException::single('file', 'Le quota global de pièces jointes est atteint.');
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
