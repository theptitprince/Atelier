<div align="center">

# Atelier — modular web application

**A common, secure foundation on which independent feature modules are added without touching the core.**

![Version](https://img.shields.io/badge/version-0.9.1-2ea44f)
![PHP](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-vanilla-F7DF1E?logo=javascript&logoColor=black)
![Apache](https://img.shields.io/badge/Apache-2.4-D22128?logo=apache&logoColor=white)
![Platform](https://img.shields.io/badge/platform-web-0078D6)
![License](https://img.shields.io/badge/license-GPL--3.0--or--later-blue)

</div>

[Français](README.md) · **English**

> The program's interface is in French; its labels are quoted below as they appear on screen.

---

> [!WARNING]
> **Single-user application, shipped with a public initial password.** Atelier is designed for its
> owner, its only user. The account created by the demonstration data (`admin` / `123456789azerty`)
> has a password published in this repository: the application requires you to change it at first login.
> On a server, create the administrator with `admin:create` instead, which generates a random temporary password,
> and serve Atelier over HTTPS only (see [4. Deploying on a server](#4-deploying-on-a-server)).

Atelier is a modular, secure web application restricted to authenticated users: a common environment
(authentication, permissions, navigation, interface, shared data, logging) into which independent feature
modules are added without touching the core.

**Who it is for.** Its owner, who wants to bring their tools together in a single application: wiki-style pages,
a notepad, projects, a household budget, equipment maintenance, news monitoring, GPS points and a map. These
modules share their tags, their relations, their attachments and a common trash; a new module is added by
dropping its directory into `modules/`.

- **Technologies**: plain PHP 8.4, SQLite (migration to MariaDB ready, see [docs/exploitation.md](docs/exploitation.md)),
  HTML5/CSS, plain JavaScript (`fetch`). No framework, no Composer.
- **Target**: desktop computers (width ≥ 1280 px), recent browsers (Firefox, Chrome, Edge).
- **Specification**: [docs/cahier-des-charges.md](docs/cahier-des-charges.md) (v0.8, in French).

Current version: 0.9.1 — the release history is in [docs/CHANGELOG.md](docs/CHANGELOG.md) (in French). In
the application, this changelog is displayed by clicking the version number in the status bar; the *À propos*
button (the "i" icon at the bottom of the left-hand column) displays the legal notices: license, additional
terms and third-party libraries.

## Contents

- [1. Installation](#1-installation)
- [2. The files](#2-the-files)
- [3. Launching](#3-launching)
- [4. Deploying on a server](#4-deploying-on-a-server)
- [5. The modules](#5-the-modules)
- [6. Administration console](#6-administration-console)
- [7. Documentation](#7-documentation)
- [8. Troubleshooting](#8-troubleshooting)
- [9. License](#9-license)

---

## 1. Installation

### Requirements

- **PHP 8.4 or later**, with the `pdo_sqlite`, `mbstring`, `fileinfo` and `openssl` extensions, as well as
  `json`, `ctype` and `session`, which are usually present by default. The `curl` extension is used by the
  Actualités (News) module (fetching feeds); `pdo_mysql` is only needed for a migration to MariaDB.
- **No web server or database to install locally**: PHP's built-in server is enough and SQLite is
  bundled with PHP. In production: Apache 2.4 with `mod_rewrite`.
- **A browser**: one of the last two versions of Firefox, Chrome or Edge, on a screen at least 1280 px
  wide.

### Portable PHP on Windows

On Windows, a portable version of PHP can be placed in `_tools/php84/php.exe`: the launchers
detect it automatically (then, failing that, `_tools/php/php.exe`, then the `php` on the `PATH`). Its `php.ini` must
enable `extension_dir="ext"` and the `pdo_sqlite`, `sqlite3`, `mbstring`, `fileinfo` and `openssl` extensions.
The `_tools/` directory is not under version control: you have to create it yourself.

On Linux and macOS, the launchers look for `./_tools/php84/php`, `./_tools/php/php`, `php8.4` then `php`. For
example, on Debian or Ubuntu:

```bash
sudo apt install php8.4-cli php8.4-sqlite3 php8.4-mbstring
```

### Getting the project and checking it

Clone the repository (or copy the directory), then check PHP, the extensions and the writable directories:

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

## 2. The files

| File | Purpose |
|---|---|
| `start.bat` / `start.sh` | Windows / Linux-macOS launchers: check PHP, create the database on first start, start the development server |
| `console.bat` / `console.sh` | administration console (`tools/console.php`) |
| `cron.bat` / `cron.sh` | background tasks (`cron:run`), to be scheduled every 5 to 15 minutes |
| `index.php`, `.htaccess` (root) | hosting with a fixed root: the `.htaccess` rewrites every request to `public/`; `index.php` displays a diagnostic page if rewriting is not active |
| `public/` | single entry point (`index.php`), shared CSS and JS — the only directory exposed by the web server |
| `src/` | core (`Atelier\` namespace): Kernel, Http, Security, Persistence, Modules, Shared, Activity, Logging, View, Error, Support |
| `modules/<id>/` | one directory per module: `manifest.json`, `src/`, `templates/`, `assets/`, `migrations/` |
| `config/` | `app.php` (versioned defaults, set for production), `env.php.dist` → `env.local.php` (local, not versioned) |
| `var/` | local data, not versioned: SQLite database, attachments, logs, cache, backups, sessions, `config/modules.json` |
| `tools/` | `dev-router.php` (built-in server), `console.php` |
| `docs/` | documentation: specification, installation, operations, developer's guide, module contract, manifest, shared data, acceptance testing, CHANGELOG |
| `_tools/` | optional, not versioned: portable PHP on Windows (`_tools/php84/php.exe`) |
| `LICENSE` | full text of the GNU GPL version 3 |
| `NOTICE` | the additional license terms (section 7): the notice to keep |

---

## 3. Launching

### Quick start

```powershell
.\start.bat
```

```bash
chmod +x start.sh console.sh cron.sh
./start.sh
```

The launcher checks PHP (version 8.4 or later, `pdo_sqlite` and `mbstring` extensions), creates the
`var/data/atelier.sqlite` database and the demonstration data on first start — on subsequent starts, it only
applies pending migrations — then opens http://127.0.0.1:8000 in the browser. `Ctrl+C` stops the
server.

The port is passed as an argument: `.\start.bat 8080` or `./start.sh 8080`.

`config/app.php`, which is versioned, is set for production (`env = prod`, `debug = false`): a repository deployed as
is sends the client no stack trace, no file path and no SQL query. The development server
(`tools/dev-router.php`, used by the launchers) restores `dev` and `debug` locally.

### Manual equivalent

```powershell
.\_tools\php84\php.exe tools\console.php db:migrate
.\_tools\php84\php.exe tools\console.php db:seed
.\_tools\php84\php.exe -S 127.0.0.1:8000 -t public tools\dev-router.php
```

With PHP installed on the `PATH`, replace `.\_tools\php84\php.exe` with `php`.

```bash
php tools/console.php db:migrate
php tools/console.php db:seed
php -S 127.0.0.1:8000 -t public tools/dev-router.php
```

### The owner's account

Atelier is designed for a single user, its owner. `console db:seed` creates this single account:

| Account | Password | Role |
|---|---|---|
| `admin` | `123456789azerty` | Administrator (change requested at first login) |

This initial password is public since it appears in the repository: change it as soon as you first log in,
especially on a server. Until it has been changed, no module opens. The new password must be
at least 12 characters long and must not contain the username.

---

## 4. Deploying on a server

There is no launcher on the server: Apache 2.4 (with `mod_rewrite`) serves `public/index.php`,
the single entry point. Only the `public/` directory must be exposed; three cases:

- **DocumentRoot on `public/`** (recommended, personal server): in the VirtualHost,
  `DocumentRoot /srv/atelier/public`.
- **Shared hosting with a fixed web root** (OVH: `www/` folder): upload **the whole project** to
  `www/`, including the hidden `.htaccess` files. The root `.htaccess` rewrites every request to
  `public/`; `src/`, `config/`, `var/`, `modules/`, `tools/` and `docs/` each carry a `.htaccess` file
  containing `Require all denied`. You need `mod_rewrite` and `AllowOverride All`.
- **Subdirectory of the site** (e.g. `https://exemple.fr/atelier/`): same principle, by uncommenting
  `RewriteBase /atelier/` in the relevant `.htaccess` and by setting `app.base_url = '/atelier'` in
  `config/env.local.php`.

After the transfer, on the server:

```bash
cp config/env.php.dist config/env.local.php
php tools/console.php check
php tools/console.php db:migrate
php tools/console.php admin:create <identifiant>
```

`admin:create` displays a temporary password, to be changed at first login. In
`config/env.local.php`, force `session.cookie_secure = true` and serve the application over HTTPS only.

Then check that `https://votre-site/` displays the login page, and that `https://votre-site/config/app.php`
and `https://votre-site/var/data/atelier.sqlite` return 403. The PHP process must be able to write to `var/` and
its subdirectories; the SQLite file must reside on a disk local to the server, never on a network share
or a synchronized folder.

The details (configuration, `ATELIER_*` environment variables, installing a module) are in
[docs/installation.md](docs/installation.md); backup, restore and MariaDB migration in
[docs/exploitation.md](docs/exploitation.md).

---

## 5. The modules

Each module lives in its `modules/<id>/` directory and describes itself in its `manifest.json`. The modules share
the core's services: tags, relations, attachments (encrypted at rest by default), the shared-data
catalog and the trash. Items deleted in the modules (pages, notes, projects, transactions, equipment,
GPS points, feeds, attached files…) can be restored from the Corbeille (Trash) for 30 days by default.

| Module | ID | Purpose |
|---|---|---|
| **Général** (General) | | |
| Accueil (Home) | `home` | dashboard displayed after login |
| Mon profil (My profile) | `profile` | account information, password change and personal preferences |
| **Outils** (Tools) | | |
| Bloc-notes (Notepad) | `notes` | personal notes: writing, search, shared tags and trash |
| Projets (Projects) | `project` | containers that group tasks, a logbook, documents and information from all the modules |
| Pages | `wiki` | wiki-style pages linked to one another, with images and attached files, tags, GPS locations and version history |
| Entretien (Maintenance) | `maintenance` | household maintenance management (CMMS): equipment, scheduled maintenance tasks, breakdowns, reminders, service records, history |
| Budget | `budget` | household accounting: accounts, transactions, budgets by category, forecast, savings goals |
| Coordonnées GPS (GPS coordinates) | `geo` | reference set of GPS points, reusable by the other modules to attach information to |
| Tags partagés (Shared tags) | `tags` | tag cloud and tag management: search, renaming, merging, duplicates and unused tags |
| Carte (Map) | `map` | map of the GPS points and of what is linked to them (OpenStreetMap or OpenSeaMap base maps, layers by tag and by link type) |
| Explorateur (Explorer) | `explorer` | cross-module view of shared information: search, tags, relations and attachments |
| Fichiers joints (Attached files) | `attachments` | upload, display name, tags, virtual folders and secure download of attached files |
| Actualités (News) | `news` | news monitoring from RSS, Atom or JSON Feed feeds, sorted by category and by area of interest, with archiving |
| Corbeille (Trash) | `trash` | global trash: restore and permanent deletion |
| Démonstration (Demonstration) | `demo` | sample module, reference for the core's components and behaviors |
| **Administration** | | |
| Utilisateurs et droits (Users and permissions) | `users` | accounts, groups and access rights (ACL) |
| Gestion des modules (Module management) | `modules-admin` | states, display order and dataset catalog |
| Paramètres généraux (General settings) | `settings` | dynamic application settings, backups and maintenance |
| Journal d’activité (Activity log) | `activity` | viewing and exporting the functional and security activity log |

The Carte module loads its base maps from `tile.openstreetmap.org` and `tiles.openseamap.org`: it needs Internet
access. The Actualités module fetches its feeds as a background task (see [Scheduled tasks](#scheduled-tasks)).
Most modules describe how they work in their own `README.md` (in French).

---

## 6. Administration console

```powershell
.\console.bat help
```

```bash
./console.sh help
```

| Command | Purpose |
|---|---|
| `help` | displays the help, preceded by the license notice |
| `check` | checks PHP, the extensions and the directories |
| `db:migrate` | applies the core and module migrations, synchronizes the manifests |
| `db:seed` | creates the single administrator account, the groups, the initial rules and the modules' sample data |
| `db:reset --force` | deletes the SQLite database then recreates it with the demonstration data (SQLite only) |
| `admin:create <identifiant> [--password=…]` | creates an administrator account |
| `admin:recover --confirm [identifiant]` | restores administrator access to an existing account (`admin` by default); without `--confirm`, it only explains what it will do |
| `user:password <identifiant>` | assigns a temporary password to a user and unlocks their account |
| `modules:list` | lists the installed modules and their state |
| `modules:sync` | forces synchronization of the manifests (resources, permissions, catalog) |
| `modules:set <id> <état>` | changes a module's state: `active`, `inactive` or `maintenance` |
| `module:create <id> [options]` | generates the skeleton of a module (see the [developer's guide](docs/developpeur-module.md)) |
| `backup:create` | consistent backup of the database, the attachments and the configuration |
| `backup:list` | lists the available backups |
| `backup:restore <nom> --force` | restores a backup (a safety backup is created first) |
| `maintenance:purge` | applies the retention rules (activity log, technical logs, trash) |
| `cron:run` | runs the modules' background tasks |
| `attachments:verify` | checks the integrity of all attachments |
| `attachments:encrypt` | encrypts the attachments still stored in plain text |
| `mariadb:export --to=…` | exports the SQLite data to a configured MariaDB database (`--dry-run` for a trial run) |

### Scheduled tasks

The modules' background tasks (currently, fetching the feeds of the Actualités module) go through `cron.bat` or
`cron.sh`, to be scheduled every 5 to 15 minutes:

```powershell
schtasks /Create /SC MINUTE /MO 10 /TN "Atelier cron" /TR "C:\chemin\vers\atelier\cron.bat" /F
```

```bash
# crontab -e
*/10 * * * * /chemin/vers/atelier/cron.sh >> /chemin/vers/atelier/var/logs/cron.log 2>&1
```

Also schedule `maintenance:purge` once a day, and regular backups with `backup:create` (recommended
policy in [docs/exploitation.md](docs/exploitation.md)).

---

## 7. Documentation

All these documents are in French.

- [Specification](docs/cahier-des-charges.md) (v0.8)
- [Installation and configuration](docs/installation.md)
- [Operations, backup, restore, MariaDB migration](docs/exploitation.md)
- [Module developer's guide](docs/developpeur-module.md) (`console module:create` generator)
- [Technical contract of a module](docs/contrat-module.md)
- [Manifest schema](docs/manifest-schema.md)
- [Shared data, catalog, tags, relations, attachments](docs/donnees-partagees.md)
- [Acceptance testing of the foundation](docs/recette.md): criteria from the specification (status as of version 0.2.0)
- [Changelog](docs/CHANGELOG.md)

---

## 8. Troubleshooting

**"PHP introuvable" (PHP not found) at startup.** Install PHP 8.4, or place a portable version in `_tools\php84\`
on Windows (see [1. Installation](#1-installation)).

**"PHP 8.4 ou supérieur est requis" (PHP 8.4 or later is required) or "Extensions PHP requises manquantes"
(required PHP extensions missing).** Check the PHP version and its `php.ini`; `.\console.bat check` (or
`./console.sh check`) tells you exactly what is missing.

**The "Atelier n’est pas encore servi correctement" (Atelier is not yet being served correctly) page appears on
the server.** Rewriting to `public/` is not active: point the DocumentRoot to `public/`, or check `mod_rewrite`,
`AllowOverride All` and that the hidden `.htaccess` file is present after the transfer.

**An error message gives an `ERR-XXXXXX` reference.** Look for it in the day's technical log,
`var/logs/atelier-AAAA-MM-JJ.log` (AAAA-MM-JJ being the date as YYYY-MM-DD).

**Maintenance page, 503 responses.** The database is unreachable: the application then refuses all
protected content and the page refreshes every 60 seconds. Check the database with the console's `check`
command; the incident reference displayed can be found in the day's technical log.

**Lost administrator access.** There is no hidden account. On the server only:

```bash
php tools/console.php admin:recover --confirm [identifiant]
```

The command reactivates and unlocks the account (`admin` by default), assigns it a temporary password
displayed only once, to be changed at the next login, and gives it back its administration rights (ACL rule
`allow admin` on the root). The operation is recorded in the activity log.

**Actualités: "unable to get local issuer certificate".** The PHP in use (often a portable PHP) has no
root certificate. Place the Mozilla `cacert.pem` bundle (<https://curl.se/docs/caextract.html>) in
`var/config/cacert.pem`: it is used automatically when `curl.cainfo` is not configured.

---

## 9. License

Atelier is **free software**, distributed under the **GNU General Public License, version 3 or later**
(GPL-3.0-or-later).

Copyright © 2026 ETDEL.

In practical terms, you may use the program freely, study it, modify it and redistribute it — provided
that any redistributed version, modified or not, remains under the same license, with its source code and the
copyright notice. The program is provided **without any warranty**.

### Additional terms (section 7)

The GPL v3 lets the author add a few terms (section 7). Atelier's are in the
[`NOTICE`](NOTICE) file, in French and in English:

- **Attribution**: every copy or modified version must keep, visible to the user (an "About"
  window or equivalent) and in its documentation, the notice:

  > Atelier, par ETDEL — https://github.com/theptitprince/Atelier

  A modified version may prefix it with "D'après" (or "Based on") and add its own credits,
  but may neither remove nor hide it.
- **Origin**: a modified version must state clearly that it has been modified, and must not
  present itself as the original or as endorsed by ETDEL.

In Atelier, this notice is shown on the login page and in the *À propos* window (sidebar).

The full text of the license is in the repository's `LICENSE` file and at
<https://www.gnu.org/licenses/gpl-3.0.html>. Each Atelier source file (PHP, JavaScript, CSS, launchers and
`.htaccess`) carries a header that restates the notice and the license, and points to `NOTICE`.

### Third-party libraries

This third-party material keeps its own license: Atelier's additional terms do not apply to it, and its
files do not carry Atelier's header.

- **Leaflet 1.9.4** (Carte module), © 2010-2023 Volodymyr Agafonkin, © 2010-2011 CloudMade, under the
  BSD-2-Clause license: text in `modules/map/assets/vendor/leaflet/LICENSE`.
- **mini-sparkline 1.0.0** (Démonstration module), under the MIT license, as stated in its header and in the
  module's manifest.
- The base maps come from OpenStreetMap (© OpenStreetMap contributors) and OpenSeaMap; the map
  displays these attributions.

---

*Atelier — ETDEL 2026 — GPL-3.0-or-later*
