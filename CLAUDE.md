# Atelier — repères pour le développement assisté

## Exécuter
- PHP portable : `_tools/php84/php.exe` (aucun PHP dans le PATH). Toujours l'utiliser pour lint (`-l`), tests et console.
- Serveur de dev : configuration `atelier` de `.claude/launch.json` (port 8000) ou `start.bat`.
- Console : `_tools/php84/php.exe tools/console.php <commande>` (`help`, `check`, `db:migrate`, `db:seed`, `modules:list`, `test`).
- Tests : `_tools/php84/php.exe tests/run.php [filtre]` — doivent rester verts avant tout commit.

## Conventions
- PHP 8.4 natif, `declare(strict_types=1)`, PSR-12, aucun framework ni Composer ; autoloader maison (`Atelier\` → `src/`, modules → `modules/<id>/src/`).
- Identifiants de code, tables et fichiers en **anglais** ; interface, commentaires, documentation, messages de commit en **français**.
- Aucun SQL dans les contrôleurs/gestionnaires de routes ni dans les gabarits : dépôts et services uniquement, requêtes préparées via `Database`.
- Échapper toute sortie HTML (`$e()` dans les gabarits, `Str::e`). Chaque action vérifie ses droits côté serveur (`AclService`) même si le bouton est masqué.
- Dates stockées en UTC `Y-m-d H:i:s`, affichées via `Clock` en `Europe/Paris`.
- Les modules ne modifient jamais `src/` ; ils respectent `docs/contrat-module.md` et `docs/manifest-schema.md`. Le module `demo` est la référence.
- Les fichiers modifiés par l'application sont écrits atomiquement (`Files::writeAtomic`).

## Versions (gérées de façon autonome, sans demander)
- Noyau `config/app.php` → `app.version` : `0.x.0` par lot de fonctionnalités, `0.x.y` correctif, `1.0.0` à la recette du socle. Chaque bump ajoute une section en tête de `docs/CHANGELOG.md`.
- Modules `manifest.json` → `version` : `1.x.0` fonctionnalité, `1.0.y` correctif, majeure si le contrat de données change.
- Le bump se fait dans le commit qui livre le changement.

## Périmètre décidé par l'utilisateur
- Modules v1 : home, users, modules-admin, settings, activity, notes, profile, demo. **Pas de module chat.** Demander avant d'ajouter un module.
- Compte de démonstration : `admin` / `123456789azerty` (défini dans `Kernel\Seeder`).

## Architecture en bref
- `public/index.php` → `Kernel\Application::run()` : session, découverte des modules, dispatch (`/login`, `/logout`, `/m/{module}/{route}`, `/api/…`, `/core/…`, `/module-assets/…`, `/files/{id}`), `ErrorHandler`.
- Réponses dynamiques : enveloppe JSON `{ok, data, message, errorId, error, directives?}` ; vues = `ModuleView` (bandeau + contenu + état), actions = `ActionResult`.
- Client : `public/assets/js/atelier.js` (onglets, bandeau, colonne, toaster, dialogues, ressources ref-comptées, comportements `data-route`/`data-action`/`form[data-action]`).
- Droits : ressources hiérarchiques `atelier/<module>/…`, règles user/group/all, résolution précise > utilisateur > groupes (refus prioritaire) > refus par défaut.
