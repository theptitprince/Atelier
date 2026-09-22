<?php

declare(strict_types=1);

namespace Atelier\Modules\Users;

use Atelier\Error\ForbiddenException;
use Atelier\Modules\ModuleContext;

/**
 * Service intermodule du jeu de données partagé « users.account ».
 *
 * Expose uniquement l'identité publique des comptes (id, username, display_name, status) ;
 * jamais le mot de passe, le courriel ni les compteurs de sécurité. Chaque méthode vérifie
 * que l'utilisateur demandeur dispose du droit de lecture sur le jeu via le catalogue.
 */
final class UsersService
{
    public const DATASET = 'users.account';

    private const PUBLIC_FIELDS = ['id', 'username', 'display_name', 'status'];

    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /**
     * Comptes actifs, pour les sélecteurs des autres modules.
     *
     * @return list<array{id: int, username: string, display_name: string, status: string}>
     */
    public function listActive(int $viewerUserId): array
    {
        $this->assertReadable($viewerUserId);
        $rows = $this->ctx->db->select("SELECT id, username, display_name, status FROM users WHERE status = 'active' ORDER BY display_name, username");
        return array_map([$this, 'publicRow'], $rows);
    }

    /**
     * Identité publique d'un compte, ou null s'il n'existe pas.
     *
     * @return array{id: int, username: string, display_name: string, status: string}|null
     */
    public function find(int $viewerUserId, int $id): ?array
    {
        $this->assertReadable($viewerUserId);
        $row = $this->ctx->db->selectOne('SELECT id, username, display_name, status FROM users WHERE id = :id', ['id' => $id]);
        return $row === null ? null : $this->publicRow($row);
    }

    /**
     * Nom affiché d'un compte (ou « Utilisateur #id » s'il a disparu). Le demandeur est l'utilisateur connecté.
     */
    public function displayName(int $id): string
    {
        $this->assertReadable($this->ctx->userId());
        $row = $this->ctx->db->selectOne('SELECT display_name FROM users WHERE id = :id', ['id' => $id]);
        return $row === null ? 'Utilisateur #' . $id : (string) $row['display_name'];
    }

    private function assertReadable(int $viewerUserId): void
    {
        if (!$this->ctx->shared->catalog->canAccess($viewerUserId, self::DATASET, 'read')) {
            throw new ForbiddenException('Accès au jeu de données « ' . self::DATASET . ' » refusé.', 'atelier/users/data/account', 'read');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, username: string, display_name: string, status: string}
     */
    private function publicRow(array $row): array
    {
        $public = array_intersect_key($row, array_flip(self::PUBLIC_FIELDS));
        return [
            'id' => (int) $public['id'],
            'username' => (string) $public['username'],
            'display_name' => (string) $public['display_name'],
            'status' => (string) $public['status'],
        ];
    }
}
