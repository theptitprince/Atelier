<?php

declare(strict_types=1);

namespace Atelier\Modules\Users;

use Atelier\Error\ConflictException;
use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Modules\ModuleContext;
use Atelier\Security\PasswordPolicy;
use Atelier\Support\Clock;

/**
 * Règles métier des comptes : validation, création, mise à jour, mot de passe, état, suppression.
 * Toutes les écritures passent par UserRepository (noyau) ; les opérations sensibles sont
 * protégées par RootAdminGuard.
 */
final class AccountManager
{
    public const USERNAME_PATTERN = '/^[a-zA-Z0-9._-]{2,64}$/';

    public function __construct(
        private readonly ModuleContext $ctx,
        private readonly PasswordPolicy $passwords,
        private readonly RootAdminGuard $guard,
    ) {
    }

    public function passwords(): PasswordPolicy
    {
        return $this->passwords;
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        $user = $this->ctx->users->find($id);
        if ($user === null) {
            throw new NotFoundException('Ce compte utilisateur n’existe pas (ou plus).');
        }
        return $user;
    }

    /**
     * Valide les champs communs d'un compte (création ou édition).
     *
     * @param array<string, mixed> $input username, display_name, email, status, groups
     * @return array{username: string, display_name: string, email: ?string, status: string, groups: list<int>}
     */
    public function validate(array $input, ?int $existingId = null): array
    {
        $errors = [];
        $username = trim((string) ($input['username'] ?? ''));
        $displayName = trim((string) ($input['display_name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $status = (string) ($input['status'] ?? 'active');

        if ($username === '') {
            $errors['username'] = 'L’identifiant est obligatoire.';
        } elseif (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            $errors['username'] = 'L’identifiant doit comporter de 2 à 64 caractères : lettres, chiffres, point, tiret ou souligné.';
        } elseif ($this->ctx->users->usernameExists($username, $existingId)) {
            $errors['username'] = 'Cet identifiant est déjà utilisé par un autre compte.';
        }
        if ($displayName === '') {
            $errors['display_name'] = 'Le nom affiché est obligatoire.';
        } elseif (mb_strlen($displayName, 'UTF-8') > 120) {
            $errors['display_name'] = 'Le nom affiché ne doit pas dépasser 120 caractères.';
        }
        if ($email !== '' && (mb_strlen($email, 'UTF-8') > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'] = 'L’adresse de courriel est invalide.';
        }
        if (!in_array($status, ['active', 'disabled'], true)) {
            $errors['status'] = 'L’état doit être « actif » ou « désactivé ».';
        }

        $groups = [];
        $known = array_map(static fn (array $g): int => (int) $g['id'], $this->ctx->users->allGroups());
        foreach ((array) ($input['groups'] ?? []) as $groupId) {
            if (!is_numeric($groupId)) {
                continue;
            }
            $groupId = (int) $groupId;
            if (!in_array($groupId, $known, true)) {
                $errors['groups'] = 'Un des groupes sélectionnés n’existe pas.';
                continue;
            }
            $groups[] = $groupId;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'username' => $username,
            'display_name' => $displayName,
            'email' => $email === '' ? null : $email,
            'status' => $status,
            'groups' => array_values(array_unique($groups)),
        ];
    }

    /**
     * Crée un compte. Si aucun mot de passe n'est fourni, un mot de passe temporaire est généré.
     *
     * @param array{username: string, display_name: string, email: ?string, status: string, groups: list<int>} $data
     * @return array{id: int, temporaryPassword: ?string}
     */
    public function create(array $data, string $password, bool $mustChange): array
    {
        $generated = null;
        if ($password === '') {
            $generated = $this->passwords->generateTemporary();
            $password = $generated;
            $mustChange = true;
        } else {
            $this->assertPasswordValid($password, $data['username']);
        }
        $id = $this->ctx->db->transaction(function () use ($data, $password, $mustChange): int {
            $id = $this->ctx->users->create([
                'username' => $data['username'],
                'display_name' => $data['display_name'],
                'email' => $data['email'],
                'password_hash' => $this->passwords->hash($password),
                'status' => $data['status'],
                'must_change_password' => $mustChange ? 1 : 0,
                'password_changed_at' => $mustChange ? null : Clock::utc(),
            ]);
            if ($data['groups'] !== []) {
                $this->ctx->users->setGroups($id, $data['groups']);
            }
            return $id;
        });
        $this->ctx->acl->clearCache();
        return ['id' => $id, 'temporaryPassword' => $generated];
    }

    /**
     * Met à jour un compte existant (identité, état, groupes), sous protection du dernier administrateur.
     *
     * @param array{username: string, display_name: string, email: ?string, status: string, groups: list<int>} $data
     * @return array<string, string> champs modifiés (ancien => nouveau, sans secret) pour le journal
     */
    public function update(int $id, array $data, int $actorId): array
    {
        $user = $this->get($id);
        if ($id === $actorId && $data['status'] !== 'active') {
            throw new ValidationException(['status' => 'Vous ne pouvez pas désactiver votre propre compte.']);
        }
        $changes = [];
        foreach (['username', 'display_name', 'email', 'status'] as $field) {
            if (($user[$field] ?? null) !== $data[$field]) {
                $changes[$field] = (string) ($user[$field] ?? '') . ' → ' . (string) ($data[$field] ?? '');
            }
        }
        $currentGroups = array_map(static fn (array $g): int => (int) $g['id'], $this->ctx->users->groupsOf($id));
        sort($currentGroups);
        $newGroups = $data['groups'];
        sort($newGroups);
        if ($currentGroups !== $newGroups) {
            $changes['groups'] = implode(',', $currentGroups) . ' → ' . implode(',', $newGroups);
        }

        $this->guard->protect(function () use ($id, $user, $data, $newGroups, $currentGroups): void {
            $fields = [
                'username' => $data['username'],
                'display_name' => $data['display_name'],
                'email' => $data['email'],
                'status' => $data['status'],
            ];
            if ($data['status'] === 'disabled' && $user['status'] !== 'disabled') {
                $fields['disabled_at'] = Clock::utc();
            } elseif ($data['status'] === 'active') {
                $fields['disabled_at'] = null;
            }
            $this->ctx->users->update($id, $fields);
            if ($currentGroups !== $newGroups) {
                $this->ctx->users->setGroups($id, $newGroups);
            }
        }, 'Cette modification retirerait le dernier accès d’administration (groupe ou état du compte) : elle est refusée.');
        return $changes;
    }

    /** Génère et applique un mot de passe temporaire ; le compte devra le changer à la prochaine connexion. */
    public function resetPassword(int $id): string
    {
        $this->get($id);
        $temporary = $this->passwords->generateTemporary();
        $this->ctx->users->setPassword($id, $this->passwords->hash($temporary), true);
        return $temporary;
    }

    public function disable(int $id, int $actorId): void
    {
        $user = $this->get($id);
        if ($id === $actorId) {
            throw new ConflictException('Vous ne pouvez pas désactiver votre propre compte.');
        }
        if ($user['status'] === 'disabled') {
            throw new ConflictException('Ce compte est déjà désactivé.');
        }
        $this->guard->protect(fn () => $this->ctx->users->disable($id), 'Impossible de désactiver le dernier administrateur actif.');
    }

    public function enable(int $id): void
    {
        $user = $this->get($id);
        if ($user['status'] === 'active') {
            throw new ConflictException('Ce compte est déjà actif.');
        }
        $this->ctx->users->enable($id);
        $this->ctx->acl->clearCache();
    }

    public function unlock(int $id): void
    {
        $user = $this->get($id);
        if (!AccountRepository::isLocked($user) && (int) $user['failed_attempts'] === 0) {
            throw new ConflictException('Ce compte n’est pas bloqué.');
        }
        $this->ctx->users->unlock($id);
    }

    /** Suppression définitive (interdite pour soi-même et pour le dernier administrateur racine). */
    public function delete(int $id, int $actorId): array
    {
        $user = $this->get($id);
        if ($id === $actorId) {
            throw new ConflictException('Vous ne pouvez pas supprimer votre propre compte.');
        }
        $this->guard->protect(function () use ($id): void {
            $this->ctx->db->delete('acl_rules', "subject_type = 'user' AND subject_id = :id", ['id' => $id]);
            $this->ctx->users->delete($id);
        }, 'Impossible de supprimer le dernier administrateur actif.');
        return $user;
    }

    private function assertPasswordValid(string $password, string $username): void
    {
        $messages = $this->passwords->validate($password, $username);
        if ($messages !== []) {
            throw new ValidationException(['password' => $messages[0]]);
        }
    }
}
