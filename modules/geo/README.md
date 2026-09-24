# Module Coordonnées GPS (`geo`)

Référentiel de points géographiques nommés (WGS 84), partagé entre les utilisateurs autorisés et exposé aux autres modules comme **clé de rattachement** : une information de n'importe quel module peut être « localisée à » un point.

## Fonctions

- Liste, recherche (nom, code, adresse) et tri ; une recherche par coordonnées liste les points à moins de 25 km, du plus proche au plus éloigné.
- Saisie souple des coordonnées : degrés décimaux (`48.8566, 2.3522`, virgule décimale acceptée), degrés-minutes-secondes (`48°51'24"N 2°21'03"E`), degrés-minutes décimales (`N 48°51.400' E 2°21.050'`). Aperçu immédiat (décimal, DMS, lien OpenStreetMap, points déjà référencés à moins de 5 km).
- Tags partagés dans le formulaire de création et de modification (saisie commune `data-tags-input` avec suggestions, 20 tags de 60 caractères au plus), comme dans les autres modules ; l'ajout et le retrait restent possibles depuis la fiche. Un enregistrement sans champ `tags` (import, appel d'API) laisse les tags existants intacts.
- Fiche du point : formats décimal / DMS / URI `geo:`, liens OpenStreetMap et Google Maps, tags partagés, informations rattachées (avec ouverture du module d'origine), pièces jointes, points à proximité.
- Rattachement d'une information : recherche dans les jeux de données partagés que l'utilisateur peut lire, relation typée `located_at` (information → point).
- Import CSV (en-têtes reconnus sans accent ni casse, coordonnées en deux colonnes ou une seule), export CSV, corbeille avec restauration et purge automatique (`trash.retention_days`).

## Corbeille

Un point supprimé (`deleted_at`) reste `trash.retention_days` jours (30 par défaut) en corbeille ; ses tags, relations et rattachements de pièces jointes sont conservés jusqu'à la purge. Le hook `purge()` (`console maintenance:purge`) le supprime physiquement ensuite et le retire du registre commun.

- **Vue du module** (`trash`, droit `delete`) : liste des points en corbeille avec « Restaurer » (action `restore`) et « Supprimer » (action `purge`) ; bouton « Voir toute la corbeille » vers le module Corbeille s'il est actif.
- **Corbeille globale** : la classe d'entrée implémente `Atelier\Modules\TrashProviderInterface`. `trashItems()` expose les points non expirés (jeu `geo.point`, libellé « Nom [code] », `purge_at` = suppression + rétention, `deleted_by` inconnu → `null`) ; `restoreTrashItem()` et `purgeTrashItem()` réutilisent exactement la logique des actions du module (revérification du droit `delete`, `NotFoundException` si le point n'est pas en corbeille, retrait du registre commun à la purge). Les points étant communs à tous les utilisateurs autorisés, restaurer comme purger exigent `delete`, comme dans la vue du module.
- **Registre commun** (1.2.0) : la mise à la corbeille signale le point au registre (`registry->trash`), la restauration l'en retire (`registry->restore`). Un point en corbeille n'apparaît donc plus sous ses tags, dans l'Explorateur, ni dans les éléments liés des autres modules ; ses tags et rattachements sont conservés et réapparaissent à la restauration. Symétriquement, la fiche d'un point n'affiche plus les informations rattachées qui sont en corbeille, et une information en corbeille ne peut pas être rattachée. La migration `002_registry_trash` a marqué les points déjà en corbeille.
- Journal d'activité : `geo.delete`, `geo.restore`, `geo.purge` (le message précise « depuis la corbeille globale » le cas échéant).

Le module ne dépend pas du module `trash` : sa propre corbeille fonctionne sans lui.

## Droits

| Permission | Effet |
|---|---|
| `open` | Liste, fiche, aperçu des coordonnées, `lookup` |
| `create` | Nouveau point |
| `update` | Modification, tags, rattachements |
| `delete` | Corbeille, restauration, suppression définitive |
| `import` sur `atelier/geo/action/import` | Import CSV |
| `export` sur `atelier/geo/action/export` | Export CSV |
| `read` sur `atelier/geo/data/point` | Accès intermodule (service) |

## Service intermodule (`$this->ctx->moduleService('geo')`)

Toutes les méthodes vérifient le droit `read` sur le jeu partagé `geo.point`.

```php
$geo = $this->ctx->moduleService('geo');

$geo->find(12);                       // point (id, code, name, latitude, longitude, altitude, address, description, decimal, dms, label) ou null
$geo->get(12);                        // idem, NotFoundException si absent
$geo->findByCode('TOUR-EIFFEL');
$geo->findMany([1, 2, 3]);            // indexés par identifiant
$geo->label(12);                      // "Nom [code]"
$geo->options();                      // [['id' => 1, 'label' => '…'], …] pour un <select>
$geo->search('eiffel', 10);           // par nom/code/adresse ; ou par coordonnées → points proches (distance_km)
$geo->nearby(48.85, 2.35, 10.0, 20);  // rayon en km
$geo->coordinates(12)->dms();         // objet Coordinates : decimal(), dms(), distanceTo(), openStreetMapUrl()…
$geo->distanceKm(12, 15);

// Rattachement par le registre commun : l'information du module appelant doit y être inscrite.
$infoId = $this->ctx->shared->registry->register('mon-module.objet', (string) $id, $titre, $userId);
$geo->attach($infoId, 12);            // relation located_at (information → point)
$geo->detach($infoId, 12);
$geo->pointsOf($infoId);              // points auxquels l'information est rattachée (avec relation_id)
$geo->infosAt(12);                    // informations rattachées au point (jeux partagés lisibles seulement)
```

Deux stratégies pour un module consommateur :

1. **Clé étrangère** : stocker `geo_point_id` dans sa table et résoudre l'affichage avec `find()` / `label()` ; proposer un sélecteur alimenté par `options()` ou, en JavaScript, par `GET /m/geo/lookup?q=…` (réponse `{ items: [{ id, label, decimal, dms, distance }] }`).
2. **Relation** : inscrire l'information au registre commun puis `attach()` ; elle apparaît alors sur la fiche du point avec un lien vers le module d'origine.

Le manifeste du module consommateur déclare `"consumes": ["geo.point"]`.

## Données

Table `geo_point` (jeu partagé `geo.point`) : `id`, `code` (unique, facultatif), `name`, `latitude`, `longitude`, `altitude`, `address`, `description`, `created_by`, `created_at`, `updated_at`, `deleted_at`. Chaque point est inscrit au registre commun (`geo.point` / identifiant) avec le libellé « Nom [code] ».
