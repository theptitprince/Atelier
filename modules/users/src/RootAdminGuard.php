<?php

declare(strict_types=1);

namespace Atelier\Modules\Users;

use Atelier\Error\ConflictException;
use Atelier\Persistence\Database;
use Atelier\Security\Acl\AclService;

/**
 * Garantit qu'il reste toujours au moins un administrateur racine actif.
 *
 * L'opération est exécutée dans une transaction ; si, une fois appliquée, plus aucun compte
 * actif ne dispose du droit « admin » sur la racine, la transaction est annulée et une
 * ConflictException explicite est levée.
 */
final class RootAdminGuard
{
    public function __construct(private readonly Database $db, private readonly AclService $acl)
    {
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function protect(callable $operation, string $message = 'Cette opération retirerait le dernier accès d’administration : elle est refusée.'): mixed
    {
        try {
            return $this->db->transaction(function () use ($operation, $message): mixed {
                $result = $operation();
                $this->acl->clearCache();
                if ($this->acl->countRootAdmins() === 0) {
                    throw new ConflictException($message);
                }
                return $result;
            });
        } finally {
            // Le cache a pu être alimenté avec l'état intermédiaire (avant annulation éventuelle).
            $this->acl->clearCache();
        }
    }

    /** Le compte est-il actuellement un administrateur racine actif ? */
    public function isRootAdmin(int $userId): bool
    {
        return in_array($userId, $this->acl->rootAdminIds(), true);
    }
}
