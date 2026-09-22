<?php

declare(strict_types=1);

namespace Atelier\Kernel;

use Atelier\Activity\ActivityLog;
use Atelier\Security\Acl\AclService;
use Atelier\Support\Clock;
use Atelier\Support\Files;
use Atelier\Support\Json;
use Atelier\Support\Str;
use Throwable;

/**
 * Commandes d'administration locales (tools/console.php). Elles ne sont jamais accessibles
 * depuis le Web et leurs actions sensibles sont journalisées.
 */
final class Console
{
    /** @var array<string, array{0: string, 1: string}> nom => [méthode, description] */
    private const COMMANDS = [
        'help' => ['help', 'Affiche cette aide'],
        'check' => ['check', 'Vérifie PHP, les extensions et les répertoires'],
        'db:migrate' => ['migrate', 'Applique les migrations du noyau et des modules, synchronise les manifestes'],
        'db:reset' => ['reset', 'Supprime la base SQLite puis la recrée avec les données de démonstration (--force requis)'],
        'db:seed' => ['seed', 'Ajoute les données de démonstration (comptes, groupes, ACL, exemples)'],
        'admin:create' => ['adminCreate', 'Crée un compte administrateur : admin:create <identifiant> [--password=...]'],
        'admin:recover' => ['adminRecover', 'Rétablit temporairement l’accès du compte administrateur initial (confirmation requise)'],
        'user:password' => ['userPassword', 'Réinitialise le mot de passe d’un utilisateur : user:password <identifiant>'],
        'modules:list' => ['modulesList', 'Liste les modules installés et leur état'],
        'modules:sync' => ['modulesSync', 'Force la synchronisation des manifestes (ressources, permissions, catalogue)'],
        'modules:set' => ['modulesSet', 'Modifie l’état d’un module : modules:set <id> active|inactive|maintenance'],
        'backup:create' => ['backupCreate', 'Sauvegarde cohérente de la base, des pièces jointes et de la configuration'],
        'backup:list' => ['backupList', 'Liste les sauvegardes disponibles'],
        'backup:restore' => ['backupRestore', 'Restaure une sauvegarde : backup:restore <nom> (--force requis)'],
        'maintenance:purge' => ['purge', 'Applique les rétentions (journal d’activité, journaux techniques, corbeille)'],
        'mariadb:export' => ['mariadbExport', 'Exporte les données SQLite vers une base MariaDB configurée : mariadb:export --to=config/env.mariadb.php'],
        'test' => ['test', 'Exécute la suite de tests'],
    ];

    /** @var array<string, string|bool> */
    private array $options = [];

    /** @var list<string> */
    private array $arguments = [];

