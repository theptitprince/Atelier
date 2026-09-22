# Module « Démonstration » (`demo`)

Module **fictif** : il ne gère aucune donnée réelle. Il a deux rôles.

1. **Référence technique et visuelle du noyau.** Chaque composant du CSS commun (`public/assets/css/atelier.css`),
   chaque comportement déclaratif du client (`public/assets/js/atelier.js`) et chaque directive serveur
   (`ActionResult`) y est exercé au moins une fois. C'est l'écran à ouvrir pour vérifier qu'une évolution du noyau
   n'a rien cassé, ou pour voir « comment ça se présente » avant d'écrire un module.
2. **Modèle pour créer un nouveau module.** Le code est volontairement explicite et commenté ; la section
   « Créer un module à partir de demo » ci-dessous décrit la démarche pas à pas.

Les articles manipulés (outillage, papeterie, mobilier, informatique) sont générés de façon déterministe :
`console db:seed` en crée 120 si la table est vide, et le bouton « Réinitialiser les articles » (écran Formulaires)
les remet à l'identique.

## Écrans

| Route | Écran | Ce qui est exercé |
|---|---|---|
| `index` | Vue d'ensemble | Présentation, indicateurs, checklist §6.7.3 avec liens `data-route`, mini-courbe tracée par la bibliothèque tierce `mini-sparkline`, extraits **réels** du code du module (manifeste, routes, vue, action, gabarit, JS) |
| `components` | Composants | Titres h1–h4, textes, liens, code, listes ; boutons (toutes variantes, états normal/survolé/actif/désactivé/`is-busy`/focus) ; badges, chips, alertes 4 niveaux ; cartes, `.kpi`, `.dl`, `.split`, `.list`, `.toolbar`, fieldset ; `.subtabs` (`data-subtabs`), `.dropdown` (details), `.tree`, `.spinner`, `progress`, `.skeleton`, `.dropzone` ; frontière CSS commun / CSS du module avec le composant propre `.demo-gauge` |
| `forms` | Formulaires | Formulaire complet `data-action` + `data-track-dirty` + `data-save-shortcut` (text, email, number, date, select, select multiple, textarea, checkbox, radio, fichier, aides, requis, `.input-group`, `.input-icon`, `.form-grid`, `.form-actions`) validé côté serveur (`ValidationException` près des champs) ; filtre instantané `data-auto-submit` dont le résultat est affiché par le JS (`atelier:submitted`) ; formulaire `data-confirm` + `data-danger` ; bouton `data-action` + `data-prompt` ; états des champs |
| `tables` | Tableaux | Pagination serveur 25/page (`renderCore('pagination')`), tri sur toutes les colonnes (`renderCore('sort_header')`), filtres (recherche, catégorie, actif) en `data-auto-submit`, état vide (`renderCore('state')`), lignes `is-selected` / `is-disabled` / `is-warning`, actions par ligne (`data-action="toggle"` → `->refresh()`, `data-prompt`), cases à cocher + actions groupées (`<form data-action="bulk">`, `ids[]`), export CSV (route brute), barre d'état « N éléments » |
| `feedback` | Notifications et états | `ctx.toast.info/success/warning/error`, toasts groupés (compteur), persistant vs temporaire, erreur avec référence ; `ActionResult::ok/info/warning` ; `ctx.status`, `Atelier.status.message`, `Atelier.status.progress` (0→100 et indéterminée) ; action longue avec `ctx.busy(true)` ; `ctx.dialog.confirm/alert/prompt/open` et `data-confirm` déclaratif ; blocs d'état 5 types ; `Atelier.announce` |
| `lifecycle` | Cycle de vie et onglets | Compteur `ctx.interval` suspendu/repris avec l'onglet, journal des hooks (`mount`, `render`, `suspend`, `resume`, `beforeClose`, `unmount`) affiché et dans la console (`[demo] hook …`), `ctx.setDirty`, `data-open-module`, directives `->close()`, `->navigate()`, `->banner()`, affichage de `ctx.state`, liens internes pour précédent/suivant, ressource manquante (`Atelier.resources.acquire`) et comptage de références |
| `shared` | Tags, relations, pièces jointes | `registry->register`, `tags->attach/detach`, `relations->relate/remove`, téléversement multipart (`attachments->store`), liste des pièces jointes avec `/files/{id}` et `?inline=1`, suppression logique, catalogue `catalog->shared()` et preuve que `demo.secret` (privé) n'y figure pas ni n'est lisible par l'API intermodule, `moduleService('demo')` |
| `errors` | Cas d'erreur | `ValidationException` (422), `ForbiddenException` (403), `NotFoundException` (404), `ConflictException` (409), `ModuleUnavailableException` (503), `moduleService('inexistant')`, exception PHP brute (500 + référence, admin) en action et en vue (`errors/server`), route inexistante, module inexistant, action réservée `action/secret` (permission `execute`), procédure pour simuler une session expirée, routes brutes |

