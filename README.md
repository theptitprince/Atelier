<div align="center">

# Atelier — webapplication modulaire

**Un socle commun et sécurisé, sur lequel des modules métier indépendants s’ajoutent sans toucher au noyau.**

![Version](https://img.shields.io/badge/version-0.9.0-2ea44f)
![PHP](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-natif-F7DF1E?logo=javascript&logoColor=black)
![Apache](https://img.shields.io/badge/Apache-2.4-D22128?logo=apache&logoColor=white)
![Plateforme](https://img.shields.io/badge/plateforme-web-0078D6)
![Licence](https://img.shields.io/badge/licence-GPL--3.0--only-blue)

</div>

---

> [!WARNING]
> **Application à un seul utilisateur, livrée avec un mot de passe initial public.** Atelier est conçu pour son
> propriétaire, seul utilisateur. Le compte créé par les données de démonstration (`admin` / `123456789azerty`)
> porte un mot de passe publié dans ce dépôt : l’application impose de le changer à la première connexion.
> Sur un serveur, créez plutôt l’administrateur avec `admin:create`, qui tire un mot de passe temporaire au hasard,
> et servez Atelier uniquement en HTTPS (voir [4. Mise en service sur un serveur](#4-mise-en-service-sur-un-serveur)).

Atelier est une webapplication modulaire, sécurisée et réservée aux utilisateurs authentifiés : un environnement
commun (authentification, droits, navigation, interface, données partagées, journalisation) dans lequel des
modules métier indépendants s’ajoutent sans toucher au noyau.

**À qui ça sert.** À son propriétaire, qui veut réunir ses outils dans une seule application : pages de type wiki,
bloc-notes, projets, budget domestique, entretien des équipements, veille d’actualité, points GPS et carte. Ces
modules partagent leurs tags, leurs relations, leurs pièces jointes et une corbeille commune ; un nouveau module
s’ajoute en déposant son répertoire dans `modules/`.

- **Technologies** : PHP 8.4 natif, SQLite (migration vers MariaDB prête, voir [docs/exploitation.md](docs/exploitation.md)),
  HTML5/CSS, JavaScript natif (`fetch`). Aucun framework, aucun Composer.
- **Cible** : ordinateurs de bureau (largeur ≥ 1280 px), navigateurs récents (Firefox, Chrome, Edge).
- **Cahier des charges** : [docs/cahier-des-charges.md](docs/cahier-des-charges.md) (v0.8).

Version actuelle : 0.9.0 — l’historique des livraisons est dans [docs/CHANGELOG.md](docs/CHANGELOG.md). Dans
l’application, ce journal s’affiche en cliquant sur le numéro de version de la barre d’état.

## Sommaire

- [1. Installation](#1-installation)
- [2. Les fichiers](#2-les-fichiers)
- [3. Lancement](#3-lancement)
- [4. Mise en service sur un serveur](#4-mise-en-service-sur-un-serveur)
- [5. Les modules](#5-les-modules)
- [6. Console d’administration](#6-console-dadministration)
- [7. Documentation](#7-documentation)
- [8. En cas de souci](#8-en-cas-de-souci)
- [9. Licence](#9-licence)

---

## 1. Installation

### Prérequis

- **PHP 8.4 ou plus récent**, avec les extensions `pdo_sqlite`, `mbstring`, `fileinfo` et `openssl`, ainsi que
  `json`, `ctype` et `session`, généralement présentes d’office. L’extension `curl` sert au module Actualités
  (récupération des flux) ; `pdo_mysql` n’est utile que pour une migration vers MariaDB.
- **Aucun serveur Web ni base de données à installer en local** : le serveur intégré à PHP suffit et SQLite est
  embarqué dans PHP. En production : Apache 2.4 avec `mod_rewrite`.
- **Un navigateur** : l’une des deux dernières versions de Firefox, Chrome ou Edge, sur un écran d’au moins 1280 px
  de large.

### PHP portable sous Windows

Sous Windows, une version portable de PHP peut être déposée dans `_tools/php84/php.exe` : les lanceurs la
détectent automatiquement (puis, à défaut, `_tools/php/php.exe`, puis le `php` du `PATH`). Son `php.ini` doit
activer `extension_dir="ext"` et les extensions `pdo_sqlite`, `sqlite3`, `mbstring`, `fileinfo` et `openssl`.
Le répertoire `_tools/` n’est pas versionné : c’est à vous de le créer.

Sous Linux et macOS, les lanceurs cherchent `./_tools/php84/php`, `./_tools/php/php`, `php8.4` puis `php`. Par
exemple, sous Debian ou Ubuntu :

```bash
sudo apt install php8.4-cli php8.4-sqlite3 php8.4-mbstring
```

### Récupérer le projet et vérifier

Clonez le dépôt (ou copiez le répertoire), puis vérifiez PHP, les extensions et les répertoires inscriptibles :

```powershell
git clone https://github.com/theptitprince/Atelier.git
cd Atelier
.\console.bat check
```

```bash
git clone https://github.com/theptitprince/Atelier.git
cd Atelier
chmod +x start.sh console.sh cron.sh
./console.sh check
```

---

## 2. Les fichiers

| Fichier | Rôle |
|---|---|
| `start.bat` / `start.sh` | lanceurs Windows / Linux-macOS : vérifient PHP, créent la base au premier démarrage, démarrent le serveur de développement |
| `console.bat` / `console.sh` | console d’administration (`tools/console.php`) |
| `cron.bat` / `cron.sh` | tâches de fond (`cron:run`), à planifier toutes les 5 à 15 minutes |
| `index.php`, `.htaccess` (racine) | hébergement à racine imposée : le `.htaccess` réécrit toute requête vers `public/` ; `index.php` affiche une page de diagnostic si la réécriture n’est pas active |
| `public/` | point d’entrée unique (`index.php`), CSS et JS communs — seul répertoire exposé par le serveur Web |
| `src/` | noyau (namespace `Atelier\`) : Kernel, Http, Security, Persistence, Modules, Shared, Activity, Logging, View, Error, Support |
| `modules/<id>/` | un répertoire par module : `manifest.json`, `src/`, `templates/`, `assets/`, `migrations/` |
| `config/` | `app.php` (défauts versionnés, réglés pour la production), `env.php.dist` → `env.local.php` (local, non versionné) |
| `var/` | données locales, non versionnées : base SQLite, pièces jointes, journaux, cache, sauvegardes, sessions, `config/modules.json` |
| `tools/` | `dev-router.php` (serveur intégré), `console.php` |
| `docs/` | documentation : cahier des charges, installation, exploitation, guide du développeur, contrat de module, manifeste, données partagées, recette, CHANGELOG |
| `_tools/` | facultatif, non versionné : PHP portable sous Windows (`_tools/php84/php.exe`) |
| `LICENSE` | texte intégral de la GNU GPL version 3 |

---

## 3. Lancement

### Démarrage rapide

```powershell
.\start.bat
```

```bash
chmod +x start.sh console.sh cron.sh
./start.sh
```

Le lanceur vérifie PHP (version 8.4 ou plus, extensions `pdo_sqlite` et `mbstring`), crée la base
`var/data/atelier.sqlite` et les données de démonstration au premier démarrage — les fois suivantes, il applique
seulement les migrations en attente —, puis ouvre http://127.0.0.1:8000 dans le navigateur. `Ctrl+C` arrête le
serveur.

Le port se passe en argument : `.\start.bat 8080` ou `./start.sh 8080`.

`config/app.php`, versionné, est réglé pour la production (`env = prod`, `debug = false`) : un dépôt déployé tel
quel ne renvoie au client ni trace d’appel, ni chemin de fichier, ni requête SQL. Le serveur de développement
(`tools/dev-router.php`, utilisé par les lanceurs) rétablit `dev` et `debug` localement.

### Équivalent manuel

```powershell
.\_tools\php84\php.exe tools\console.php db:migrate
.\_tools\php84\php.exe tools\console.php db:seed
.\_tools\php84\php.exe -S 127.0.0.1:8000 -t public tools\dev-router.php
```

Avec un PHP installé dans le `PATH`, remplacez `.\_tools\php84\php.exe` par `php`.

```bash
php tools/console.php db:migrate
php tools/console.php db:seed
php -S 127.0.0.1:8000 -t public tools/dev-router.php
```

### Le compte du propriétaire

Atelier est conçu pour un seul utilisateur, son propriétaire. `console db:seed` crée ce compte unique :

| Compte | Mot de passe | Rôle |
|---|---|---|
| `admin` | `123456789azerty` | Administrateur (changement demandé à la première connexion) |

Ce mot de passe initial est public puisqu’il figure dans le dépôt : changez-le dès la première connexion, en
particulier sur un serveur. Tant qu’il n’est pas changé, aucun module ne s’ouvre. Le nouveau mot de passe doit
compter au moins 12 caractères et ne pas contenir l’identifiant.

---

## 4. Mise en service sur un serveur

Il n’y a pas de lanceur sur le serveur : c’est Apache 2.4 (avec `mod_rewrite`) qui sert `public/index.php`,
unique point d’entrée. Seul le répertoire `public/` doit être exposé ; trois cas :

- **DocumentRoot sur `public/`** (recommandé, serveur personnel) : dans le VirtualHost,
  `DocumentRoot /srv/atelier/public`.
- **Hébergement mutualisé avec racine web imposée** (OVH : dossier `www/`) : déposez **tout le projet** dans
  `www/`, y compris les fichiers cachés `.htaccess`. Le `.htaccess` de la racine réécrit chaque requête vers
  `public/` ; `src/`, `config/`, `var/`, `modules/`, `tools/` et `docs/` portent chacun un `.htaccess`
  `Require all denied`. Il faut `mod_rewrite` et `AllowOverride All`.
- **Sous-répertoire du site** (ex. `https://exemple.fr/atelier/`) : même principe, en décommentant
  `RewriteBase /atelier/` dans le `.htaccess` concerné et en définissant `app.base_url = '/atelier'` dans
  `config/env.local.php`.

Après le transfert, sur le serveur :

```bash
cp config/env.php.dist config/env.local.php
php tools/console.php check
php tools/console.php db:migrate
php tools/console.php admin:create <identifiant>
```

`admin:create` affiche un mot de passe temporaire, à changer à la première connexion. Dans
`config/env.local.php`, forcez `session.cookie_secure = true` et servez l’application uniquement en HTTPS.

Vérifiez ensuite que `https://votre-site/` affiche la page de connexion, et que `https://votre-site/config/app.php`
et `https://votre-site/var/data/atelier.sqlite` répondent 403. Le processus PHP doit pouvoir écrire dans `var/` et
ses sous-répertoires ; le fichier SQLite doit résider sur un disque local au serveur, jamais sur un partage réseau
ou un dossier synchronisé.

Le détail (configuration, variables d’environnement `ATELIER_*`, installation d’un module) est dans
[docs/installation.md](docs/installation.md) ; sauvegarde, restauration et migration MariaDB dans
[docs/exploitation.md](docs/exploitation.md).

---

## 5. Les modules

Chaque module vit dans son répertoire `modules/<id>/` et se décrit dans son `manifest.json`. Les modules partagent
les services du noyau : tags, relations, pièces jointes (chiffrées au repos par défaut), catalogue des données
partagées et corbeille. Les éléments supprimés dans les modules (pages, notes, projets, opérations, équipements,
points GPS, flux, fichiers joints…) restent restaurables depuis la Corbeille pendant 30 jours par défaut.

| Module | Identifiant | Rôle |
|---|---|---|
| **Général** | | |
| Accueil | `home` | tableau de bord affiché après connexion |
| Mon profil | `profile` | informations du compte, changement de mot de passe et préférences personnelles |
| **Outils** | | |
| Bloc-notes | `notes` | notes personnelles : rédaction, recherche, tags partagés et corbeille |
| Projets | `project` | conteneurs qui regroupent tâches, journal de bord, documents et informations de tous les modules |
| Pages | `wiki` | pages de type wiki reliées entre elles, avec images et fichiers joints, tags, lieux GPS et historique des versions |
| Entretien | `maintenance` | GMAO domestique : équipements, tâches d’entretien planifiées, pannes, rappels, fiches d’intervention, historique |
| Budget | `budget` | comptabilité domestique : comptes, opérations, budgets par catégorie, prévisionnel, objectifs d’épargne |
| Coordonnées GPS | `geo` | référentiel de points GPS, réutilisable par les autres modules pour rattacher des informations |
| Tags partagés | `tags` | nuage et gestion des tags : recherche, renommage, fusion, doublons et tags inutilisés |
| Carte | `map` | carte des points GPS et de ce qui leur est relié (fonds OpenStreetMap ou OpenSeaMap, calques par tag et par type de lien) |
| Explorateur | `explorer` | vue transversale des informations partagées : recherche, tags, relations et pièces jointes |
| Fichiers joints | `attachments` | téléversement, nom d’affichage, tags, dossiers virtuels et téléchargement sécurisé des fichiers joints |
| Actualités | `news` | veille à partir de flux RSS, Atom ou JSON Feed, classés par catégorie et par centre d’intérêt, avec archivage |
| Corbeille | `trash` | corbeille globale : restauration et suppression définitive |
| Démonstration | `demo` | module fictif, référence des composants et des comportements du noyau |
| **Administration** | | |
| Utilisateurs et droits | `users` | comptes, groupes et droits d’accès (ACL) |
| Gestion des modules | `modules-admin` | états, ordre d’affichage et catalogue des jeux de données |
| Paramètres généraux | `settings` | paramètres dynamiques de l’application, sauvegardes et maintenance |
| Journal d’activité | `activity` | consultation et export du journal d’activité fonctionnel et de sécurité |

La Carte charge ses fonds depuis `tile.openstreetmap.org` et `tiles.openseamap.org` : elle a besoin d’un accès
Internet. Les Actualités récupèrent leurs flux en tâche de fond (voir [Tâches planifiées](#tâches-planifiées)).
La plupart des modules décrivent leur fonctionnement dans leur propre `README.md`.

---

## 6. Console d’administration

```powershell
.\console.bat help
```

```bash
./console.sh help
```

| Commande | Rôle |
|---|---|
| `help` | affiche l’aide |
| `check` | vérifie PHP, les extensions et les répertoires |
| `db:migrate` | applique les migrations du noyau et des modules, synchronise les manifestes |
| `db:seed` | crée le compte administrateur unique, les groupes, les règles de départ et les exemples des modules |
| `db:reset --force` | supprime la base SQLite puis la recrée avec les données de démonstration (SQLite uniquement) |
| `admin:create <identifiant> [--password=…]` | crée un compte administrateur |
| `admin:recover --confirm [identifiant]` | rétablit l’accès administrateur d’un compte existant (par défaut `admin`) ; sans `--confirm`, explique seulement ce qu’elle fera |
| `user:password <identifiant>` | attribue un mot de passe temporaire à un utilisateur et déverrouille son compte |
| `modules:list` | liste les modules installés et leur état |
| `modules:sync` | force la synchronisation des manifestes (ressources, permissions, catalogue) |
| `modules:set <id> <état>` | modifie l’état d’un module : `active`, `inactive` ou `maintenance` |
| `module:create <id> [options]` | génère le squelette d’un module (voir le [guide du développeur](docs/developpeur-module.md)) |
| `backup:create` | sauvegarde cohérente de la base, des pièces jointes et de la configuration |
| `backup:list` | liste les sauvegardes disponibles |
| `backup:restore <nom> --force` | restaure une sauvegarde (une sauvegarde de sécurité est créée avant) |
| `maintenance:purge` | applique les rétentions (journal d’activité, journaux techniques, corbeille) |
| `cron:run` | exécute les tâches de fond des modules |
| `attachments:verify` | vérifie l’intégrité de toutes les pièces jointes |
| `attachments:encrypt` | chiffre les pièces jointes encore stockées en clair |
| `mariadb:export --to=…` | exporte les données SQLite vers une base MariaDB configurée (`--dry-run` pour un essai à blanc) |

### Tâches planifiées

Les tâches de fond des modules (à ce jour, la récupération des flux du module Actualités) passent par `cron.bat` ou
`cron.sh`, à planifier toutes les 5 à 15 minutes :

```powershell
schtasks /Create /SC MINUTE /MO 10 /TN "Atelier cron" /TR "C:\chemin\vers\atelier\cron.bat" /F
```

```bash
# crontab -e
*/10 * * * * /chemin/vers/atelier/cron.sh >> /chemin/vers/atelier/var/logs/cron.log 2>&1
```

Planifiez aussi `maintenance:purge` une fois par jour, et des sauvegardes régulières avec `backup:create` (politique
recommandée dans [docs/exploitation.md](docs/exploitation.md)).

---

## 7. Documentation

- [Cahier des charges](docs/cahier-des-charges.md) (v0.8)
- [Installation et configuration](docs/installation.md)
- [Exploitation, sauvegarde, restauration, migration MariaDB](docs/exploitation.md)
- [Guide du développeur de module](docs/developpeur-module.md) (générateur `console module:create`)
- [Contrat technique d’un module](docs/contrat-module.md)
- [Schéma du manifeste](docs/manifest-schema.md)
- [Données partagées, catalogue, tags, relations, pièces jointes](docs/donnees-partagees.md)
- [Recette du socle](docs/recette.md) : critères du cahier des charges (état à la version 0.2.0)
- [Journal des versions](docs/CHANGELOG.md)

---

## 8. En cas de souci

**« PHP introuvable » au lancement.** Installez PHP 8.4, ou déposez une version portable dans `_tools\php84\`
sous Windows (voir [1. Installation](#1-installation)).

**« PHP 8.4 ou supérieur est requis » ou « Extensions PHP requises manquantes ».** Vérifiez la version de PHP et
son `php.ini` ; `.\console.bat check` (ou `./console.sh check`) indique précisément ce qui manque.

**La page « Atelier n’est pas encore servi correctement » s’affiche sur le serveur.** La réécriture vers `public/`
n’est pas active : pointez le DocumentRoot sur `public/`, ou vérifiez `mod_rewrite`, `AllowOverride All` et la
présence du fichier caché `.htaccess` après le transfert.

**Un message d’erreur donne une référence `ERR-XXXXXX`.** Cherchez-la dans le journal technique du jour,
`var/logs/atelier-AAAA-MM-JJ.log`.

**Page de maintenance, réponses 503.** La base de données est inaccessible : l’application refuse alors tout
contenu protégé et la page se rafraîchit toutes les 60 secondes. Vérifiez la base avec la commande `check` de la
console ; la référence d’incident affichée se retrouve dans le journal technique du jour.

**Accès administrateur perdu.** Il n’existe aucun compte caché. Sur le serveur uniquement :

```bash
php tools/console.php admin:recover --confirm [identifiant]
```

La commande réactive et déverrouille le compte (par défaut `admin`), lui attribue un mot de passe temporaire
affiché une seule fois, à changer à la connexion suivante, et lui rend les droits d’administration (règle ACL
`allow admin` sur la racine). L’opération est inscrite dans le journal d’activité.

**Actualités : « unable to get local issuer certificate ».** Le PHP utilisé (souvent un PHP portable) ne dispose
d’aucun certificat racine. Déposez le bundle Mozilla `cacert.pem` (<https://curl.se/docs/caextract.html>) dans
`var/config/cacert.pem` : il est utilisé automatiquement lorsque `curl.cainfo` n’est pas configuré.

---

## 9. Licence

Atelier est un **logiciel libre**, distribué sous la licence **GNU General Public License, version 3**
(GPL-3.0-only).

Copyright © 2026 ETDEL.

Concrètement, vous pouvez utiliser le programme librement, l’étudier, le modifier et le redistribuer — à
condition que toute version redistribuée, modifiée ou non, reste sous la même licence, avec son code source et la
mention de copyright. Le programme est fourni **sans aucune garantie**.

Le texte intégral de la licence est dans le fichier `LICENSE` du dépôt et sur
<https://www.gnu.org/licenses/gpl-3.0.html>.

### Bibliothèques tierces

- **Leaflet 1.9.4** (module Carte), © 2010-2023 Volodymyr Agafonkin, © 2010-2011 CloudMade, sous licence
  BSD-2-Clause : texte dans `modules/map/assets/vendor/leaflet/LICENSE`.
- **mini-sparkline 1.0.0** (module Démonstration), sous licence MIT, comme l’indiquent son en-tête et le manifeste
  du module.
- Les fonds de carte proviennent d’OpenStreetMap (© contributeurs OpenStreetMap) et d’OpenSeaMap ; la carte
  affiche ces mentions.

---

*Atelier — ETDEL 2026 — GPL-3.0-only*
