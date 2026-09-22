# Module Corbeille (`trash`)

Corbeille globale d'Atelier (cahier des charges §8 « suppression logique ou corbeille », §16 « corbeille de 30 jours »). Version 1.0.0.

Le module ne possède aucune table : il **agrège** ce que les autres modules et le noyau ont supprimé logiquement, et délègue la restauration et la suppression définitive à leur propriétaire.

## Sources agrégées

| Source | Provenance | Restauration | Suppression définitive |
|---|---|---|---|
| `module` | Chaque module actif dont la classe d'entrée implémente `Atelier\Modules\TrashProviderInterface` (ex. Bloc-notes) | `restoreTrashItem($id)` du module | `purgeTrashItem($id)` du module |
| `attachment` | Pièces jointes du noyau (`attachments.deleted_at IS NOT NULL`) | `AttachmentService::restore()` | `AttachmentService::purge()` : fichier chiffré supprimé du stockage puis ligne effacée |

Visibilité d'une pièce jointe supprimée : l'utilisateur en est l'auteur (`uploaded_by`), **ou** il possède `update` sur le jeu de données de l'information rattachée (`atelier/<module>/data/<jeu>`), **ou** il est administrateur racine (`admin` sur `atelier`).

Un module n'est interrogé que s'il est utilisable (actif, manifeste valide) et si l'utilisateur a `open` dessus. Un fournisseur qui lève une exception n'interrompt pas la corbeille : l'erreur est consignée dans le journal technique et un avertissement s'affiche en tête de la liste.

Chaque élément est identifié par la clé `source:module:id` (`module` vaut `core` pour une pièce jointe), utilisée dans les actions groupées et comme `resource_ref` du journal d'activité.

## Écrans et actions

| Route | Nature | Permission | Rôle |
|---|---|---|---|
| `list` | vue | `open` | Tableau paginé (25/page, agrégation en mémoire puis tranche) : libellé, type (jeu de données ou pièce jointe), module, supprimé le, purge prévue (badge orange si moins de 3 jours). Sous-onglets Tous / Données des modules / Pièces jointes, filtres module et recherche (`data-auto-submit`), tri sur toutes les colonnes, sélection multiple. Paramètres : `source`, `module`, `q`, `sort` (`label`, `type`, `module`, `deleted_at`, `purge_at`), `dir`, `page`. |
| `filter` | action | `open` | Formulaire de filtres → `navigate('list?…')` |
| `restore` | action | `open` | `{ "source", "module", "id" }` ou `{ "key": "source:module:id" }` ; le fournisseur revérifie ses droits |
| `purge` | action | `open` | Idem, suppression définitive (confirmation `data-confirm data-danger`) |
| `restore-many` / `purge-many` | action | `open` | `{ "ids": ["module:notes:12", …] }` (ou chaîne séparée par des virgules). Continue malgré les échecs individuels ; `ActionResult::warning` avec compte rendu (« 2 éléments restaurés, 1 échec : … ») |
| `empty` | action | `purge-all` | Vide tout ce que l'utilisateur voit et peut purger. Confirmation forte : le champ `confirm` doit contenir `VIDER` (sinon erreur de validation) |
| `badge` | action GET | `open` | `{ "count": n, "label": "n éléments dans la corbeille" }` pour la colonne de gauche |

Toutes les actions répondent par `->refresh()` avec un message précis. Journal d'activité (catégorie `data`) : `trash.restore`, `trash.purge` (succès ou `failure` par élément), `trash.empty` (compte rendu global).

### Permission propre

`purge-all` — « Vider toute la corbeille ». À accorder avec parcimonie : le bouton du bandeau n'apparaît qu'aux détenteurs de la permission et l'action la revérifie côté serveur.

### Hook `purge()`

Appelé par `console maintenance:purge`. Le module ne purge rien lui-même (chaque fournisseur et le noyau appliquent leur propre rétention) ; il rend compte du nombre d'éléments dont la date de purge est dépassée mais encore présents. Hors requête utilisateur (console), seules les pièces jointes sont dénombrées : les fournisseurs ne répondent que pour un utilisateur connecté.

## Contrat des modules fournisseurs

Pour apparaître dans la corbeille globale, un module implémente `Atelier\Modules\TrashProviderInterface` sur sa classe d'entrée (voir `modules/notes/src/NotesModule.php`, section « Corbeille globale », pour l'implémentation de référence).

