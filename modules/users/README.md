# Module « Utilisateurs et droits » (`users`)

Administration des comptes utilisateurs, des groupes et des droits d’accès (ACL) d’Atelier. Le module s’appuie exclusivement sur les tables du noyau (`users`, `groups`, `user_groups`, `resources`, `permissions`, `acl_rules`) : il ne déclare aucune migration.

## Navigation

| Entrée | Route | Permission | Contenu |
|---|---|---|---|
| Consulter la liste des utilisateurs | `list` | `open` | Tableau paginé, filtres, actions rapides |
| Ajouter un utilisateur | `new` | `create` | Formulaire de création |
| Groupes | `groups` | `open` | Liste des groupes, fiche d’un groupe, membres, duplication |
| Droits d’accès (ACL) | `acl` | `admin` | Arborescence des ressources protégées et règles |

Les permissions génériques s’appliquent sur la ressource `atelier/users` : `open` (voir le module), `create`, `update`, `delete`, `export`, `admin` (règles ACL). Les droits sont vérifiés côté serveur pour chaque route **et** dans chaque gestionnaire ; masquer un bouton n’est jamais suffisant.

## 1. Liste des utilisateurs

- Pagination serveur : 25 lignes par défaut, 50 ou 100 au choix (jamais plus de 100).
- Tri par identifiant, nom affiché, état et dernière connexion (en-têtes cliquables). Les comptes jamais connectés sont placés en fin de liste.
- Filtres cumulables, appliqués automatiquement au changement : recherche (identifiant, nom, courriel), état, groupe. Ils se retrouvent dans l’URL (`list?search=…&status=…&group=…&page=…&sort=…&dir=…`) et sont conservés par la pagination et le tri.
- États affichés par badges : **Actif**, **Désactivé**, **Bloqué temporairement** (échecs de connexion répétés), **Mot de passe temporaire** (changement obligatoire à la prochaine connexion). Le filtre d’état propose ces quatre situations.
- Actions rapides selon les droits : modifier, réinitialiser le mot de passe, déverrouiller (si bloqué ou après des échecs), désactiver / réactiver, supprimer définitivement (confirmation renforcée).
- Bandeau : Actualiser, Exporter CSV (permission `export`, respecte les filtres courants, séparateur « ; », UTF-8 avec BOM, sans mot de passe), Ajouter.

## 2. Fiche d’un compte (création / édition)

Champs : identifiant (unique, `^[a-zA-Z0-9._-]{2,64}$`), nom affiché, courriel facultatif (validé), état (actif / désactivé), groupes (cases à cocher).

À la création, un mot de passe temporaire lisible est proposé (bouton **Générer** pour en produire un autre, bouton de copie). Il peut être remplacé par un mot de passe saisi (au moins 12 caractères, ne contenant pas l’identifiant). S’il est laissé vide, le serveur en génère un et l’affiche **une seule fois** dans une boîte de résultat après l’enregistrement. La case « Obliger le changement de mot de passe à la première connexion » est cochée par défaut.

Le formulaire suit les modifications (l’onglet est marqué « modifié », confirmation avant de quitter) et se soumet par Ctrl+S. Les erreurs de validation sont affichées près des champs.

La fiche d’un compte existant affiche ses informations (dates de création, modification, dernière connexion, changement de mot de passe, échecs, blocage) et propose dans le bandeau : réinitialiser le mot de passe, déverrouiller, désactiver / réactiver, supprimer.

### Onglet « Droits » (permission `admin`)

- **Règles directes** de l’utilisateur (ressource, permission, effet, commentaire), avec ajout et suppression.
- **Droits effectifs** : après choix d’une ressource, chaque permission pertinente est affichée avec la décision calculée (autorisé / refusé) et son explication — la règle déterminante (directe, de groupe, générale, héritée d’un niveau supérieur) ou le refus par défaut. C’est la réponse à « pourquoi cet accès est-il autorisé ou refusé ? ».

## 3. Groupes

