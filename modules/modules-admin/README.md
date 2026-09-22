# Gestion des modules (`modules-admin`)

Module d’administration : état des modules installés, détail d’un module, ordre d’affichage de la colonne de navigation et catalogue des jeux de données.

## Vues

| Route | Permission | Contenu |
|---|---|---|
| `list` | `open` | Tableau des modules (nom, identifiant, version, groupe, état effectif, validité du manifeste avec détail des erreurs dépliable). Actions par ligne pour les administrateurs : activer, mettre en maintenance, désactiver (confirmation rappelant que les données sont conservées et listant les jeux de données consommés par d’autres modules), détail. Bandeau : « Resynchroniser les manifestes » (admin) et « Actualiser ». |
| `detail/{id}` | `open` | Identité, état et surcharges, navigation, permissions propres (et leur synchronisation en base), ressources ACL synchronisées, jeux de données produits (visibilité, tables et volumes, champs, opérations, version, consommateurs) et consommés (état du propriétaire), modules dépendants, ressources statiques, migrations (version courante, appliquées, en attente), dix dernières erreurs / refus / échecs du journal d’activité. |
| `order` | `admin` | Groupes (libellé et ordre modifiables), modules de chaque groupe et accès directs de chaque module. Boutons « Monter / Descendre » accessibles au clavier, glisser-déposer HTML5, changement de groupe par liste déroulante, réinitialisation globale, par groupe ou par module. |
| `datasets` | `open` | Catalogue complet (`DatasetCatalog::all()`) : visibilité, propriétaire et son état, tables, opérations, version, producteurs et consommateurs ; section « Jeux de données non alimentés » (`orphaned()`). |

## Actions (POST, permission `admin`, journalisées)

| Action | Paramètres | Effet | Journal |
|---|---|---|---|
| `set-state` | `id`, `state` (`active`/`inactive`/`maintenance`) | Surcharge `status` du module (retirée si égale au manifeste). Le module `modules-admin` ne peut pas se désactiver lui-même. | `module.state` |
| `sync` | — | Relit les manifestes (`discover(true)`), invalide l’empreinte, exécute `syncAll()` (migrations, ressources, permissions, catalogue) et réécrit l’empreinte. Retourne le journal. | `module.sync` |
| `move` | `type` (`group`/`module`/`nav`), `id`, `direction`, `module` (pour `nav`) | Échange avec le voisin puis réécrit des ordres explicites (10, 20, 30…). | `module.reorder` |
| `reorder` | `type`, `ids[]`, `group` (modules) ou `module` (nav) | Applique une liste ordonnée complète ; un module déposé dans un autre groupe change de groupe. | `module.reorder` |
| `set-group` | `id`, `group` | Déplace un module en fin d’un autre groupe. | `module.group` |
| `group-save` | `id`, `label`, `order` | Libellé (≤ 60 caractères) et ordre (0–9999) d’un groupe. | `module.group_save` |
| `reset-order` | `scope` (`all`/`group`/`module`), `id` | Supprime les surcharges d’ordre, de groupe, de navigation et de libellé concernées ; les états des modules sont conservés. | `module.reorder_reset` |

Toutes les écritures passent par `ModuleManager::setModuleOverride()`, `setNavigationOrder()` et `setGroupOverride()` (fichier `var/config/modules.json`, écriture atomique).

## Fichiers

- `src/ModulesAdminModule.php` — routes, vues et actions.
- `src/ModuleInfoRepository.php` — seules requêtes SQL du module : migrations, ressources et permissions synchronisées, erreurs récentes, volumes des tables.
- `templates/list.php`, `detail.php`, `order.php`, `datasets.php`.
- `assets/modules-admin.css` — styles préfixés `.module-modules-admin`.
- `assets/modules-admin.js` — glisser-déposer et appel de `Atelier.nav.refresh()` après chaque action réussie (événements `atelier:action` / `atelier:submitted`).

## Notes

- La restauration d’un module désactivé ou l’activation de ce module lui-même restent possibles par la console : `console modules:set <id> active`.
- Les modules dont le manifeste est invalide apparaissent en erreur avec la liste des problèmes ; leur état ne peut pas être modifié tant que le manifeste n’est pas corrigé et resynchronisé.
