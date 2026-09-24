<?php

declare(strict_types=1);

namespace Atelier\Kernel;

use Atelier\Http\Request;
use Atelier\Security\Acl\AclService;

/**
 * Données de démonstration : compte administrateur initial, groupes, règles ACL de départ,
 * comptes d'exemple, puis données d'exemple de chaque module (hook seed() optionnel).
 * Idempotent : ne recrée pas ce qui existe déjà.
 */
final class Seeder
{
    public const ADMIN_USERNAME = 'admin';
    public const ADMIN_PASSWORD = '123456789azerty';

    public function __construct(private readonly Application $app)
    {
    }

    /** @return list<string> */
    public function run(): array
    {
        $log = [];
        $users = $this->app->users;
        $acl = $this->app->acl;

        $admins = $users->findGroupByName('admins');
        $members = $users->findGroupByName('users');
        if ($admins === null || $members === null) {
            throw new \RuntimeException('Groupes système absents : lancez db:migrate.');
        }
        $adminsId = (int) $admins['id'];
        $usersId = (int) $members['id'];

        // Compte administrateur initial
        $admin = $users->findByUsername(self::ADMIN_USERNAME);
        if ($admin === null) {
            $id = $users->create([
                'username' => self::ADMIN_USERNAME,
                'display_name' => 'Administrateur',
                'email' => null,
                'password_hash' => $this->app->passwords->hash(self::ADMIN_PASSWORD),
                'must_change_password' => 1,
            ]);
            $users->addToGroup($id, $adminsId);
            $log[] = 'Compte administrateur "admin" créé.';
        } else {
            $users->addToGroup((int) $admin['id'], $adminsId);
            $log[] = 'Compte administrateur "admin" déjà présent.';
        }

        // Règles ACL de départ
        $acl->setRule('group', $adminsId, AclService::ROOT, 'admin', 'allow', null, 'Administration complète');
        $acl->setRule('all', null, AclService::ROOT, 'view', 'allow', null, 'Tous les connectés voient les modules');
        foreach (['home', 'notes', 'profile', 'demo', 'geo', 'attachments', 'news', 'map', 'wiki'] as $moduleId) {
            if ($this->app->modules->has($moduleId)) {
                $acl->setRule('group', $usersId, AclService::module($moduleId), 'open', 'allow', null, 'Accès de base');
                $acl->setRule('group', $usersId, AclService::module($moduleId), 'read', 'allow');
                $acl->setRule('group', $usersId, AclService::module($moduleId), 'create', 'allow');
                $acl->setRule('group', $usersId, AclService::module($moduleId), 'update', 'allow');
                $acl->setRule('group', $usersId, AclService::module($moduleId), 'delete', 'allow');
            }
        }
        $log[] = 'Règles ACL de départ appliquées.';

        // Aucun compte d'exemple : Atelier n'a qu'un utilisateur, son propriétaire. Les trois
        // comptes créés auparavant (dont une administratrice) portaient un mot de passe publié
        // dans le dépôt et ne demandaient pas de le changer : un `db:seed` lancé sur un serveur
        // ouvrait donc trois accès connus de tous. Les tests créent leurs propres comptes.

        // Données d'exemple des modules
        $context = $this->app->context(Request::create('GET', '/'));
        foreach ($this->app->modules->all() as $descriptor) {
            if (!$descriptor->isValid()) {
                continue;
            }
            $module = $this->app->modules->instance($descriptor->id);
            if (method_exists($module, 'seed')) {
                $module->boot($context);
                $result = $module->seed();
                $log[] = 'Module ' . $descriptor->id . ' : ' . (is_string($result) ? $result : 'données d’exemple créées');
            }
        }
        $acl->clearCache();
        return $log;
    }
}
