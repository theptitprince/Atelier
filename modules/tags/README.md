# Module « Tags partagés » (`tags`)

Vue transversale sur les tags partagés (portée `shared`) créés par les autres modules (Bloc-notes, etc.) : nuage et tableau, recherche, détail avec les informations taguées, et outils de gestion (renommage, fusion, suppression, doublons probables, tags inutilisés).

Le module ne possède **aucune table** : les tables `tags` et `info_tags` appartiennent au noyau. Il s'appuie sur `TagService`, `InfoRegistry` et `DatasetCatalog` (`$this->ctx->shared`) et sur une petite classe de lecture `TagQueries` pour les requêtes absentes du service (usage d'un tag, tags voisins).

## Écrans

| Route | Permission | Contenu |
|---|---|---|
| `list` | `open` | Tous les tags partagés avec leur nombre d'utilisations. Recherche (`?q=`), tri (`?sort=name\|usage&dir=asc\|desc`), bascule **Nuage** (taille selon l'usage, classes `.tag-cloud__item--1..5`) / **Tableau** (onglets internes `data-subtabs`). Chaque tag ouvre son détail. |
| `detail/{id}` | `open` | Nom, utilisations, date de création, auteur ; **informations portant le tag**, limitées aux jeux de données **partagés** (`catalog->findShared`) **et lisibles** par l'utilisateur (`catalog->canAccess(..., 'read')`), avec libellé, jeu, module et lien « Ouvrir » construit depuis l'`openRoute` du jeu (`{key}` remplacé par la clé locale, `data-open-module`/`data-open-route`). Les autres informations sont seulement comptées (« N information(s) d'autres jeux de données non affichées »). Bloc **Tags voisins** (tags les plus souvent présents sur les mêmes informations). Actions Renommer / Fusionner / Supprimer si `manage`. |
| `manage` | `manage` | Tableau de tous les tags avec cases à cocher (« tout sélectionner ») et actions groupées : fusionner la sélection dans un tag cible, supprimer la sélection ; **doublons probables** (forme normalisée identique une fois les accents, les séparateurs et un « s » final retirés) avec bouton « Fusionner » par paire ; **tags inutilisés** avec « Supprimer les tags inutilisés ». |

Le badge de la colonne (route `badge`) affiche le nombre de tags inutilisés aux utilisateurs disposant de `manage`.

## Permission propre

- `manage` — « Gérer les tags : renommer, fusionner, supprimer » (permission `tags.manage` du cahier des charges). Toutes les actions d'écriture la revérifient côté serveur (`$this->require('manage')`). Ressource ACL : `atelier/tags`.

## Actions (POST JSON, jeton CSRF)

| Action | Paramètres | Effet |
|---|---|---|
| `rename` | `id`, `name` | Renomme le tag. Nom vide ou > 60 caractères → erreur de validation ; nom déjà pris → message proposant la fusion. |
| `merge` | `id`, `target_id` **ou** `target` (nom) | Fusionne le tag `id` dans la cible puis le supprime. |
| `merge-many` | `ids[]`, `target_id` **ou** `target` | Fusionne chaque tag sélectionné dans la cible (la cible elle-même est ignorée). |
| `delete` | `id` | Supprime le tag (retiré de toutes les informations). |
| `delete-many` | `ids[]` | Supprime les tags sélectionnés. |
| `delete-unused` | — | Supprime tous les tags sans utilisation. |
| `search` | `q`, `sort`, `dir` | Navigation vers la liste filtrée (formulaire de recherche). |
| `badge` (GET) | — | `{ count, label }` : tags inutilisés (0 si l'utilisateur n'a pas `manage`). |

Chaque écriture est journalisée en catégorie `admin` : `tags.rename` (anciens/nouveaux noms), `tags.merge` (source, cible, usages déplacés), `tags.delete` (nom, usages).

## Données de démonstration

Le hook `seed()` crée les tags partagés `exemple`, `atelier`, `urgent` et `à-classer` s'ils n'existent pas (`console db:seed`).

## Fichiers

```
modules/tags/
├── manifest.json            identité, permission manage, navigation, badge, assets
├── src/TagsModule.php       routes, vues, actions, seed
├── src/TagQueries.php       lectures complémentaires (usage, tags voisins)
├── templates/list.php       nuage + tableau
├── templates/detail.php     détail d'un tag
├── templates/manage.php     gestion groupée, doublons, inutilisés
├── assets/tags.css          styles préfixés .module-tags (nuage, tailles)
├── assets/tags.js           « tout sélectionner », compteur, synchronisation de la suppression groupée
└── README.md
```

## Vérifier avec curl

```bash
curl -s -b cj.txt -H "X-Atelier-Request: json" http://127.0.0.1:8000/m/tags/list
curl -s -b cj.txt -H "X-Atelier-Request: json" -H "X-CSRF-Token: $CSRF" -H "Content-Type: application/json" \
     -X POST http://127.0.0.1:8000/m/tags/rename -d '{"id":3,"name":"important"}'
```

## Versions

- 1.0.0 — première version : liste (nuage/tableau), détail, gestion, badge, seed.