- Liste : nom technique, libellé, description, nombre de membres (lien vers la liste filtrée), type (système / personnalisé).
- Création (nom technique unique, non modifiable ensuite : minuscules, chiffres, tiret, souligné), modification du libellé et de la description, suppression (les groupes système `admins` et `users` ne sont pas supprimables). La suppression d’un groupe retire ses appartenances et ses règles ACL.
- Membres : ajout par sélecteur, retrait individuel.
- Règles ACL du groupe (lecture, suppression, lien vers l’écran ACL).
- **Duplication d’un profil** : crée un nouveau groupe avec la copie de toutes les règles ACL du groupe source, et en option la copie de ses membres. Depuis la liste, l’icône « Dupliquer » demande simplement le nouveau nom technique (règles copiées, membres non copiés).

## 4. Droits d’accès (ACL)

Écran en deux colonnes :

- **À gauche**, l’arborescence des ressources protégées présentes (application, modules, écrans, jeux de données, actions), repliable ; un clic sur une ressource la sélectionne (`acl?resource=…`).
- **À droite**, pour la ressource sélectionnée :
  - ses caractéristiques et ses permissions pertinentes ;
  - les **règles explicites** définies sur elle ;
  - les **règles héritées** des niveaux supérieurs, distinguées (badge « héritée » et ressource d’origine) ;
  - un formulaire d’ajout de règle : sujet (tous les connectés / groupe / utilisateur), permission, effet (autoriser / refuser), commentaire ; une règle existante pour le même sujet et la même permission est remplacée ;
  - la suppression d’une règle ;
  - **Tester le droit effectif** : utilisateur + permission → décision, explication et liste des règles candidates par ordre de priorité, la règle déterminante étant mise en évidence.

Rappel de l’ordre de résolution du noyau : la règle la plus précise (chemin le plus long) l’emporte ; à précision égale, utilisateur > groupe > tous ; entre groupes, un refus l’emporte ; sans règle, refus. La permission `admin` implique toutes les autres sur la ressource et ses descendants.

### Garde-fou « dernier administrateur »

Toute opération qui laisserait l’application sans administrateur racine actif est refusée avec un message explicite (erreur de type conflit) et annulée : ajout d’un refus, suppression d’une autorisation, retrait d’un membre du groupe d’administration, changement de groupes, désactivation ou suppression d’un compte, suppression d’un groupe. Il est par ailleurs interdit de désactiver ou de supprimer son propre compte.

## Journal d’activité

Chaque modification est journalisée dans le journal d’activité du noyau (module `users`), sans jamais y inscrire de mot de passe : `user.create`, `user.update`, `user.password_reset`, `user.disable`, `user.enable`, `user.unlock`, `user.delete`, `user.export`, `group.create`, `group.update`, `group.delete`, `group.member_add`, `group.member_remove`, `group.duplicate`, `acl.rule_add`, `acl.rule_remove`.

## Jeu de données partagé `users.account`

Le module publie le jeu **partagé** `users.account` (tables `users`, `user_groups` ; champs `id`, `username`, `display_name`, `status` ; opération `read`). Les autres modules le consultent via le service du module :

```php
$users = $this->ctx->moduleService('users');
$users->listActive($viewerUserId);   // comptes actifs : id, username, display_name, status
$users->find($viewerUserId, $id);    // un compte ou null
$users->displayName($id);            // nom affiché (demandeur = utilisateur connecté)
```

Chaque méthode vérifie d’abord que le demandeur dispose de `read` sur `atelier/users/data/account` (`catalog->canAccess($viewer, 'users.account', 'read')`) et ne renvoie jamais le mot de passe ni le courriel.

## Fichiers

```
modules/users/
├── manifest.json
├── README.md
├── src/
│   ├── UsersModule.php       routes et gestionnaires (aucun SQL)
│   ├── UsersService.php      service intermodule du jeu users.account
│   ├── AccountManager.php    règles métier des comptes
│   ├── GroupManager.php      règles métier des groupes (dont duplication)
│   ├── AclAdmin.php          règles ACL, test de droit effectif
│   ├── RootAdminGuard.php    protection du dernier administrateur
│   ├── AccountRepository.php lecture : liste paginée, export, sélections
│   ├── ResourceRepository.php lecture : ressources protégées, arborescence, libellés
│   └── UserPresenter.php     badges et libellés d’affichage
├── templates/                list, form, user_rights, groups, group_form, acl
└── assets/                   users.css (préfixe .module-users), users.js
```

Le JavaScript du module se limite au pliage de l’arbre ACL, à la génération et à la copie d’un mot de passe et à l’affichage unique du mot de passe temporaire renvoyé par le serveur ; tout le reste repose sur le HTML déclaratif du noyau.
