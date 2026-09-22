# Journal des versions

Ce fichier est affiché dans l’application en cliquant sur le numéro de version de la barre d’état. Format : une section par version, la plus récente en premier.

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
