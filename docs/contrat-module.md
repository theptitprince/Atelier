# Contrat technique d’un module Atelier

Ce document décrit ce qu’un module doit fournir et ce que le noyau lui garantit. Le module `demo` sert d’exemple complet.

## 1. Arborescence d’un module

```
modules/<id>/
├── manifest.json          # déclaration normalisée (source de vérité), voir docs/manifest-schema.md
├── src/                   # classes PHP, namespace déclaré dans le manifeste (PSR-4)
│   └── <Entry>Module.php  # point d’entrée : extends Atelier\Modules\AbstractModule
├── templates/             # gabarits PHP rendus par $this->render('nom', [...])
├── assets/                # CSS/JS/images propres au module, servis via /module-assets/<id>/...
├── migrations/            # 001_xxx.php : return static function (Database $db): void { ... };
└── README.md              # documentation fonctionnelle (optionnel)
```

L’identifiant `<id>` (minuscules, chiffres, tirets) est le nom du répertoire **et** la valeur `id` du manifeste.

## 2. Classe d’entrée

```php
namespace Atelier\Modules\Notes;

use Atelier\Http\Request;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;

final class NotesModule extends AbstractModule
{
    public function routes(RouteCollection $r): void
    {
        $r->view('list', [$this, 'list'], permission: 'open');                     // GET /m/notes/list
        $r->view('edit/{id}', [$this, 'edit'], permission: 'update');
        $r->action('save', [$this, 'save'], permission: 'update');                 // POST /m/notes/save
        $r->action('delete', [$this, 'delete'], permission: 'delete');
        $r->raw('export.csv', [$this, 'export'], permission: 'export');            // GET, Response brute
    }

    public function list(Request $request, array $params): ModuleView { ... }
    public function save(Request $request, array $params): ActionResult { ... }
}
```

### Trois natures de routes

| Nature | Méthode HTTP | Retour attendu | Usage |
|---|---|---|---|
| `view` | GET | `ModuleView` | Écran chargé dans l’onglet (bandeau + contenu) |
| `action` | POST (par défaut) | `ActionResult`, tableau ou `null` | Traitement JSON, formulaires, boutons |
| `raw` | GET (par défaut) | `Response` | Téléchargements, CSV, impression |

Motifs : segments séparés par `/`, paramètres `{id}`, reste du chemin `{path*}`. La chaîne de requête (`?page=2&sort=x`) n’intervient pas dans la correspondance ; elle est lue via `$request->query('page')`.

### Permissions

Chaque route déclare la permission requise (`view`, `open`, `read`, `create`, `update`, `delete`, `import`, `export`, `admin`, `execute` ou une permission propre déclarée dans le manifeste). Le noyau vérifie, avant d’appeler le gestionnaire :

1. que le module est actif et que l’utilisateur a `open` sur `atelier/<id>` ;
2. la permission de la route sur `atelier/<id>` ou sur `atelier/<id>/<resource>` si `resource:` est fourni ;
3. le jeton CSRF pour toute méthode d’écriture.

Le module reste responsable des contrôles plus fins dans ses gestionnaires : `$this->require('delete', 'data/note')`, `$this->can('admin')`, `$this->rights(['update', 'delete'])`. **Masquer un bouton n’est jamais suffisant.**

## 3. Ce que le noyau fournit : `$this->ctx`

| Accès | Rôle |
|---|---|
| `$this->ctx->request()` | Requête courante (`string()`, `int()`, `bool()`, `arrayInput()`, `file()`) |
| `$this->ctx->user()`, `userId()` | Utilisateur connecté (exception si absent) |
| `$this->ctx->db` | `Database` : `select`, `selectOne`, `insert`, `update`, `delete`, `transaction` — **uniquement dans les dépôts/services du module** |
| `$this->ctx->acl` | Résolution des droits |
| `$this->ctx->activity` | Journal d’activité : `$this->log('note.create', 'success', 'note:12', 'Note créée')` |
| `$this->ctx->settings` | Paramètres (`get`/`set` avec `moduleId`) et préférences utilisateur |
| `$this->ctx->shared` | `registry`, `tags`, `relations`, `attachments`, `catalog` (voir docs/donnees-partagees.md) |
| `$this->ctx->moduleService('users')` | Service d’un autre module pour lire ses jeux de données partagés |
| `$this->ctx->template`, `logger`, `csrf`, `users`, `config` | Services divers |

Helpers de `AbstractModule` : `render()`, `renderCore('banner'|'pagination'|'state'|'sort_header')`, `view()`, `resource()`, `can()`, `require()`, `rights()`, `url()`, `actionUrl()`, `assetUrl()`, `e()`, `log()`.

## 4. Vue : `ModuleView`