```php
use Atelier\Error\NotFoundException;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\TrashProviderInterface;
use Atelier\Support\Clock;

final class InventaireModule extends AbstractModule implements TrashProviderInterface
{
    /** Éléments en corbeille visibles par l'utilisateur courant uniquement. */
    public function trashItems(): array
    {
        $userId = $this->ctx->userId();
        $retention = max(1, $this->ctx->config->int('trash.retention_days', 30));
        $canRestore = $this->can('update');
        $canPurge = $this->can('delete');
        $items = [];
        foreach ($this->repo()->trashed($userId, $retention) as $row) {
            $purgeAt = Clock::parseUtc($row['deleted_at'])?->modify('+' . $retention . ' days');
            $items[] = [
                'id' => (string) $row['id'],                 // identifiant local stable (chaîne)
                'label' => (string) $row['name'],            // libellé affiché
                'dataset' => 'inventaire.item',              // code du jeu de données (nom lu dans le manifeste)
                'deleted_at' => (string) $row['deleted_at'], // UTC "Y-m-d H:i:s"
                'deleted_by' => $row['deleted_by'] ?? null,  // identifiant utilisateur ou null
                'purge_at' => $purgeAt === null ? null : Clock::utc($purgeAt), // ou null si aucune purge prévue
                'can_restore' => $canRestore,
                'can_purge' => $canPurge,
            ];
        }
        return $items;
    }

    /** Revérifie les droits puis restaure ; NotFoundException / ForbiddenException sinon. */
    public function restoreTrashItem(string $id): void
    {
        $this->require('update');
        if ($this->repo()->findTrashed((int) $id, $this->ctx->userId()) === null) {
            throw new NotFoundException('Cet élément n’est pas dans la corbeille.');
        }
        $this->repo()->restore((int) $id);
        $this->log('inventaire.restore', 'success', 'item:' . $id, 'Élément restauré depuis la corbeille globale');
    }

    /** Suppression physique + nettoyage du registre commun (tags, relations, pièces jointes détachées). */
    public function purgeTrashItem(string $id): void
    {
        $this->require('delete');
        if ($this->repo()->findTrashed((int) $id, $this->ctx->userId()) === null) {
            throw new NotFoundException('Cet élément n’est pas dans la corbeille.');
        }
        $this->ctx->db->transaction(function () use ($id): void {
            $this->repo()->purge((int) $id);
            $this->ctx->shared->registry->unregister('inventaire.item', $id);
        });
        $this->log('inventaire.purge', 'success', 'item:' . $id, 'Élément supprimé définitivement depuis la corbeille globale');
    }
}
```

Règles à respecter :

1. **Le module reste seul juge des droits.** `trashItems()` ne retourne que ce que l'utilisateur courant (`$this->ctx->userId()`) a le droit de voir ; `restoreTrashItem()` et `purgeTrashItem()` revérifient les droits et lèvent `NotFoundException` / `ForbiddenException` — la corbeille n'affiche que des boutons, elle ne décide de rien.
2. `id` est une chaîne stable ; `deleted_at` et `purge_at` sont en UTC au format `Y-m-d H:i:s` (`Clock::utc()`).
3. `dataset` est un code déclaré dans le manifeste : la corbeille affiche le `name` du jeu de données (« Notes ») en colonne Type.
4. Le module applique lui-même sa rétention dans son hook `purge()` (appelé par `console maintenance:purge`) ; la corbeille globale ne purge jamais à sa place.
5. Aucune dépendance au module `trash` : s'il est désactivé, le module fournisseur continue de fonctionner (sa propre vue corbeille, le cas échéant).

## Structure

```
modules/trash/
├── manifest.json               id trash, groupe tools, ordre 80, badge « badge », permission purge-all
├── src/TrashModule.php         routes, vue list, actions, hook purge()
├── src/TrashAggregator.php     collecte (fournisseurs + pièces jointes), normalisation, filtre/tri, restauration/purge
├── src/TrashQueries.php        SQL des pièces jointes supprimées (lecture) et comptage des expirées
├── templates/list.php          tableau, filtres, sélection multiple, états vides
├── assets/trash.css            styles préfixés .module-trash
├── assets/trash.js             « tout sélectionner », compteur, choix restore-many / purge-many du formulaire groupé
└── README.md
```

## Vérification rapide (curl)

```bash
rm -f /tmp/cj.txt
TOKEN=$(curl -s -c /tmp/cj.txt http://127.0.0.1:8000/login | grep -oP 'name="_token" value="\K[^"]+')
curl -s -o /dev/null -b /tmp/cj.txt -c /tmp/cj.txt -X POST http://127.0.0.1:8000/login -d "_token=$TOKEN&username=admin&password=<mot de passe>"
CSRF=$(curl -s -b /tmp/cj.txt http://127.0.0.1:8000/ | grep -oP 'name="csrf-token" content="\K[^"]+')

curl -s -b /tmp/cj.txt -H "X-Atelier-Request: json" "http://127.0.0.1:8000/m/trash/list?source=module&q=test"
curl -s -b /tmp/cj.txt -H "X-Atelier-Request: json" "http://127.0.0.1:8000/m/trash/badge"
curl -s -b /tmp/cj.txt -H "X-Atelier-Request: json" -H "X-CSRF-Token: $CSRF" -H "Content-Type: application/json" \
     -X POST http://127.0.0.1:8000/m/trash/restore -d '{"source":"module","module":"notes","id":"12"}'
curl -s -b /tmp/cj.txt -H "X-Atelier-Request: json" -H "X-CSRF-Token: $CSRF" -H "Content-Type: application/json" \
     -X POST http://127.0.0.1:8000/m/trash/purge-many -d '{"ids":["module:notes:12","attachment:core:<id>"]}'
```

Documentation : `docs/developpeur-module.md`, `docs/contrat-module.md`, `docs/manifest-schema.md`, `src/Modules/TrashProviderInterface.php`.
