# Journal des versions

Ce fichier est affiché dans l’application en cliquant sur le numéro de version de la barre d’état. Format : une section par version, la plus récente en premier.

## 0.8.1 — 23/09/2026

Version de correction issue d’une revue systématique : sécurité, noyau, modules métier et client.

### Sécurité
- **Actualités 1.2.2 — falsification de requête côté serveur (SSRF) corrigée.** Le garde-fou anti-adresses-internes ne reconnaissait pas les écritures numériques d’une IPv4 : `http://2130706433/` (soit 127.0.0.1 en décimal), ses variantes hexadécimale et octale, la forme abrégée `127.1` et les IPv4 encapsulées dans une IPv6 étaient acceptées, alors que curl les joignait. Un compte ordinaire autorisé à ajouter un flux pouvait ainsi faire interroger le réseau interne par le serveur et en lire le contenu dans l’application. Toutes ces écritures sont désormais ramenées à leur forme canonique puis refusées, la résolution de nom contrôle **chaque** adresse obtenue, et les redirections sont suivies manuellement : chaque saut repasse par les contrôles et l’adresse validée est épinglée côté curl (protection contre le DNS-rebinding).
- **Refus d’ouverture d’un module : les données suivent.** Un refus explicite de la permission `open` sur un module ne portait que sur ses écrans ; ses jeux de données partagés restaient lisibles par l’Explorateur, la recherche transversale et les sélecteurs. Retirer l’accès à un module ferme maintenant aussi ses données. L’absence de règle, elle, ne bloque rien : un droit accordé sur un seul jeu de données reste une délégation volontaire.
- **Pièces jointes et points GPS cités dans une page.** Le rendu des pages révélait le nom d’origine et la taille d’un fichier, ainsi que le libellé et les coordonnées d’un point GPS, sans vérifier les droits du lecteur. Une règle unique du noyau gouverne désormais l’affichage comme le téléchargement ; un élément inaccessible se présente comme absent.
- **Mode débogage.** `config/app.php`, qui est versionné, livrait `env = dev` et `debug = true` : déployé tel quel, il renvoyait au client les traces d’appel, les chemins absolus et les requêtes SQL. Le fichier est désormais réglé pour la production ; le serveur de développement (`start.bat`, `tools/dev-router.php`) rétablit le mode debug localement.

### Corrections du noyau
- Un module dont l’installation échoue est isolé : sa migration défectueuse mettait l’application entière en erreur 500. Les autres modules s’installent, le module fautif passe à l’état « erreur » avec sa cause, et son ouverture donne une indisponibilité explicite.
- Deux sauvegardes déclenchées dans la même seconde se percutaient (erreur 500) ; le nom est rendu unique et une sauvegarde interrompue est nettoyée au lieu d’être proposée à la restauration.
- Une réponse contenant de l’UTF-8 invalide provoquait une erreur 500 : l’encodage JSON est tolérant à la frontière HTTP.
- `backup:restore` sur un nom inexistant créait malgré tout une sauvegarde de sécurité complète ; l’existence est vérifiée d’abord. La console n’affiche plus de trace d’appel pour une erreur d’usage (sauf `--verbose`).
- Un échec de lecture de la session n’est plus mémorisé comme « non connecté » : l’application signale une indisponibilité de stockage au lieu de déconnecter.

### Corrections des modules
- **Budget 1.1.1** : la projection était tronquée à 400 occurrences (une récurrence quotidienne s’arrêtait avant deux ans) ; `Money::parse` refusait le signe moins typographique que `Money::format` produit.
- **Projets 1.0.1** : les retards étaient calculés en UTC et non en heure de Paris, donc invisibles entre minuit et 2 h.
- **Bloc-notes 1.2.1** : deux enregistrements dans la même seconde échappaient au contrôle de concurrence et l’un écrasait l’autre sans avertissement ; l’empreinte de version ne dépend plus de la seule date.
- **Actualités 1.2.2** : un flux simplement désactivé rendait ses entrées inatteignables et remettait le compteur de non-lues à zéro.
- **Pages 1.2.3** : le compteur de rétroliens comptait les pages en corbeille.
- **Entretien 1.2.1, Coordonnées GPS 1.1.1 et cinq autres modules** : un numéro de page démesuré débordait le calcul du décalage et donnait une erreur 500 ; la page est bornée.

