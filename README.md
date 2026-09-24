# Atelier

[![PHP 8.4](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)](https://www.sqlite.org/)
[![MariaDB prêt](https://img.shields.io/badge/MariaDB-migration%20pr%C3%AAte-003545?logo=mariadb&logoColor=white)](docs/exploitation.md)
[![JavaScript natif](https://img.shields.io/badge/JavaScript-natif%2C%20sans%20framework-F7DF1E?logo=javascript&logoColor=black)](public/assets/js/atelier.js)
[![Apache 2.4](https://img.shields.io/badge/Apache-2.4-D22128?logo=apache&logoColor=white)](docs/installation.md)
[![Version 0.7.1](https://img.shields.io/badge/version-0.7.1-2c3e50)](docs/CHANGELOG.md)
[![Licence GPL v3](https://img.shields.io/badge/licence-GPL%20v3-blue)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-132%20verts-1f8a4c)](tests/run.php)

Webapplication modulaire, sécurisée et réservée aux utilisateurs authentifiés : un environnement commun (authentification, droits, navigation, interface, données partagées, journalisation) dans lequel des modules métier indépendants s’ajoutent sans toucher au noyau.

- **Technologies** : PHP 8.4 natif, SQLite (évolution prévue vers MariaDB), HTML5/CSS, JavaScript natif (`fetch`). Aucun framework, aucun Composer.
- **Cible** : ordinateurs de bureau (largeur ≥ 1280 px), navigateurs récents (Firefox, Chrome, Edge).
- **Cahier des charges** : [docs/cahier-des-charges.md](docs/cahier-des-charges.md) (v0.8).

## Démarrage rapide

Prérequis : PHP 8.4 avec les extensions `pdo_sqlite`, `mbstring`, `fileinfo`, `openssl`. Sous Windows, une version portable peut être déposée dans `_tools/php84/php.exe` (détectée automatiquement par les lanceurs).

```bash
# Windows
start.bat

# Linux / macOS
chmod +x start.sh console.sh && ./start.sh
```

Le lanceur vérifie PHP, crée la base et les données de démonstration au premier démarrage, puis ouvre http://127.0.0.1:8000.

Atelier est conçu pour un seul utilisateur, son propriétaire. `console db:seed` crée ce compte unique :

| Compte | Mot de passe | Rôle |
|---|---|---|
| `admin` | `123456789azerty` | Administrateur (changement demandé à la première connexion) |

Ce mot de passe initial est public puisqu’il figure dans le dépôt : changez-le dès la première connexion, en particulier sur un serveur.

## Console d’administration

```bash
console.bat help          # Windows
./console.sh help         # Linux / macOS
```

Principales commandes : `check`, `db:migrate`, `db:seed`, `db:reset --force`, `admin:create <id>`, `admin:recover --confirm`, `user:password <id>`, `modules:list`, `modules:sync`, `modules:set <id> <état>`, `backup:create`, `backup:list`, `backup:restore <nom> --force`, `maintenance:purge`, `mariadb:export --to=…`, `test`.

## Organisation du code

```
public/            point d’entrée unique (index.php), CSS et JS communs — seul répertoire exposé par le serveur Web
src/               noyau (namespace Atelier\) : Kernel, Http, Security, Persistence, Modules, Shared, Activity, Logging, View, Error
modules/<id>/      un répertoire par module : manifest.json, src/, templates/, assets/, migrations/
config/            app.php (défauts versionnés), env.php.dist → env.local.php (local, non versionné)
var/               données locales : SQLite, pièces jointes, journaux, cache, sauvegardes, config/modules.json
tools/             dev-router.php (serveur intégré), console.php
tests/             suite de tests du noyau (php tests/run.php)
docs/              documentation : installation, exploitation, contrat de module, manifeste, données partagées, CHANGELOG
```

## Documentation

- [Installation et configuration](docs/installation.md)
- [Exploitation, sauvegarde, restauration, migration MariaDB](docs/exploitation.md)
- [Guide du développeur de module](docs/developpeur-module.md) (générateur `console module:create`)
- [Contrat technique d’un module](docs/contrat-module.md)
- [Schéma du manifeste](docs/manifest-schema.md)
- [Données partagées, catalogue, tags, relations, pièces jointes](docs/donnees-partagees.md)
- [Journal des versions](docs/CHANGELOG.md)

## Tests

```bash
_tools/php84/php.exe tests/run.php          # ou : console.bat test
```

## Licence

Atelier est distribué sous licence [GNU GPL v3](LICENSE). Vous pouvez l’utiliser, l’étudier, le modifier et le redistribuer, à condition que les versions dérivées restent sous la même licence.
