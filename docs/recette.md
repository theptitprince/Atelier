# Recette du socle — critères du cahier des charges (§14)

État au 22/09/2026, version 0.2.0. Légende : ✅ vérifié (test automatisé ou vérification manuelle décrite), 🔶 partiellement vérifié, ⏳ à vérifier, ➖ hors périmètre décidé.

| # | Critère | État | Vérification |
|---|---|---|---|
| 1 | Un visiteur non connecté n’accède ni aux pages, ni aux données, ni aux points d’entrée dynamiques | ✅ | `ApplicationTest` : redirection `/login`, JSON 401 sur `/m/*` et `/core/*` |
| 2 | Connexion et déconnexion correctes | ✅ | `AuthTest` ; navigateur : connexion `admin`, déconnexion depuis la colonne |
| 3 | Session expirée : réaction cohérente, y compris en requête dynamique | ✅ | `Auth::user()` applique les expirations ; le client affiche la fenêtre « Session expirée » sur `error.type = auth` et redirige vers `/login?expired=1&next=…` |
| 4 | Le menu présente tous les modules installés avec état et droits | ✅ | `ModuleManagerTest::testNavigationTreeWithoutAclLocksEverything`, `ApplicationTest::testNavigationTreeMarksLockedModules` ; navigateur : modules verrouillés/erreur distingués |
| 5 | URL directe vers un module interdit refusée côté serveur | ✅ | `ApplicationTest::testLoginThenForbiddenModuleThenAllowed` (403 puis 200 après règle) |
| 6 | Droit effectif calculé depuis règles générales, groupes, directes, héritage | ✅ | `AclServiceTest` (11 tests couvrant l’ordre du §4.3) |
| 7 | L’interface ACL explique l’origine d’un droit effectif | ✅ | Module Utilisateurs et droits : écran ACL, règles explicites/héritées, « Tester le droit effectif » avec explication (`Decision::explanation`) |
| 8 | Ouverture/activation d’un onglet met à jour zone centrale, bandeau, barre d’état et URL sans rechargement | ✅ | Navigateur : onglets Accueil, Utilisateurs, Journal ; `history.pushState` ; bandeau par module |
| 9 | Les boutons d’action respectent les permissions et appellent les traitements | ✅ | Modules users/settings/modules-admin : actions `data-action` vérifiées en curl (403/422/200) |
| 10 | Précédent/suivant du navigateur cohérents | 🔶 | Implémenté (`popstate` → ouverture/réutilisation d’onglet) ; à rejouer manuellement en recette |
| 11 | Erreurs et chargements visibles et compréhensibles | ✅ | Blocs d’état (`state.php`), toaster, indicateur de chargement, référence d’incident |
| 12 | Activer, désactiver, mettre en maintenance un module | ✅ | Gestion des modules : `set-state` vérifié en curl et fichier `var/config/modules.json` |
| 13 | Ajouter un module conforme ne modifie aucun autre module | ✅ | Modules users, settings, notes… ajoutés sans toucher au noyau ni aux autres modules |
| 14 | Un module lit un jeu partagé d’un autre module sans contourner les permissions | ✅ | `users.account` via `UsersService` (`catalog->canAccess`) ; `SharedServicesTest::testCatalogHidesPrivateDatasetsAndChecksAcl` |
| 15 | Une donnée privée n’est pas exposée dans le catalogue ni par l’API intermodule | ✅ | `SharedServicesTest` (`demo.secret`), `DatasetCatalog::findShared` |
| 16 | Une écriture sur des données partagées est validée par le service propriétaire | 🔶 | Principe appliqué (services `UsersService`, `DemoService`) ; aucun module consommateur n’écrit encore |
| 17 | La désactivation d’un module ne supprime pas ses données et signale les dépendances | ✅ | `set-state` conserve les tables ; catalogue « jeux non alimentés » (`DatasetCatalog::orphaned`) |
| 18 | Le module fictif valide composants, onglets et comportements | ⏳ | Module `demo` en cours de finalisation |
| 19 | Chat AJAX | ➖ | Module chat abandonné par décision du 22/09/2026 |
| 20 | Tags, relations, pièces jointes relient des informations de modules différents | ✅ | `SharedServicesTest` ; notes : tags partagés avec suggestions |
| 21 | Un fichier joint n’est accessible qu’après contrôle des ACL | ✅ | `/files/{id}` : droit `read` sur le jeu de données de l’information, sinon auteur uniquement |
| 22 | Les actions sensibles apparaissent dans le journal, filtrable et triable par action | ✅ | Journal 1.1.0 : catégories, filtres cumulables, tri, export CSV ; `ActivityLogTest` |
| 23 | Les notifications utilisent le toaster commun selon les règles | ✅ | `atelier.js` : quatre niveaux, regroupement des doublons, persistance des erreurs |
| 24 | Protections contre les principales attaques testées | 🔶 | CSRF (`CsrfTest`, `ApplicationTest::testPostWithoutCsrfIsRejected`), XSS (échappement systématique, `BbCode` liste blanche), blocage après échecs (`AuthTest`), en-têtes et CSP ; test d’intrusion externe à prévoir |
| 25 | Sauvegarde et restauration cohérentes vérifiées | 🔶 | `backup:create` vérifié (manifeste, empreinte) ; restauration à rejouer sur environnement d’essai |
| 26 | Serveur intégré lancé par les commandes ou lanceurs fournis | ✅ | `start.bat` / `start.sh`, `.claude/launch.json` |
| 27 | Transfert vers un serveur compatible en ne modifiant que la configuration | 🔶 | Configuration externalisée, `.htaccess` fourni ; déploiement réel à effectuer |
| 28 | Formulaires, boutons, bandeaux, tableaux utilisent le CSS commun | ✅ | Tous les modules ; feuilles propres préfixées `.module-<id>` |
| 29 | Feuille propre à un module chargée avant affichage, conservée puis retirée | ✅ | `resources.acquire/release` par comptage de références (`home.css`, `users.css`…) |
| 30 | Plusieurs modules ouverts, un onglet par module, fermeture protégée | ✅ | Navigateur ; confirmation si onglet modifié (`data-track-dirty`) |
| 31 | Un manifeste valide fait apparaître le module au prochain chargement | ✅ | `ModuleManagerTest::testDiscoveryIsolatesInvalidManifests` ; modules ajoutés pendant le développement |
| 32 | Les accès directs apparaissent dans l’ordre prévu | ✅ | Arborescence ; ordre `manifest` puis surcharges (`ModuleDescriptor::navigation`) |
| 33 | Un accès direct ouvre ou réutilise l’onglet, charge la route, respecte les ACL de l’entrée | ✅ | `buildChildren` filtre par `view` + permission ; navigateur |
| 34 | L’ordre personnalisé est conservé dans le fichier de configuration et survit à une mise à jour | ✅ | `ModuleManagerTest::testSortingAndOverrides` |
| 35 | Un manifeste invalide désactive uniquement le module concerné avec une erreur compréhensible | ✅ | `modules:list` et Gestion des modules affichent le détail ; journal `module.manifest_invalid` |
| 36 | Stockage indisponible : manifestes découverts, accès refusé par défaut | ✅ | `Application::maintenance()` : page 503 avec modules verrouillés, JSON 503 en dynamique |
| 37 | Migration SQLite → MariaDB sans perte | ⏳ | Outil `mariadb:export` écrit ; à exécuter sur une instance MariaDB |

## Jeux de tests

- Automatisés : `php tests/run.php` — 74 tests, 236 assertions (noyau).
- Manuels : parcours navigateur décrits ci-dessus ; les modules `demo` et `users` servent de scénarios de référence.