### Corrections du client (JavaScript et feuille de style)
- Ouverture directe d’une URL de module sans sous-route (`/m/notes`, lien « ouvrir dans un nouvel onglet » depuis la colonne, F5) : la route valait littéralement la chaîne « null » et la vue affichait « Route inconnue ». La page de base du module est désormais utilisée, chaîne de requête conservée.
- Onglet fermé puis rouvert pendant le chargement de ses ressources : la réponse de l’onglet disparu s’appliquait au nouvel onglet (URL, titre, bandeau et colonne faussés). La détection de réponse obsolète compare maintenant l’onglet lui-même et non son seul identifiant ; les ressources acquises pour une réponse abandonnée sont rendues.
- Réponse hors enveloppe JSON (sortie parasite, page d’erreur d’un intermédiaire) : le panneau restait vide et sans message, et l’onglet se croyait chargé. Elle est maintenant traitée comme une erreur technique, avec bouton « Réessayer ».
- Jeton CSRF périmé (mot de passe changé ailleurs, second onglet du navigateur) : la requête est rejouée une seule fois avec le jeton rafraîchi au lieu d’afficher « Actualisez la page puis réessayez ».
- Suggestions de tags : une réponse lente pour un terme abandonné écrasait la liste du terme courant. Même correction dans les recherches des modules Fichiers joints, Coordonnées GPS et Pages.
- Liens `/m/…` dans un panneau : un identifiant de module préfixe d’un autre détournait la navigation vers l’onglet courant ; le module visé s’ouvre désormais dans son propre onglet.
- Toaster : la pile est bornée à la hauteur visible et défilante ; les avertissements et erreurs persistants ne sortent plus de l’écran hors de portée.

## 0.8.0 — 23/09/2026

### Corbeille globale pour tous les modules
- Règle du projet : toute suppression est logique, restaurable 30 jours et regroupée dans le module Corbeille (recherche, restauration, purge groupées). Le générateur `module:create` produit ce squelette.
- Entretien 1.2.0 (équipements, tâches, interventions ; documents joints sur chaque équipement et chaque intervention), Budget 1.1.0 (opérations, comptes, récurrences, objectifs, épargnes), Actualités 1.2.0 (flux, faits archivés), Pages 1.2.1, Coordonnées GPS 1.1.0.

### Modules
- Nouveau : **Projets 1.0.0** — conteneur transversal (tâches, journal de bord, documents, éléments liés de tous les modules, lieux, budget, tags, corbeille, service intermodule).
- Fichiers joints 1.1.0 : nom d’affichage distinct du nom d’origine, tags sur les fichiers, dossiers virtuels (arbre, fil d’Ariane, déplacement, suppression sans perte), dépôt direct avec nom, tags et dossier.
- Journal d’activité 1.2.0 : purge ciblée réservée aux administrateurs (catégorie, résultat, module, ancienneté, aperçu, confirmation, journalisée).
- Routes d’ouverture (`openRoute`) déclarées par Pages, Coordonnées GPS et Entretien : les éléments liés s’ouvrent dans leur module.

### Noyau
- Pièces jointes : `label`, dossiers virtuels (`AttachmentFolderService`), filtres dossier/tag, validation UTF-8, désinscription du registre à la purge.
- Migrations : erreur explicite sur deux fichiers de même numéro ; Corbeille : agrégateur réinitialisé à chaque requête.

## 0.7.2 — 22/09/2026

