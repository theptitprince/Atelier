# Module Carte (`map`)

Carte interactive des points GPS référencés par le module Coordonnées GPS (`geo`), avec ce qui leur est relié : informations rattachées des autres modules (archives d'actualité, pages, comptes…), pièces jointes et tags.

## Fonctions

- **Fonds** : OpenStreetMap ou OpenSeaMap (carte marine = fond OpenStreetMap + balisage maritime) ; le balisage peut aussi être superposé au fond standard. Les tuiles sont chargées depuis `tile.openstreetmap.org` et `tiles.openseamap.org` (autorisés dans la politique CSP du noyau) : la carte nécessite un accès Internet.
- **Calques** : par tag, par module relié, « avec pièces jointes », « sans lien » ; combinables avec la recherche (nom, code, adresse, description, tags, libellés reliés).
- **Liste** triable par nom, dernière modification, nombre de liens ou distance au centre de la carte ; un clic recentre la carte et ouvre la fenêtre du point.
- **Fenêtre d'un point** : coordonnées, adresse, altitude, tags, informations reliées (ouverture du module d'origine), nombre de pièces jointes, fiche du point, lien OpenStreetMap.
- **Préférences par utilisateur** : fond, balisage, position, zoom et tri sont mémorisés (préférences « map »).
- Ouverture ciblée : `index?point=<id>` recentre sur un point (utilisé par les autres modules).

## Droits

`open` sur le module, et `read` sur le jeu partagé `geo.point` (`atelier/geo/data/point`) : la carte consomme exclusivement le service intermodule du module `geo` (`all()`, `infosAt()`), plus le registre commun pour les tags et les pièces jointes.

## Bibliothèque tierce

Leaflet 1.9.4 (BSD-2-Clause), servie localement depuis `assets/vendor/leaflet/` et déclarée dans le manifeste (`assets.vendor`).
