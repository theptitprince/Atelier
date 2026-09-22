<?php

declare(strict_types=1);

namespace Atelier\Modules\Users;

use Atelier\Error\ModuleUnavailableException;
use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Http\Response;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleContext;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Security\Acl\AclService;
use Atelier\Security\PasswordPolicy;
use Atelier\Support\Clock;

/**
 * Module « Utilisateurs et droits » : comptes, groupes et règles ACL.
 *
 * Les gestionnaires de routes ne contiennent aucun SQL : ils s'appuient sur les dépôts du noyau
 * ($this->ctx->users, $this->ctx->acl) et sur les classes de service du module (src/).
 * Chaque action vérifie ses droits côté serveur, indépendamment de l'affichage des boutons.
 */
final class UsersModule extends AbstractModule
{
    private const PER_PAGE_DEFAULT = 25;
    private const PER_PAGE_MAX = 100;
    private const PER_PAGE_CHOICES = [25, 50, 100];

    private ?AccountRepository $accounts = null;
    private ?ResourceRepository $resources = null;
    private ?AccountManager $accountManager = null;
    private ?GroupManager $groupManager = null;
    private ?AclAdmin $aclAdmin = null;
    private ?RootAdminGuard $guard = null;

    public function boot(ModuleContext $context): void
    {
        parent::boot($context);
        $this->accounts = null;
        $this->resources = null;
        $this->accountManager = null;
        $this->groupManager = null;
        $this->aclAdmin = null;
        $this->guard = null;
    }

    public function routes(RouteCollection $r): void
    {
        // Comptes
        $r->view('list', [$this, 'list'], permission: 'open');
        $r->view('new', [$this, 'new'], permission: 'create');
        $r->view('edit/{id}', [$this, 'edit'], permission: 'update');
        $r->action('filter', [$this, 'filter'], permission: 'open');
        $r->action('save', [$this, 'save'], permission: 'open'); // create ou update vérifié dans le gestionnaire
        $r->action('reset-password', [$this, 'resetPassword'], permission: 'update');
        $r->action('disable', [$this, 'disable'], permission: 'update');
        $r->action('enable', [$this, 'enable'], permission: 'update');
        $r->action('unlock', [$this, 'unlock'], permission: 'update');
        $r->action('delete', [$this, 'delete'], permission: 'delete');
        $r->action('rules/add', [$this, 'userRuleAdd'], permission: 'admin');
        $r->action('rules/remove', [$this, 'userRuleRemove'], permission: 'admin');
        $r->raw('export.csv', [$this, 'exportCsv'], permission: 'export');

        // Groupes
        $r->view('groups', [$this, 'groups'], permission: 'open');
        $r->view('groups/new', [$this, 'groupNew'], permission: 'create');
        $r->view('groups/edit/{id}', [$this, 'groupEdit'], permission: 'update');
        $r->action('groups/save', [$this, 'groupSave'], permission: 'open'); // create ou update vérifié dans le gestionnaire
        $r->action('groups/delete', [$this, 'groupDelete'], permission: 'delete');
        $r->action('groups/members/add', [$this, 'groupMemberAdd'], permission: 'update');
        $r->action('groups/members/remove', [$this, 'groupMemberRemove'], permission: 'update');
        $r->action('groups/duplicate', [$this, 'groupDuplicate'], permission: 'create');

        // ACL
        $r->view('acl', [$this, 'acl'], permission: 'admin');
        $r->action('acl/rules/add', [$this, 'aclRuleAdd'], permission: 'admin');
        $r->action('acl/rules/remove', [$this, 'aclRuleRemove'], permission: 'admin');
        $r->action('acl/test', [$this, 'aclTest'], permission: 'admin');
    }

    /**
     * Service intermodule du jeu partagé users.account.
     * Le module doit avoir été démarré (boot) : sans contexte, l'erreur est explicite plutôt qu'une erreur fatale.
     */
    public function service(): ?object
    {
        if (!isset($this->ctx)) {
            throw new ModuleUnavailableException('Le module « users » doit être démarré (boot) avant d’exposer son service.', $this->id());
        }
        return new UsersService($this->ctx);
    }

    /** Les comptes de démonstration sont créés par le Seeder du noyau. */
    public function seed(): string
    {
        return 'aucune donnée supplémentaire (comptes créés par le noyau)';
    }

    // =====================================================================
    // Comptes : vues
    // =====================================================================

    public function list(Request $request, array $params): ModuleView
    {
        $query = $this->listQuery($request);
        $result = $this->accounts()->paginate(
            ['search' => $query['search'], 'status' => $query['status'], 'group_id' => $query['group']],
            $query['page'],
            $query['per_page'],
            $query['sort'],
            $query['dir']
        );
        $rights = $this->rights(['create', 'update', 'delete', 'export', 'admin']);
        $counts = $this->accounts()->countByStatus();
        $groups = $this->ctx->users->allGroups();

        $filterQuery = $this->filterQuery($query);
        $content = $this->render('list', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'query' => $query,
            'filterQuery' => $filterQuery,
            'groups' => $groups,
            'rights' => $rights,
            'currentUserId' => $this->ctx->userId(),
            'perPageChoices' => self::PER_PAGE_CHOICES,
            'statusFilters' => UserPresenter::STATUS_LABELS,
            'counts' => $counts,
        ]);

