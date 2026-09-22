<?php

declare(strict_types=1);

namespace Atelier\Modules\Users;

use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Modules\ModuleContext;
use Atelier\Security\Acl\AclService;
use Atelier\Security\Acl\Decision;

/**
 * Administration des règles ACL : ajout et suppression protégés (jamais sans administrateur racine),
 * analyse d'un sujet saisi et test d'un droit effectif.
 */
final class AclAdmin
{
    public function __construct(
        private readonly ModuleContext $ctx,
        private readonly ResourceRepository $resources,
        private readonly RootAdminGuard $guard,
    ) {
    }

    /**
     * Analyse un sujet « all », « group:12 » ou « user:3 ».
     *
     * @return array{0: string, 1: ?int, 2: string} type, identifiant, libellé
     */
    public function parseSubject(string $subject): array
    {
        $subject = trim($subject);
        if ($subject === 'all') {
            return ['all', null, 'Tous les utilisateurs connectés'];
        }
        if (preg_match('/^(group|user):(\d+)$/', $subject, $m) !== 1) {
            throw new ValidationException(['subject' => 'Choisissez un sujet : tous, un groupe ou un utilisateur.']);
        }
        $id = (int) $m[2];
        if ($m[1] === 'group') {
            $group = $this->ctx->users->findGroup($id);
            if ($group === null) {
                throw new ValidationException(['subject' => 'Ce groupe n’existe pas.']);
            }
            return ['group', $id, 'Groupe ' . $group['label']];
        }
        $user = $this->ctx->users->find($id);
        if ($user === null) {
            throw new ValidationException(['subject' => 'Cet utilisateur n’existe pas.']);
        }
        return ['user', $id, 'Utilisateur ' . $user['username']];
    }

    /**
     * Vérifie qu'une ressource est connue et présente, et retourne sa description.
     *
     * @return array<string, mixed>
     */
    public function resource(string $path): array
    {
        $resource = $this->resources->find($path);
        if ($resource === null) {
            throw new ValidationException(['resource' => 'Cette ressource protégée est inconnue.']);
        }
        return $resource;
    }

    /**
     * Vérifie qu'une permission est pertinente pour la ressource (ou générique).
     *
     * @param array<string, mixed> $resource
     */
    public function validatePermission(array $resource, string $permission): string
    {
        $permission = trim($permission);
        $allowed = array_values(array_unique(array_merge($resource['permission_list'], AclService::GENERIC_PERMISSIONS)));
        if ($permission === '' || !in_array($permission, $allowed, true)) {
            throw new ValidationException(['permission' => 'Choisissez une permission valide pour cette ressource.']);
        }
        return $permission;
    }

    /**
     * Ajoute (ou remplace) une règle. Un refus qui supprimerait le dernier administrateur est rejeté.
     *
     * @return array{id: int, subjectLabel: string}
     */
    public function addRule(string $subject, string $resourcePath, string $permission, string $effect, ?string $comment, int $actorId): array
    {
        [$type, $subjectId, $subjectLabel] = $this->parseSubject($subject);
        $resource = $this->resource($resourcePath);
        $permission = $this->validatePermission($resource, $permission);
        if (!in_array($effect, ['allow', 'deny'], true)) {
            throw new ValidationException(['effect' => 'L’effet doit être « autoriser » ou « refuser ».']);
        }
        $comment = trim((string) $comment);
        if (mb_strlen($comment, 'UTF-8') > 500) {
            throw new ValidationException(['comment' => 'Le commentaire ne doit pas dépasser 500 caractères.']);
        }
        $id = $this->guard->protect(
            fn (): int => $this->ctx->acl->setRule($type, $subjectId, (string) $resource['path'], $permission, $effect, $actorId, $comment === '' ? null : $comment),
            'Cette règle retirerait le dernier accès d’administration à la racine : elle est refusée.'
        );
        return ['id' => $id, 'subjectLabel' => $subjectLabel];
    }

    /**
     * Supprime une règle. La suppression de la dernière autorisation d'administration est rejetée.
     *
     * @return array<string, mixed> la règle supprimée
     */
    public function removeRule(int $ruleId): array
    {
        $rule = $this->ctx->acl->rule($ruleId);
        if ($rule === null) {
            throw new NotFoundException('Cette règle n’existe plus.');
        }
        $this->guard->protect(
            fn (): bool => $this->ctx->acl->removeRule($ruleId),
            'La suppression de cette règle retirerait le dernier accès d’administration : elle est refusée.'
        );
        return $rule;
    }

    /**
     * Teste un droit effectif pour un utilisateur.
     */
    public function test(int $userId, string $resourcePath, string $permission): Decision
    {
        if ($this->ctx->users->find($userId) === null) {
            throw new ValidationException(['test_user' => 'Choisissez un utilisateur.']);
        }
        $resource = $this->resource($resourcePath);
        $permission = $this->validatePermission($resource, $permission);
        return $this->ctx->acl->resolve($userId, (string) $resource['path'], $permission);
    }

    /**
     * Droits effectifs détaillés d'un utilisateur sur une ressource : permission => décision.
     *
     * @param array<string, mixed> $resource
     * @return array<string, Decision>
     */
    public function effectiveDetailed(int $userId, array $resource): array
    {
        $result = [];
        foreach ($resource['permission_list'] as $permission) {
            $result[$permission] = $this->ctx->acl->resolve($userId, (string) $resource['path'], (string) $permission);
        }
        return $result;
    }
}
