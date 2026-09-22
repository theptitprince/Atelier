# Module `profile` — Mon profil

Profil et préférences de l'utilisateur connecté. Le module n'agit **que** sur le compte courant (`$this->ctx->user()`) : il ne permet ni de consulter ni de modifier d'autres comptes (rôle du module Utilisateurs).

## Écrans

- **Profil** (`index`) : informations du compte (identifiant, nom affiché, email, groupes, date de création, dernière connexion, date du dernier changement de mot de passe) ; formulaire de modification du nom affiché et de l'email ; formulaire de changement de mot de passe.
- **Préférences** (`preferences`) : taille de page des tableaux (10/25/50/100), colonne des modules repliée par défaut, confirmation avant fermeture d'onglet même sans modification, format d'heure (24 h, lecture seule en v1), notifications sonores (à venir, désactivé).

## Actions

| Route | Effet | Journal |
|---|---|---|
| `save` | Valide puis enregistre `display_name` (obligatoire, ≤ 100 caractères) et `email` (facultatif, valide, ≤ 190) via `UserRepository::update()` | `profile.update` |
| `password` | Délègue à `Auth::changePassword(current, new, confirmation)` (erreurs de champs `current_password`, `password`, `password_confirmation`) | `profile.update` (+ `auth.password_changed` par le noyau) |
| `save-preferences` | Enregistre `pageSize`, `sidebarCollapsed`, `confirmClose` via `Settings::setPreference($userId, $name, $value, 'core')` | `profile.preferences` |

Toutes les routes exigent la permission `open` sur `atelier/profile` ; les actions ne prennent aucun identifiant d'utilisateur en entrée.

## Préférences lisibles par les autres modules

Les préférences sont stockées dans la portée `core` :

```php
$perPage   = (int)  $this->ctx->settings->preference($userId, 'pageSize', 25);
$collapsed = (bool) $this->ctx->settings->preference($userId, 'sidebarCollapsed', false);
$confirm   = (bool) $this->ctx->settings->preference($userId, 'confirmClose', false);
```

## JavaScript

`assets/profile.js` écoute `atelier:submitted` sur les formulaires du module pour : appliquer immédiatement `Atelier.nav.setCollapsed(bool)` après l'enregistrement des préférences, refléter le nouveau nom affiché dans le pied de la colonne, réinitialiser le formulaire de mot de passe. Aucune logique métier côté client.

## Fichiers

```
modules/profile/
├── manifest.json
├── src/ProfileModule.php
├── templates/index.php
├── templates/preferences.php
├── assets/profile.css
├── assets/profile.js
└── README.md
```
