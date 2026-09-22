# Module Pages (`wiki`)

Pages de connaissance de type wiki : rédigées avec l'éditeur BBCode commun, reliées entre elles, illustrées par les fichiers joints, taguées et rattachées à des lieux GPS. Chaque enregistrement crée une version.

## Syntaxe (en plus du BBCode commun)

| Syntaxe | Rendu |
|---|---|
| `[[Titre de page]]`, `[[cible\|libellé]]` | Lien interne ; rouge si la page n'existe pas (un clic ouvre la création avec le titre prérempli). La cible est un titre ou un identifiant (`slug`). |
| `[file=identifiant]`, `[file=identifiant\|légende\|largeur]` | Image affichée (fichier joint de type image, largeur en px facultative) ou lien de téléchargement pour les autres types. Le téléchargement passe par `/files/<id>` (droits contrôlés par le noyau). |
| `[point=n]`, `[point=n\|libellé]` | Lieu du module Coordonnées GPS : lien vers la fiche du point (coordonnées DMS en info-bulle). |

Aucun HTML saisi n'est rendu : les compléments sont remplacés par des jetons avant le rendu BBCode (qui échappe tout) puis réinjectés sous forme sûre.

## Fonctions

- **Liste** : recherche dans titres et contenus, tri, extraits, tags, nombre de liens entrants ; bouton « Accueil » si une page `accueil` existe.
- **Lecture** : contenu rendu, tags, lieux (fiche du point, position sur la carte), pièces jointes (téléchargement, « Joindre un fichier »), pages qui pointent ici (rétroliens), pages à créer (liens rouges), autres relations du registre commun.
- **Édition** : titre (unique, sert de cible aux liens), contenu (éditeur BBCode), tags (saisie commune avec suggestions), lieux (recherche des points GPS par nom, code ou coordonnées ; relation « localisé à »), insertion au curseur de `[[Titre]]`, `[file=…]` et `[point=…]`, aide sur la syntaxe.
- **Historique** : versions (50 conservées), lecture d'une version, restauration (crée une nouvelle version).
- **Corbeille** : suppression logique, restauration, suppression définitive, purge après `trash.retention_days`.

## Droits

`open` (lire, historique), `create` (nouvelle page), `update` (modifier, restaurer une version, lier des lieux), `delete` (corbeille, restauration, suppression définitive) ; `read` sur `atelier/wiki/data/page` pour l'accès intermodule.

## Service intermodule (`$this->ctx->moduleService('wiki')`)

```php
$wiki = $this->ctx->moduleService('wiki');
$wiki->find(3);                 // { id, slug, title, excerpt, content, updated_at, route } ou null
$wiki->findBySlug('accueil');
$wiki->search('procédure', 10);
$wiki->infoId(3);               // identifiant du registre commun (tags, relations, pièces jointes)
```

## Données

`wiki_page` (page courante, `slug` unique, `revision`, `deleted_at`), `wiki_revision` (historique), `wiki_link` (liens internes sortants pour les rétroliens et les pages manquantes). Jeu partagé `wiki.page` (lecture). Chaque page est inscrite au registre commun (`wiki.page` / identifiant).
