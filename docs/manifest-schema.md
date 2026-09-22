# Schéma du fichier `manifest.json`

Le manifeste est la source de vérité de l’identité d’un module, de sa navigation et de ses déclarations. Il est validé à chaque découverte ; toute erreur désactive uniquement le module concerné.

```json
{
  "id": "notes",
  "name": "Bloc-notes",
  "version": "1.0.0",
  "description": "Notes personnelles",
  "author": "Atelier",
  "icon": "note",
  "group": "tools",
  "order": 20,
  "status": "active",
  "namespace": "Atelier\\Modules\\Notes",
  "entry": "NotesModule",
  "defaultRoute": "list",
  "keepAlive": false,
  "badge": null,
  "permissions": [
    { "code": "assist", "label": "Assistance", "description": "Lire les notes d’autres utilisateurs" }
  ],
  "navigation": [
    { "id": "list", "label": "Mes notes", "route": "list", "order": 1, "permission": "open", "icon": "list", "description": "Liste des notes" },
    { "id": "new", "label": "Nouvelle note", "route": "new", "order": 2, "permission": "create", "icon": "plus", "parent": null }
  ],
  "resources": [
    { "path": "action/purge", "label": "Purger la corbeille", "kind": "action", "permissions": ["execute"] }
  ],
  "assets": {
    "css": ["assets/notes.css"],
    "js": ["assets/notes.js"],
    "vendor": [
      { "type": "js", "path": "assets/vendor/lib.min.js", "name": "lib", "version": "1.2.3", "license": "MIT" }
    ]
  },
  "datasets": [
    {
      "code": "notes.note",
      "name": "Notes",
      "description": "Notes personnelles",
      "visibility": "private",
      "tables": ["notes_note"],
      "fields": { "title": "Titre", "content": "Contenu" },
      "operations": ["read", "create", "update", "delete"],
      "version": 1
    }
  ],
  "consumes": ["users.account"],
  "migrations": "migrations"
}
```

## Champs

| Champ | Obligatoire | Règles |
|---|---|---|
| `id` | oui | slug, identique au nom du répertoire, non réservé (`core`, `atelier`, `assets`, `m`, `api`, `files`, `login`, `logout`) |
| `name` | oui | libellé affiché |
| `version` | oui | `X.Y.Z` |
| `description`, `author` | non | texte court |
| `icon` | non | nom d’icône du sprite commun (`module` par défaut) |
| `group` | non | identifiant de groupe d’affichage (`general`, `tools`, `administration` ou libre) ; défaut `tools` |
| `order` | non | ordre par défaut dans le groupe (défaut 100) |
| `status` | non | `active` (défaut), `inactive`, `maintenance` ; surchargeable par l’administrateur |
| `namespace` | oui | espace de noms PHP de `src/` |
| `entry` | oui | nom de la classe d’entrée, fichier `src/<entry>.php` |
| `defaultRoute` | non | route ouverte par le libellé du module (défaut `index`) |
| `keepAlive` | non | conserve les minuteries en onglet masqué (à justifier) |
| `badge` | non | route d’action GET retournant `{ "count": n, "label": "..." }`, interrogée périodiquement pour la colonne |
| `permissions[]` | non | `code` (slug ≤ 32), `label`, `description` : permissions propres au module en plus des génériques |
| `navigation[]` | non | `id` unique, `label`, `route`, `order`, `permission` (défaut `open`), `icon`, `description`, `parent` (id d’une autre entrée, 3 niveaux max), `resource` (sous-ressource ACL, défaut `screen/<id>`) |
| `resources[]` | non | ressources protégées supplémentaires : `path`, `label`, `kind` (`screen`, `dataset`, `action`, `group`), `permissions` |
| `assets.css[]`, `assets.js[]` | non | chemins relatifs au module, fichiers existants |
| `assets.vendor[]` | non | `type` (`css`/`js`), `path`, `name`, `version`, `license` |
| `datasets[]` | non | `code` préfixé `<id>.`, `name`, `visibility` **obligatoire** (`shared`/`private`), `tables`, `fields`, `operations`, `version`, `description`, `openRoute` (route interne contenant `{key}`, ex. `edit/{key}`, permettant aux modules transversaux — tags, explorateur, corbeille — d’ouvrir l’information dans son module) |
| `consumes[]` | non | codes de jeux partagés consommés |
| `migrations` | non | répertoire des migrations (défaut `migrations`) |

## Ressources ACL générées

Pour un module `notes` :

- `atelier/notes` — module (permissions génériques + propres) ;
- `atelier/notes/screen/<navId>` — chaque entrée de navigation (`view` + sa permission) ;
- `atelier/notes/data/<nom>` — chaque jeu de données (`view`, opérations, `import`, `export`) ;
- `atelier/notes/<resources[].path>` — ressources supplémentaires.

## Fichier de surcharges administratives

`var/config/modules.json` (protégé, hors `public/`, écrit atomiquement, jamais écrasé par une mise à jour du module) :

```json
{
  "groups": { "tools": { "label": "Outils", "order": 20 } },
  "modules": {
    "notes": { "status": "maintenance", "group": "tools", "order": 15, "navigation": { "new": { "order": 1 } } }
  }
}
```

Seules les clés `status`, `group`, `order` et `navigation.<id>.order` sont prises en compte.
