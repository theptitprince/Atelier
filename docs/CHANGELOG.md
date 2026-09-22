# Journal des versions

Ce fichier est affiché dans l’application en cliquant sur le numéro de version de la barre d’état. Format : une section par version, la plus récente en premier.

## 0.5.0 — 22/09/2026

### Noyau
- Onglets réorganisables par glisser-déposer et au clavier (Ctrl+←/→ sur l’onglet actif).
- Bouton « Tout fermer » dans la barre d’onglets et menu contextuel d’onglet (clic droit ou Maj+F10) : page de base du module, fermer, fermer les autres, fermer ceux de droite, tout fermer. Chaque fermeture respecte la confirmation des modifications non enregistrées.

## 0.4.0 — 22/09/2026

### Modules
- Tags partagés 1.0.0 : nuage et tableau des tags, détail avec informations reliées et tags voisins, gestion (renommage, fusion, suppression, doublons probables, tags inutilisés) sous permission `manage` (`tags.manage` du cahier des charges), badge des tags inutilisés.
- Explorateur 1.0.0 : recherche transversale des informations partagées (filtres jeu, module, tag), fiche d’information (tags, relations, pièces jointes, historique), jeux de données, relations, pièces jointes ; règle de visibilité stricte (jeux partagés et lisibles uniquement).
- Corbeille 1.0.0 : agrégation des éléments supprimés des modules (contrat `TrashProviderInterface`) et des pièces jointes, restauration et purge unitaires ou groupées, vidage sous permission `purge-all` avec confirmation forte, badge.
- Bloc-notes 1.2.0 : implémente la corbeille globale ; route d’ouverture `edit/{key}`.

### Noyau
- Manifeste : `datasets[].openRoute` pour ouvrir une information depuis les modules transversaux.
- `TrashProviderInterface` : contrat optionnel de corbeille pour les modules.
- Badges : la permission déclarée par la route `badge` est désormais contrôlée.
- Composants : `data-route` accepté sur les boutons ; la saisie de tags émet `change` (formulaires à soumission automatique) ; variables de gabarit réservées protégées ; `maintenance:purge` n’appelle que le hook `purge()` sans paramètre.

## 0.3.0 — 22/09/2026

### Noyau
- Générateur de squelette de module : `console module:create <id>` (manifeste, classe d’entrée avec liste/édition/suppression, dépôt, migration, gabarits, feuille de style, README), migration et synchronisation appliquées immédiatement.
- Guide du développeur de module (`docs/developpeur-module.md`) : génération, droits, cycle de développement, données partagées, vérification curl, check-list de livraison.

### Modules
- Nouveau : Entretien 1.0.0 (GMAO domestique). Équipements (véhicule, chauffage, électroménager, habitation…) avec compteur (km, heures, cycles) et relevé rapide ; tâches d’entretien périodiques par jours et/ou au compteur avec rappel anticipé propre à chaque tâche ; pannes et défauts ; fiche d’intervention (descriptif BBCode, pièces, contacts, outillage, durée et coût estimés) imprimable ; historique des interventions avec replanification automatique, coûts et export CSV ; tableau de bord des rappels, badge du module, calendrier iCalendar ; documents et factures joints depuis les fiches ou depuis le module Fichiers joints ; tags partagés ; corbeille ; service intermodule (`assets`, `reminders`).

## 0.3.0 — 22/09/2026

### Noyau
- Pièces jointes chiffrées au repos (AES-256-GCM par blocs, clé locale `var/config/attachments.key` incluse dans les sauvegardes), déchiffrement à la volée au téléchargement, empreinte SHA-256 et compteur de téléchargements ; commandes `attachments:verify` et `attachments:encrypt`.
- Réponses en flux (`Response::stream`) pour les téléchargements déchiffrés, `Request::fileList()` pour les envois multiples, type `Database::double()`, icônes carte, RSS, archive, image, fichier.
- Politique CSP : tuiles OpenStreetMap et OpenSeaMap autorisées en `img-src` pour le module Carte.

