<?php

declare(strict_types=1);

namespace Atelier\Modules\Users;

use Atelier\Error\ConflictException;
use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Modules\ModuleContext;
use Atelier\Support\Str;

/**
 * Règles métier des groupes : validation, création, modification, suppression, membres et duplication
 * (copie des règles ACL, membres en option) = duplication d'un profil de droits.
 */
final class GroupManager
{
    public function __construct(private readonly ModuleContext $ctx, private readonly RootAdminGuard $guard)
    {
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        $group = $this->ctx->users->findGroup($id);
        if ($group === null) {
            throw new NotFoundException('Ce groupe n’existe pas (ou plus).');
        }
        return $group;
    }

    /**
     * @param array<string, mixed> $input name, label, description
     * @return array{name: string, label: string, description: ?string}
     */
    public function validate(array $input, ?int $existingId = null): array
    {
        $errors = [];
        $name = mb_strtolower(trim((string) ($input['name'] ?? '')), 'UTF-8');
        $label = trim((string) ($input['label'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));

        if ($existingId === null) {
            if ($name === '') {
                $errors['name'] = 'Le nom technique est obligatoire.';
            } elseif (!Str::isSlug($name) || strlen($name) < 2) {
                $errors['name'] = 'Le nom technique doit comporter de 2 à 64 caractères : minuscules, chiffres, tiret ou souligné.';
            } elseif ($this->ctx->users->findGroupByName($name) !== null) {
                $errors['name'] = 'Un groupe porte déjà ce nom technique.';
            }
        }
        if ($label === '') {
            $errors['label'] = 'Le libellé est obligatoire.';
        } elseif (mb_strlen($label, 'UTF-8') > 120) {
            $errors['label'] = 'Le libellé ne doit pas dépasser 120 caractères.';
        }
        if (mb_strlen($description, 'UTF-8') > 1000) {
            $errors['description'] = 'La description ne doit pas dépasser 1000 caractères.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return ['name' => $name, 'label' => $label, 'description' => $description === '' ? null : $description];
    }

    /** @param array{name: string, label: string, description: ?string} $data */
    public function create(array $data): int
    {
        return $this->ctx->users->createGroup($data['name'], $data['label'], $data['description']);
    }

    /** @param array{name: string, label: string, description: ?string} $data */
    public function update(int $id, array $data): void
    {
        $this->get($id);
        $this->ctx->users->updateGroup($id, $data['label'], $data['description']);
        $this->ctx->acl->clearCache();
    }

    /** Suppression d'un groupe non système (ses règles ACL et appartenances sont supprimées). */
    public function delete(int $id): array
    {
        $group = $this->get($id);
        if ((int) $group['is_system'] === 1) {
            throw new ConflictException('Le groupe système « ' . $group['label'] . ' » ne peut pas être supprimé.');
        }
        $this->guard->protect(fn () => $this->ctx->users->deleteGroup($id), 'La suppression de ce groupe retirerait le dernier accès d’administration : elle est refusée.');
        return $group;
    }

    public function addMember(int $groupId, int $userId): array
    {
        $group = $this->get($groupId);
        $user = $this->ctx->users->find($userId);
        if ($user === null) {
            throw new NotFoundException('Ce compte utilisateur n’existe pas.');
        }
        $this->ctx->users->addToGroup($userId, $groupId);
        $this->ctx->acl->clearCache();
        return ['group' => $group, 'user' => $user];
    }

    public function removeMember(int $groupId, int $userId): array
    {
        $group = $this->get($groupId);
        $user = $this->ctx->users->find($userId);
        if ($user === null) {
            throw new NotFoundException('Ce compte utilisateur n’existe pas.');
        }
        $this->guard->protect(function () use ($groupId, $userId): void {
            $this->ctx->db->delete('user_groups', 'user_id = :u AND group_id = :g', ['u' => $userId, 'g' => $groupId]);
        }, 'Retirer ce membre supprimerait le dernier accès d’administration : opération refusée.');
        return ['group' => $group, 'user' => $user];
    }

    /**
     * Duplique un groupe : copie de toutes ses règles ACL, et de ses membres si demandé.
     *
     * @param array{name: string, label: string, description: ?string} $data
     * @return int identifiant du nouveau groupe
     */
    public function duplicate(int $sourceId, array $data, bool $copyMembers, ?int $actorId): int
    {
        $this->get($sourceId);
        return $this->ctx->db->transaction(function () use ($sourceId, $data, $copyMembers, $actorId): int {
            $newId = $this->ctx->users->createGroup($data['name'], $data['label'], $data['description']);
            foreach ($this->ctx->acl->rulesForSubject('group', $sourceId) as $rule) {
                $this->ctx->acl->setRule('group', $newId, (string) $rule['resource'], (string) $rule['permission'], (string) $rule['effect'], $actorId, $rule['comment'] ?? null);
            }
            if ($copyMembers) {
                foreach ($this->ctx->users->membersOf($sourceId) as $member) {
                    $this->ctx->users->addToGroup((int) $member['id'], $newId);
                }
            }
            $this->ctx->acl->clearCache();
            return $newId;
        });
    }
}
