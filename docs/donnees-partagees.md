# Données partagées, catalogue, tags, relations et pièces jointes

## 1. Partagé ou privé au module

Chaque jeu de données déclaré dans un manifeste porte obligatoirement `visibility: "shared"` ou `"private"`.

| | Partagé | Privé au module |
|---|---|---|
| Catalogue commun | oui | non |
| Lecture par un autre module | via le **service** du module propriétaire | impossible |
| Sélecteurs de relations/tags proposés aux autres modules | oui | non |
| ACL utilisateur | ressource `atelier/<module>/data/<nom>` | idem (le module propriétaire l’applique) |
| Migrations versionnées et sauvegardes | oui | oui |

« Privé » signifie privé **entre modules** : à l’intérieur du module propriétaire, les ACL des utilisateurs continuent de s’appliquer.

## 2. Catalogue (`$ctx->shared->catalog`)

Synchronisé depuis les manifestes (`ModuleSynchronizer::syncDatasets`). Pour chaque jeu : code, nom, description, module propriétaire, tables, champs, opérations autorisées, version de structure, producteurs et consommateurs connus (`consumes` des manifestes), ressource ACL.

- `shared()` : jeux partagés présents ;
- `findShared($code)` : `null` pour un jeu privé ou absent — un jeu privé n’est **jamais** exposé ;
- `canAccess($userId, $code, $operation)` : jeu partagé, opération déclarée et droit ACL de l’utilisateur ;
- `readableCodes($userId)` : codes lisibles, pour alimenter les sélecteurs ;
- `canReadData($userId, $code)` : droit `read` sur le jeu d’une information, **partagé ou privé** — utilisé par le noyau pour les pièces jointes, dont l’affichage et le téléchargement suivent les droits de la fiche ;
- `orphaned($moduleStates)` : jeux dont le producteur est désactivé ou absent, avec leurs consommateurs.

**Refus d’ouverture d’un module.** Un refus explicite de la permission `open` sur `atelier/<module>` ferme aussi la lecture transversale de ses jeux partagés : l’Explorateur, la recherche et les sélecteurs n’en montrent plus rien. C’est le geste attendu pour exclure quelqu’un d’un module. À l’inverse, l’**absence** de règle sur le module ne bloque pas : un droit `read` posé sur le seul jeu de données (`atelier/<module>/data/<nom>`) reste une délégation volontaire, qui donne accès aux données sans donner accès aux écrans du module.

## 3. Accès intermodule

Un module consommateur n’interroge jamais les tables d’un autre module. Il passe par le service exposé :

```php
$users = $this->ctx->moduleService('users');       // ModuleUnavailableException si absent/inactif
$accounts = $users->listActive($this->ctx->userId()); // le service vérifie catalog->canAccess(...)
```

Le service propriétaire valide les écritures et applique ses règles métier ; c’est lui qui garantit la cohérence des données partagées.

## 4. Identifiants globaux (`registry`)

`register(datasetCode, localKey, label, userId)` retourne un UUID stable pour une information d’un module. C’est cet identifiant qu’utilisent tags, relations et pièces jointes, quel que soit le module d’origine.

## 5. Tags (`tags`)

- Portée `shared` (tags communs) ou `<moduleId>` (tags internes au module, ni visibles ni fusionnables depuis le catalogue).
- Normalisation : minuscules, `#` retiré, espaces réduits ; unicité par portée insensible à la casse.
- Création libre par tout utilisateur autorisé à modifier la donnée ; renommage, fusion et suppression réservés à la permission `tags.manage` (à vérifier par le module d’administration appelant).
- API : `attach`, `detach`, `replace`, `tagsOf`, `infosWithTag`, `search`, `all`, `rename`, `merge`, `delete`.

## 6. Relations (`relations`)

Relations typées entre deux informations du registre (`related`, `parent`, `child`, `references`, `duplicates`, `depends_on` ou tout autre slug). `relationsOf($infoId)` retourne les relations entrantes et sortantes avec le libellé, le jeu de données et le module de l’autre information.

## 7. Pièces jointes (`attachments`)

- Stockage sur disque dans `var/attachments/AAAA/MM/<id>.bin` (nom interne imprévisible), métadonnées en base : nom d’origine, type MIME détecté par `finfo`, taille, SHA-256, auteur, dates, information rattachée.
- Limites : 20 Mo par fichier, 500 Mo par utilisateur, 5 Go au total ; types autorisés (PDF, JPEG/PNG/WebP, texte/CSV, bureautique) ; exécutables, scripts et archives refusés.
- Téléchargement toujours par PHP : `/files/<id>` (et `?inline=1` pour les PDF et images) après contrôle par le noyau du droit `read` sur le jeu de données de l’information rattachée ; un fichier orphelin n’est accessible qu’à son auteur. Un module peut aussi exposer sa propre route `raw` pour un contrôle plus fin.
- Suppression logique puis purge après 30 jours (`maintenance:purge`).
- **Nom d’affichage** (`label`) libre, distinct du nom de fichier d’origine conservé pour le téléchargement : `AttachmentService::displayName($attachment)`, `setLabel()`, paramètre `$label` de `store()`.
- **Dossiers virtuels** (`$ctx->shared->folders`, table `attachment_folders`) : arborescence en base avec chemin matérialisé, création/renommage/déplacement/suppression (les fichiers remontent dans le parent), `moveAttachment()`, filtres `folder_id` (`'root'` = non rangés) et `folder_ids` (sous-arbre) de `paginate()`. Les fichiers ne bougent jamais sur le disque.
- **Tags sur les fichiers** : un fichier peut être inscrit au registre sous le jeu `attachments.file` (clé = identifiant du fichier) et recevoir des tags partagés ; filtre `tag` de `paginate()`.

## 8. Bonnes pratiques

- Enregistrer l’information dans le registre dès sa création si elle doit pouvoir être reliée.
- Ne jamais stocker de contenu privé (texte d’une note, mot de passe) dans les libellés du registre ni dans les détails du journal.
- Documenter les champs (`fields`) du manifeste : ils sont affichés dans le catalogue et servent aux modules consommateurs.
- Incrémenter `version` du jeu de données à chaque changement de structure et fournir la migration correspondante.