Toutes les vues répondent en JSON `{ ok: true, data: { title, banner, content, route, status, state } }` lorsqu'elles
sont demandées avec l'en-tête `X-Atelier-Request: json`, et par la coquille HTML complète sinon.

## Checklist des vérifications (cahier des charges §6.7.3)

| # | Vérification | Écran |
|---|---|---|
| 01 | Styles des titres, textes, boutons, champs, listes et panneaux | components |
| 02 | CSS commun vs CSS propre au module (préfixe `.module-demo`) | components |
| 03 | États normal, survolé, sélectionné, désactivé, erreur | components, forms, tables |
| 04 | Formulaires et validation serveur près des champs | forms, errors |
| 05 | Modales et confirmations (confirm, alert, prompt, dialogue libre) | feedback |
| 06 | Tableaux : tri, filtres, pagination, sélection, état vide, export | tables |
| 07 | Toasters des quatre niveaux, regroupement des doublons | feedback |
| 08 | Messages temporaires et persistants, référence d'incident | feedback |
| 09 | Bandeau dynamique remplacé par une directive serveur | lifecycle |
| 10 | Barre d'état dynamique (client et serveur) | feedback, tables |
| 11 | Indicateurs de chargement et de progression | feedback, components |
| 12 | Accès refusé, session expirée, module indisponible, erreur serveur | errors |
| 13 | Ouverture, suspension, réactivation et fermeture d'onglet | lifecycle |
| 14 | Largeurs bureau : grilles, split, cartes, tableaux larges | components, tables |
| 15 | Libération des écouteurs et minuteries à la fermeture | lifecycle |
| 16 | Registre commun, tags, relations, pièces jointes, catalogue | shared |

## Fichiers

```
modules/demo/
├── manifest.json                       identité, navigation (3 niveaux), ressource action/secret, assets, jeux de données
├── README.md
├── migrations/001_create_demo.php      tables demo_item et demo_secret (dialecte via Database)
├── src/
│   ├── DemoModule.php                  classe d'entrée : routes, vues, actions, hooks seed()/service()
│   ├── ItemRepository.php              seul accès SQL à demo_item (lecture paginée, écriture, génération déterministe)
│   ├── ItemFilters.php                 normalisation des paramètres de la vue Tableaux et construction des routes
│   └── DemoService.php                 service intermodule du jeu partagé demo.item (contrôle par le catalogue)
├── templates/                          un gabarit par vue ; HTML enveloppé dans <div class="module module-demo">
├── assets/
│   ├── demo.css                        styles propres, tous préfixés .module-demo
│   ├── demo.js                         Atelier.modules.register('demo', {...}) : hooks, [data-demo], sélection, résultats
│   └── vendor/mini-sparkline/          bibliothèque tierce (MIT) déclarée dans assets.vendor
```

## Créer un module à partir de demo

1. **Copier le squelette.** Créez `modules/<id>/` (`<id>` en minuscules, chiffres, tirets) avec `manifest.json`,
   `src/`, `templates/`, `assets/`, `migrations/`. Le plus simple : copier `modules/demo/` puis supprimer ce qui ne
   sert pas.
2. **Écrire le manifeste** (`docs/manifest-schema.md`). Renseignez `id` (= nom du répertoire), `name`, `version`,
   `namespace` (`Atelier\Modules\<Studly>`), `entry` (`<Studly>Module`), `icon` (nom du sprite
   `src/Kernel/templates/icons.php`), `navigation[]` (une entrée par écran, `parent` pour les sous-écrans,
   `permission` par entrée), `datasets[]` avec `visibility` obligatoire, `assets`. Vérifiez avec
   `php tools/console.php modules:list` : une erreur de manifeste ne bloque que votre module et est détaillée.
3. **Créer les tables** dans `migrations/001_xxx.php` : `return static function (Database $db): void { ... };`.
   Préfixez les tables par `<id>_` et utilisez les méthodes de dialecte (`primaryKey()`, `varchar()`, `datetime()`,
   `tableOptions()`) pour rester compatible SQLite/MariaDB. Appliquez avec `php tools/console.php db:migrate`.
4. **Écrire un dépôt** (`src/<Entity>Repository.php`) : c'est la **seule** classe qui exécute du SQL
   (`$db->select/selectOne/insert/update/delete/count/transaction`). Les gestionnaires de routes ne contiennent
   jamais de SQL. Voir `ItemRepository`.
