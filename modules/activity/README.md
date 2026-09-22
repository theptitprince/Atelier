# Module `activity` — Journal d'activité

Consultation, en lecture seule, du journal d'activité fonctionnel et de sécurité alimenté par le noyau (`activity_log`) et par les modules via `$this->log(...)`.

## Fonctionnalités

- **Liste** (`list`) : tableau paginé côté serveur (25 entrées par défaut, sélecteur 25/50/100, jamais plus de 100), tri par date, utilisateur, module, action ou résultat, filtres cumulables : période (début/fin, interprétées dans le fuseau d'affichage et converties en UTC), utilisateur, module, type d'action, résultat, ressource, recherche libre. Bouton « Réinitialiser les filtres ». Badges de résultat colorés, lien vers le détail de chaque entrée, état vide.
- **Détail** (`detail/{id}`) : toutes les colonnes de l'entrée, détails JSON formatés, liens « Filtrer sur cet utilisateur / ce module / cette action », retour au journal.
- **Export CSV** (`export.csv?…`, mêmes filtres et tri que la liste) : BOM UTF-8, séparateur `;`, fins de ligne CRLF, 10 000 lignes au plus, téléchargé en pièce jointe. Chaque export est journalisé (`activity.export`).

Les entrées ne sont **jamais** modifiables ni supprimables depuis ce module : aucune route d'écriture n'est exposée en dehors de l'action `filter`, qui ne fait que rediriger vers `list?…`.

## Droits

| Ressource ACL | Permission | Usage |
|---|---|---|
| `atelier/activity` | `open` | Ouvrir le module, consulter la liste et le détail |
| `atelier/activity/screen/list` | `view` | Afficher l'entrée de navigation |
| `atelier/activity/action/export` | `export` | Bouton et route d'export CSV (vérifié côté serveur dans le gestionnaire) |

## Paramètres de la route `list`

`from`, `to` (`aaaa-mm-jj` ou `jj/mm/aaaa`), `user_id`, `module`, `action`, `result` (`success|failure|denied|error`), `resource`, `q`, `sort` (`occurred_at|username|module_id|action|result`), `dir` (`asc|desc`), `per_page` (`25|50|100`), `page`.

La taille de page par défaut reprend la préférence utilisateur `pageSize` (module `profile`) lorsqu'elle vaut 25, 50 ou 100.

## Données

Le module ne possède aucune table : le jeu `activity.log` (privé, lecture seule) documente la table `activity_log` du noyau, à laquelle il accède uniquement via `Atelier\Activity\ActivityLog`.

## Fichiers

```
modules/activity/
├── manifest.json
├── src/ActivityModule.php      # routes, vues, action filter, export CSV
├── src/ActivityFilters.php     # normalisation des filtres, dates → UTC, construction des routes
├── templates/list.php
├── templates/detail.php
├── assets/activity.css
└── README.md
```
