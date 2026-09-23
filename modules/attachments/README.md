# Module Fichiers joints (`attachments`)

Interface du mécanisme transversal de pièces jointes du noyau : téléversement, nom d'affichage, tags, dossiers virtuels, rattachement à une information, téléchargement sécurisé, corbeille, contrôle d'intégrité.

## Sécurité des fichiers

- **Contrôles à l'entrée** : type réel détecté (`finfo`), extensions interdites (exécutables, scripts, archives), taille maximale par fichier, quotas par utilisateur et global (`config/app.php`, section `attachments`).
- **Chiffrement au repos** : chaque fichier est chiffré en AES-256-GCM par blocs de 1 Mio (`Atelier\Shared\FileCrypto`), sous un nom interne imprévisible, hors du répertoire public (`var/attachments/AAAA/MM/<id>.enc`). La clé (32 octets) est générée au premier usage dans `var/config/attachments.key` (`paths.attachments_key`) ; elle est incluse dans les sauvegardes et restaurée avec elles. Toute altération, troncature ou réordonnancement d'un bloc est détecté au déchiffrement.
- **Téléchargement contrôlé** : uniquement par PHP (`/m/attachments/download/<id>` ou `/files/<id>` du noyau), après contrôle des droits, déchiffrement à la volée en flux (le clair n'est jamais écrit sur disque), en-têtes `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`. L'affichage dans le navigateur (`?inline=1`) n'est accordé qu'aux types sûrs (PDF, images, texte brut). Chaque téléchargement est journalisé et compté.
- **Intégrité** : empreinte SHA-256 du contenu conservée ; vérification depuis la fiche (« Vérifier l'intégrité ») ou en console (`attachments:verify`).
- **Migration** : `console attachments:encrypt` chiffre les fichiers encore stockés en clair (installations antérieures).

## Règles d'accès à un fichier

| Qui | Consulter / télécharger | Gérer (renommer, tags, dossier, rattacher, supprimer) |
|---|---|---|
| Auteur du fichier | oui | oui |
| Permission `assist` du module | oui (tous les fichiers) | oui |
| Lecteur du jeu partagé de l'information rattachée | oui | non |
| Autres | non | non |

Ces règles s'ajoutent aux permissions génériques du module vérifiées par le noyau sur chaque route : `open` (liste, fiche), `read` (téléchargement), `create` (téléversement), `update` (renommage, tags, dossiers, rattachement), `delete` (corbeille, restauration, suppression définitive).

## Nom d'affichage

Chaque fichier possède deux noms :

- le **nom du fichier d'origine** (`original_name`), assaini, conservé et toujours utilisé pour le téléchargement (son extension ne peut pas changer) ;
- un **nom d'affichage** facultatif (`label`, 200 caractères), libre, montré dans les listes, la fiche, la corbeille et le bandeau ; le nom d'origine reste visible en second ou en info-bulle.

Le nom d'affichage se saisit au téléversement (champ « Nom d'affichage ») et se modifie depuis la fiche (« Renommer », champ vide = nom du fichier affiché). **Plusieurs fichiers dans un même envoi** : le nom est suffixé d'un numéro d'ordre — « Rapport (1) », « Rapport (2) »… — que les fichiers partent en une requête (`files[]`) ou fichier par fichier depuis l'interface. La recherche de la liste porte sur les deux noms et sur la description.

## Tags

Chaque fichier est inscrit au registre commun sous le jeu `attachments.file` (clé = identifiant du fichier) dès son téléversement, ou au premier besoin pour les fichiers antérieurs. Il porte des **tags partagés** (les mêmes que les notes, points GPS, etc.) :

- saisie au téléversement (appliqués à tous les fichiers de l'envoi) et depuis la fiche (composant commun `data-tags-input`, 20 tags au plus, 60 caractères chacun) ;
- chaque puce de tag est cliquable et filtre la liste (`list?tag=<nom>`) ; le filtre « Tag » de la liste propose les tags réellement portés par des fichiers de la portée (mes fichiers ou tous) avec leur nombre ;
- la suppression définitive d'un fichier retire son entrée du registre, donc ses tags.

## Dossiers virtuels

Les dossiers (`$ctx->shared->folders`, table `attachment_folders` du noyau) forment une arborescence **en base uniquement** : ranger, renommer, déplacer ou supprimer un dossier ne déplace ni ne renomme **jamais** un fichier sur le disque (les fichiers restent stockés par date sous leur nom interne chiffré). Un fichier appartient à un dossier au plus ; « Non rangés » désigne les fichiers sans dossier (racine).

- **Colonne de gauche** de la liste : « Tous les fichiers », « Non rangés » puis l'arbre des dossiers avec le nombre de fichiers de la portée courante ; fil d'Ariane au-dessus des filtres. Routes : `list` (tout), `list?folder=root` (non rangés), `list?folder=<id>` (ce dossier seul, sans ses sous-dossiers).
- **Actions** (permission `update`, dossiers communs à tous les utilisateurs) : nouveau dossier à la racine (bouton « + » de la colonne) ou sous-dossier du dossier courant, renommer, déplacer le dossier sous un autre parent, supprimer — les fichiers et sous-dossiers du dossier supprimé **remontent dans le dossier parent** (ou à la racine), le message le précise ; rien n'est supprimé.
- **Ranger des fichiers** : cases à cocher dans la liste + « Déplacer vers » (sélection multiple, action `move` avec `ids[]`), ou « Déplacer » sur la fiche (`move` avec `id`). Au téléversement, le dossier de destination est choisi dans le formulaire (dossier courant présélectionné quand on arrive depuis un dossier : `upload?folder=<id>`).
- Limites du noyau : 8 niveaux de profondeur, noms de 120 caractères, unicité du chemin.

## Rattachement

Un fichier peut être rattaché à toute information inscrite au registre commun (`$this->ctx->shared->registry`) : point GPS du module `geo`, fiche d'un autre module… Depuis la fiche d'un point GPS, « Joindre un fichier » ouvre ce module avec la cible présélectionnée (`upload?point=<id>` ; `upload?info=<uuid>` pour toute autre information).

Le rattachement est facultatif : un **dépôt direct** (téléversement sans information) depuis « Téléverser » ou par glisser-déposer produit un fichier consultable par son auteur et l'assistance, rangé, nommé et taggé comme les autres ; il peut être rattaché **plus tard** depuis sa fiche (« Rattacher à une information »), ou détaché. Un fichier ne peut pas être rattaché à un autre fichier.

Un module peut aussi gérer ses pièces jointes directement avec le service du noyau, sans passer par ce module :

```php
$files = $this->ctx->shared->attachments;
$record = $files->store($request->file('file'), $infoId, $userId, $description, $label);   // chiffré, quotas et types contrôlés
$files->listFor($infoId);                                                                    // fichiers rattachés à une information
AttachmentService::displayName($record);                                                     // nom d'affichage, sinon nom d'origine
$files->download($id, inline: false);                                                        // Response en flux déchiffré (après contrôle ACL par l'appelant)
$files->verify($id);                                                                         // ['ok' => bool, 'message' => …]
$this->ctx->shared->folders->moveAttachment($id, $folderId);                                 // rangement dans un dossier virtuel
```

## Écrans

- **Mes fichiers** : dossiers à gauche, fil d'Ariane, recherche, filtres par type, tag et rattachement, tri, pagination, sélection multiple pour déplacer, quota personnel.
- **Tous les fichiers** (permission `assist`) : idem pour l'ensemble des utilisateurs, quota global.
- **Téléverser** : glisser-déposer multiple, nom d'affichage, dossier, tags, description communs à l'envoi, rattachement facultatif ; chaque fichier est envoyé séparément et les refus sont rapportés individuellement.
- **Fiche** : métadonnées (nom d'affichage et nom du fichier, dossier, tags, type, taille, empreinte, chiffrement, téléchargements), aperçu des images, ouverture des PDF dans un onglet, renommage, tags, déplacement, rattachement, vérification d'intégrité, corbeille.
- **Corbeille** : suppression logique (noyau), restauration ou suppression définitive ; purge automatique après `trash.retention_days` jours (`maintenance:purge`). Les fichiers apparaissent aussi dans la corbeille globale (module `trash`).

## Jeu de données

`attachments.file` est **partagé** en lecture (`operations: read`, `openRoute: show/{key}`) : les modules transversaux (tags, explorateur, corbeille) peuvent ouvrir un fichier dans sa fiche. Les autres modules n'accèdent au contenu que par le téléchargement contrôlé du noyau.
