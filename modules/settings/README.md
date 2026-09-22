# Module Paramètres généraux (`settings`)

Administration des valeurs modifiables depuis l’application, des sauvegardes et de la maintenance.

## Écrans

| Route | Permission | Contenu |
|---|---|---|
| `general` | `open` (modification : `admin`) | Paramètres dynamiques stockés en base (table `settings`, portée `core`) : nom affiché, message d’accueil, fuseau d’affichage, rétentions, taille de page par défaut. Rappel en lecture seule de la configuration d’environnement. |
| `backups` | `admin` | Liste des sauvegardes de `var/backups`, création d’une sauvegarde complète (base, pièces jointes, configuration des modules). La restauration reste réservée à la console. |
| `maintenance` | `admin` | Indicateurs (taille de la base, pièces jointes, journal, sessions…), application des rétentions, vidage du cache des manifestes. |

## Actions

`save`, `backup`, `purge`, `clear-cache` — toutes soumises à la permission `admin` sur `atelier/settings` et journalisées (`settings.update`, `backup.create`, `maintenance.purge`, `maintenance.clear_cache`).

## Paramètres exposés aux autres modules

`$this->ctx->settings->get('default_page_size', 25)`, `get('display_timezone', 'Europe/Paris')`, `get('activity_retention_months', 12)`, `get('technical_retention_days', 30)`, `get('app_display_name', '')`, `get('welcome_message', '')`.