        $actions = '<a class="btn btn--ghost" href="#" data-route="' . $this->e($this->listRoute($query)) . '" title="Actualiser">' . $this->icon('refresh') . '<span>Actualiser</span></a>';
        if ($rights['export']) {
            $actions .= '<a class="btn" href="' . $this->e($this->url('export.csv') . ($filterQuery ? '?' . http_build_query($filterQuery) : '')) . '" download title="Exporter la liste filtrée au format CSV">' . $this->icon('download') . '<span>Exporter CSV</span></a>';
        }
        if ($rights['create']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="new">' . $this->icon('plus') . '<span>Ajouter</span></a>';
        }
        $banner = $this->renderCore('banner', [
            'icon' => 'users',
            'title' => 'Utilisateurs',
            'subtitle' => sprintf('%d compte(s) · %d actif(s) · %d désactivé(s)', array_sum([$counts['active'], $counts['disabled']]), $counts['active'], $counts['disabled']),
            'actions' => $actions,
        ]);
        $status = $result['total'] === 0 ? 'Aucun compte ne correspond aux filtres' : sprintf('%d compte(s) affiché(s) sur %d', count($result['rows']), $result['total']);

        return ModuleView::make('Utilisateurs')->banner($banner)->content($content)->status($status)->route($this->listRoute($query));
    }

    public function new(Request $request, array $params): ModuleView
    {
        $usersGroup = $this->ctx->users->findGroupByName('users');
        $user = [
            'id' => null,
            'username' => '',
            'display_name' => '',
            'email' => '',
            'status' => 'active',
            'must_change_password' => 1,
            'groups' => $usersGroup !== null ? [$usersGroup] : [],
        ];
        $content = $this->render('form', [
            'user' => $user,
            'isNew' => true,
            'groups' => $this->ctx->users->allGroups(),
            'memberGroupIds' => array_map(static fn (array $g): int => (int) $g['id'], $user['groups']),
            'rights' => $this->rights(['create', 'update', 'delete', 'admin']),
            'generatedPassword' => $this->passwords()->generateTemporary(),
            'passwordMinLength' => $this->passwords()->minLength(),
            'tab' => 'account',
            'currentUserId' => $this->ctx->userId(),
        ]);
        $banner = $this->renderCore('banner', [
            'icon' => 'user',
            'title' => 'Nouvel utilisateur',
            'subtitle' => 'Création d’un compte',
            'actions' => '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('chevron-left') . '<span>Retour à la liste</span></a>',
        ]);
        return ModuleView::make('Nouvel utilisateur')->banner($banner)->content($content)->status('Création d’un compte');
    }

    public function edit(Request $request, array $params): ModuleView
    {
        $id = (int) ($params['id'] ?? 0);
        $user = $this->accountManager()->get($id);
        $user['groups'] = $this->ctx->users->groupsOf($id);
        $rights = $this->rights(['create', 'update', 'delete', 'admin']);
        $tab = $request->query('tab') === 'rights' && $rights['admin'] ? 'rights' : 'account';

        $vars = [
            'user' => $user,
            'isNew' => false,
            'groups' => $this->ctx->users->allGroups(),
            'memberGroupIds' => array_map(static fn (array $g): int => (int) $g['id'], $user['groups']),
            'rights' => $rights,
            'generatedPassword' => null,
            'passwordMinLength' => $this->passwords()->minLength(),
            'tab' => $tab,
            'currentUserId' => $this->ctx->userId(),
            'isRootAdmin' => $this->guard()->isRootAdmin($id),
            'rightsHtml' => '',
        ];
        if ($rights['admin']) {
            $vars['rightsHtml'] = $this->renderUserRights($user, (string) ($request->query('resource') ?? ''));
        }
        $content = $this->render('form', $vars);

        $actions = '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('chevron-left') . '<span>Liste</span></a>';
        $actions .= $this->quickActions($user, $rights, $this->ctx->userId(), false);
        $banner = $this->renderCore('banner', [
            'icon' => 'user',
            'title' => (string) $user['display_name'],
            'subtitle' => '@' . $user['username'] . ' · ' . (UserPresenter::STATUS_LABELS[$user['status']] ?? $user['status']),
            'actions' => $actions,
        ]);
        $route = 'edit/' . $id . ($tab === 'rights' ? '?' . http_build_query(array_filter(['tab' => 'rights', 'resource' => $request->query('resource')])) : '');
        return ModuleView::make('Utilisateur ' . $user['username'])->banner($banner)->content($content)->status('Fiche de ' . $user['display_name'])->route($route);
    }

    // =====================================================================
    // Comptes : actions
    // =====================================================================

    /** Formulaire de filtres (data-auto-submit) : redirige vers la liste avec la chaîne de requête. */
    public function filter(Request $request, array $params): ActionResult
    {
        $query = $this->listQuery($request);
        $query['page'] = 1;
        return ActionResult::ok()->navigate($this->listRoute($query));
    }

    public function save(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $manager = $this->accountManager();
        $input = [
            'username' => $request->string('username'),
            'display_name' => $request->string('display_name'),
            'email' => $request->string('email'),
            'status' => $request->string('status', 'active'),
            'groups' => $request->arrayInput('groups'),
        ];

        if ($id === null || $id <= 0) {
            $this->require('create', null, 'Vous n’avez pas le droit de créer des comptes.');
            $data = $manager->validate($input);
            $mustChange = $request->bool('must_change_password', true);
            $rawPassword = $request->input('password', '');
            $created = $manager->create($data, is_string($rawPassword) ? $rawPassword : '', $mustChange);
            $this->log('user.create', 'success', 'user:' . $created['id'], 'Compte créé', ['username' => $data['username'], 'status' => $data['status'], 'groups' => $data['groups'], 'must_change_password' => $mustChange]);
            $message = 'Compte « ' . $data['username'] . ' » créé.';
            $payload = ['id' => $created['id']];
            if ($created['temporaryPassword'] !== null) {
                $payload['temporaryPassword'] = $created['temporaryPassword'];
                $payload['username'] = $data['username'];
                $message .= ' Mot de passe temporaire : ' . $created['temporaryPassword'] . ' (affiché une seule fois).';
            }
            return ActionResult::ok($payload, $message)->navigate('edit/' . $created['id']);
        }

        $this->require('update', null, 'Vous n’avez pas le droit de modifier des comptes.');
        $data = $manager->validate($input, $id);
        $changes = $manager->update($id, $data, $this->ctx->userId());
        $this->log('user.update', 'success', 'user:' . $id, 'Compte modifié', ['username' => $data['username'], 'changes' => $changes]);
        return ActionResult::ok(['id' => $id], $changes === [] ? 'Aucune modification à enregistrer.' : 'Compte « ' . $data['username'] . ' » enregistré.')->navigate('edit/' . $id);
    }

    public function resetPassword(Request $request, array $params): ActionResult
    {
        $id = $this->requireId($request);
        $user = $this->accountManager()->get($id);
        $temporary = $this->accountManager()->resetPassword($id);
        $this->log('user.password_reset', 'success', 'user:' . $id, 'Mot de passe temporaire généré', ['username' => $user['username']]);
        return ActionResult::ok(
            ['id' => $id, 'username' => $user['username'], 'temporaryPassword' => $temporary],
            'Mot de passe temporaire de « ' . $user['username'] . ' » : ' . $temporary . ' — notez-le, il ne sera plus affiché.'
        )->refresh();
    }

    public function disable(Request $request, array $params): ActionResult
    {
        $id = $this->requireId($request);
        $this->accountManager()->disable($id, $this->ctx->userId());
        $user = $this->accountManager()->get($id);
        $this->log('user.disable', 'success', 'user:' . $id, 'Compte désactivé', ['username' => $user['username']]);
        return ActionResult::ok(['id' => $id], 'Compte « ' . $user['username'] . ' » désactivé.')->refresh();
    }

    public function enable(Request $request, array $params): ActionResult
    {
        $id = $this->requireId($request);
        $this->accountManager()->enable($id);
        $user = $this->accountManager()->get($id);
        $this->log('user.enable', 'success', 'user:' . $id, 'Compte réactivé', ['username' => $user['username']]);
        return ActionResult::ok(['id' => $id], 'Compte « ' . $user['username'] . ' » réactivé.')->refresh();
    }

    public function unlock(Request $request, array $params): ActionResult
    {
        $id = $this->requireId($request);
        $this->accountManager()->unlock($id);
        $user = $this->accountManager()->get($id);
        $this->log('user.unlock', 'success', 'user:' . $id, 'Compte déverrouillé', ['username' => $user['username']]);
        return ActionResult::ok(['id' => $id], 'Compte « ' . $user['username'] . ' » déverrouillé.')->refresh();
    }

    public function delete(Request $request, array $params): ActionResult
    {
        $id = $this->requireId($request);
        $user = $this->accountManager()->delete($id, $this->ctx->userId());
        $this->log('user.delete', 'success', 'user:' . $id, 'Compte supprimé définitivement', ['username' => $user['username']]);
        return ActionResult::ok(null, 'Compte « ' . $user['username'] . ' » supprimé définitivement.')->navigate('list');
    }

    /** Ajout d'une règle ACL directe depuis la fiche d'un utilisateur. */
    public function userRuleAdd(Request $request, array $params): ActionResult
    {
        $id = $this->requireId($request, 'user_id');
        $user = $this->accountManager()->get($id);
        $result = $this->aclAdmin()->addRule('user:' . $id, $request->string('resource'), $request->string('permission'), $request->string('effect', 'allow'), $request->string('comment'), $this->ctx->userId());
        $this->log('acl.rule_add', 'success', 'acl_rule:' . $result['id'], 'Règle directe ajoutée', ['username' => $user['username'], 'resource' => $request->string('resource'), 'permission' => $request->string('permission'), 'effect' => $request->string('effect', 'allow')]);
        return ActionResult::ok(['id' => $result['id']], 'Règle enregistrée pour ' . $user['username'] . '.')->navigate('edit/' . $id . '?tab=rights');
    }

    public function userRuleRemove(Request $request, array $params): ActionResult
    {
        $id = $this->requireId($request, 'user_id');
        $ruleId = $this->requireId($request, 'rule_id');
        $rule = $this->ctx->acl->rule($ruleId);
        if ($rule === null || $rule['subject_type'] !== 'user' || (int) $rule['subject_id'] !== $id) {
            throw new NotFoundException('Cette règle n’appartient pas à cet utilisateur.');
        }
        $removed = $this->aclAdmin()->removeRule($ruleId);
        $this->log('acl.rule_remove', 'success', 'acl_rule:' . $ruleId, 'Règle directe supprimée', ['user_id' => $id, 'resource' => $removed['resource'], 'permission' => $removed['permission'], 'effect' => $removed['effect']]);
        return ActionResult::ok(null, 'Règle supprimée.')->navigate('edit/' . $id . '?tab=rights');
    }

    /** Export CSV de la liste filtrée (séparateur « ; », UTF-8 avec BOM pour les tableurs). */
    public function exportCsv(Request $request, array $params): Response
    {
        $query = $this->listQuery($request);
        $rows = $this->accounts()->export(['search' => $query['search'], 'status' => $query['status'], 'group_id' => $query['group']], $query['sort'], $query['dir']);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Impossible de préparer l’export.');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Identifiant', 'Nom affiché', 'Courriel', 'État', 'Bloqué', 'Mot de passe temporaire', 'Groupes', 'Dernière connexion', 'Créé le'], ';', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['username'],
                $row['display_name'],
                $row['email'] ?? '',
                UserPresenter::STATUS_LABELS[$row['status']] ?? $row['status'],
                AccountRepository::isLocked($row) ? 'oui' : 'non',
                (int) $row['must_change_password'] === 1 ? 'oui' : 'non',
                implode(', ', array_map(static fn (array $g): string => (string) $g['label'], $row['groups'])),
                Clock::formatDateTime($row['last_login_at'] ?? null, ''),
                Clock::formatDateTime($row['created_at'] ?? null, ''),
            ], ';', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);
        $this->log('user.export', 'success', null, 'Export CSV des utilisateurs', ['count' => count($rows), 'filters' => $this->filterQuery($query)]);
        return Response::raw($csv, 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="utilisateurs-' . Clock::now()->format('Ymd-Hi') . '.csv"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    // =====================================================================
    // Groupes
    // =====================================================================

    public function groups(Request $request, array $params): ModuleView
    {
        $groups = $this->ctx->users->allGroups();
        $rights = $this->rights(['create', 'update', 'delete', 'admin']);
        $content = $this->render('groups', ['groups' => $groups, 'rights' => $rights]);
        $actions = '<a class="btn btn--ghost" href="#" data-route="groups">' . $this->icon('refresh') . '<span>Actualiser</span></a>';
        if ($rights['create']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="groups/new">' . $this->icon('plus') . '<span>Nouveau groupe</span></a>';
        }
        $banner = $this->renderCore('banner', ['icon' => 'layers', 'title' => 'Groupes', 'subtitle' => count($groups) . ' groupe(s)', 'actions' => $actions]);
        return ModuleView::make('Groupes')->banner($banner)->content($content)->status(count($groups) . ' groupe(s)');
    }

    public function groupNew(Request $request, array $params): ModuleView
    {
        $content = $this->render('group_form', [
            'group' => ['id' => null, 'name' => '', 'label' => '', 'description' => '', 'is_system' => 0],
            'isNew' => true,
            'members' => [],
            'candidates' => [],
            'rules' => [],
            'rights' => $this->rights(['create', 'update', 'delete', 'admin']),
        ]);
        $banner = $this->renderCore('banner', [
            'icon' => 'layers',
            'title' => 'Nouveau groupe',
            'actions' => '<a class="btn btn--ghost" href="#" data-route="groups">' . $this->icon('chevron-left') . '<span>Groupes</span></a>',
        ]);
        return ModuleView::make('Nouveau groupe')->banner($banner)->content($content)->status('Création d’un groupe');
    }

    public function groupEdit(Request $request, array $params): ModuleView
    {
        $id = (int) ($params['id'] ?? 0);
        $group = $this->groupManager()->get($id);
        $rights = $this->rights(['create', 'update', 'delete', 'admin']);
        $members = $this->ctx->users->membersOf($id);
        $rules = $rights['admin'] ? $this->decorateRules($this->ctx->acl->rulesForSubject('group', $id)) : [];
        $content = $this->render('group_form', [
            'group' => $group,
            'isNew' => false,
            'members' => $members,
            'candidates' => $this->accounts()->notInGroup($id),
            'rules' => $rules,
            'rights' => $rights,
        ]);
        $actions = '<a class="btn btn--ghost" href="#" data-route="groups">' . $this->icon('chevron-left') . '<span>Groupes</span></a>';
        if ($rights['delete'] && (int) $group['is_system'] !== 1) {
            $actions .= '<button type="button" class="btn btn--outline-danger" data-action="groups/delete" data-params=\'' . $this->e(json_encode(['id' => $id])) . '\' data-confirm="Supprimer définitivement le groupe « ' . $this->e($group['label']) . ' », ses appartenances et ses règles ACL ?" data-danger>' . $this->icon('trash') . '<span>Supprimer</span></button>';
        }
        $banner = $this->renderCore('banner', [
            'icon' => 'layers',
            'title' => (string) $group['label'],
            'subtitle' => $group['name'] . ' · ' . count($members) . ' membre(s)' . ((int) $group['is_system'] === 1 ? ' · groupe système' : ''),
            'actions' => $actions,
        ]);
        return ModuleView::make('Groupe ' . $group['label'])->banner($banner)->content($content)->status(count($members) . ' membre(s)');
    }

    public function groupSave(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $manager = $this->groupManager();
        $input = ['name' => $request->string('name'), 'label' => $request->string('label'), 'description' => $request->string('description')];
        if ($id === null || $id <= 0) {
            $this->require('create', null, 'Vous n’avez pas le droit de créer des groupes.');
            $data = $manager->validate($input);
            $newId = $manager->create($data);
            $this->log('group.create', 'success', 'group:' . $newId, 'Groupe créé', ['name' => $data['name'], 'label' => $data['label']]);
            return ActionResult::ok(['id' => $newId], 'Groupe « ' . $data['label'] . ' » créé.')->navigate('groups/edit/' . $newId);
        }
        $this->require('update', null, 'Vous n’avez pas le droit de modifier des groupes.');
        $data = $manager->validate($input, $id);
        $manager->update($id, $data);
        $this->log('group.update', 'success', 'group:' . $id, 'Groupe modifié', ['label' => $data['label']]);
        return ActionResult::ok(['id' => $id], 'Groupe « ' . $data['label'] . ' » enregistré.')->navigate('groups/edit/' . $id);
    }

    public function groupDelete(Request $request, array $params): ActionResult
    {
        $id = $this->requireId($request);
        $group = $this->groupManager()->delete($id);
        $this->log('group.delete', 'success', 'group:' . $id, 'Groupe supprimé', ['name' => $group['name'], 'label' => $group['label']]);
        return ActionResult::ok(null, 'Groupe « ' . $group['label'] . ' » supprimé.')->navigate('groups');
    }

    public function groupMemberAdd(Request $request, array $params): ActionResult
    {
        $groupId = $this->requireId($request, 'group_id');
        $userId = $request->int('user_id');
        if ($userId === null || $userId <= 0) {
            throw new ValidationException(['user_id' => 'Choisissez un utilisateur à ajouter.']);
        }
        $result = $this->groupManager()->addMember($groupId, $userId);
        $this->log('group.member_add', 'success', 'group:' . $groupId, 'Membre ajouté au groupe', ['group' => $result['group']['name'], 'username' => $result['user']['username']]);
        return ActionResult::ok(null, $result['user']['display_name'] . ' ajouté(e) au groupe « ' . $result['group']['label'] . ' ».')->refresh();
    }

    public function groupMemberRemove(Request $request, array $params): ActionResult
    {
        $groupId = $this->requireId($request, 'group_id');
        $userId = $this->requireId($request, 'user_id');
        $result = $this->groupManager()->removeMember($groupId, $userId);
        $this->log('group.member_remove', 'success', 'group:' . $groupId, 'Membre retiré du groupe', ['group' => $result['group']['name'], 'username' => $result['user']['username']]);
        return ActionResult::ok(null, $result['user']['display_name'] . ' retiré(e) du groupe « ' . $result['group']['label'] . ' ».')->refresh();
    }

    /** Duplication d'un profil : copie des règles ACL du groupe, membres en option. */
    public function groupDuplicate(Request $request, array $params): ActionResult
    {
        $sourceId = $this->requireId($request);
        $source = $this->groupManager()->get($sourceId);
        $name = $request->string('name');
        $label = $request->string('label');
        if ($label === '') {
            $label = $source['label'] . ' (copie)';
        }
        $data = $this->groupManager()->validate(['name' => $name, 'label' => $label, 'description' => $request->string('description') !== '' ? $request->string('description') : (string) ($source['description'] ?? '')]);
        $copyMembers = $request->bool('copy_members', false);
        $newId = $this->groupManager()->duplicate($sourceId, $data, $copyMembers, $this->ctx->userId());
        $this->log('group.duplicate', 'success', 'group:' . $newId, 'Groupe dupliqué', ['source' => $source['name'], 'name' => $data['name'], 'copy_members' => $copyMembers]);
        return ActionResult::ok(['id' => $newId], 'Groupe « ' . $data['label'] . ' » créé à partir de « ' . $source['label'] . ' » (règles copiées' . ($copyMembers ? ', membres copiés' : '') . ').')->navigate('groups/edit/' . $newId);
    }

    // =====================================================================
    // ACL
    // =====================================================================

    public function acl(Request $request, array $params): ModuleView
    {
        $tree = $this->resources()->tree();
        $selectedPath = AclService::normalize((string) ($request->query('resource') ?? AclService::ROOT));
        $selected = $this->resources()->find($selectedPath);
        if ($selected === null) {
            $selected = $this->resources()->find(AclService::ROOT);
            $selectedPath = AclService::ROOT;
        }

        $vars = [
            'tree' => $tree,
            'selected' => $selected,
            'selectedPath' => $selectedPath,
            'openPaths' => $this->openPaths($selectedPath),
            'rules' => [],
            'permissionLabels' => $this->resources()->permissionLabels(),
            'groups' => $this->ctx->users->allGroups(),
            'users' => $this->accounts()->forSelect(),
            'test' => null,
            'testInput' => ['user' => $request->int('test_user'), 'permission' => (string) ($request->query('test_permission') ?? '')],
        ];
        if ($selected !== null) {
            $vars['rules'] = $this->decorateRules($this->ctx->acl->rulesOnResourceWithInheritance($selectedPath));
            $testUser = $request->int('test_user');
            $testPermission = (string) ($request->query('test_permission') ?? '');
            if ($testUser !== null && $testUser > 0 && $testPermission !== '') {
                try {
                    $decision = $this->aclAdmin()->test($testUser, $selectedPath, $testPermission);
                    $vars['test'] = ['decision' => $decision, 'user' => $this->ctx->users->find($testUser), 'candidates' => $this->decorateRules($decision->candidates)];
                } catch (ValidationException $e) {
                    $vars['test'] = ['error' => implode(' ', $e->fieldErrors())];
                }
            }
        }
        $content = $this->render('acl', $vars);
        $banner = $this->renderCore('banner', [
            'icon' => 'shield',
            'title' => 'Droits d’accès (ACL)',
            'subtitle' => $selected !== null ? $selected['label'] . ' — ' . $selectedPath : 'Aucune ressource',
            'actions' => '<a class="btn btn--ghost" href="#" data-route="' . $this->e('acl?resource=' . rawurlencode($selectedPath)) . '">' . $this->icon('refresh') . '<span>Actualiser</span></a>',
        ]);
        $status = count($vars['rules']) . ' règle(s) applicable(s) à ' . $selectedPath;
        return ModuleView::make('Droits d’accès')->banner($banner)->content($content)->status($status)->route('acl?resource=' . rawurlencode($selectedPath))->state(['resource' => $selectedPath]);
    }

    public function aclRuleAdd(Request $request, array $params): ActionResult
    {
        $resource = $request->string('resource');
        $result = $this->aclAdmin()->addRule($request->string('subject'), $resource, $request->string('permission'), $request->string('effect', 'allow'), $request->string('comment'), $this->ctx->userId());
        $this->log('acl.rule_add', 'success', 'acl_rule:' . $result['id'], 'Règle ACL enregistrée', ['subject' => $result['subjectLabel'], 'resource' => AclService::normalize($resource), 'permission' => $request->string('permission'), 'effect' => $request->string('effect', 'allow')]);
        return ActionResult::ok(['id' => $result['id']], 'Règle enregistrée : ' . $result['subjectLabel'] . ' — ' . $request->string('permission') . ' (' . ($request->string('effect', 'allow') === 'allow' ? 'autorise' : 'refuse') . ').')->navigate('acl?resource=' . rawurlencode(AclService::normalize($resource)));
    }

    public function aclRuleRemove(Request $request, array $params): ActionResult
    {
        $ruleId = $this->requireId($request);
        $current = AclService::normalize($request->string('resource', AclService::ROOT));
        $removed = $this->aclAdmin()->removeRule($ruleId);
        $this->log('acl.rule_remove', 'success', 'acl_rule:' . $ruleId, 'Règle ACL supprimée', ['subject_type' => $removed['subject_type'], 'subject_id' => $removed['subject_id'], 'resource' => $removed['resource'], 'permission' => $removed['permission'], 'effect' => $removed['effect']]);
        return ActionResult::ok(null, 'Règle supprimée.')->navigate('acl?resource=' . rawurlencode($current));
    }

    /** Test d'un droit effectif : validation puis affichage du résultat dans la vue ACL. */
    public function aclTest(Request $request, array $params): ActionResult
    {
        $resource = AclService::normalize($request->string('resource', AclService::ROOT));
        $userId = $request->int('test_user');
        if ($userId === null || $userId <= 0) {
            throw new ValidationException(['test_user' => 'Choisissez un utilisateur.']);
        }
        $decision = $this->aclAdmin()->test($userId, $resource, $request->string('test_permission'));
        return ActionResult::info(['allowed' => $decision->allowed], $decision->explanation)
            ->navigate('acl?' . http_build_query(['resource' => $resource, 'test_user' => $userId, 'test_permission' => $decision->permission]));
    }

    // =====================================================================
    // Helpers internes
    // =====================================================================

    /** Onglet « Droits » de la fiche utilisateur : règles directes et droits effectifs sur une ressource. */
    private function renderUserRights(array $user, string $resourcePath): string
    {
        $id = (int) $user['id'];
        $resourcesList = $this->resources()->allPresent();
        $selected = $resourcePath !== '' ? $this->resources()->find($resourcePath) : null;
        $effective = $selected !== null ? $this->aclAdmin()->effectiveDetailed($id, $selected) : [];
        return $this->render('user_rights', [
            'user' => $user,
            'directRules' => $this->decorateRules($this->ctx->acl->rulesForSubject('user', $id)),
            'resources' => $resourcesList,
            'selected' => $selected,
            'effective' => $effective,
            'permissionLabels' => $this->resources()->permissionLabels(),
            'groups' => $user['groups'],
        ]);
    }

    /**
     * Boutons d'actions rapides d'un compte selon les droits (liste et fiche).
     *
     * @param array<string, mixed> $user
     * @param array<string, bool> $rights
     */
    public function quickActions(array $user, array $rights, int $currentUserId, bool $compact = true): string
    {
        $id = (int) $user['id'];
        $params = $this->e(json_encode(['id' => $id]));
        $cls = $compact ? 'btn btn--sm btn--icon btn--ghost' : 'btn';
        $label = static fn (string $text): string => $compact ? '' : '<span>' . $text . '</span>';
        $html = '';
        if ($rights['update']) {
            if ($compact) {
                $html .= '<a class="' . $cls . '" href="#" data-route="edit/' . $id . '" title="Modifier" aria-label="Modifier">' . $this->icon('edit') . '</a>';
            }
            $html .= '<button type="button" class="' . $cls . '" data-action="reset-password" data-params=\'' . $params . '\' data-confirm="Générer un nouveau mot de passe temporaire pour « ' . $this->e($user['username']) . ' » ? L’ancien mot de passe cessera immédiatement de fonctionner." title="Réinitialiser le mot de passe" aria-label="Réinitialiser le mot de passe">' . $this->icon('key') . $label('Réinitialiser le mot de passe') . '</button>';
            if (AccountRepository::isLocked($user) || (int) ($user['failed_attempts'] ?? 0) > 0) {
                $html .= '<button type="button" class="' . $cls . '" data-action="unlock" data-params=\'' . $params . '\' title="Déverrouiller" aria-label="Déverrouiller">' . $this->icon('unlock') . $label('Déverrouiller') . '</button>';
            }
            if ($id !== $currentUserId) {
                if ($user['status'] === 'active') {
                    $html .= '<button type="button" class="' . $cls . '" data-action="disable" data-params=\'' . $params . '\' data-confirm="Désactiver le compte « ' . $this->e($user['username']) . ' » ? La personne ne pourra plus se connecter." title="Désactiver" aria-label="Désactiver">' . $this->icon('power') . $label('Désactiver') . '</button>';
                } else {
                    $html .= '<button type="button" class="' . $cls . '" data-action="enable" data-params=\'' . $params . '\' title="Réactiver" aria-label="Réactiver">' . $this->icon('check') . $label('Réactiver') . '</button>';
                }
            }
        }
        if ($rights['delete'] && $id !== $currentUserId) {
            $html .= '<button type="button" class="' . ($compact ? $cls . ' text-danger' : 'btn btn--outline-danger') . '" data-action="delete" data-params=\'' . $params . '\' data-confirm="Supprimer définitivement le compte « ' . $this->e($user['username']) . ' » ? Cette action est irréversible ; ses règles ACL directes et ses appartenances seront supprimées." data-danger title="Supprimer définitivement" aria-label="Supprimer définitivement">' . $this->icon('trash') . $label('Supprimer') . '</button>';
        }
        return $html;
    }

    /** Icône du sprite commun. */
    public function icon(string $name, string $extra = ''): string
    {
        return '<svg class="icon' . ($extra !== '' ? ' ' . $extra : '') . '" aria-hidden="true"><use href="#i-' . $this->e($name) . '"></use></svg>';
    }

    /**
     * Paramètres normalisés de la liste (chaîne de requête ou corps du formulaire de filtres).
     *
     * @return array{search: string, status: string, group: int, page: int, per_page: int, sort: string, dir: string}
     */
    private function listQuery(Request $request): array
    {
        $search = mb_substr($request->string('search'), 0, 100, 'UTF-8');
        $status = $request->string('status');
        if (!in_array($status, AccountRepository::STATUS_FILTERS, true)) {
            $status = '';
        }
        $group = $request->int('group', 0) ?? 0;
        $perPage = $request->int('per_page', self::PER_PAGE_DEFAULT) ?? self::PER_PAGE_DEFAULT;
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage));
        $sort = $request->string('sort', 'username');
        if (!AccountRepository::isSortable($sort)) {
            $sort = 'username';
        }
        $dir = strtolower($request->string('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        return [
            'search' => $search,
            'status' => $status,
            'group' => max(0, $group),
            'page' => max(1, $request->int('page', 1) ?? 1),
            'per_page' => $perPage,
            'sort' => $sort,
            'dir' => $dir,
        ];
    }

    /** Paramètres de filtre non vides (hors page), pour construire les liens de tri et de pagination. */
    private function filterQuery(array $query): array
    {
        $out = [];
        if ($query['search'] !== '') {
            $out['search'] = $query['search'];
        }
        if ($query['status'] !== '') {
            $out['status'] = $query['status'];
        }
        if ($query['group'] > 0) {
            $out['group'] = $query['group'];
        }
        if ($query['per_page'] !== self::PER_PAGE_DEFAULT) {
            $out['per_page'] = $query['per_page'];
        }
        if ($query['sort'] !== 'username' || $query['dir'] !== 'asc') {
            $out['sort'] = $query['sort'];
            $out['dir'] = $query['dir'];
        }
        return $out;
    }

    private function listRoute(array $query): string
    {
        $params = $this->filterQuery($query);
        if ($query['page'] > 1) {
            $params['page'] = $query['page'];
        }
        return 'list' . ($params === [] ? '' : '?' . http_build_query($params));
    }

    /** Chemins à déplier dans l'arbre : racine, modules et ancêtres de la sélection. */
    private function openPaths(string $selectedPath): array
    {
        $open = [AclService::ROOT];
        foreach ($this->resources()->allPresent() as $resource) {
            if ((int) $resource['depth'] <= 2) {
                $open[] = (string) $resource['path'];
            }
        }
        foreach (AclService::ancestors($selectedPath) as $ancestor) {
            $open[] = $ancestor;
        }
        return array_values(array_unique($open));
    }

    /**
     * Complète les règles avec le nom du sujet et le libellé de la ressource pour l'affichage.
     *
     * @param list<array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    private function decorateRules(array $rules): array
    {
        $labels = [];
        foreach ($this->resources()->allPresent() as $resource) {
            $labels[(string) $resource['path']] = (string) $resource['label'];
        }
        foreach ($rules as &$rule) {
            if (!isset($rule['subject_name'])) {
                $rule['subject_name'] = match ($rule['subject_type']) {
                    'user' => $this->ctx->users->find((int) $rule['subject_id'])['username'] ?? null,
                    'group' => $this->ctx->users->findGroup((int) $rule['subject_id'])['label'] ?? null,
                    default => null,
                };
            }
            $rule['resource_label'] = $labels[(string) $rule['resource']] ?? null;
            $rule['inherited'] = (bool) ($rule['inherited'] ?? false);
        }
        unset($rule);
        return $rules;
    }

    private function requireId(Request $request, string $key = 'id'): int
    {
        $id = $request->int($key);
        if ($id === null || $id <= 0) {
            throw new ValidationException([$key => 'Identifiant manquant.'], 'Identifiant manquant.');
        }
        return $id;
    }

    private function passwords(): PasswordPolicy
    {
        return $this->accountManager()->passwords();
    }

    private function accounts(): AccountRepository
    {
        return $this->accounts ??= new AccountRepository($this->ctx->db);
    }

    private function resources(): ResourceRepository
    {
        return $this->resources ??= new ResourceRepository($this->ctx->db);
    }

    private function guard(): RootAdminGuard
    {
        return $this->guard ??= new RootAdminGuard($this->ctx->db, $this->ctx->acl);
    }

    private function accountManager(): AccountManager
    {
        return $this->accountManager ??= new AccountManager(
            $this->ctx,
            new PasswordPolicy($this->ctx->config->int('security.password_min_length', 12)),
            $this->guard()
        );
    }

    private function groupManager(): GroupManager
    {
        return $this->groupManager ??= new GroupManager($this->ctx, $this->guard());
    }

    private function aclAdmin(): AclAdmin
    {
        return $this->aclAdmin ??= new AclAdmin($this->ctx, $this->resources(), $this->guard());
    }
}
