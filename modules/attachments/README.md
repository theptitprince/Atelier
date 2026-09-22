# Module Fichiers joints (`attachments`)

Interface du mécanisme transversal de pièces jointes du noyau : téléversement, rattachement à une information, téléchargement sécurisé, corbeille, contrôle d'intégrité.

## Sécurité des fichiers

- **Contrôles à l'entrée** : type réel détecté (`finfo`), extensions interdites (exécutables, scripts, archives), taille maximale par fichier, quotas par utilisateur et global (`config/app.php`, section `attachments`).
- **Chiffrement au repos** : chaque fichier est chiffré en AES-256-GCM par blocs de 1 Mio (`Atelier\Shared\FileCrypto`), sous un nom interne imprévisible, hors du répertoire public (`var/attachments/AAAA/MM/<id>.enc`). La clé (32 octets) est générée au premier usage dans `var/config/attachments.key` (`paths.attachments_key`) ; elle est incluse dans les sauvegardes et restaurée avec elles. Toute altération, troncature ou réordonnancement d'un bloc est détecté au déchiffrement.
- **Téléchargement contrôlé** : uniquement par PHP (`/m/attachments/download/<id>` ou `/files/<id>` du noyau), après contrôle des droits, déchiffrement à la volée en flux (le clair n'est jamais écrit sur disque), en-têtes `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`. L'affichage dans le navigateur (`?inline=1`) n'est accordé qu'aux types sûrs (PDF, images, texte brut). Chaque téléchargement est journalisé et compté.
- **Intégrité** : empreinte SHA-256 du contenu conservée ; vérification depuis la fiche (« Vérifier l'intégrité ») ou en console (`attachments:verify`).
- **Migration** : `console attachments:encrypt` chiffre les fichiers encore stockés en clair (installations antérieures).

## Règles d'accès à un fichier

| Qui | Consulter / télécharger | Gérer (renommer, rattacher, supprimer) |
|---|---|---|
| Auteur du fichier | oui | oui |
| Permission `assist` du module | oui (tous les fichiers) | oui |
| Lecteur du jeu partagé de l'information rattachée | oui | non |
| Autres | non | non |

Ces règles s'ajoutent aux permissions génériques du module vérifiées par le noyau sur chaque route : `open` (liste, fiche), `read` (téléchargement), `create` (téléversement), `update` (renommage, rattachement), `delete` (corbeille, restauration, suppression définitive).

## Rattachement

Un fichier peut être rattaché à toute information inscrite au registre commun (`$this->ctx->shared->registry`) : point GPS du module `geo`, fiche d'un autre module… Depuis la fiche d'un point GPS, « Joindre un fichier » ouvre ce module avec la cible présélectionnée (`upload?point=<id>` ; `upload?info=<uuid>` pour toute autre information).

Un module peut aussi gérer ses pièces jointes directement avec le service du noyau, sans passer par ce module :

```php
$files = $this->ctx->shared->attachments;
$record = $files->store($request->file('file'), $infoId, $userId, $description);   // chiffré, quotas et types contrôlés
$files->listFor($infoId);                                                            // fichiers rattachés à une information
$files->download($id, inline: false);                                                // Response en flux déchiffré (après contrôle ACL par l'appelant)
$files->verify($id);                                                                 // ['ok' => bool, 'message' => …]
```

## Écrans

- **Mes fichiers** : recherche, filtres par type et rattachement, tri, pagination, quota personnel.
- **Tous les fichiers** (permission `assist`) : idem pour l'ensemble des utilisateurs, quota global.
- **Téléverser** : glisser-déposer multiple, description commune, rattachement facultatif ; chaque fichier est envoyé séparément et les refus sont rapportés individuellement.
- **Fiche** : métadonnées (type, taille, empreinte, chiffrement, téléchargements), aperçu des images, ouverture des PDF dans un onglet, rattachement, renommage, vérification d'intégrité, corbeille.
- **Corbeille** : restauration ou suppression définitive ; purge automatique après `trash.retention_days` jours (`maintenance:purge`).
