<?php

declare(strict_types=1);

namespace Atelier\Modules\Settings;

use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Kernel\Backup;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Support\Files;
use Atelier\Support\Str;

/**
 * Paramètres généraux : valeurs dynamiques modifiables depuis l'application (stockées en base),
 * sauvegardes et maintenance (rétentions, cache des manifestes).
 */
final class SettingsModule extends AbstractModule
{
    /** Paramètres dynamiques : nom => [type, défaut, libellé, aide]. */
    public const DEFINITIONS = [
        'app_display_name' => ['string', '', 'Nom affiché de l’application', 'Laisser vide pour conserver « Atelier ».'],
        'welcome_message' => ['text', '', 'Message d’accueil', 'Affiché sur la page d’accueil des utilisateurs.'],
        'display_timezone' => ['timezone', 'Europe/Paris', 'Fuseau horaire d’affichage', 'Les dates sont stockées en UTC et affichées dans ce fuseau.'],
        'activity_retention_months' => ['int', 12, 'Rétention du journal d’activité (mois)', 'Entre 1 et 60 mois.'],
        'technical_retention_days' => ['int', 30, 'Rétention des journaux techniques (jours)', 'Entre 1 et 365 jours.'],
        'default_page_size' => ['choice', 25, 'Taille de page par défaut des tableaux', 'Jamais plus de 100 lignes par page.'],
    ];

    public const PAGE_SIZES = [10, 25, 50, 100];

    public function routes(RouteCollection $r): void
    {
        $r->view('general', [$this, 'general'], permission: 'open');
        $r->view('backups', [$this, 'backups'], permission: 'admin');
        $r->view('maintenance', [$this, 'maintenance'], permission: 'admin');
        $r->action('save', [$this, 'save'], permission: 'admin');
        $r->action('backup', [$this, 'backup'], permission: 'admin');
        $r->action('purge', [$this, 'purge'], permission: 'admin');
        $r->action('clear-cache', [$this, 'clearCache'], permission: 'admin');
    }

    // ----- Vues -----

    public function general(Request $request, array $params): ModuleView
    {
        $canEdit = $this->can('admin');
        $values = [];
        foreach (self::DEFINITIONS as $name => [, $default]) {
            $values[$name] = $this->ctx->settings->get($name, $default);
        }
        $config = $this->ctx->config;
        $readonly = [
            'Environnement' => $config->string('app.env'),
            'Mode débogage' => $config->isDebug() ? 'activé' : 'désactivé',
            'Version' => $config->string('app.version'),
            'Pilote de base de données' => $this->ctx->db->driver(),
            'Fichier SQLite' => $this->ctx->db->sqlitePath() ?? '—',
            'Répertoire var' => $config->path('var'),
            'Pièces jointes' => $config->path('attachments'),
            'Journaux techniques' => $config->path('logs'),
            'Configuration des modules' => $config->path('modules_config'),
            'Expiration de session' => sprintf('%d min d’inactivité, %d h au plus', intdiv($config->int('session.idle_timeout'), 60), intdiv($config->int('session.absolute_timeout'), 3600)),
        ];

        $content = $this->render('general', [
            'definitions' => self::DEFINITIONS,
            'values' => $values,
            'timezones' => $this->timezones(),
            'pageSizes' => self::PAGE_SIZES,
            'readonly' => $readonly,
            'canEdit' => $canEdit,
        ]);
        return $this->view('Paramètres généraux', $this->banner('Paramètres', 'Valeurs modifiables depuis l’application'), $content)
            ->status(count(self::DEFINITIONS) . ' paramètres');
    }

    public function backups(Request $request, array $params): ModuleView
    {
        $backup = new Backup($this->ctx->db, $this->ctx->config);
        $list = $backup->list();
        $actions = '<button type="button" class="btn btn--primary" data-action="backup" data-confirm="Créer une sauvegarde complète maintenant ? L’opération peut prendre quelques secondes.">'
            . Str::e('') . '<svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg> Créer une sauvegarde</button>';
        $content = $this->render('backups', [
            'backups' => $list,
            'directory' => $backup->directory(),
            'isSqlite' => $this->ctx->db->isSqlite(),
        ]);
        return $this->view('Sauvegardes', $this->banner('Sauvegardes', count($list) . ' sauvegarde(s)', $actions), $content)
            ->status(count($list) . ' sauvegarde(s) disponible(s)');
    }

    public function maintenance(Request $request, array $params): ModuleView
    {
        $db = $this->ctx->db;
        $config = $this->ctx->config;
        $sqlitePath = $db->sqlitePath();
        $indicators = [
            'Taille de la base SQLite' => $sqlitePath !== null && is_file($sqlitePath) ? Str::humanSize((int) filesize($sqlitePath)) : '—',
            'Pièces jointes (fichiers)' => Str::humanSize(Files::directorySize($config->path('attachments'))),
            'Pièces jointes (quota global)' => Str::humanSize($this->ctx->shared->attachments->usageTotal()) . ' / ' . Str::humanSize($config->int('attachments.max_total')),
            'Entrées du journal d’activité' => (string) $db->count('SELECT COUNT(*) FROM activity_log'),
            'Plus ancienne entrée du journal' => \Atelier\Support\Clock::formatDateTime($db->scalar('SELECT MIN(occurred_at) FROM activity_log'), '—'),
            'Journaux techniques' => count(glob($config->path('logs') . '/atelier-*.log') ?: []) . ' fichier(s), ' . Str::humanSize(Files::directorySize($config->path('logs'))),
            'Sessions actives (fichiers)' => (string) count(glob($config->string('session.save_path') . '/sess_*') ?: []),
            'Comptes actifs' => (string) $this->ctx->users->countActive(),
            'Modules découverts' => (string) count($this->ctx->modules()->all()),
            'Cache des manifestes' => is_file($config->path('cache') . '/manifests.hash') ? 'présent' : 'absent (synchronisation au prochain chargement)',
        ];
        $retentions = [
            'Journal d’activité' => $this->retention('activity_retention_months', 'logging.activity_retention_months', 12) . ' mois',
            'Journaux techniques' => $this->retention('technical_retention_days', 'logging.technical_retention_days', 30) . ' jours',
            'Corbeille des pièces jointes' => $config->int('trash.retention_days', 30) . ' jours',
        ];
        $content = $this->render('maintenance', ['indicators' => $indicators, 'retentions' => $retentions]);
        return $this->view('Maintenance', $this->banner('Maintenance', 'Indicateurs et rétentions'), $content)->status('Maintenance');
    }

