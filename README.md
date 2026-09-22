# Atelier

Webapplication modulaire, sécurisée et réservée aux utilisateurs authentifiés : un environnement commun (authentification, droits, navigation, interface, données partagées, journalisation) dans lequel des modules métier indépendants s’ajoutent sans toucher au noyau.

- **Technologies** : PHP 8.4 natif, SQLite (évolution prévue vers MariaDB), HTML5/CSS, JavaScript natif (`fetch`). Aucun framework, aucun Composer.
- **Cible** : ordinateurs de bureau (largeur ≥ 1280 px), navigateurs récents (Firefox, Chrome, Edge).
- **Cahier des charges** : `Cahier_des_charges_webapplication_modulaire.md` (v0.8).

## Démarrage rapide

Prérequis : PHP 8.4 avec les extensions `pdo_sqlite`, `mbstring`, `fileinfo`, `openssl`. Sous Windows, une version portable peut être déposée dans `_tools/php84/php.exe` (détectée automatiquement par les lanceurs).

```bash
# Windows
start.bat

# Linux / macOS
chmod +x start.sh console.sh && ./start.sh
```

Le lanceur vérifie PHP, crée la base et les données de démonstration au premier démarrage, puis ouvre http://127.0.0.1:8000.

Comptes de démonstration (voir `console db:seed`) :

| Compte | Mot de passe | Rôle |
|---|---|---|
| `admin` | `123456789azerty` | Administrateur (changement demandé à la première connexion) |
| `alice`, `bruno`, `claire` | `Atelier-demo-2026` | Utilisateurs d’exemple (`claire` est aussi administratrice) |

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
- [Contrat technique d’un module](docs/contrat-module.md)
- [Schéma du manifeste](docs/manifest-schema.md)
- [Données partagées, catalogue, tags, relations, pièces jointes](docs/donnees-partagees.md)
- [Journal des versions](docs/CHANGELOG.md)

## Tests

```bash
_tools/php84/php.exe tests/run.php          # ou : console.bat test
```
