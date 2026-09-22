# Installation et configuration

## 1. Prérequis

| Élément | Exigence |
|---|---|
| PHP | 8.4 ou supérieur (CLI pour le développement, module Apache ou PHP-FPM en production) |
| Extensions PHP | `pdo_sqlite` (et `sqlite3`), `mbstring`, `fileinfo`, `openssl`, `ctype`, `session`, `json` ; `pdo_mysql` si migration vers MariaDB |
| Serveur Web | Aucun en local (serveur intégré à PHP) ; Apache 2.4 avec `mod_rewrite` en production |
| Base de données | Aucune : SQLite est embarqué dans PHP |
| Navigateur | Deux dernières versions de Firefox, Chrome, Edge ; écran ≥ 1280 px |

Vérification : `console check`.

## 2. Installation locale (développement et essais)

1. Récupérer le projet (clone ou copie du répertoire).
2. Optionnel sous Windows : déposer une version portable de PHP 8.4 dans `_tools/php84/` (le fichier `php.ini` doit activer `extension_dir="ext"` et les extensions `pdo_sqlite`, `sqlite3`, `mbstring`, `fileinfo`, `openssl`).
3. Lancer `start.bat` (Windows) ou `./start.sh` (Linux/macOS). Au premier démarrage, la base `var/data/atelier.sqlite` est créée, les migrations appliquées et les données de démonstration insérées.
4. Ouvrir http://127.0.0.1:8000 et se connecter avec `admin`.

Le port peut être passé en argument : `start.bat 8080`.

Équivalent manuel :

```bash
php tools/console.php db:migrate
php tools/console.php db:seed
php -S 127.0.0.1:8000 -t public tools/dev-router.php
```

## 3. Configuration

Les valeurs par défaut sont dans `config/app.php` (versionné, sans secret). Pour surcharger localement :

```bash
cp config/env.php.dist config/env.local.php
```

`config/env.local.php` n’est pas versionné et ne contient que les clés à modifier (`app.env`, `app.debug`, `app.base_url`, `database.*`, `session.cookie_secure`…). Les variables d’environnement `ATELIER_*` ont la priorité finale : `ATELIER_APP_DEBUG=0`, `ATELIER_DATABASE_SQLITE_PATH=/srv/atelier/var/data/atelier.sqlite`, `ATELIER_DATABASE_DRIVER=mysql`.

Environnements distincts : `app.env` = `dev`, `test` ou `prod`. En `prod`, désactiver `app.debug`, forcer `session.cookie_secure = true` et servir uniquement en HTTPS.

### Fichier de configuration des modules

`var/config/modules.json` conserve les surcharges administratives (activation, maintenance, groupe, ordre). Il est écrit atomiquement par l’application et n’est jamais écrasé par la mise à jour d’un module. Il doit rester hors de `public/`.

## 4. Déploiement sur Apache (OVH ou serveur personnel)

Il n’y a pas de lanceur sur le serveur : c’est Apache qui sert `public/index.php`, unique point d’entrée. Seul le répertoire `public/` doit être exposé ; trois cas :

- **DocumentRoot sur `public/`** (recommandé, serveur personnel) : dans le VirtualHost, `DocumentRoot /srv/atelier/public`. Le `public/.htaccess` route toute URL vers `index.php` et refuse les autres fichiers PHP.
- **Hébergement mutualisé avec racine web imposée** (OVH : dossier `www/`) : déposer **tout le projet** dans `www/` (y compris les fichiers cachés `.htaccess`). Le `.htaccess` de la racine réécrit chaque requête vers `public/` ; les répertoires `src/`, `config/`, `var/`, `modules/`, `tools/`, `tests/` et `docs/` portent chacun un `.htaccess` `Require all denied`, donc ni la base SQLite, ni la configuration, ni le code ne sont accessibles en HTTP. Prérequis : `mod_rewrite` et `AllowOverride All` (standard chez OVH). Si la réécriture n’est pas active, `index.php` à la racine affiche une page de diagnostic au lieu de l’application.
- **Sous-répertoire du site** (ex. `https://exemple.fr/atelier/`) : même principe, en décommentant `RewriteBase /atelier/` dans le `.htaccess` concerné et en définissant `app.base_url = '/atelier'` dans `config/env.local.php`.

Vérification après transfert : `https://votre-site/` doit afficher la page de connexion ; `https://votre-site/config/app.php` et `https://votre-site/var/data/atelier.sqlite` doivent répondre 403.

Droits : le processus PHP doit pouvoir écrire dans `var/` et ses sous-répertoires (`data`, `attachments`, `logs`, `cache`, `backups`, `sessions`, `tmp`, `config`). Les répertoires `src/`, `modules/`, `config/` peuvent rester en lecture seule.

Le fichier SQLite doit résider sur un disque local au serveur, jamais sur un partage réseau ou un dossier synchronisé.

Après transfert, exécuter sur le serveur :

```bash
php tools/console.php check
php tools/console.php db:migrate
php tools/console.php admin:create <identifiant>
```

Le transfert du local vers le serveur ne demande que la configuration d’environnement : aucun code métier ne dépend de l’hébergeur.

## 5. Installation d’un module

1. Déposer le répertoire du module dans `modules/<id>/` (l’identifiant du manifeste doit être égal au nom du répertoire).
2. `php tools/console.php db:migrate` : validation du manifeste, migrations, synchronisation des ressources ACL, permissions et catalogue. Le rechargement complet de l’interface effectue la même synchronisation dès qu’un manifeste change.
3. Vérifier `modules:list` : un manifeste invalide met le module seul en erreur, avec le détail.
4. Activer le module et attribuer les droits depuis l’administration (modules « Gestion des modules » et « Utilisateurs et droits »), ou `modules:set <id> active`.

Aucun catalogue en ligne ni téléversement de code depuis le navigateur n’est fourni.