    public function __construct(private readonly Application $app, private readonly string $rootPath)
    {
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        array_shift($argv);
        $command = array_shift($argv) ?? 'help';
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--')) {
                [$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
                $this->options[$name] = $value;
            } else {
                $this->arguments[] = $arg;
            }
        }
        if (!isset(self::COMMANDS[$command])) {
            $this->error('Commande inconnue : ' . $command);
            $this->help();
            return 1;
        }
        try {
            $result = $this->{self::COMMANDS[$command][0]}();
            return is_int($result) ? $result : 0;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            if ($this->app->config->isDebug()) {
                $this->line($e->getTraceAsString());
            }
            return 1;
        }
    }

    // ----- Commandes -----

    private function help(): int
    {
        $this->line('Atelier — console d’administration');
        $this->line('Usage : php tools/console.php <commande> [arguments] [--option=valeur]');
        $this->line('');
        foreach (self::COMMANDS as $name => [, $description]) {
            $this->line(sprintf('  %-20s %s', $name, $description));
        }
        return 0;
    }

    private function check(): int
    {
        $ok = true;
        $this->line('PHP ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.4.0', '>=') ? '  OK' : '  ATTENTION : PHP 8.4 requis'));
        foreach (['pdo_sqlite', 'mbstring', 'json', 'fileinfo', 'openssl', 'ctype', 'session'] as $ext) {
            $loaded = extension_loaded($ext);
            $ok = $ok && $loaded;
            $this->line(sprintf('  extension %-12s %s', $ext, $loaded ? 'OK' : 'MANQUANTE'));
        }
        foreach (['var', 'attachments', 'logs', 'cache', 'backups', 'tmp'] as $name) {
            $path = $this->app->config->path($name);
            Files::ensureDirectory($path);
            $writable = Files::isWritableDirectory($path);
            $ok = $ok && $writable;
            $this->line(sprintf('  répertoire %-11s %s (%s)', $name, $writable ? 'OK' : 'NON INSCRIPTIBLE', $path));
        }
        $this->line('  base de données      ' . ($this->app->db->isAvailable() ? 'OK (' . $this->app->db->driver() . ')' : 'INDISPONIBLE'));
        $this->line('  modules              ' . count($this->app->modules->all()) . ' découverts');
        return $ok ? 0 : 1;
    }

    private function migrate(): int
    {
        foreach ($this->app->synchronizer()->syncAll() as $line) {
            $this->line('  ' . $line);
        }
        $this->app->synchronizer()->invalidate();
        $this->app->synchronizer()->syncIfNeeded();
        $this->info('Migrations et synchronisation terminées.');
        return 0;
    }

    private function reset(): int
    {
        if (!$this->app->db->isSqlite()) {
            $this->error('db:reset n’est disponible que pour SQLite.');
            return 1;
        }
        if (!$this->hasOption('force')) {
            $this->error('Cette commande supprime toutes les données. Ajoutez --force pour confirmer.');
            return 1;
        }
        $path = $this->app->db->sqlitePath();
        $this->app->db->close();
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if ($file !== null && is_file($file)) {
                @unlink($file);
            }
        }
        $this->app->synchronizer()->invalidate();
        $this->info('Base supprimée.');
        $this->migrate();
        return $this->seed();
    }

    private function seed(): int
    {
        $this->app->synchronizer()->syncIfNeeded();
        $seeder = new Seeder($this->app);
        foreach ($seeder->run() as $line) {
            $this->line('  ' . $line);
        }
        $this->info('Données de démonstration en place. Compte administrateur : admin / Atelier-admin-2026 (changement demandé à la première connexion).');
        return 0;
    }

    private function adminCreate(): int
    {
        $username = $this->arguments[0] ?? null;
        if ($username === null || !preg_match('/^[a-zA-Z0-9._-]{2,64}$/', $username)) {
            $this->error('Identifiant requis (lettres, chiffres, . _ -).');
            return 1;
        }
        $this->app->synchronizer()->syncIfNeeded();
        $password = is_string($this->options['password'] ?? null) ? (string) $this->options['password'] : $this->app->passwords->generateTemporary();
        if ($this->app->users->usernameExists($username)) {
            $this->error('Cet identifiant existe déjà.');
            return 1;
        }
        $id = $this->app->users->create([
            'username' => $username,
            'display_name' => $username,
            'password_hash' => $this->app->passwords->hash($password),
            'must_change_password' => 1,
        ]);
        $admins = $this->app->users->findGroupByName('admins');
        if ($admins !== null) {
            $this->app->users->addToGroup($id, (int) $admins['id']);
            $this->app->acl->setRule('group', (int) $admins['id'], AclService::ROOT, 'admin', 'allow', null, 'Administration complète (console)');
        }
        $this->app->activity->record('core', 'console.admin_create', ActivityLog::SUCCESS, 'user:' . $id, 'Compte administrateur créé depuis la console', ['username' => $username]);
        $this->info("Compte créé : $username / $password (changement obligatoire à la première connexion).");
        return 0;
    }

    private function adminRecover(): int
    {
        if (!$this->hasOption('confirm')) {
            $this->line('Cette commande rétablit l’accès administrateur d’un compte existant :');
            $this->line('  - réactivation et déverrouillage du compte ;');
            $this->line('  - nouveau mot de passe temporaire ;');
            $this->line('  - règle ACL allow admin sur la racine pour ce compte.');
            $this->line('Relancez avec --confirm [identifiant] pour exécuter (par défaut : admin).');
            return 1;
        }
        $this->app->synchronizer()->syncIfNeeded();
        $username = $this->arguments[0] ?? 'admin';
        $user = $this->app->users->findByUsername($username);
        if ($user === null) {
            $this->error('Compte introuvable : ' . $username . '. Utilisez admin:create.');
            return 1;
        }
        $id = (int) $user['id'];
        $password = $this->app->passwords->generateTemporary();
        $this->app->users->enable($id);
        $this->app->users->unlock($id);
        $this->app->users->setPassword($id, $this->app->passwords->hash($password), true);
        $this->app->acl->setRule('user', $id, AclService::ROOT, 'admin', 'allow', null, 'Rétabli par admin:recover le ' . Clock::formatDateTime(Clock::utc()));
        $this->app->activity->record('core', 'console.admin_recover', ActivityLog::SUCCESS, 'user:' . $id, 'Accès administrateur rétabli depuis la console', ['username' => $username]);
        $this->info("Accès rétabli : $username / $password (changement obligatoire à la première connexion).");
        return 0;
    }

    private function userPassword(): int
    {
        $username = $this->arguments[0] ?? null;
        if ($username === null) {
            $this->error('Identifiant requis.');
            return 1;
        }
        $user = $this->app->users->findByUsername($username);
        if ($user === null) {
            $this->error('Compte introuvable.');
            return 1;
        }
        $password = $this->app->passwords->generateTemporary();
        $this->app->users->setPassword((int) $user['id'], $this->app->passwords->hash($password), true);
        $this->app->users->unlock((int) $user['id']);
        $this->app->activity->record('core', 'console.password_reset', ActivityLog::SUCCESS, 'user:' . $user['id'], 'Mot de passe réinitialisé depuis la console');
        $this->info("Mot de passe temporaire de $username : $password");
        return 0;
    }

    private function modulesList(): int
    {
        foreach ($this->app->modules->sorted() as $descriptor) {
            $this->line(sprintf('  %-14s %-9s v%-8s %s%s', $descriptor->id, $descriptor->state(), $descriptor->version(), $descriptor->name(), $descriptor->errors ? '  ERREURS : ' . implode(' ', $descriptor->errors) : ''));
        }
        return 0;
    }

    private function modulesSync(): int
    {
        $this->app->synchronizer()->invalidate();
        $this->app->synchronizer()->syncIfNeeded();
        $this->info('Synchronisation effectuée.');
        return 0;
    }

    private function modulesSet(): int
    {
        [$id, $state] = [$this->arguments[0] ?? '', $this->arguments[1] ?? ''];
        if (!$this->app->modules->has($id)) {
            $this->error('Module inconnu : ' . $id);
            return 1;
        }
        $this->app->modules->setModuleOverride($id, 'status', $state);
        $this->app->activity->record('core', 'console.module_state', ActivityLog::SUCCESS, 'module:' . $id, 'État modifié depuis la console : ' . $state);
        $this->info("Module $id : $state");
        return 0;
    }

    private function backupCreate(): int
    {
        $backup = new Backup($this->app);
        $name = $backup->create();
        $this->app->activity->record('core', 'console.backup_create', ActivityLog::SUCCESS, 'backup:' . $name, 'Sauvegarde créée depuis la console');
        $this->info('Sauvegarde créée : ' . $name);
        return 0;
    }

    private function backupList(): int
    {
        foreach ((new Backup($this->app))->list() as $entry) {
            $this->line(sprintf('  %-32s %10s  %s', $entry['name'], Str::humanSize($entry['size']), $entry['created_at']));
        }
        return 0;
    }

    private function backupRestore(): int
    {
        $name = $this->arguments[0] ?? '';
        if ($name === '' || !$this->hasOption('force')) {
            $this->error('Usage : backup:restore <nom> --force (remplace les données actuelles ; une sauvegarde de sécurité est créée avant).');
            return 1;
        }
        $backup = new Backup($this->app);
        $safety = $backup->create('avant-restauration');
        $this->line('  Sauvegarde de sécurité : ' . $safety);
        $report = $backup->restore($name);
        foreach ($report as $line) {
            $this->line('  ' . $line);
        }
        $this->app->activity->record('core', 'console.backup_restore', ActivityLog::SUCCESS, 'backup:' . $name, 'Sauvegarde restaurée depuis la console');
        $this->info('Restauration terminée.');
        return 0;
    }

    private function purge(): int
    {
        $months = $this->app->config->int('logging.activity_retention_months', 12);
        $days = $this->app->config->int('logging.technical_retention_days', 30);
        $trash = $this->app->config->int('trash.retention_days', 30);
        $this->line('  Journal d’activité : ' . $this->app->activity->purge($months) . ' entrées purgées (> ' . $months . ' mois)');
        $this->line('  Journaux techniques : ' . $this->app->logger->rotate() . ' fichiers supprimés (> ' . $days . ' jours)');
        $this->line('  Pièces jointes supprimées : ' . $this->app->shared->attachments->purgeDeleted($trash) . ' purgées (> ' . $trash . ' jours)');
        foreach ($this->app->modules->all() as $descriptor) {
            if (!$descriptor->isUsable()) {
                continue;
            }
            $module = $this->app->modules->instance($descriptor->id);
            if (method_exists($module, 'purge')) {
                $module->boot($this->app->context(\Atelier\Http\Request::create('GET', '/')));
                $this->line('  Module ' . $descriptor->id . ' : ' . (string) $module->purge());
            }
        }
        return 0;
    }

    private function mariadbExport(): int
    {
        $target = $this->options['to'] ?? null;
        if (!is_string($target) || !is_file($this->rootPath . '/' . $target) && !is_file($target)) {
            $this->error('Indiquez --to=<fichier de configuration> contenant la connexion MariaDB (voir docs/exploitation.md).');
            return 1;
        }
        $file = is_file($target) ? $target : $this->rootPath . '/' . $target;
        $config = require $file;
        $mysql = $config['database']['mysql'] ?? $config['mysql'] ?? null;
        if (!is_array($mysql)) {
            $this->error('Le fichier doit retourner [\'database\' => [\'mysql\' => [...]]].');
            return 1;
        }
        $targetConfig = Config::fromArray(Config::merge($this->app->config->all(), ['database' => ['driver' => 'mysql', 'mysql' => $mysql]]), $this->rootPath);
        $exporter = new MariaDbExporter($this->app, \Atelier\Persistence\Database::fromConfig($targetConfig));
        foreach ($exporter->run($this->hasOption('dry-run')) as $line) {
            $this->line('  ' . $line);
        }
        $this->info('Export terminé.');
        return 0;
    }

    private function test(): int
    {
        $runner = $this->rootPath . '/tests/run.php';
        if (!is_file($runner)) {
            $this->error('Suite de tests introuvable.');
            return 1;
        }
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner), $code);
        return $code;
    }

    // ----- Sortie -----

    private function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    private function line(string $text): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    private function info(string $text): void
    {
        $this->line('✔ ' . $text);
    }

    private function error(string $text): void
    {
        fwrite(STDERR, '✖ ' . $text . PHP_EOL);
    }
}