### Modules
- Coordonnées GPS : référentiel de points (saisie décimale, DMS ou degrés-minutes), recherche de points proches, import et export CSV, tags, corbeille ; jeu partagé `geo.point` et service intermodule (`find`, `search`, `nearby`, `attach`…) pour rattacher les informations des autres modules à un lieu.
- Fichiers joints : téléversement multiple par glisser-déposer, rattachement à toute information du registre commun, téléchargement contrôlé par les droits (auteur, assistance, lecteurs du jeu rattaché), aperçu des images, vérification d’intégrité, corbeille, quotas.
- Actualités 1.0.0 : flux RSS / Atom / JSON Feed choisis, catégories et centres d’intérêt (mots-clés), lecture personnelle et badge de non lues, archivage des faits à conserver (note BBCode, tags, registre commun), rétention par flux.
- Carte 1.0.0 : points GPS sur fond OpenStreetMap ou OpenSeaMap (Leaflet 1.9.4 servi localement), calques par tag et par module relié, liste triable, fenêtre d’information avec les liens, préférences par utilisateur.
- Pages 1.0.0 : pages de type wiki en BBCode avec liens internes `[[Titre]]`, images et fichiers joints `[file=…]`, lieux `[point=…]`, tags, rétroliens, historique des versions, corbeille ; jeu partagé `wiki.page`.

## 0.2.2 — 22/09/2026

### Noyau
- Message de bienvenue dans le toaster après la connexion (message flash de session affiché une seule fois).

## 0.2.1 — 22/09/2026

### Noyau
- Correctif : la route canonique d’une vue conserve sa chaîne de requête (filtres, page, tri) ; l’URL, l’historique et l’actualisation d’un onglet gardent l’état affiché.

### Modules
- Démonstration 1.0.0 : module fictif de référence (composants, formulaires, tableaux, notifications, cycle de vie, tags/relations/pièces jointes, cas d’erreur) et guide « Créer un module à partir de demo ».

## 0.2.0 — 22/09/2026

### Noyau
- Journal d’activité enrichi : catégories (sécurité, données, administration, technique, débogage), identifiant de requête pour corréler les entrées, durée des traitements, trace de chaque vue et action en niveau `debug`, journalisation des routes introuvables, sessions expirées, rejets CSRF, manifestes invalides et synchronisations. Helper `debug()` pour les modules.
- Éditeur de texte riche BBCode commun (`data-editor="bbcode"`) avec rendu sécurisé côté serveur.
- Saisie de tags commune avec suggestions des tags existants (`data-tags-input`, `/core/tags`).
- Le numéro de version de la barre d’état ouvre le journal des versions.
- Un clic sur l’onglet actif ramène à la page de base du module.
- Correctifs : appel d’un service intermodule non démarré, retour d’un module à l’état actif, variable écrasée dans la validation des manifestes.

### Modules
- Bloc-notes 1.1.0 : éditeur BBCode, suggestions de tags, extraits sans balises.
- Journal d’activité 1.1.0 : filtres par catégorie et par requête, colonnes catégorie, durée et requête, détail corrélé.
- Nouveaux : Utilisateurs et droits, Gestion des modules, Paramètres généraux, Mon profil.

### Règle de versions
- Noyau : `0.x.0` par lot de fonctionnalités, `0.x.y` pour un correctif, `1.0.0` à la recette du socle.
- Modules : `1.x.0` fonctionnalité, `1.0.y` correctif, majeure si le contrat de données change.

## 0.1.0 — 22/09/2026

Première version de développement du socle.

### Noyau
- Point d’entrée unique, routage, authentification locale, sessions (60 min d’inactivité, 12 h au plus), blocage temporaire après cinq échecs.
- ACL entièrement paramétrables (utilisateurs, groupes, règle générale), héritage par ressource et explication du droit effectif.
- Découverte des modules depuis `manifest.json`, surcharges administratives dans un fichier protégé, synchronisation des ressources, permissions et catalogue.
- Interface générale : colonne arborescente, onglets (un par module), bandeau fourni par le module, barre d’état, toaster commun, boîtes de dialogue, historique de navigation.
- Mécanismes transversaux : identifiants globaux, tags, relations typées, pièces jointes.
- Journal d’activité et journal technique distincts.
- Console : migrations, données de démonstration, sauvegarde et restauration, rétablissement de l’accès administrateur, export vers MariaDB.
- Lanceurs Windows et Linux autour du serveur intégré à PHP et de SQLite.

### Modules
- Accueil (tableau de bord).
- Utilisateurs et droits, Gestion des modules, Paramètres généraux, Journal d’activité, Bloc-notes, Mon profil, Démonstration.