```php
return ModuleView::make('Bloc-notes')
    ->banner($this->renderCore('banner', ['icon' => 'note', 'title' => 'Bloc-notes', 'subtitle' => '12 notes', 'actions' => $actionsHtml]))
    ->content($this->render('list', ['notes' => $notes]))
    ->status('12 notes affichées')
    ->state(['selected' => 3]);   // transmis au JavaScript du module (ctx.state)
```

Le bandeau est **entièrement** produit par le module ; `renderCore('banner')` est une commodité, pas une obligation. Il doit utiliser les composants du CSS commun.

Variables réservées dans les gabarits (injectées par le noyau, interdites dans `$vars` : `LogicException`) : `module`, `moduleId`, `csrfToken`, `baseUrl`, `e`, `date`, `datetime`. Le nom de méthode `purge()` sans paramètre est réservé au hook de rétention : nommer autrement un gestionnaire d’action de route (`purgeItem`, `purgeRetention`…).

## 5. Action : `ActionResult`

```php
return ActionResult::ok(['id' => $id], 'Note enregistrée.')->navigate('edit/' . $id);
return ActionResult::ok(null, 'Note supprimée.')->refresh();
return ActionResult::ok()->close();
return ActionResult::warning(null, 'Aucun élément sélectionné.');
```

Directives interprétées par le client : `refresh()`, `navigate(route)`, `close()`, `status(text)`, `dirty(bool)`, `banner(html)`. Le `message` est affiché dans le toaster avec le niveau (`ok` = succès, `info`, `warning`).

Erreurs : lever `ValidationException(['champ' => 'message'])`, `NotFoundException`, `ForbiddenException`, `ConflictException`. Le noyau les convertit en réponse normalisée et le client affiche les erreurs près des champs.

## 6. HTML déclaratif (aucun JavaScript nécessaire)

Dans le contenu et le bandeau d’un module, le noyau interprète :

| Attribut | Effet |
|---|---|
| `<a data-route="edit/3">` ou `<a href="/m/notes/edit/3">` | Charge la route dans l’onglet du module (URL et historique mis à jour) |
| `<button data-action="delete" data-params='{"id":3}' data-confirm="Supprimer ?" data-danger>` | POST l’action après confirmation ; applique les directives |
| `data-prompt="Nouveau nom"` `data-prompt-field="name"` | Demande une saisie avant l’action |
| `<form data-action="save">` | Soumission fetch (JSON, ou multipart si fichier), erreurs de champs affichées, directives appliquées |
| `<form data-action="save" data-track-dirty data-save-shortcut>` | Marque l’onglet « modifié » à la saisie ; Ctrl+S soumet |
| `<form data-action="search" data-auto-submit>` | Soumission au changement (Entrée pour les champs texte) |
| `<select data-route-select>` | Charge la route de l’option choisie |
| `<a data-open-module="users" data-open-route="list">` | Ouvre (ou réutilise) l’onglet d’un autre module |
| `[data-subtabs="id"] > [data-subtab="x"]` + `[data-subtab-panel="x"]` | Onglets internes de contenu |

Attributs générés par le bandeau standard : `[data-banner-busy]` (indicateur d’action en cours), `[data-banner-subtitle]`.

### Composants de saisie communs (activés automatiquement dans tout contenu inséré)

| Attribut | Composant |
|---|---|
| `<textarea data-editor="bbcode">` | Éditeur de texte riche BBCode : barre d’outils (gras, italique, souligné, barré, titres, citation, code, liste, lien, séparateur), raccourcis Ctrl+B/I/U, aperçu, aide. La valeur reste du BBCode ; le rendu HTML côté serveur passe par `\Atelier\View\BbCode::toHtml($texte)` (sécurisé, liste blanche) et `BbCode::toText()` pour les extraits. |
| `<input data-tags-input data-tags-max="20" data-tags-scope="shared">` | Saisie de tags avec puces et suggestions des tags existants (`/core/tags`), création libre. La valeur du champ reste une liste séparée par des virgules ; `data-tags-scope` permet une portée privée au module. |

Tout champ de texte long destiné à être affiché doit utiliser l’éditeur commun, et tout champ de tags le composant commun.

### Journal d’activité et débogage

- `$this->log('note.create', 'success', 'note:12', 'Note créée', [...])` : la catégorie (`security`, `data`, `admin`, `technical`) est déduite du préfixe de l’action (`auth.`, `access.`, `user.`, `acl.`, `module.`, `settings.`, `backup.`, `error.`, `route.`…) ou passée en 6ᵉ argument.
- `$this->debug('Filtre appliqué', ['filtre' => ...])` : trace de diagnostic (catégorie `debug`), enregistrée seulement si `logging.activity_level = debug`, corrélée aux autres entrées de la requête par `request_id`.
- Le noyau trace lui-même chaque vue et action (`debug.view`, `debug.action`, `debug.raw`) avec route, paramètres, permission et durée, ainsi que les erreurs (`error.server`, `route.not_found`, `access.denied`, `csrf.rejected`, `session.expired`), les manifestes invalides et les synchronisations. Le module « Journal d’activité » filtre par catégorie et par requête.

