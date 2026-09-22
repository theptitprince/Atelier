# Module Explorateur (`explorer`)

Vue transversale « wiki interactif » d’Atelier : parcourir les informations partagées de **tous** les modules, leurs tags, leurs relations et leurs pièces jointes, depuis un seul endroit. Le module ne possède aucune table ni aucun jeu de données : il lit exclusivement le registre commun (`info_registry`), les tags, relations et pièces jointes du noyau, et le catalogue des jeux de données partagés.

Version 1.0.0 — groupe `tools`, icône `layers`, route par défaut `search`.

## Rôle

- Retrouver une information enregistrée dans le registre commun quel que soit son module d’origine (libellé, jeu de données, module, tag).
- Voir d’un coup d’œil ses tags, ses relations (dans les deux sens), ses pièces jointes et les dernières entrées du journal la concernant.
- Enrichir l’information sans ouvrir son module : ajouter ou retirer des tags partagés, créer ou supprimer une relation typée, joindre ou supprimer (logiquement) un fichier.
- Ouvrir l’information dans son module d’origine grâce à la route `openRoute` déclarée par le jeu de données (`data-open-module` / `data-open-route`).
- Consulter le catalogue des jeux partagés lisibles, la liste de toutes les relations et de toutes les pièces jointes visibles.

## Règle de visibilité (essentielle)

Une information du registre n’est visible que si **les deux** conditions sont réunies :

1. son jeu de données est **partagé** (`catalog->findShared($code) !== null`) — un jeu `private` (par exemple `notes.note`) n’est jamais exposé ;
2. l’utilisateur a le droit **`read`** sur ce jeu (`catalog->canAccess($userId, $code, 'read')`), c’est-à-dire sur la ressource ACL `atelier/<module>/data/<nom>`.

La liste des codes lisibles (`catalog->readableCodes($userId)`) est calculée **une fois par requête** et injectée dans toutes les requêtes SQL (`dataset_code IN (...)`). Conséquences :

- une information non visible n’apparaît ni dans la recherche, ni dans la recherche de cible (`lookup`), ni dans les tableaux de relations et de pièces jointes, **même si elle porte un tag ou participe à une relation** ;
- dans le détail d’une information, une relation vers une information non visible affiche « information non accessible » sans libellé, clé ni jeu de données ;
- le détail (`info/{id}`) d’une information non visible répond 404, sans révéler son existence ;
- toute **modification** (tags, relations, pièces jointes) exige en plus le droit **`update`** sur le jeu de données de l’information concernée ; pour la suppression d’une relation, c’est l’information *source* qui compte, et l’autre information doit être visible.

Le téléchargement des fichiers passe par la route du noyau `/files/{id}` (et `?inline=1` pour les PDF, images et texte), qui applique son propre contrôle `read`.

## Écrans

| Route | Description |
|---|---|
| `search?q=&dataset=&module=&tag=&sort=&page=` | Recherche paginée (25 par page) : texte libre sur le libellé, jeu de données, module, tag (composant commun `data-tags-input`, un seul tag), tri (libellé, plus récentes, jeu). Pour chaque résultat : libellé, jeu, module, tags cliquables (filtre), nombre de relations et de pièces jointes, « Détail » et « Ouvrir dans le module ». Le formulaire est en soumission automatique (`data-auto-submit`) ; le changement du tag, qui n’émet qu’un événement `input`, est relayé par `assets/explorer.js`. |
| `info/{id}` | Détail : identité (libellé, jeu, module, clé locale, identifiant global, créé par / le), bouton « Ouvrir dans le module », tags (retrait, ajout), relations entrantes/sortantes (retrait, ajout par type + cible choisie par recherche `lookup`), pièces jointes (téléchargement, affichage, suppression logique, téléversement multipart), historique (10 dernières entrées du journal dont `resource_ref` contient `info:{id}`). |
| `datasets` | Cartes des jeux partagés lisibles : nom, module, code, version, description, champs, opérations, droits de l’utilisateur, producteurs, consommateurs, route d’ouverture, nombre d’informations enregistrées dans le registre ; liens « Rechercher dans ce jeu » et « Pièces jointes ». |
| `relations?type=&page=` | Tableau paginé de toutes les relations dont les deux informations sont visibles ; filtre par type (avec compteur) ; suppression si `update` sur la source. |
| `attachments?dataset=&page=` | Tableau paginé des pièces jointes actives (`deleted_at IS NULL`) rattachées à des informations visibles ; filtre par jeu ; liens de téléchargement ; nombre et taille totale. |