5. **Écrire la classe d'entrée** `src/<Studly>Module.php extends AbstractModule` :
   - `routes(RouteCollection $r)` : `$r->view(...)` pour chaque écran (une route par entrée de navigation, même
     chemin), `$r->action(...)` pour les traitements POST, `$r->raw(...)` pour les téléchargements ; chaque route
     porte sa permission (`open`, `update`, `delete`, `export`, `admin`, `execute`…) et éventuellement sa
     sous-ressource (`resource: 'action/xxx'` déclarée dans `resources[]` du manifeste).
   - Une vue retourne `ModuleView::make($title)->banner($html)->content($html)->status($texte)->state([...])`.
     Le bandeau se construit avec `$this->renderCore('banner', [...])` (icône, titre, sous-titre, actions HTML).
     Si la vue dépend de la chaîne de requête (`?page=2`), fixez la route canonique avec `->route('liste?page=2')`
     pour que l'URL et `->refresh()` la conservent.
   - Une action lit la requête (`$request->string()`, `int()`, `bool()`, `arrayInput()`, `file()`), valide
     (`throw new ValidationException(['champ' => 'message'])`), écrit via le dépôt, journalise (`$this->log()`) et
     retourne `ActionResult::ok($data, 'Message')` avec ses directives (`->refresh()`, `->navigate()`, `->close()`,
     `->status()`, `->dirty(false)`, `->banner()`).
   - Contrôles fins : `$this->require('delete')`, `$this->can('admin')`, `$this->rights([...])` pour adapter
     l'interface. **Masquer un bouton n'est jamais suffisant** : la route et le gestionnaire vérifient.
   - Hooks optionnels : `seed(): string` (données d'exemple, idempotent), `purge(): string` (rétention),
     `service(): ?object` (API intermodule des jeux partagés, voir `DemoService`).
6. **Écrire les gabarits** `templates/<vue>.php`. Variables reçues : les vôtres + `$module`, `$moduleId`,
   `$csrfToken`, `$baseUrl`, `$e` (échappement, **obligatoire** sur toute valeur), `$date`, `$datetime`. Enveloppez
   le HTML dans `<div class="module module-<id>">`, n'utilisez que les composants du CSS commun, et les comportements
   déclaratifs : `data-route`, `data-action` (+ `data-params`, `data-confirm`, `data-danger`, `data-prompt`),
   `<form data-action>` (+ `data-track-dirty`, `data-save-shortcut`, `data-auto-submit`, `data-confirm`),
   `select[data-route-select]`, `data-open-module`, `data-subtabs`. Composants serveur : `renderCore('state')`,
   `renderCore('pagination')`, `renderCore('sort_header')`.
7. **Styles propres** dans `assets/<id>.css`, chaque règle préfixée `.module-<id>` ; variables `--c-*`, `--sp-*`.
   Ne redéfinissez jamais `body`, `.btn`, `.table`, `.input`…
8. **JavaScript** (seulement s'il apporte quelque chose) dans `assets/<id>.js` :
   `Atelier.modules.register('<id>', { mount, render, suspend, resume, beforeClose, unmount })`. Écouteurs via
   `ctx.on(ctx.root, 'click', '[data-x]', handler)` (délégation, survit aux changements de vue), minuteries via
   `ctx.interval`/`ctx.timeout`, appels via `ctx.api.post('route', data)`, notifications via `ctx.toast`,
   `ctx.dialog`, `ctx.status`, `ctx.busy`. Dispatchez par écran avec `Atelier.util.splitRoute(view.route).path`.
   Bibliothèques tierces : dans `assets/vendor/` et déclarées dans `assets.vendor` (nom, version, licence).
9. **Données partagées** (`docs/donnees-partagees.md`) : `$this->ctx->shared->registry->register('<id>.<jeu>',
   $cle, $libelle, $userId)` donne un identifiant global ; ensuite `tags->attach()`, `relations->relate()`,
   `attachments->store()`. Un jeu `private` n'est jamais catalogué ni exposé.
10. **Installer et tester** : `php tools/console.php db:migrate`, `modules:list` (état `active`, aucune erreur),
    `db:seed` ; attribuer les droits dans le module Utilisateurs ; ouvrir le module et comparer chaque écran à la
    référence de ce module. En ligne de commande, chaque vue est vérifiable avec
    `curl -H "X-Atelier-Request: json" http://127.0.0.1:8000/m/<id>/<route>` (après connexion) : `ok:true` attendu.

## Vérifications effectuées à la livraison

- `php -l` sur tous les fichiers PHP, `node --check` sur les fichiers JS.
- `db:migrate` (migration `demo 001_create_demo` appliquée), `modules:list` (`demo active v1.0.0`), 120 articles créés.
- Toutes les vues `ok:true` en JSON ; cas d'erreur : 422 `validation` (champs), 403 `forbidden`, 404 `not_found`,
  409 `conflict`, 503 `unavailable` (avec référence), 500 `server` (avec référence), 401 `auth` sans session.
- Actions : formulaire valide/invalide (JSON et multipart), filtre instantané, actions groupées (dont avertissement sans
  sélection), export CSV (`text/csv`, `Content-Disposition`), directives `refresh`/`navigate`/`close`/`status`/`banner`,
  action réservée `secret`, registre, tag attaché/détaché, relation créée/supprimée (auto-relation refusée par le noyau),
  téléversement d'un fichier texte puis téléchargement `/files/{id}` et `?inline=1`, refus d'un `.exe`, suppression logique.
- Assets servis en 200 avec le bon `Content-Type` (`text/css`, `application/javascript`), feuille inexistante en 404.