### Modules
- Pages 1.1.0 : page d’arrivée paramétrable (liste de toutes les pages ou page d’accueil choisie, « Paramètres des pages » réservé aux administrateurs, bouton « Définir comme accueil » sur une page) ; classement par catégories = tags partagés (barre de catégories avec compteurs, chips cliquables, filtre `tag`).

## 0.7.1 — 22/09/2026

### Déploiement
- Hébergement mutualisé (racine web imposée) : `.htaccess` racine réécrivant toute requête vers `public/`, `.htaccess` de refus dans `src/`, `config/`, `var/`, `modules/`, `tools/`, `tests/`, `docs/`, et `index.php` racine affichant un diagnostic si la réécriture n’est pas active. Documentation d’installation mise à jour.

## 0.7.0 — 22/09/2026

### Modules
- Nouveau : Budget 1.0.0 (comptabilité domestique). Comptes et soldes (courant, pointé), opérations à montant signé avec catégorie, tiers, pointage, justificatifs joints, actions groupées et export CSV ; import CSV de relevés bancaires sans doublons avec catégorie devinée ; catégories à deux niveaux et budgets mensuels ou annuels datés, vue réalisé / budget du mois ; prévisionnel (récurrences à poster, badge, projection du solde sur 6 à 24 mois intégrant les coûts estimés des entretiens) ; objectifs d’épargne et économies réalisées (budget non dépensé calculé + registre des gains) ; service intermodule pour le report d’opérations externes.
- Entretien 1.1.0 : le coût réel d’une intervention est reporté automatiquement dans le Budget (opération d’origine « Entretien », mise à jour et retrait suivis) ; les coûts estimés des tâches à venir alimentent le prévisionnel du Budget.

## 0.6.0 — 22/09/2026

### Modules
- Nouveau : Entretien 1.0.0 (GMAO domestique). Équipements (véhicule, chauffage, électroménager, habitation…) avec compteur (km, heures, cycles) et relevé rapide ; tâches d’entretien périodiques par jours et/ou au compteur avec rappel anticipé propre à chaque tâche ; pannes et défauts ; fiche d’intervention (descriptif BBCode, pièces, contacts, outillage, durée et coût estimés) imprimable ; historique des interventions avec replanification automatique, coûts et export CSV ; tableau de bord des rappels, badge du module, calendrier iCalendar ; documents et factures joints depuis les fiches ou depuis le module Fichiers joints ; tags partagés ; corbeille ; service intermodule (`assets`, `reminders`).

## 0.5.1 — 22/09/2026

### Noyau
- Les badges de la colonne (corbeille, tags inutilisés, rappels…) se rafraîchissent après chaque action et chaque chargement de vue, au retour sur l’onglet du navigateur, et toutes les 30 s.
- Boîtes de dialogue : la confirmation se résout à la fermeture sans dépendre de l’événement `close` du navigateur (certains navigateurs ne l’émettent pas), ce qui bloquait les actions à confirmation.

## 0.5.0 — 22/09/2026

### Noyau
- Onglets réorganisables par glisser-déposer et au clavier (Ctrl+←/→ sur l’onglet actif).
- Bouton « Tout fermer » dans la barre d’onglets et menu contextuel d’onglet (clic droit ou Maj+F10) : page de base du module, fermer, fermer les autres, fermer ceux de droite, tout fermer. Chaque fermeture respecte la confirmation des modifications non enregistrées.

## 0.4.1 — 22/09/2026

### Noyau
- Tâches de fond : commande `cron:run` (verrou, compte rendu et date de dernière exécution dans les paramètres `cron.*`, journalisation) appelant le hook `cron()` des modules ; lanceurs `cron.bat` et `cron.sh` à planifier toutes les 5 à 15 minutes.

### Modules
- Actualités 1.1.0 : récupération des flux en tâche de fond selon une fréquence propre à chaque flux (15 minutes à 7 jours), copie locale du texte des articles (option par flux, extraction du contenu principal, lecture hors source, téléchargement immédiat à l’archivage), écran « Flux suivis » signalant l’absence de planification.

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
