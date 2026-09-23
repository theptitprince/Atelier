# Module Projets (`project`)

Un **projet** est un conteneur qui regroupe des informations de tous les modules autour d'un même sujet : un objet à fabriquer plus tard, un voyage, un achat futur documenté, des travaux… Il porte ses propres tâches, un journal de bord et des documents joints, et se relie par le registre commun aux pages, lieux GPS, opérations du budget, équipements, actualités archivées, etc.

Version 1.0.0 — dépend du noyau Atelier ≥ 0.7 ; les modules `geo`, `budget`, `explorer` et `map` sont détectés et utilisés s'ils sont présents, jamais requis.

## Écrans

| Route | Permission | Contenu |
|---|---|---|
| `list` (défaut) | `open` | Cartes par statut (sous-onglets Tous / Idée / Planifié / En cours / Terminé / Abandonné), recherche, filtre par tag, tri (mise à jour, échéance, titre, priorité), indicateurs (tâches faites/total, éléments liés, documents, budget estimé, retard) |
| `show/{id}` | `open` | Fiche : statut modifiable, dates, priorité, budget, responsable, description (BBCode), tâches, journal de bord, documents, éléments liés groupés par module, lieux GPS, budget, tags. `{id}` accepte l'identifiant numérique ou l'identifiant lisible (`show/voyage-en-ecosse`) |
| `new` | `create` | Création |
| `edit/{id}` | `update` | Modification (`data-track-dirty`, Ctrl+S, tags partagés) |
| `trash` | `open` | Projets supprimés (restauration et purge réservées à `delete`) |

## Actions (POST, JSON ou formulaire)

| Action | Permission | Paramètres |
|---|---|---|
| `filter` | `open` | `q`, `tag`, `sort`, `status` → redirige vers la liste filtrée |
| `save` | `create` / `update` | `id?`, `title`*, `status`, `summary`, `description`, `start_date`, `due_date` (AAAA-MM-JJ), `budget_estimate` (euros, « 1250,50 »), `priority` (1-3), `owner_id`, `tags` |
| `set-status` | `update` | `id`, `status` (`idea`, `planned`, `active`, `done`, `dropped`) |
| `delete` / `restore` / `purge` | `delete` | `id` — suppression logique, restauration, suppression définitive |
| `task-add` | `update` | `id`, `title`*, `due_date?` |
| `task-toggle` | `update` | `id`, `task_id`, `done?` (bascule si absent) |
| `task-move` | `update` | `id`, `task_id`, `direction` (`up` / `down`) |
| `task-delete` | `update` | `id`, `task_id` (suppression logique) |
| `note-add` / `note-delete` | `update` | `id`, `content` (BBCode) / `id`, `note_id` |
| `attach` | `update` | multipart : `id`, `files[]` (ou `file`), `label?` — type, taille et quotas contrôlés par le noyau |
| `attachment-delete` | `update` | `id`, `attachment_id` (suppression logique, corbeille des fichiers) |
| `link` | `update` | `id`, `to` (UUID du registre), `type?` |
| `unlink` | `update` | `id`, `relation_id` |
| `lookup` (GET) | `open` | `q`, `exclude?` → `{results: [{id, label, dataset, dataset_name, module, module_name}]}` |
| `badge` (GET) | `open` | nombre de projets « en cours » ayant une tâche non faite en retard |

## Éléments liés

- Le projet est inscrit au registre commun (`project.project` / id) dès sa création : tags partagés, relations et pièces jointes reposent sur cet identifiant.
- La recherche `lookup` applique la **règle de visibilité de l'Explorateur** : seules les informations des jeux de données partagés que l'utilisateur peut lire sont proposées ; un jeu privé ou non autorisé n'est jamais révélé, ni dans la recherche ni dans la liste des relations (un compteur « relations non accessibles » est affiché).
- Type par défaut `part_of` (« Fait partie du projet ») : relation de l'information **vers** le projet. Les autres types (`related`, `references`, `depends_on`) partent du projet. Une cible `geo.point` est rattachée par le service du module GPS (relation `located_at`), ce qui la fait apparaître sur la fiche du point, sur la carte et dans le bloc « Lieux ».
- Chaque élément relié s'ouvre dans son module quand le jeu de données déclare `openRoute` dans son manifeste, sinon dans l'Explorateur (`info/{uuid}`) s'il est actif.
- Bloc **Budget** (si le module `budget` est actif et lisible) : budget estimé du projet et opérations reliées. Le total des opérations n'est calculé que si le service du module Budget expose une lecture par identifiant (`transaction(int $viewer, int $id)`), ce qui n'est pas le cas en 1.0.0 : le bloc affiche alors la liste des opérations sans montant.

## Données

| Jeu | Visibilité | Table(s) | Notes |
|---|---|---|---|
| `project.project` | partagé (`read`), `openRoute: show/{key}` | `project_project`, `project_note` | titre, identifiant lisible unique, statut, résumé, description BBCode, dates, budget estimé (centimes), priorité, responsable, `deleted_at` |
| `project.task` | privé | `project_task` | tâches ordonnées (`position`), faites ou non, échéance, `deleted_at` |

Suppression logique : un projet supprimé passe dans la corbeille du module **et** dans la corbeille globale (`TrashProviderInterface`, identifiants = id du projet) ; il est purgé après `trash.retention_days` jours (hook `purge()`), avec ses tâches, son journal et son inscription au registre. Le jeu privé `project.task` n'est jamais exposé aux autres modules.

## Service intermodule (`ProjectService`)

```php
$projects = $this->ctx->moduleService('project');
$projects->list($viewerId, 'active');          // projets actifs (champs publics), filtre statut optionnel
$projects->find($viewerId, 12);                // un projet ou null
$projects->infoId($viewerId, 12);              // UUID du registre commun
$projects->linkedInfoIds($viewerId, 12);       // informations reliées, limitées aux jeux lisibles par $viewerId
```

Chaque méthode vérifie `catalog->canAccess($viewerId, 'project.project', 'read')`. Déclarer `consumes: ["project.project"]` dans le manifeste consommateur.

## Données de démonstration

`console db:seed` crée deux projets si la table est vide : « Voyage en Écosse » (planifié, tâches dont une en retard, journal, budget 3 200 €) et « Établi d'atelier » (idée, budget 450 €).

## Vérification rapide

```bash
php tests/run.php Project
curl -s -b cj.txt -H "X-Atelier-Request: json" http://127.0.0.1:8000/m/project/list
curl -s -b cj.txt -H "X-Atelier-Request: json" "http://127.0.0.1:8000/m/project/lookup?q=Accueil"
```

## Historique

- 1.0.0 — liste, fiche, édition, tâches, journal, documents, éléments liés (recherche transversale), lieux GPS, budget, tags, corbeille (module + globale), badge, service intermodule, données d'exemple.