    // ----- Actions -----

    public function save(Request $request, array $params): ActionResult
    {
        $this->require('admin');
        $errors = [];
        $changed = [];
        foreach (self::DEFINITIONS as $name => [$type, $default]) {
            $raw = $request->input($name);
            $value = match ($type) {
                'string' => Str::truncate(trim((string) ($raw ?? '')), 100, ''),
                'text' => Str::truncate(trim((string) ($raw ?? '')), 2000, ''),
                'timezone' => (string) ($raw ?? $default),
                'int' => $raw === null || $raw === '' ? null : (is_numeric($raw) ? (int) $raw : null),
                'choice' => is_numeric($raw) ? (int) $raw : null,
            };
            if ($type === 'timezone' && !in_array($value, $this->timezones(), true)) {
                $errors[$name] = 'Fuseau horaire inconnu.';
                continue;
            }
            if ($type === 'int') {
                [$min, $max] = $name === 'activity_retention_months' ? [1, 60] : [1, 365];
                if ($value === null || $value < $min || $value > $max) {
                    $errors[$name] = sprintf('Valeur entière attendue entre %d et %d.', $min, $max);
                    continue;
                }
            }
            if ($type === 'choice' && !in_array($value, self::PAGE_SIZES, true)) {
                $errors[$name] = 'Taille de page non autorisée.';
                continue;
            }
            if ($this->ctx->settings->get($name, $default) !== $value) {
                $this->ctx->settings->set($name, $value, 'core', $this->ctx->userId());
                $changed[] = $name;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($changed !== []) {
            $this->log('settings.update', 'success', 'settings:core', 'Paramètres modifiés', ['keys' => $changed]);
        }
        return ActionResult::ok(['changed' => $changed], $changed === [] ? 'Aucune modification.' : 'Paramètres enregistrés.')->dirty(false);
    }

    public function backup(Request $request, array $params): ActionResult
    {
        $this->require('admin');
        $backup = new Backup($this->ctx->db, $this->ctx->config);
        $name = $backup->create();
        $this->log('backup.create', 'success', 'backup:' . $name, 'Sauvegarde créée depuis l’interface');
        return ActionResult::ok(['name' => $name], 'Sauvegarde créée : ' . $name)->refresh();
    }

    public function purge(Request $request, array $params): ActionResult
    {
        $this->require('admin');
        $months = (int) $this->retention('activity_retention_months', 'logging.activity_retention_months', 12);
        $days = (int) $this->retention('technical_retention_days', 'logging.technical_retention_days', 30);
        $trash = $this->ctx->config->int('trash.retention_days', 30);
        $report = [
            'activity' => $this->ctx->activity->purge($months),
            'logs' => $this->ctx->logger->rotate(),
            'attachments' => $this->ctx->shared->attachments->purgeDeleted($trash),
        ];
        $this->log('maintenance.purge', 'success', null, 'Rétentions appliquées', $report);
        return ActionResult::ok($report, sprintf('Rétentions appliquées : %d entrée(s) de journal, %d fichier(s) de log, %d pièce(s) jointe(s) purgés.', $report['activity'], $report['logs'], $report['attachments']))->refresh();
    }

    public function clearCache(Request $request, array $params): ActionResult
    {
        $this->require('admin');
        $file = $this->ctx->config->path('cache') . '/manifests.hash';
        $existed = is_file($file);
        if ($existed) {
            @unlink($file);
        }
        $this->log('maintenance.clear_cache', 'success', null, 'Cache des manifestes vidé');
        return ActionResult::ok(['cleared' => $existed], $existed ? 'Cache vidé : les manifestes seront resynchronisés au prochain chargement.' : 'Le cache était déjà vide.')->refresh();
    }

    // ----- Utilitaires -----

    private function banner(string $title, string $subtitle, string $actions = ''): string
    {
        return $this->renderCore('banner', ['icon' => 'settings', 'title' => $title, 'subtitle' => $subtitle, 'actions' => $actions]);
    }

    /** Valeur dynamique si définie, sinon valeur de configuration. */
    private function retention(string $setting, string $configKey, int $default): int
    {
        $value = $this->ctx->settings->get($setting);
        return is_numeric($value) ? (int) $value : $this->ctx->config->int($configKey, $default);
    }

    /** @return list<string> */
    private function timezones(): array
    {
        return array_values(array_merge(['UTC'], \DateTimeZone::listIdentifiers(\DateTimeZone::EUROPE)));
    }
}
