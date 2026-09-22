# Journal des versions

Ce fichier est affiché dans l’application en cliquant sur le numéro de version de la barre d’état. Format : une section par version, la plus récente en premier.

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
