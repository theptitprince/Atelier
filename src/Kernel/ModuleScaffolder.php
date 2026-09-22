<?php

declare(strict_types=1);

namespace Atelier\Kernel;

use Atelier\Support\Files;
use Atelier\Support\Str;
use RuntimeException;

/**
 * Génère le squelette d'un nouveau module conforme au contrat (manifest.json, classe d'entrée,
 * gabarits, feuille de style, migration optionnelle, README). Commande console `module:create`.
 */
final class ModuleScaffolder
{
    public function __construct(private readonly string $modulesDirectory)
    {
    }

    /**
     * @param array{name?: string, description?: string, group?: string, icon?: string, with_table?: bool, shared?: bool} $options
     * @return list<string> fichiers créés (chemins relatifs)
     */
    public function create(string $id, array $options = []): array
    {
        if (!Str::isSlug($id) || in_array($id, ['core', 'atelier', 'assets', 'm', 'api', 'files', 'login', 'logout'], true)) {
            throw new RuntimeException('Identifiant invalide ou réservé : ' . $id . ' (minuscules, chiffres, tirets).');
        }
        $directory = $this->modulesDirectory . '/' . $id;
        if (is_dir($directory)) {
            throw new RuntimeException('Le répertoire modules/' . $id . ' existe déjà.');
        }

        $name = trim((string) ($options['name'] ?? ucfirst(str_replace(['-', '_'], ' ', $id))));
        $description = trim((string) ($options['description'] ?? 'Module ' . $name));
        $group = Str::isSlug((string) ($options['group'] ?? 'tools')) ? (string) ($options['group'] ?? 'tools') : 'tools';
        $icon = preg_match('/^[a-z0-9-]+$/', (string) ($options['icon'] ?? 'module')) === 1 ? (string) ($options['icon'] ?? 'module') : 'module';
        $withTable = (bool) ($options['with_table'] ?? true);
        $shared = (bool) ($options['shared'] ?? false);
        $studly = Str::studly($id);
        $namespace = 'Atelier\\Modules\\' . $studly;
        $entry = $studly . 'Module';
        $table = str_replace('-', '_', $id) . '_item';

        $files = [];
        $write = static function (string $relative, string $content) use ($directory, &$files): void {
            Files::writeAtomic($directory . '/' . $relative, $content);
            $files[] = 'modules/' . basename($directory) . '/' . $relative;
        };

        $datasets = $withTable ? [[
            'code' => $id . '.item',
            'name' => 'Éléments',
            'description' => 'Éléments gérés par le module ' . $name,
            'visibility' => $shared ? 'shared' : 'private',
            'tables' => [$table],
            'fields' => ['id' => 'Identifiant', 'title' => 'Titre', 'created_at' => 'Date de création'],
            'operations' => ['read', 'create', 'update', 'delete'],
            'version' => 1,
        ]] : [];

        $manifest = [
            'id' => $id,
            'name' => $name,
            'version' => '0.1.0',
            'description' => $description,
            'author' => '',
            'icon' => $icon,
            'group' => $group,
            'order' => 100,
            'status' => 'active',
            'namespace' => $namespace,
            'entry' => $entry,
            'defaultRoute' => 'index',
            'permissions' => [],
            'navigation' => [
                ['id' => 'index', 'label' => $name, 'route' => 'index', 'order' => 1, 'permission' => 'open', 'icon' => $icon, 'description' => 'Écran principal'],
            ],
            'resources' => [],
            'assets' => ['css' => ['assets/' . $id . '.css'], 'js' => []],
            'datasets' => $datasets,
            'consumes' => [],
            'migrations' => 'migrations',
        ];
        $write('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

        $write('src/' . $entry . '.php', $this->entryClass($id, $namespace, $entry, $name, $icon, $withTable, $table));
        if ($withTable) {
            $write('src/ItemRepository.php', $this->repository($namespace, $table));
            $write('migrations/001_create_' . str_replace('-', '_', $id) . '.php', $this->migration($table));
        }
        $write('templates/index.php', $this->indexTemplate($id, $name, $withTable));
        if ($withTable) {
            $write('templates/edit.php', $this->editTemplate($id));
        }
        $write('assets/' . $id . '.css', "/* Styles propres au module « {$name} », limités à sa racine .module-{$id}. */\n.module-{$id} .{$id}__intro { color: var(--c-text-muted); }\n");
        $write('README.md', $this->readme($id, $name, $withTable, $shared));

        return $files;
    }

    private function entryClass(string $id, string $namespace, string $entry, string $name, string $icon, bool $withTable, string $table): string
    {
        $repoUse = $withTable ? "\n    private ?ItemRepository \$repository = null;\n\n    private function repo(): ItemRepository\n    {\n        return \$this->repository ??= new ItemRepository(\$this->ctx->db);\n    }\n" : '';
        $routes = $withTable
            ? "        \$r->view('index', [\$this, 'index'], permission: 'open');\n        \$r->view('edit/{id}', [\$this, 'edit'], permission: 'update');\n        \$r->view('new', [\$this, 'create'], permission: 'create');\n        \$r->action('save', [\$this, 'save'], permission: 'update');\n        \$r->action('delete', [\$this, 'delete'], permission: 'delete');"
            : "        \$r->view('index', [\$this, 'index'], permission: 'open');";
        $indexBody = $withTable
            ? "        \$items = \$this->repo()->all();\n        \$rights = \$this->rights(['create', 'update', 'delete']);\n        \$actions = \$rights['create'] ? '<a class=\"btn btn--primary\" href=\"#\" data-route=\"new\">' . \$this->e('') . '<svg class=\"icon\" aria-hidden=\"true\"><use href=\"#i-plus\"></use></svg> Ajouter</a>' : '';\n        return \$this->view('{$name}', \$this->banner('{$name}', count(\$items) . ' élément(s)', \$actions), \$this->render('index', ['items' => \$items, 'rights' => \$rights]))\n            ->status(count(\$items) . ' élément(s)');"
            : "        return \$this->view('{$name}', \$this->banner('{$name}', 'Écran principal'), \$this->render('index', []))->status('Prêt');";
        $crud = $withTable ? <<<PHP

    public function create(Request \$request, array \$params): ModuleView
    {
        return \$this->editor(['id' => null, 'title' => '', 'content' => '']);
    }

    public function edit(Request \$request, array \$params): ModuleView
    {
        \$item = \$this->repo()->find((int) \$params['id']);
        if (\$item === null) {
            throw new NotFoundException('Élément introuvable.');
        }
        return \$this->editor(\$item);
    }

    public function save(Request \$request, array \$params): ActionResult
    {
        \$id = \$request->int('id');
        \$title = \$request->string('title');
        \$content = \$request->string('content');
        \$errors = [];
        if (\$title === '') {
            \$errors['title'] = 'Le titre est obligatoire.';
        } elseif (mb_strlen(\$title, 'UTF-8') > 200) {
            \$errors['title'] = 'Le titre ne doit pas dépasser 200 caractères.';
        }
        if (\$errors !== []) {
            throw new ValidationException(\$errors);
        }
        if (\$id === null) {
            \$this->require('create');
            \$id = \$this->repo()->create(\$title, \$content, \$this->ctx->userId());
            \$this->log('{$id}.create', 'success', 'item:' . \$id, 'Élément créé');
        } else {
            \$this->repo()->update(\$id, \$title, \$content);
            \$this->log('{$id}.update', 'success', 'item:' . \$id, 'Élément modifié');
        }
        return ActionResult::ok(['id' => \$id], 'Élément enregistré.')->navigate('edit/' . \$id)->dirty(false);
    }

    /** Suppression logique : l'élément part dans la corbeille (module Corbeille) pendant trash.retention_days. */
    public function delete(Request \$request, array \$params): ActionResult
    {
        \$id = (int) \$request->input('id');
        if (\$this->repo()->find(\$id) === null) {
            throw new NotFoundException('Élément introuvable.');
        }
        \$this->repo()->softDelete(\$id);
        \$this->log('{$id}.delete', 'success', 'item:' . \$id, 'Élément placé dans la corbeille');
        return ActionResult::ok(null, 'Élément placé dans la corbeille.')->navigate('index');
    }

    // ----- Corbeille globale (TrashProviderInterface) -----

    public function trashItems(): array
    {
        \$days = \$this->ctx->config->int('trash.retention_days', 30);
        \$rights = \$this->rights(['update', 'delete']);
        \$items = [];
        foreach (\$this->repo()->trashed(\$days) as \$row) {
            \$purgeAt = \Atelier\Support\Clock::parseUtc((string) \$row['deleted_at'])?->modify('+' . \$days . ' days');
            \$items[] = [
                'id' => (string) \$row['id'],
                'label' => (string) \$row['title'],
                'dataset' => '{$id}.item',
                'deleted_at' => (string) \$row['deleted_at'],
                'deleted_by' => null,
                'purge_at' => \$purgeAt === null ? null : \Atelier\Support\Clock::utc(\$purgeAt),
                'can_restore' => \$rights['update'],
                'can_purge' => \$rights['delete'],
            ];
        }
        return \$items;
    }

    public function restoreTrashItem(string \$id): void
    {
        \$this->require('update');
        if (!\$this->repo()->restore((int) \$id)) {
            throw new NotFoundException('Cet élément n’est pas dans la corbeille.');
        }
        \$this->log('{$id}.restore', 'success', 'item:' . \$id, 'Élément restauré');
    }

    public function purgeTrashItem(string \$id): void
    {
        \$this->require('delete');
        if (!\$this->repo()->purge((int) \$id)) {
            throw new NotFoundException('Cet élément n’est pas dans la corbeille.');
        }
        \$this->log('{$id}.purge', 'success', 'item:' . \$id, 'Élément supprimé définitivement');
    }

    /** Hook de rétention (console maintenance:purge) : purge physique des éléments expirés. */
    public function purge(): string
    {
        \$days = \$this->ctx->config->int('trash.retention_days', 30);
        \$count = 0;
        foreach (\$this->repo()->expiredTrashIds(\$days) as \$expired) {
            \$this->repo()->purge(\$expired);
            \$count++;
        }
        return \$count . ' élément(s) purgé(s) de la corbeille (> ' . \$days . ' jours)';
    }

    /** Écran d'édition (création et modification). */
    private function editor(array \$item): ModuleView
    {
        \$isNew = \$item['id'] === null;
        \$content = \$this->render('edit', ['item' => \$item, 'isNew' => \$isNew, 'canDelete' => !\$isNew && \$this->can('delete')]);
        return \$this->view(\$isNew ? 'Nouvel élément' : 'Élément n° ' . \$item['id'], \$this->banner(\$isNew ? 'Nouvel élément' : \$item['title'], 'Ctrl+S pour enregistrer'), \$content);
    }
PHP : '';
        $uses = $withTable ? "use Atelier\\Error\\NotFoundException;\nuse Atelier\\Error\\ValidationException;\n" : '';
        $implements = $withTable ? ' implements \\Atelier\\Modules\\TrashProviderInterface' : '';

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

{$uses}use Atelier\\Http\\Request;
use Atelier\\Modules\\AbstractModule;
use Atelier\\Modules\\ActionResult;
use Atelier\\Modules\\ModuleView;
use Atelier\\Modules\\RouteCollection;

/**
 * Module « {$name} » : point d'entrée généré par `console module:create`.
 * Voir docs/contrat-module.md et docs/developpeur-module.md.
 */
final class {$entry} extends AbstractModule{$implements}
{{$repoUse}
    public function routes(RouteCollection \$r): void
    {
{$routes}
    }

    public function index(Request \$request, array \$params): ModuleView
    {
{$indexBody}
    }
{$crud}

    private function banner(string \$title, string \$subtitle, string \$actions = ''): string
    {
        return \$this->renderCore('banner', ['icon' => '{$icon}', 'title' => \$title, 'subtitle' => \$subtitle, 'actions' => \$actions]);
    }
}

PHP;
    }

    private function repository(string $namespace, string $table): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Atelier\\Persistence\\Database;
use Atelier\\Support\\Clock;

/**
 * Accès aux données de la table {$table}. Tout le SQL du module est ici : jamais dans les routes ni les gabarits.
 */
final class ItemRepository
{
    public function __construct(private readonly Database \$db)
    {
    }

    /** @return list<array<string, mixed>> éléments actifs (hors corbeille) */
    public function all(): array
    {
        return \$this->db->select('SELECT * FROM {$table} WHERE deleted_at IS NULL ORDER BY updated_at DESC');
    }

    /** @return array<string, mixed>|null */
    public function find(int \$id): ?array
    {
        return \$this->db->selectOne('SELECT * FROM {$table} WHERE id = :id AND deleted_at IS NULL', ['id' => \$id]);
    }

    // ----- Corbeille (suppression logique) -----

    public function softDelete(int \$id): bool
    {
        return \$this->db->update('{$table}', ['deleted_at' => Clock::utc()], 'id = :id AND deleted_at IS NULL', ['id' => \$id]) > 0;
    }

    public function restore(int \$id): bool
    {
        return \$this->db->update('{$table}', ['deleted_at' => null], 'id = :id AND deleted_at IS NOT NULL', ['id' => \$id]) > 0;
    }

    /** Suppression physique d'un élément en corbeille. */
    public function purge(int \$id): bool
    {
        return \$this->db->delete('{$table}', 'id = :id AND deleted_at IS NOT NULL', ['id' => \$id]) > 0;
    }

    /** @return list<array<string, mixed>> éléments en corbeille non expirés */
    public function trashed(int \$retentionDays): array
    {
        \$limit = Clock::utc(Clock::now()->modify('-' . \$retentionDays . ' days'));
        return \$this->db->select('SELECT * FROM {$table} WHERE deleted_at IS NOT NULL AND deleted_at >= :l ORDER BY deleted_at DESC', ['l' => \$limit]);
    }

    /** @return list<int> */
    public function expiredTrashIds(int \$retentionDays): array
    {
        \$limit = Clock::utc(Clock::now()->modify('-' . \$retentionDays . ' days'));
        \$rows = \$this->db->select('SELECT id FROM {$table} WHERE deleted_at IS NOT NULL AND deleted_at < :l', ['l' => \$limit]);
        return array_map(static fn (array \$r): int => (int) \$r['id'], \$rows);
    }

    public function create(string \$title, string \$content, ?int \$userId): int
    {
        \$now = Clock::utc();
        return \$this->db->insert('{$table}', ['title' => \$title, 'content' => \$content, 'created_by' => \$userId, 'created_at' => \$now, 'updated_at' => \$now]);
    }

    public function update(int \$id, string \$title, string \$content): void
    {
        \$this->db->update('{$table}', ['title' => \$title, 'content' => \$content, 'updated_at' => Clock::utc()], 'id = :id', ['id' => \$id]);
    }

}

PHP;
    }