## 7. JavaScript optionnel

Déclarer le fichier dans `assets.js` du manifeste, puis :

```js
Atelier.modules.register('notes', {
  mount(ctx)        { /* onglet créé */ },
  render(ctx, view) { /* après chaque chargement de vue : ctx.root, ctx.banner, ctx.state */ },
  suspend(ctx)      { /* onglet masqué : les ctx.interval() sont suspendus automatiquement */ },
  resume(ctx)       { },
  beforeClose(ctx)  { return true; /* ou false / Promise<bool> ; le noyau gère déjà ctx.isDirty() */ },
  unmount(ctx)      { /* les écouteurs ctx.on() et minuteries ctx.interval() sont libérés automatiquement */ },
});
```

`ctx` : `moduleId`, `root`, `banner`, `state`, `data` (stockage privé de l’onglet), `api.get/post(route)`, `navigate(route)`, `refresh()`, `close()`, `setDirty()`, `isDirty()`, `isActive()`, `status(text)`, `busy(bool)`, `toast`, `dialog`, `util`, `on(el, evt, [selector], handler)`, `interval(fn, ms)`, `timeout(fn, ms)`.

Le manifeste peut déclarer `"keepAlive": true` si un module a réellement besoin de conserver ses minuteries lorsqu’il est masqué (à justifier).

## 8. Styles propres au module

- Déclarés dans `assets.css` ; chargés avant l’affichage, conservés tant qu’un onglet du module existe, retirés à la fermeture (comptage de références).
- Toute règle est préfixée par la racine du module : `.module-notes .note-card { ... }`. Le contenu rendu doit envelopper son HTML dans `<div class="module module-<id>">`.
- Interdit : redéfinir `body`, `.btn`, `.table`, `.input`… Priorité aux variables `--c-*`, `--sp-*`.
- Bibliothèques tierces : déclarées dans `assets.vendor` avec nom, version et licence.

## 9. Données

- Tables préfixées par l’identifiant du module : `notes_note`. Créées par les migrations du module.
- Chaque jeu de données est déclaré dans `datasets` avec `visibility: shared|private`. Un jeu privé n’est ni catalogué ni accessible par l’API intermodule.
- Un module consommateur lit un jeu partagé via le **service** du module propriétaire (`$this->ctx->moduleService('users')->...`) et jamais directement ses tables. Le service vérifie les permissions de l’utilisateur avec `$this->ctx->shared->catalog->canAccess($userId, 'users.account', 'read')`.
- Identifiants globaux, tags, relations, pièces jointes : `$this->ctx->shared->registry->register('notes.note', (string) $id, $title, $userId)` puis `tags->attach($infoId, 'urgent')`, etc.

## 9 bis. Suppression et corbeille (règle du projet)

Toute suppression d’une donnée métier par un utilisateur est **logique** (colonne `deleted_at`), restaurable pendant `trash.retention_days` (30 jours), puis purgée par le hook `purge()` appelé par `console maintenance:purge`. Les lignes supprimées sont exclues de toutes les listes, comptages, badges, exports et services intermodules.

Le module implémente `\Atelier\Modules\TrashProviderInterface` (`trashItems()`, `restoreTrashItem()`, `purgeTrashItem()`) pour que ses éléments apparaissent dans le module **Corbeille**, qui regroupe toutes les corbeilles au même endroit (recherche, restauration et purge groupées). Un module qui gère plusieurs types d’éléments préfixe l’identifiant (`asset:12`, `log:9`). Une vue `trash` propre au module reste possible ; elle renvoie vers la corbeille globale (`data-open-module="trash"`). La purge physique retire aussi l’entrée du registre commun (`registry->unregister`). Le générateur `console module:create` produit ce squelette.

## 10. Cycle de vie et installation

1. Dépôt du répertoire dans `modules/` ;
2. `console db:migrate` (ou premier chargement de l’interface) : validation du manifeste, migrations, synchronisation des ressources ACL, permissions et catalogue ;
3. activation depuis l’administration des modules (ou `console modules:set <id> active`) ;
4. attribution des droits dans le module Utilisateurs et ACL.

Hooks optionnels de la classe : `install(ModuleContext $ctx)`, `seed(): string` (données de démonstration), `purge(): string` (rétention), `cron(): string` (tâche de fond appelée par `console cron:run`, à planifier toutes les 5 à 15 minutes avec `cron.sh` / `cron.bat` ; chaque module y borne lui-même son travail en nombre et en durée), `service(): ?object`.

Un module qui dépend du cron peut lire la date de la dernière exécution (`$this->ctx->settings->get('cron.last_run', null, 'core')`) pour signaler une planification absente.

Un manifeste invalide n’empêche que le module concerné : il apparaît en erreur dans la colonne et dans l’administration avec le détail des erreurs.
