# Guide du développeur de module

Ce guide s’adresse à un développeur extérieur qui veut ajouter un module à Atelier sans toucher au noyau. Il complète le contrat technique (`contrat-module.md`), le schéma du manifeste (`manifest-schema.md`) et la documentation des données partagées (`donnees-partagees.md`). Le module `demo` est la référence vivante : chacun de ses écrans montre un composant ou un comportement du noyau, et son README détaille sa structure.

## 1. Prérequis

- PHP 8.4 en ligne de commande (sous Windows, `_tools/php84/php.exe` si présent, sinon `php`).
- Une copie du projet qui démarre : `start.bat` / `./start.sh`, connexion avec un compte administrateur.
- Lecture de `contrat-module.md` (10 minutes) : natures de routes, `ModuleView`, `ActionResult`, HTML déclaratif, permissions.

## 2. Générer le squelette

```bash
console.bat module:create inventaire --name="Inventaire" --group=tools --icon=grid
```

Options : `--description=…`, `--no-table` (module sans base de données, ex. outil de calcul), `--shared` (jeu de données publié dans le catalogue commun).

Le générateur crée :

```
modules/inventaire/
├── manifest.json                 identité, navigation, jeu de données inventaire.item
├── src/InventaireModule.php      routes index / new / edit / save / delete
├── src/ItemRepository.php        tout le SQL du module
├── migrations/001_create_inventaire.php
├── templates/index.php           liste (composants communs, état vide)
├── templates/edit.php            formulaire data-action="save" avec éditeur BBCode
├── assets/inventaire.css         styles préfixés .module-inventaire
└── README.md
```

La commande applique la migration et synchronise les droits. Le module apparaît aussitôt dans la colonne de gauche (verrouillé tant qu’aucun droit n’est attribué).

## 3. Attribuer les droits

Utilisateurs et droits → Droits d’accès → ressource `atelier/inventaire` : ajouter `open` (et `create`, `update`, `delete` selon les besoins) au groupe visé. Un administrateur racine a déjà tous les droits.

## 4. Développer

### Cycle habituel

1. Modifier le manifeste (entrées de navigation, permissions propres, jeux de données) → recharger l’interface : la colonne et les ressources ACL sont resynchronisées automatiquement quand le manifeste change.
2. Ajouter une route dans `routes()` puis sa méthode : `view` retourne une `ModuleView` (bandeau + contenu), `action` retourne un `ActionResult`, `raw` retourne une `Response` (téléchargement).
3. Écrire le gabarit dans `templates/` : envelopper dans `<div class="module module-inventaire">`, échapper toute valeur avec `$e()`, utiliser les composants communs et l’HTML déclaratif (`data-route`, `data-action`, `form[data-action]`).
4. Vérifier : lint (`php -l`), test HTTP avec curl (voir §6), navigateur.

### Ce que le noyau fait pour vous

- Authentification, session, CSRF, contrôle du droit de la route, journalisation des vues et actions (catégorie debug), erreurs normalisées (`ValidationException` → erreurs près des champs, `NotFoundException`, `ForbiddenException`, `ConflictException`).
- Onglet, bandeau, barre d’état (`->status('12 éléments')`), toaster (`ActionResult::ok(null, 'Enregistré.')`), dialogues de confirmation (`data-confirm`), formulaires (`data-track-dirty`, Ctrl+S avec `data-save-shortcut`), pagination (`renderCore('pagination')`), tri (`renderCore('sort_header')`), états vides (`renderCore('state')`).
- Éditeur BBCode (`data-editor="bbcode"`) et saisie de tags (`data-tags-input`).
- Journal : `$this->log('inventaire.create', 'success', 'item:12', 'Élément créé')` ; débogage : `$this->debug('Filtre appliqué', [...])`.

### Ce que vous devez faire vous-même