## Actions

| Action | Méthode | Paramètres | Contrôles |
|---|---|---|---|
| `search` | POST | `q, dataset, module, tag, sort` | redirige vers la vue `search` filtrée (page 1) |
| `lookup` | GET | `q, exclude` | informations visibles dont le libellé contient `q` (15 max) : `{ results: [{ id, label, dataset, dataset_name, module }] }` |
| `tag-add` | POST | `info, tags` (liste séparée par des virgules) | visible + `update` |
| `tag-remove` | POST | `info, tag_id` | visible + `update` |
| `relate` | POST | `info, type, to, comment` | source visible + `update`, cible visible, type parmi `RelationService::DEFAULT_TYPES` |
| `unrelate` | POST | `id` | source visible + `update`, cible visible |
| `upload` | POST multipart | `info, file` | visible + `update` ; type, taille et quotas contrôlés par `AttachmentService` |
| `attachment-delete` | POST | `id` | information rattachée visible + `update` ; suppression logique (purge par la maintenance) |

Chaque écriture est journalisée en catégorie `data` avec `resource_ref = info:{uuid}` : `explorer.tag_add`, `explorer.tag_remove`, `explorer.relate`, `explorer.unrelate`, `explorer.upload`, `explorer.attachment_delete`. Ces entrées alimentent l’historique du détail.

## Structure

```
modules/explorer/
├── manifest.json               identité, navigation (4 écrans), assets ; aucun jeu de données
├── src/ExplorerModule.php      routes, visibilité, décoration (noms de jeu/module, openRoute), actions
├── src/ExplorerQueries.php     requêtes SQL transversales (recherche paginée, comptages, relations, pièces jointes)
├── templates/search.php        recherche
├── templates/info.php          détail
├── templates/datasets.php      catalogue
├── templates/relations.php     relations
├── templates/attachments.php   pièces jointes
├── assets/explorer.css         styles préfixés .module-explorer
├── assets/explorer.js          soumission du filtre tag, recherche de cible (lookup)
└── README.md
```

Le module utilise `$this->ctx->shared` (`registry`, `tags`, `relations`, `attachments`, `catalog`) pour tout ce que le noyau fournit ; `ExplorerQueries` ne couvre que les lectures paginées et filtrées qui n’existent pas dans les services communs (recherche multi-critères, comptages, jointures relations/pièces jointes ↔ registre).

## Droits

- `open` sur `atelier/explorer` : accès au module et à ses quatre écrans (`atelier/explorer/screen/<id>`).
- Ce que l’utilisateur voit et peut modifier dépend ensuite uniquement de ses droits `read` / `update` sur les jeux de données partagés des autres modules (`atelier/<module>/data/<nom>`), jamais des droits du module Explorateur lui-même.

## Vérification rapide (curl)

```bash
curl -s -b cj.txt -H "X-Atelier-Request: json" "http://127.0.0.1:8000/m/explorer/search?q=perceuse"
curl -s -b cj.txt -H "X-Atelier-Request: json" "http://127.0.0.1:8000/m/explorer/lookup?q=bureau"
curl -s -b cj.txt -H "X-Atelier-Request: json" -H "X-CSRF-Token: $CSRF" -X POST \
     http://127.0.0.1:8000/m/explorer/upload -F "info=<uuid>" -F "file=@document.pdf"
```

Une recherche sur le titre d’une note du Bloc-notes (jeu privé `notes.note`) ne doit renvoyer aucun résultat.