    private function migration(string $table): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Atelier\\Persistence\\Database;

/** Création de la table {$table}. Les méthodes de dialecte assurent la compatibilité SQLite / MariaDB. */
return static function (Database \$db): void {
    \$db->execute(sprintf(
        'CREATE TABLE {$table} (
            id %s,
            title %s NOT NULL,
            content %s NULL,
            created_by %s NULL,
            created_at %s NOT NULL,
            updated_at %s NOT NULL,
            deleted_at %s NULL
        )%s',
        \$db->primaryKey(),
        \$db->varchar(200),
        \$db->text(),
        \$db->integer(),
        \$db->datetime(),
        \$db->datetime(),
        \$db->datetime(),
        \$db->tableOptions()
    ));
    \$db->execute('CREATE INDEX idx_{$table}_updated ON {$table} (updated_at)');
    \$db->execute('CREATE INDEX idx_{$table}_deleted ON {$table} (deleted_at)');
};

PHP;
    }

    private function indexTemplate(string $id, string $name, bool $withTable): string
    {
        if (!$withTable) {
            return <<<PHP
<?php
/** Écran principal du module « {$name} ». Variables : \$e (échappement), \$module, \$baseUrl. */
?>
<div class="module module-{$id}">
    <div class="card">
        <div class="card__body">
            <h1>{$name}</h1>
            <p class="{$id}__intro">Squelette généré : remplacez ce contenu par vos écrans. Utilisez les composants du CSS commun (.card, .table, .field, .btn…).</p>
        </div>
    </div>
</div>

PHP;
        }
        return <<<PHP
<?php
/** Liste des éléments. Variables : \$items, \$rights, \$e, \$datetime, \$module. */
?>
<div class="module module-{$id}">
    <?php if (\$items === []): ?>
        <?= \$module->renderCore('state', ['type' => 'empty', 'title' => 'Aucun élément', 'message' => 'Créez le premier élément depuis le bandeau.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Titre</th><th>Modifié le</th><th class="col-actions">Actions</th></tr></thead>
                <tbody>
                <?php foreach (\$items as \$item): ?>
                    <tr>
                        <td><a href="#" data-route="edit/<?= (int) \$item['id'] ?>"><?= \$e(\$item['title']) ?></a></td>
                        <td class="text-nowrap"><?= \$e(\$datetime(\$item['updated_at'])) ?></td>
                        <td class="col-actions">
                            <?php if (\$rights['update']): ?>
                                <a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="edit/<?= (int) \$item['id'] ?>" title="Modifier"><svg class="icon" aria-hidden="true"><use href="#i-edit"></use></svg></a>
                            <?php endif; ?>
                            <?php if (\$rights['delete']): ?>
                                <button type="button" class="btn btn--sm btn--icon btn--ghost text-danger" data-action="delete" data-params='{"id":<?= (int) \$item['id'] ?>}' data-confirm="Supprimer cet élément ?" data-danger title="Supprimer"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

PHP;
    }

    private function editTemplate(string $id): string
    {
        return <<<PHP
<?php
/** Formulaire d'édition. Variables : \$item, \$isNew, \$canDelete, \$e. */
?>
<div class="module module-{$id}">
    <form class="card" data-action="save" data-track-dirty data-save-shortcut novalidate>
        <input type="hidden" name="id" value="<?= \$isNew ? '' : (int) \$item['id'] ?>">
        <div class="card__body">
            <div class="field">
                <label class="field__label" for="{$id}-title">Titre <span class="required">*</span></label>
                <input class="input" type="text" id="{$id}-title" name="title" value="<?= \$e(\$item['title']) ?>" maxlength="200" required autofocus>
                <span class="field__error"></span>
            </div>
            <div class="field">
                <label class="field__label" for="{$id}-content">Contenu</label>
                <textarea class="textarea" id="{$id}-content" name="content" rows="12" data-editor="bbcode"><?= \$e(\$item['content'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="card__footer form-actions">
            <button type="submit" class="btn btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg> Enregistrer</button>
            <a class="btn btn--ghost" href="#" data-route="index">Retour à la liste</a>
            <span class="grow"></span>
            <?php if (\$canDelete): ?>
                <button type="button" class="btn btn--outline-danger" data-action="delete" data-params='{"id":<?= (int) \$item['id'] ?>}' data-confirm="Supprimer cet élément ?" data-danger>
                    <svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Supprimer
                </button>
            <?php endif; ?>
        </div>
    </form>
</div>

PHP;
    }

    private function readme(string $id, string $name, bool $withTable, bool $shared): string
    {
        $data = $withTable
            ? "Jeu de données `{$id}.item` (" . ($shared ? 'partagé' : 'privé au module') . "), table `" . str_replace('-', '_', $id) . "_item` créée par `migrations/001_create_*.php`."
            : 'Aucun jeu de données déclaré.';
        return <<<MD
# Module {$name} (`{$id}`)

Squelette généré par `console module:create {$id}`. Version 0.1.0.

## Écrans

| Route | Permission | Contenu |
|---|---|---|
| `index` | `open` | Écran principal |

## Données

{$data}

## Mise en route

1. Le module est découvert automatiquement ; `console db:migrate` applique ses migrations et synchronise ses droits.
2. Attribuer les droits (`open`, `create`, `update`, `delete`) dans **Utilisateurs et droits → Droits d'accès** sur la ressource `atelier/{$id}`.
3. Adapter le manifeste (navigation, permissions, jeux de données), la classe d'entrée, les gabarits et la feuille de style.

Documentation : `docs/developpeur-module.md`, `docs/contrat-module.md`, `docs/manifest-schema.md`, module `demo` (référence).

MD;
    }
}