- Vérifier les droits fins dans les gestionnaires (`$this->require('delete')`, `$this->can(...)`) : masquer un bouton ne suffit jamais.
- Valider toute entrée côté serveur (`$request->string()`, `int()`, longueurs, formats) même si le navigateur l’a déjà contrôlée.
- Garder le SQL dans les dépôts (`src/*Repository.php`), avec les méthodes de dialecte de `Database` dans les migrations (`primaryKey()`, `varchar()`, `text()`, `datetime()`, `tableOptions()`).
- Préfixer tables (`inventaire_*`) et classes CSS (`.module-inventaire …`).

### Données partagées

Pour exposer un jeu de données aux autres modules : `visibility: "shared"` dans le manifeste et une classe de service retournée par `service()`, qui vérifie `$this->ctx->shared->catalog->canAccess($viewerId, 'inventaire.item', 'read')` avant de répondre. Pour consommer : `$this->ctx->moduleService('users')->listActive($this->ctx->userId())` et déclarer `consumes: ["users.account"]`. Voir `donnees-partagees.md`.

### JavaScript optionnel

Déclarer `assets/inventaire.js` dans le manifeste et enregistrer les hooks :

```js
Atelier.modules.register('inventaire', {
  render(ctx, view) { ctx.on(ctx.root, 'click', '[data-hello]', () => ctx.toast.info('Bonjour')); },
});
```

Les écouteurs (`ctx.on`) et minuteries (`ctx.interval`, suspendues quand l’onglet est masqué) sont libérés automatiquement à la fermeture de l’onglet.

## 5. Versionner et livrer

- `version` du manifeste : `1.x.0` pour une fonctionnalité, `1.0.y` pour un correctif, majeure si le contrat de données change. Documenter dans le README du module.
- Une évolution de structure = une nouvelle migration `NNN_*.php` (jamais modifier une migration appliquée) et `version` du jeu de données incrémentée.
- Livraison = le répertoire `modules/<id>/`. Installation chez le destinataire : copie du répertoire, `console db:migrate`, activation dans Gestion des modules, droits. Aucun fichier du noyau ne doit être modifié ; si le noyau manque d’une fonction, la demander plutôt que la contourner.

## 6. Vérifier avec curl

```bash
rm -f /tmp/cj.txt
TOKEN=$(curl -s -c /tmp/cj.txt http://127.0.0.1:8000/login | grep -oP 'name="_token" value="\K[^"]+')
curl -s -o /dev/null -b /tmp/cj.txt -c /tmp/cj.txt -X POST http://127.0.0.1:8000/login -d "_token=$TOKEN&username=admin&password=<mot de passe>"
CSRF=$(curl -s -b /tmp/cj.txt http://127.0.0.1:8000/ | grep -oP 'name="csrf-token" content="\K[^"]+')

# vue : enveloppe {"ok":true,"data":{"title","banner","content","status",…}}
curl -s -b /tmp/cj.txt -H "X-Atelier-Request: json" http://127.0.0.1:8000/m/inventaire/index

# action : POST JSON avec le jeton
curl -s -b /tmp/cj.txt -H "X-Atelier-Request: json" -H "X-CSRF-Token: $CSRF" -H "Content-Type: application/json" \
     -X POST http://127.0.0.1:8000/m/inventaire/save -d '{"title":"Perceuse","content":"[b]Atelier 2[/b]"}'
```

Une erreur de validation renvoie `{"ok":false,"error":{"type":"validation","fields":{...}}}` avec le code HTTP 422 ; un refus de droit `403`, une route inconnue `404`.

## 7. Check-list avant livraison

- [ ] `manifest.json` valide (`console modules:list` sans ERREURS), version renseignée.
- [ ] Chaque route déclare sa permission ; les actions sensibles la revérifient.
- [ ] Aucun SQL hors des dépôts ; migrations compatibles SQLite et MariaDB.
- [ ] Toutes les sorties HTML échappées ; styles préfixés ; composants communs utilisés.
- [ ] Actions journalisées (`$this->log`), sans mot de passe ni contenu privé dans les détails.
- [ ] README à jour ; testé en tant qu’utilisateur non administrateur.
