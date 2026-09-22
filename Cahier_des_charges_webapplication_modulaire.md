# Cahier des charges — Atelier, webapplication modulaire

**Version :** 0.8 — Document de travail consolidé  
**Date :** 22 septembre 2026  
**Statut :** choix fonctionnels et techniques de la première version arrêtés avant développement

## 1. Objet du projet

Le projet et la webapplication portent le nom définitif **Atelier**, avec `atelier` comme identifiant technique. Atelier est une webapplication sécurisée et modulaire, accessible uniquement après authentification. Elle fournit un environnement commun dans lequel différents modules métier peuvent être ajoutés, activés, désactivés et chargés sans remettre en cause le noyau de l’application.

Un module peut avoir l’une ou plusieurs des finalités suivantes :

- stocker ou compléter des informations ;
- interpréter, recouper ou transformer des informations existantes ;
- présenter des informations triées, filtrées ou synthétisées ;
- réaliser une tâche autonome, un calcul ou un outil de travail sans utiliser nécessairement la base de données.

Les modules sont indépendants dans leur fonctionnement et leur interface. Les données qu’ils enregistrent appartiennent explicitement à l’une des deux catégories suivantes :

- **données partagées** : publiées dans le catalogue commun afin que d’autres modules autorisés puissent les consulter, les interpréter ou les enrichir ;
- **données privées au module** : réservées au fonctionnement du module propriétaire et inaccessibles aux autres modules.

Un module choisit la catégorie de chaque jeu de données qu’il possède. Une donnée privée n’est pas « privée à l’utilisateur » : elle est privée entre modules et reste soumise aux ACL des utilisateurs à l’intérieur du module propriétaire.

L’expression « données publiques pour les modules » employée dans les versions précédentes est remplacée par **données partagées**, afin d’éviter toute confusion avec un accès public sans connexion. Les données partagées restent soumises aux droits de l’utilisateur.

L’ensemble peut être décrit comme un **wiki interactif modulaire** : les informations ne sont pas seulement consultées ou modifiées comme des pages, elles peuvent également être reliées, étiquetées, complétées par des fichiers, calculées, transformées et exploitées par différents outils. Cette expression décrit la philosophie générale de l’application et n’impose pas que toutes les données soient enregistrées sous forme de pages wiki.

L’interface principale reste identique quel que soit le module utilisé. Seuls le contenu central, le bandeau supérieur entièrement fourni par le module et certaines informations de la barre d’état varient selon l’onglet actif.

## 2. Objectifs

- Centraliser plusieurs outils métier dans une seule application.
- Réserver l’intégralité de l’application aux utilisateurs authentifiés.
- Permettre l’ajout progressif de modules indépendants.
- Permettre l’exploitation croisée des données produites par différents modules.
- Permettre à chaque module de conserver séparément les données qui ne doivent pas être exposées aux autres modules.
- Offrir une interface homogène, rapide et simple à utiliser.
- Gérer les droits d’accès par utilisateur, groupe, ressource et action.
- Limiter les rechargements complets de page grâce au chargement dynamique.
- Assurer la sécurité, la traçabilité et la maintenabilité de l’ensemble.

## 3. Périmètre fonctionnel du noyau

Le noyau de l’application devra au minimum prendre en charge :

1. l’authentification et la déconnexion ;
2. la gestion des sessions ;
3. la gestion des utilisateurs, groupes, ressources et ACL ;
4. l’affichage de l’interface générale ;
5. la détection et la liste des modules disponibles ;
6. le contrôle d’accès aux modules et à leurs actions ;
7. le chargement dynamique du module sélectionné ;
8. l’accueil et le cycle de vie des bandeaux supérieurs fournis par les modules ;
9. la gestion des messages et informations de la barre d’état ;
10. la journalisation des événements importants ;
11. la gestion des paramètres généraux et des préférences utilisateur ;
12. un traitement homogène des erreurs.

Les fonctionnalités métier propres à chaque futur module feront l’objet de spécifications complémentaires.

## 4. Utilisateurs et droits d’accès

### 4.1 Authentification

Toutes les pages, données et fonctions de l’application sont privées, à l’exception des ressources strictement nécessaires à la page de connexion. La réinitialisation d’un mot de passe est réalisée par un administrateur déjà authentifié.

Un utilisateur non connecté qui tente d’accéder directement à une page, à un module ou à une requête dynamique est redirigé vers la page de connexion ou reçoit une réponse d’accès refusé adaptée au type de requête.

L’authentification reposera uniquement sur un identifiant et un mot de passe gérés localement par l’application. Aucun fournisseur d’identité externe, SSO, OAuth, SAML ou annuaire d’entreprise n’est prévu.

Les comptes seront créés par un administrateur ; aucune inscription publique ne sera proposée. Un nouveau compte recevra un mot de passe temporaire qu’il devra modifier lors de sa première connexion. En première version, la récupération d’un mot de passe sera réalisée par un administrateur, sans envoi de courriel.

### 4.2 Principe général des profils et ACL

Les profils ne seront pas limités à une liste de rôles inscrite en dur. La gestion des droits reposera sur un système d’ACL entièrement paramétrable, inspiré du fonctionnement de DokuWiki : attribution de règles à des utilisateurs ou à des groupes, ressources organisées hiérarchiquement, héritage des règles et possibilité de définir des exceptions plus précises.

Le système devra permettre :

- la création libre de groupes ou profils ;
- l’appartenance d’un utilisateur à plusieurs groupes ;
- l’attribution directe d’une règle à un utilisateur ;
- l’attribution d’une règle à un groupe ;
- une règle générale applicable à tous les utilisateurs connectés ;
- l’héritage d’un droit depuis l’application vers un module, un sous-module, un jeu de données ou une action ;
- la surcharge d’une règle générale par une règle plus précise ;
- la visualisation du droit effectif obtenu après calcul de l’héritage ;
- la duplication d’un profil existant ;
- la désactivation d’un compte sans suppression de son historique.

Un compte d’administration initial devra exister pour configurer les premières règles. Les droits d’administration ordinaires seront ensuite gérés par le même mécanisme d’ACL. Les fonctions permettant de retirer le dernier accès d’administration devront comporter une protection particulière afin d’éviter de verrouiller l’application.

### 4.3 Ressources et permissions

L’arborescence des ressources protégées pourra suivre le modèle suivant :

1. application entière ;
2. groupe de modules ;
3. module ;
4. sous-module ou écran ;
5. jeu de données ;
6. action particulière.

Chaque module déclare les permissions dont il a besoin. Le socle fournira au minimum les permissions génériques suivantes :

- voir la ressource dans l’interface ;
- ouvrir ou utiliser ;
- consulter les données ;
- créer ;
- modifier ;
- supprimer ;
- importer ;
- exporter ou imprimer ;
- administrer ;
- exécuter une action particulière déclarée par le module.

L’entrée principale d’un module installé restera visible dans la colonne de gauche, même sans droit d’ouverture. La permission de visibilité s’appliquera notamment aux sous-modules, écrans, accès directs, jeux de données et actions qui ne doivent pas être proposés à l’utilisateur.

L’interface d’administration devra présenter clairement les règles héritées, les règles explicites et le droit final. La résolution des ACL suivra l’ordre déterministe suivant :

1. la règle définie sur la ressource la plus précise prévaut sur une règle héritée d’un niveau supérieur ;
2. à précision égale, une règle attribuée directement à l’utilisateur prévaut sur les règles de ses groupes ;
3. entre plusieurs règles de groupes de même précision, un refus prévaut sur une autorisation ;
4. en l’absence de règle applicable, l’accès est refusé.

Il n’existera pas de compte caché permanent. Une commande locale, exécutable uniquement sur le serveur, permettra de rétablir temporairement l’accès du compte administrateur initial après confirmation explicite. Son utilisation sera documentée et journalisée.

Le masquage d’un bouton dans l’interface ne constitue jamais un contrôle de sécurité suffisant : chaque autorisation doit également être vérifiée côté serveur.

## 5. Interface générale après connexion

### 5.1 Organisation de l’écran

| Zone | Position | Contenu |
|---|---|---|
| Bandeau du module | En haut | Bandeau entièrement fourni par le module de l’onglet actif |
| Onglets | Sous le bandeau | Modules ouverts, sélection de l’onglet actif et fermeture |
| Menu des modules | Colonne de gauche | Arborescence dynamique construite depuis les manifestes de tous les modules installés |
| Zone de travail | Centre et partie principale | Contenu du module chargé dynamiquement |
| Barre d’état | En bas | État général, messages, progression et informations contextuelles du module |

### 5.2 Bandeau supérieur

Le bandeau supérieur de la zone de travail est fourni entièrement par le module affiché dans l’onglet actif. Le noyau réserve son emplacement, assure son insertion et sa suppression, mais n’impose ni son titre, ni ses boutons, ni son organisation interne.

Le bandeau fourni par un module peut contenir :

- le titre et éventuellement l’icône du module actif ;
- les actions principales du module : ajouter, enregistrer, modifier, supprimer, rechercher, filtrer, imprimer, exporter, actualiser, etc. ;
- des actions regroupées dans un menu secondaire si leur nombre est important ;
- un indicateur d’action en cours ;
- tout autre composant métier nécessaire à l’écran courant.

Le module construit et actualise son bandeau en utilisant prioritairement les composants du CSS commun. Il reste responsable du respect des permissions dans le contenu qu’il propose, tandis que le serveur contrôle à nouveau chaque action exécutée. Une action indisponible temporairement peut être désactivée avec une explication visible.

Les commandes globales de l’application — profil, préférences et déconnexion — sont placées hors de ce bandeau, dans la zone fixe de la colonne de gauche ou de la barre d’état. Elles restent donc disponibles quel que soit le module actif.

### 5.3 Colonne des modules

La colonne de gauche constitue un menu arborescent dépliable, comparable à un navigateur de fichiers, construit depuis les fichiers de déclaration installés avec les modules. Le premier niveau affiche les modules ; les niveaux enfants affichent leurs pages ou accès directs. Elle ne dépendra pas de la base de données pour connaître cette arborescence et ne contiendra aucune liste centralisée inscrite en dur dans le noyau.

À chaque chargement complet de l’interface, le noyau parcourra le répertoire des modules, lira leurs manifestes valides et reconstruira la colonne. Tout module correctement installé devra donc y apparaître automatiquement au prochain rechargement, sans modification manuelle du menu et même si le stockage applicatif est temporairement indisponible.

Si le stockage applicatif est indisponible, les utilisateurs et ACL ne pourront pas être vérifiés. Le noyau devra alors appliquer un refus par défaut, ne charger aucun contenu protégé et présenter un état de maintenance. La découverte des modules depuis les fichiers ne constitue pas un contournement de l’authentification.

La colonne devra :

- référencer tous les modules installés ;
- afficher leur nom, leur icône et leur état ;
- mettre clairement en évidence le module sélectionné ;
- permettre leur classement par groupes et selon un ordre défini ;
- présenter un module actif et autorisé comme accessible ;
- présenter un module non autorisé avec un état verrouillé, sans permettre son ouverture ;
- signaler distinctement un module inactif, indisponible ou en maintenance ;
- pouvoir être repliée pour augmenter la surface de travail ;
- conserver les branches ouvertes pendant la session du navigateur ;
- rester utilisable au clavier et indiquer correctement l’état ouvert ou fermé aux technologies d’assistance.

L’affichage d’un module verrouillé ne donne aucun accès à son contenu. Tous les accès directs et toutes les routes continueront d’être contrôlés côté serveur.

#### 5.3.1 Arborescence et accès directs

Chaque entrée de module pourra être développée comme un dossier afin d’afficher les pages et tâches fréquemment utilisées qu’elle contient. Une page pourra elle-même comporter des entrées enfants si un module en a réellement besoin, sans dépasser trois niveaux pour conserver une navigation lisible.

Le libellé du module ouvre sa route par défaut ou active son onglet déjà ouvert. Un chevron distinct développe ou replie la branche. Pour un module verrouillé, aucune route ne sera chargée et ses accès directs ne seront pas présentés.

Chaque accès direct devra comporter au minimum :

- un identifiant stable ;
- un libellé ;
- une route interne ;
- un ordre d’affichage ;
- une permission nécessaire ;
- éventuellement une icône et une description courte.

Les accès directs seront filtrés selon les ACL. La sélection d’un accès ouvrira le module dans un nouvel onglet s’il n’est pas encore ouvert, ou réutilisera son onglet existant, puis chargera directement l’écran correspondant. Le bandeau supérieur, la barre d’état et l’URL seront actualisés sans rechargement complet de l’interface.

Pour le module de gestion des utilisateurs, les premiers accès directs seront :

- **Consulter la liste des utilisateurs** : ouverture du tableau des utilisateurs, avec actions rapides autorisées pour modifier, désactiver ou supprimer un compte et modifier ses groupes ou ses droits ;
- **Ajouter un utilisateur** : ouverture directe du formulaire de création d’un compte.

Les actions présentes dans le tableau resteront elles-mêmes soumises à leurs permissions respectives. L’accès au tableau ne donnera donc pas automatiquement le droit de modifier, supprimer ou administrer un utilisateur.

#### 5.3.2 Ordre d’affichage

Chaque manifeste proposera un ordre d’affichage par défaut pour son module et ses accès directs. Un administrateur autorisé pourra ensuite personnaliser l’ordre global des modules, l’ordre des groupes et l’ordre des accès directs depuis le module d’administration.

L’ordre personnalisé sera enregistré dans un fichier de configuration protégé du noyau plutôt que dans la base. Sa modification devra être atomique et ne devra pas réécrire les manifestes d’origine des modules.

L’interface d’administration pourra proposer un déplacement par glisser-déposer complété par des commandes accessibles au clavier. Si aucune personnalisation n’existe, l’application utilisera l’ordre par défaut du manifeste, puis le libellé ou l’identifiant stable comme critère de départage.

### 5.4 Onglets de modules

L’interface permettra de conserver plusieurs modules ouverts simultanément sous forme d’onglets refermables. Afin de garder le comportement simple et prévisible en première version :

- un module ne pourra posséder qu’un seul onglet ouvert dans une même fenêtre de navigateur ;
- demander une autre page d’un module déjà ouvert réutilisera son onglet ;
- seul l’onglet actif sera visible, mais l’état de travail des autres onglets sera conservé ;
- chaque onglet affichera le nom du module, un indicateur de modification non enregistrée si nécessaire et un bouton de fermeture ;
- la fermeture d’un onglet contenant des modifications non enregistrées demandera confirmation ;
- le changement d’onglet remplacera le bandeau supérieur et les informations contextuelles de la barre d’état par ceux du module actif ;
- les onglets ouverts ne seront pas restaurés après déconnexion ;
- les tâches périodiques d’un onglet masqué seront suspendues, sauf besoin explicitement déclaré et validé par le noyau.

La page d’accueil pourra occuper un onglet initial. Si le dernier onglet est fermé, l’application reviendra à cette page d’accueil.

### 5.5 Zone centrale

La zone centrale charge le module sélectionné sans rechargement complet de l’interface générale. Elle doit gérer au minimum les états suivants :

- chargement en cours ;
- module chargé ;
- absence de données ;
- accès refusé ;
- erreur récupérable ;
- module indisponible.

Le module actif devra être représenté dans l’URL ou l’historique de navigation afin que les boutons précédent/suivant du navigateur fonctionnent correctement et qu’une vue autorisée puisse être ajoutée aux favoris.

### 5.6 Barre d’état inférieure

La barre d’état reste visible et peut présenter :

- l’état de connexion au serveur ;
- le nom de l’utilisateur connecté ;
- la date ou l’heure de la dernière actualisation ;
- un message de succès, d’avertissement ou d’erreur ;
- une progression pour les opérations longues ;
- des informations transmises par le module actif, par exemple le nombre d’éléments affichés ou la présence de modifications non enregistrées.

Les messages temporaires importants devront également être accessibles sous forme de notifications lisibles par les technologies d’assistance ; la barre d’état ne doit pas être l’unique moyen de signaler une erreur bloquante.

### 5.7 Notifications « toaster »

Le toaster constitue le mécanisme commun de notification temporaire. Le noyau fournit une API unique utilisable par tous les modules avec quatre niveaux : information, succès, avertissement et erreur.

- une seule pile de notifications sera affichée dans un emplacement fixe ;
- les messages brefs de succès et d’information disparaîtront automatiquement ;
- les avertissements importants et les erreurs resteront affichés jusqu’à fermeture ou action de l’utilisateur ;
- les notifications identiques pourront être regroupées afin d’éviter les répétitions ;
- un toaster ne remplacera jamais une erreur de validation placée près du champ concerné ni une confirmation nécessaire ;
- les messages seront accessibles au clavier et annoncés correctement par les technologies d’assistance ;
- les erreurs techniques détaillées resteront dans les journaux, le toaster présentant un texte compréhensible et, si disponible, une référence d’incident.

Aucun envoi par courriel, SMS ou notification du navigateur n’est prévu en première version.

### 5.8 Comportement d’affichage

La première version est destinée exclusivement aux ordinateurs. Elle ciblera une largeur minimale de 1280 pixels et restera utilisable avec le zoom du navigateur jusqu’à 200 % dans les limites raisonnables d’un écran de bureau.

Aucune interface spécifique pour téléphone ou tablette, aucun geste tactile et aucune navigation mobile ne seront exigés lors de la recette. L’absence de prise en charge mobile n’autorise toutefois pas les débordements ou composants illisibles sur une fenêtre de bureau redimensionnée.

## 6. Principe de modularité

### 6.1 Noyau et modules

Le noyau fournit les services communs : authentification, droits, navigation, interface, accès aux données, journalisation, notifications et gestion des erreurs.

Chaque module contient uniquement ses fonctions métier et ne doit pas modifier directement les fichiers du noyau. Un module pourra être ajouté ou mis à jour sans imposer de modification aux autres modules, sauf évolution documentée du contrat commun.

### 6.2 Déclaration d’un module

Chaque module devra comporter une déclaration normalisée contenant au minimum :

- un identifiant technique unique et stable ;
- un nom affiché ;
- une version ;
- une description courte ;
- une icône ;
- son ordre et son groupe d’affichage ;
- son état : installé, actif, inactif ou en maintenance ;
- les permissions qu’il utilise ;
- son point d’entrée côté serveur ;
- sa route d’ouverture par défaut ;
- les pages et accès directs proposés dans son arborescence ;
- ses ressources JavaScript, ses feuilles de style particulières et ses éventuelles ressources tierces ;
- son composant ou point d’entrée produisant l’intégralité de son bandeau supérieur ;
- la classification partagée ou privée de chacun de ses jeux de données ;
- les éventuelles opérations d’installation ou de mise à jour de son stockage.

#### 6.2.1 Déclaration de la navigation dans le manifeste

Les entrées de navigation seront déclarées directement dans un fichier `manifest.json` livré dans le répertoire de chaque module. Ce fichier constituera la source de vérité pour l’identité du module, sa route principale, ses accès directs et leurs valeurs par défaut.

Le gestionnaire de modules devra valider le manifeste à l’installation et à chaque découverte. Un manifeste invalide, un identifiant dupliqué ou une route non conforme empêchera le chargement du module concerné et produira une erreur exploitable par l’administrateur, sans bloquer les autres modules.

Le manifeste contiendra au minimum :

- l’entrée principale du module ;
- sa route par défaut ;
- ses entrées enfants et leur parent éventuel dans l’arborescence ;
- l’ordre et le groupe d’affichage ;
- la permission associée à chaque entrée ;
- les éventuels indicateurs d’état ou badges qu’il peut fournir.

Les identifiants stables permettront de conserver les préférences et l’ordre personnalisé lors d’une mise à jour du module. Une entrée supprimée du manifeste disparaîtra du menu au chargement suivant sans laisser de doublon.

Un fichier de configuration central et protégé conservera uniquement les valeurs administratives qui doivent pouvoir surcharger le manifeste, notamment l’activation, le mode maintenance, le groupe et l’ordre d’affichage. Une mise à jour du module ne devra pas écraser ces valeurs.

Une actualisation dynamique du menu pourra être proposée à l’administrateur après installation, mais l’apparition correcte au prochain rechargement complet constitue l’exigence minimale.

### 6.3 Cycle de vie d’un module

Le contrat commun devra prévoir les événements suivants :

1. contrôle des droits ;
2. ouverture ou réactivation de l’onglet du module ;
3. chargement des feuilles de style et ressources particulières déclarées par le module ;
4. chargement du contenu et du bandeau complet du module ;
5. initialisation de son interface et déclaration de son état ;
6. suspension des minuteries et tâches non nécessaires lorsque l’onglet devient masqué ;
7. reprise des traitements autorisés lorsque l’onglet redevient actif ;
8. confirmation de fermeture en présence de modifications non enregistrées ;
9. libération des événements et ressources temporaires lors de la fermeture de l’onglet ;
10. déchargement des feuilles de style et ressources qui ne sont plus utilisées par aucun onglet.

Les ressources partagées seront gérées par comptage de références : la fermeture d’un onglet ne retirera pas une feuille de style ou un script encore utilisé par un autre onglet. La libération finale évitera les événements JavaScript exécutés plusieurs fois et les fuites de mémoire.

### 6.4 Données partagées, données privées et interopérabilité

Chaque jeu de données est déclaré par son module propriétaire comme **partagé** ou **privé au module**. Cette classification est obligatoire et fait partie du contrat du module.

Une donnée partagée peut être créée ou enrichie par son module propriétaire puis consultée, triée, recoupée, interprétée ou affichée par d’autres modules autorisés. Une donnée privée au module reste accessible uniquement par le code métier et les services du module propriétaire ; elle n’apparaît ni dans le catalogue commun, ni dans les sélecteurs de relations proposés aux autres modules.

Le socle devra fournir des mécanismes transversaux permettant, lorsqu’un module en a besoin :

- d’attribuer un identifiant global et stable à une information ;
- d’associer un ou plusieurs tags ou hashtags à une information ;
- de créer une relation typée entre deux informations, même issues de modules différents ;
- de joindre un ou plusieurs fichiers à une information ;
- de retrouver les informations liées, les pièces jointes et les tags depuis n’importe quel module autorisé.

Ces mécanismes communs ne remplaceront pas les tables structurées propres aux besoins métier. Ils serviront de couche de liaison entre des données partagées qui conservent leur schéma et leurs règles de validation. Un module pourra employer ses propres tags ou relations internes pour ses données privées, sans les publier dans le registre transversal.

Cette ouverture ne doit toutefois pas conduire à des dépendances invisibles ou à des modifications incontrôlées. Le noyau devra donc fournir un catalogue des jeux de données partagés. Pour chaque jeu de données, ce catalogue indiquera au minimum :

- un identifiant et un nom fonctionnel ;
- sa description ;
- le module ou sous-module qui en définit la structure ;
- les tables ou vues concernées ;
- les champs principaux et leur signification ;
- les opérations autorisées : lecture, ajout, modification ou suppression ;
- sa version de structure ;
- les modules connus qui la produisent ou la consomment ;
- les permissions utilisateur applicables.

Tous les modules pourront lire les jeux de données partagés dès lors que l’utilisateur possède les droits nécessaires. Les lectures et écritures intermodules passeront par une API interne ou un service appartenant au module propriétaire ; un module consommateur ne devra pas interroger directement les tables d’un autre module. Les écritures seront ainsi validées par le propriétaire du jeu de données.

Les données privées au module pourront être métier ou techniques. Elles devront utiliser l’espace de stockage attribué au module et une convention de nommage empêchant les collisions. Leur structure pourra évoluer sans contrat avec les autres modules, mais leurs migrations resteront versionnées et sauvegardées comme le reste des données durables.

Le noyau ne cherchera pas à garantir l’isolement comme le ferait une base distincte par module : tous les modules s’exécutent dans la même application PHP. L’isolement reposera sur la couche d’accès aux données, l’absence d’accès SQL direct depuis les contrôleurs et les tests du contrat modulaire. Cette limite devra être connue des développeurs de modules.

La désactivation d’un module ne devra pas supprimer les données qu’il a produites. Le système devra signaler les jeux de données devenus non alimentés et les éventuels modules consommateurs concernés.

### 6.5 Sous-modules

Un module pourra contenir plusieurs sous-modules ou écrans spécialisés. Un sous-module pourra notamment servir à saisir ou compléter un jeu de données ensuite exploité par le module parent ou par un autre module.

Chaque sous-module pourra déclarer ses propres routes, actions, ressources, permissions et dépendances de données, tout en restant rattaché au module principal dans la navigation et l’administration.

### 6.6 Modules du socle initial

Les composants suivants sont proposés pour la première version :

- **Accueil / tableau de bord** : page affichée après connexion ;
- **Utilisateurs, groupes et ACL** : comptes, groupes, règles, héritage et contrôle des droits effectifs ;
- **Gestion des modules** : activation, désactivation, ordre et mode maintenance ;
- **Paramètres généraux** ;
- **Profil et préférences utilisateur** ;
- **Journal d’activité** : module de base consultable par les personnes autorisées, avec recherche, filtres, tri et accès au détail d’une action.

### 6.7 Premiers modules fonctionnels

#### 6.7.1 Utilisateurs, groupes et ACL

Ce module d’administration permettra au minimum :

- de lister, rechercher, créer et modifier les comptes ;
- d’activer, désactiver, verrouiller ou déverrouiller un compte ;
- de forcer ou déclencher le renouvellement d’un mot de passe ;
- de créer et modifier librement des groupes ;
- d’affecter un utilisateur à un ou plusieurs groupes ;
- de parcourir l’arborescence des ressources protégées ;
- d’attribuer ou retirer une règle ACL à un utilisateur ou à un groupe ;
- de distinguer visuellement une règle explicite d’une règle héritée ;
- de calculer et afficher le droit effectif d’un utilisateur sur une ressource donnée ;
- de rechercher pourquoi un accès a été autorisé ou refusé ;
- de journaliser les changements de compte, de groupe et de droits.

La suppression définitive d’un utilisateur ne sera pas l’opération normale : la désactivation préservera les liens avec ses notes, ses actions et le journal d’activité.

#### 6.7.2 Pense-bête / bloc-notes

Ce module simple permettra à un utilisateur de gérer plusieurs notes. Sa première version devra au minimum proposer :

- une liste des notes ;
- la création d’une note ;
- la modification du titre et du contenu ;
- l’enregistrement de la date de création et de dernière modification ;
- la suppression avec confirmation ;
- l’ouverture rapide d’une note depuis la liste ;
- un message clair lorsque l’enregistrement a réussi ou échoué.

En première version, les notes seront exclusivement personnelles. Un administrateur ne pourra pas en lire le contenu par la seule possession du rôle d’administration ; une permission explicite d’assistance ou d’audit sera nécessaire. Le partage de notes pourra être ajouté ultérieurement sans modifier leur modèle d’auteur et de propriétaire.

#### 6.7.3 Module fictif de démonstration et de test

Ce module servira de référence technique et visuelle pendant le développement. Il ne répondra pas à un besoin métier, mais devra permettre de vérifier de manière reproductible :

- les styles de titres, textes, boutons, champs, listes et panneaux ;
- la distinction entre les styles communs permanents et une feuille de style propre au module ;
- les états normal, survolé, sélectionné, désactivé et en erreur ;
- l’affichage et la validation des formulaires ;
- les fenêtres modales et confirmations ;
- les tableaux avec tri, filtres, pagination et absence de données ;
- les différents messages « toaster » : information, succès, avertissement et erreur ;
- les messages temporaires et persistants ;
- la production et la mise à jour dynamique de l’intégralité du bandeau supérieur du module ;
- la mise à jour dynamique de la barre d’état inférieure ;
- les indicateurs de chargement et de progression ;
- les cas d’accès refusé, de session expirée, de module indisponible et d’erreur serveur ;
- l’ouverture, la suspension, la réactivation et la fermeture d’un onglet de module ;
- le comportement sur les largeurs d’écran de bureau prises en charge ;
- la suspension lors d’un changement d’onglet et la libération correcte des événements lors de la fermeture d’un module.

Le module fictif pourra être réservé aux administrateurs ou désactivé sur l’environnement de production. Il constituera également le module d’exemple pour documenter la création de futurs modules.

#### 6.7.4 Chat élémentaire

La première version du chat proposera un salon général commun aux utilisateurs autorisés. Elle devra au minimum permettre :

- d’afficher les messages dans l’ordre chronologique avec leur auteur et leur date ;
- d’envoyer un message texte sans recharger l’interface générale ;
- de récupérer les nouveaux messages par AJAX toutes les trois secondes environ ;
- de ne demander au serveur que les messages postérieurs au dernier identifiant déjà reçu ;
- d’éviter le chevauchement de plusieurs requêtes d’actualisation ;
- d’échapper et valider le contenu des messages avant affichage ;
- de signaler une erreur d’envoi ou une interruption temporaire de l’actualisation ;
- d’appliquer des droits distincts pour consulter, publier et modérer ;
- de conserver les messages dans le stockage applicatif.

L’actualisation périodique sera active lorsque l’onglet du chat est visible. Elle sera suspendue lorsque cet onglet est masqué, arrêtée lorsqu’il est fermé ou lors de la déconnexion, et ralentie temporairement en cas d’erreurs répétées. Le faible nombre d’utilisateurs rend acceptable ce mécanisme de polling simple ; aucune connexion WebSocket n’est requise pour cette première version.

## 7. Choix techniques

### 7.1 Technologies imposées

- PHP 8.4 natif côté serveur ;
- serveur web intégré à PHP pour le développement local ;
- compatibilité Apache 2.4 pour l’hébergement de production ;
- SQLite en première version, sans service de base de données séparé ;
- JavaScript natif côté navigateur ;
- jQuery autorisé uniquement si son emploi simplifie réellement certains comportements dynamiques ;
- HTML5 et CSS pour la structure et la présentation.

Aucun framework PHP ou JavaScript lourd n’est prévu dans le socle. Les bibliothèques ponctuelles devront être justifiées, versionnées et déclarées dans le module qui les utilise.

### 7.2 Organisation recommandée

Même en PHP natif, le code devra être structuré avec une séparation claire entre :

- le routage et les contrôleurs ;
- la logique métier ;
- l’accès aux données ;
- les vues et composants d’interface ;
- les services communs ;
- les modules ;
- la configuration et les secrets ;
- les fichiers publics accessibles par le navigateur.

Un point d’entrée public unique est recommandé afin de centraliser l’authentification, le routage, les contrôles d’accès, la protection contre les requêtes frauduleuses et la gestion des erreurs.

### 7.3 Chargement dynamique par AJAX

Le chargement dynamique reposera sur AJAX, mis en œuvre par défaut avec l’API native `fetch` du navigateur. jQuery pourra être employé de manière ponctuelle, mais ne devra pas être nécessaire au fonctionnement du noyau.

AJAX sera utilisé pour charger un module ou une partie de son interface, envoyer un formulaire, mettre à jour une liste, afficher une notification et actualiser certains états sans rechargement complet de la page.

Le serveur distinguera :

- les vues ou fragments HTML destinés à la zone de travail ;
- les actions et échanges de données au format JSON ;
- les téléchargements de fichiers ;
- les erreurs normalisées.

Une réponse JSON devra suivre une structure commune comprenant au minimum un indicateur de succès, les données utiles, un message utilisateur éventuel et un identifiant d’erreur exploitable dans les journaux.

Le noyau devra également gérer l’annulation ou l’ignorance d’une réponse devenue obsolète après un changement de route, d’onglet ou la fermeture d’un module, empêcher les doubles soumissions et suspendre ou interrompre les minuteries selon le cycle de vie de l’onglet.

### 7.4 Persistance et évolution vers MariaDB

La première version utilisera **SQLite**, avec une base conservée dans un fichier protégé hors du répertoire Web. SQLite reste techniquement un moteur SQL, mais il est embarqué dans PHP et ne nécessite aucun serveur de base de données à installer, démarrer ou administrer. Ce choix répond au besoin d’un démarrage local simple.

L’accès aux données se fera exclusivement au moyen de PDO, de requêtes préparées et de dépôts ou services dédiés. Les contrôleurs et vues ne contiendront pas de requêtes SQL. Les clés étrangères seront activées, les opérations liées seront transactionnelles et le mode WAL pourra être utilisé pour améliorer les accès concurrents du faible nombre d’utilisateurs prévu.

Le noyau devra prévoir au minimum les entités suivantes :

- utilisateurs ;
- groupes ;
- permissions ;
- ressources protégées ;
- règles ACL et associations entre utilisateurs et groupes ;
- paramètres dynamiques des modules qui relèvent de la base de données ;
- droits d’accès aux modules ;
- préférences utilisateur ;
- journal d’activité ;
- catalogue des jeux de données partagés et de leurs versions ;
- dépendances déclarées entre modules et jeux de données ;
- registre commun des informations pouvant recevoir des tags, relations et pièces jointes ;
- tags et associations entre tags et informations ;
- relations typées entre informations ;
- métadonnées des fichiers joints et associations avec les informations ;
- messages du chat ;
- migrations de structure et leur version.

Le texte sera stocké en Unicode et les dates en UTC selon une convention unique. L’affichage dans le fuseau horaire configuré pour l’application sera géré séparément.

Une migration ultérieure vers **MariaDB** est prévue. Pour la rendre réaliste :

- le type de base et sa connexion seront sélectionnés par la configuration ;
- les requêtes courantes éviteront les extensions propres à SQLite ;
- les écarts inévitables seront isolés dans l’adaptateur de persistance ;
- les migrations de structure seront versionnées et compatibles avec les deux cibles lorsqu’une version MariaDB sera introduite ;
- un outil contrôlé exportera les données SQLite et les importera dans MariaDB, avec vérification du nombre d’enregistrements et des relations ;
- le passage à MariaDB constituera une opération d’administration documentée, et non un changement automatique en cours d’utilisation.

SQLite conviendra au développement et au premier déploiement à faible charge. Le fichier de base devra rester sur un disque local au serveur, jamais sur un partage réseau synchronisé. MariaDB deviendra la cible recommandée si le nombre d’utilisateurs simultanés, le volume ou l’hébergement rendent les écritures concurrentes plus importantes.

### 7.5 Configuration

Les paramètres dépendant de l’environnement — pilote de persistance, chemin SQLite ou connexion MariaDB, mode de débogage, clés et adresse de l’application — ne doivent pas être inscrits en dur dans le code ni versionnés avec des valeurs sensibles.

Des environnements distincts devront être prévus pour le développement, les essais et la production.

Le noyau disposera également d’un fichier de configuration protégé consacré aux modules. Il conservera les surcharges administratives des manifestes, notamment leur activation, leur mode maintenance, leur groupe et leur ordre d’affichage. Ce fichier ne devra pas être placé dans le répertoire public du serveur Web.

### 7.6 Hébergement et portabilité

La première installation fonctionnera en local avec le serveur de développement intégré à PHP. Aucun Docker, Apache, MariaDB ou autre service ne sera nécessaire pour développer et essayer la première version.

Le projet fournira un script de routage local et des lanceurs simples pour Windows et Linux. Le principe de démarrage sera équivalent à :

```shell
php -S 127.0.0.1:8000 -t public tools/dev-router.php
```

Les lanceurs vérifieront la version de PHP et la présence des extensions requises, notamment PDO SQLite, puis ouvriront Atelier sur `http://127.0.0.1:8000`. Des commandes distinctes permettront d’initialiser ou réinitialiser la base de démonstration, de sauvegarder les données et de restaurer une sauvegarde.

Le fichier SQLite, les pièces jointes et la configuration locale seront conservés hors du répertoire `public` ; l’arrêt ou le redémarrage du serveur PHP ne les effacera pas. Le serveur intégré de PHP sera réservé au développement et aux essais locaux. L’hébergement réel utilisera un serveur Web de production, par exemple Apache chez OVH ou sur le serveur personnel.

L’application devra ensuite pouvoir être transférée sans modification du code métier vers un hébergement OVH compatible ou vers un serveur personnel.

Les paramètres propres à l’hébergement seront externalisés. La documentation indiquera les versions et extensions PHP requises, les droits nécessaires sur les répertoires, la configuration SQLite puis, le cas échéant, MariaDB, les tâches planifiées et la procédure de passage du local vers le serveur.

Le développement ne devra dépendre d’aucune fonction spécifique à OVH. Les chemins de fichiers, adresses, paramètres de courriel et informations de connexion seront configurables selon l’environnement.

### 7.7 Politique de stockage

Le support de stockage sera choisi selon la nature de l’information :

| Nature | Stockage recommandé |
|---|---|
| Données métier partagées ou privées, comptes, droits, messages et état durable | SQLite, puis MariaDB si migration décidée |
| Paramètres modifiables depuis l’application | SQLite, puis MariaDB |
| État, groupe et ordre des modules nécessaires avant accès à la base | Fichier de configuration protégé du noyau |
| Configuration statique livrée avec un module | Fichier versionné avec le module |
| Secrets et paramètres propres à l’hébergement | Variables d’environnement ou fichier de configuration non public |
| Fichiers joints envoyés par les utilisateurs | Système de fichiers, avec métadonnées et relations dans la base |
| Cache et fichiers temporaires régénérables | Système de fichiers |
| Journaux techniques | Fichiers de logs protégés et soumis à rotation |

Les données techniques internes à un module pourront donc être placées dans un fichier lorsqu’elles sont statiques, locales, régénérables ou non partagées. Un état durable modifié par plusieurs requêtes devra rester en base afin de bénéficier des transactions, des contraintes et de la gestion des accès concurrents.

Les fichiers joints ne seront pas stockés directement dans SQLite ou MariaDB. Ils seront placés dans un répertoire non accessible directement depuis le Web, sous un nom interne imprévisible. La base conservera notamment leur nom original, leur type, leur taille, leur empreinte, leur emplacement, leur auteur, leurs dates et les informations auxquelles ils sont rattachés. Le téléchargement passera par PHP afin de contrôler les ACL.

Pour les rares fichiers modifiés par l’application, les écritures devront être atomiques autant que possible : écriture dans un fichier temporaire, verrouillage si nécessaire, puis remplacement. Aucun fichier de configuration exécutable ne devra pouvoir être téléversé par un utilisateur.

Le stockage de certaines informations en fichiers n’est pas une protection contre une panne de la base : le système de fichiers peut lui aussi être corrompu ou perdu. La fiabilité reposera sur les transactions, les sauvegardes, la vérification des restaurations et la sauvegarde cohérente du fichier SQLite — ou de MariaDB après migration — avec le répertoire des fichiers joints.

### 7.8 Architecture des feuilles de style

#### 7.8.1 Feuille de style commune

Une feuille de style commune sera chargée avec l’interface générale et restera active pendant toute la session. Elle définira l’identité visuelle et les composants réutilisables, notamment :

- variables de couleurs, espacements, tailles et typographie ;
- structure générale de l’écran ;
- bandeau supérieur, colonne des modules et barre d’état ;
- boutons standards et boutons des bandeaux ;
- formulaires, champs, libellés, aides et erreurs de validation ;
- tableaux, en-têtes, tri, filtres, pagination et états de ligne ;
- fenêtres modales, menus, infobulles et panneaux ;
- notifications de type toaster ;
- indicateurs de chargement et de progression ;
- états désactivé, actif, sélectionné, avertissement et erreur ;
- classes utilitaires communes réellement nécessaires.

Un module devra utiliser en priorité ces composants communs afin de conserver une présentation et un comportement homogènes. Il ne devra pas recopier localement les styles d’un bouton, d’un formulaire ou d’un tableau déjà fournis par le noyau.

#### 7.8.2 Feuilles de style propres aux modules

Un module pourra déclarer une ou plusieurs feuilles de style supplémentaires lorsqu’il présente un besoin qui n’appartient pas au système graphique commun : carte géographique, chronologie, diagramme, éditeur spécialisé ou composant fourni par une bibliothèque telle que Leaflet.

Ces feuilles seront ajoutées dynamiquement dans le document avant que le contenu du module soit rendu visible. Un simple changement d’onglet ne les retirera pas : elles resteront actives tant que l’onglet du module existe. Lors de la fermeture de l’onglet, le noyau retirera les feuilles devenues inutiles après avoir exécuté la procédure de fermeture du module.

Le navigateur pourra conserver les fichiers en cache : « décharger » une feuille signifie retirer son élément `link` actif, sans imposer de télécharger à nouveau le fichier à chaque retour dans le module.

#### 7.8.3 Isolation et conventions

Les styles propres à un module devront être limités à l’élément racine de ce module, par exemple avec un sélecteur de type `.module-carte`, afin d’éviter toute modification involontaire de l’interface générale ou d’un autre module.

Les règles suivantes s’appliqueront :

- pas de redéfinition globale de `body`, des boutons, formulaires ou tableaux depuis une feuille de module ;
- pas de sélecteur générique non limité à la racine du module, sauf nécessité documentée d’une bibliothèque tierce ;
- noms de classes propres au module suffisamment explicites ou préfixés ;
- priorité aux variables et composants du CSS commun ;
- déclaration des CSS tiers dans le manifeste du module ;
- chargement unique d’une même ressource lorsqu’elle est partagée par plusieurs composants ;
- retrait uniquement lorsque plus aucun onglet ni composant ouvert n’utilise la ressource ;
- affichage d’une erreur claire si une feuille indispensable ne peut pas être chargée.

Le gestionnaire de ressources du noyau devra distinguer les styles permanents du socle, les styles propres au module et les styles de bibliothèques tierces. La même logique s’appliquera aux scripts particuliers du module.

## 8. Sécurité

Les exigences minimales sont les suivantes :

- mots de passe stockés uniquement au moyen des fonctions de hachage sécurisées de PHP ;
- mots de passe d’au moins 12 caractères, sans renouvellement périodique imposé hors première connexion, suspicion de compromission ou décision administrative ;
- requêtes SQL préparées et paramètres typés autant que possible ;
- échappement des données lors de leur affichage pour prévenir les attaques XSS ;
- jeton anti-CSRF sur toute action modifiant des données ;
- cookies de session `Secure`, `HttpOnly` et avec une politique `SameSite` adaptée ;
- renouvellement de l’identifiant de session après connexion et changement de privilèges ;
- expiration de la session après 60 minutes d’inactivité et au plus 12 heures après la connexion ; aucune option « rester connecté » en première version ;
- blocage temporaire de 15 minutes après cinq échecs consécutifs, complété par une temporisation progressive ;
- validation de toutes les données côté serveur, y compris celles déjà contrôlées en JavaScript ;
- contrôle du type, du volume et du nom des fichiers téléversés ;
- placement de SQLite, de la configuration et des fichiers joints hors du répertoire publié par le serveur Web ;
- en-têtes HTTP de sécurité et politique de contenu définis pour la production ;
- messages d’erreur publics ne révélant ni structure interne, ni requête SQL, ni secret ;
- journalisation des connexions, échecs, changements de droits et opérations sensibles ;
- accès à l’application uniquement en HTTPS en production ;
- sauvegardes régulières et procédure de restauration vérifiée.

Les opérations particulièrement sensibles, notamment la suppression, devront demander une confirmation claire. Une suppression logique ou une corbeille sera privilégiée lorsque la nature des données le permet.

## 9. Journalisation et traçabilité

Le journal d’activité est un module de base de l’application. Il devra pouvoir enregistrer :

- l’utilisateur concerné ;
- l’action effectuée ;
- le module ;
- la date et l’heure ;
- le résultat de l’action ;
- l’identifiant de la ressource concernée ;
- les informations techniques nécessaires au diagnostic, sans enregistrer de mot de passe ni de secret.

Les événements couverts seront au minimum : connexions et échecs, créations et modifications de comptes, changements d’ACL, activations ou mises à jour de modules, créations, modifications et suppressions de données, imports, exports, téléchargements de fichiers, actions de modération et erreurs applicatives significatives.

L’écran de consultation devra proposer :

- un tableau paginé côté serveur ;
- le tri par date, utilisateur, module, action et résultat ;
- des filtres cumulables sur la période, l’utilisateur, le module, le type d’action, la ressource et le résultat ;
- une recherche sur les références non sensibles ;
- l’ouverture du détail d’une entrée ;
- un export CSV soumis à une permission distincte ;
- une remise à zéro simple des filtres.

Les entrées du journal d’activité ne seront ni modifiables ni supprimables depuis l’interface ordinaire. Leur contenu devra rester factuel, éviter les données complètes avant/après lorsqu’elles sont sensibles et ne jamais enregistrer de mot de passe, jeton de session, clé ou contenu privé de note.

Les journaux techniques et le journal d’activité seront distingués. Le journal d’activité sera conservé douze mois par défaut ; les journaux techniques feront l’objet d’une rotation sur trente jours. Ces durées seront configurables avant la mise en production.

## 10. Ergonomie et accessibilité

- L’emplacement des zones générales reste stable entre les modules.
- Les mêmes icônes et libellés représentent toujours les mêmes actions.
- Toute icône d’action importante possède un libellé ou une infobulle explicite.
- La navigation au clavier, l’ordre de tabulation et la visibilité du focus sont pris en compte.
- Les contrastes, tailles de texte et états actifs doivent rester lisibles.
- Une action longue affiche un indicateur de progression et empêche les doubles soumissions.
- Les modifications non enregistrées déclenchent un avertissement avant de quitter la vue.
- Les erreurs de formulaire sont indiquées près des champs concernés et résumées clairement.
- Les tableaux importants prévoient tri, filtres, pagination et état « aucune donnée » lorsque le besoin métier le justifie.

## 11. Exigences non fonctionnelles

### 11.1 Performances

Le dimensionnement initial repose sur un maximum d’environ 20 comptes nominatifs, 5 utilisateurs simultanés, 100 000 enregistrements dans une table métier courante et 5 Go de fichiers joints au total.

- Le chargement de l’interface générale ne doit pas être répété à chaque ouverture ou activation d’onglet.
- Les ressources statiques communes doivent pouvoir être mises en cache.
- Les listes volumineuses doivent utiliser une pagination ou un chargement progressif côté serveur.
- Les requêtes SQL courantes devront être analysées et indexées avant mise en production.
- Une opération longue ne doit pas donner l’impression que l’application est bloquée.

Sur l’environnement de référence et hors traitement long explicitement signalé, 95 % des actions courantes devront produire un premier retour visuel en moins de 300 ms et une réponse complète en moins de 2 secondes. Les tableaux ne chargeront pas plus de 100 lignes par page.

### 11.2 Compatibilité

L’application devra fonctionner sur les deux dernières versions majeures stables de Firefox, Chrome et Microsoft Edge sur ordinateur. Internet Explorer, les navigateurs mobiles et les anciennes versions non maintenues sont exclus.

### 11.3 Maintenabilité

- conventions de nommage et de code communes ;
- code commenté lorsque la logique n’est pas évidente ;
- documentation du contrat des modules ;
- schéma de base et migrations versionnés ;
- absence de dépendance implicite entre modules ;
- journal des versions et des modifications ;
- tests des services du noyau et des fonctions critiques ;
- procédure documentée d’installation, mise à jour, sauvegarde et restauration.

## 12. Gestion des erreurs

Le noyau centralisera les erreurs et distinguera :

- erreur de validation ;
- authentification requise ou session expirée ;
- accès interdit ;
- ressource introuvable ;
- conflit de modification ;
- erreur serveur ;
- indisponibilité temporaire d’un module.

L’utilisateur reçoit une explication compréhensible et une action possible lorsque cela est pertinent. Les informations techniques détaillées sont réservées aux journaux et au mode de développement.

En cas d’expiration de session pendant une saisie, l’application devra limiter autant que possible la perte de données et informer clairement l’utilisateur.

## 13. Administration des modules

Un administrateur autorisé pourra :

- consulter la liste et la version des modules installés ;
- activer ou désactiver un module ;
- placer un module en maintenance ;
- définir son ordre et son groupe dans le menu ;
- gérer les ressources et attribuer les ACL aux utilisateurs ou aux groupes ;
- visualiser les jeux de données produits ou consommés par chaque module ;
- consulter les éventuelles dépendances ;
- lancer une procédure contrôlée d’installation ou de mise à jour ;
- consulter l’état ou les erreurs récentes du module.

La désactivation d’un module ne doit pas supprimer automatiquement ses données.

En première version, l’installation et la mise à jour resteront volontairement simples et contrôlées : le développeur ou l’administrateur système déposera le répertoire du module dans le dossier prévu, puis l’administrateur applicatif validera son manifeste et l’activera depuis l’interface. Aucun catalogue en ligne ni téléversement d’une archive exécutable depuis le navigateur ne sera fourni.

Une mise à jour devra vérifier sa compatibilité, créer une sauvegarde, exécuter ses migrations versionnées puis enregistrer son résultat dans le journal. Une désinstallation commencera toujours par la désactivation. Le retrait du code et la purge éventuelle des données seront deux opérations distinctes ; aucune purge automatique ne sera réalisée.

## 14. Critères de recette du socle

Le socle pourra être considéré comme conforme lorsque les vérifications suivantes seront réussies :

1. un visiteur non connecté ne peut accéder ni aux pages privées, ni aux données, ni aux points d’entrée dynamiques ;
2. un utilisateur peut se connecter et se déconnecter correctement ;
3. une session expirée entraîne une réaction cohérente, y compris lors d’une requête dynamique ;
4. le menu présente tous les modules installés et représente correctement leur disponibilité, leur état et les droits de l’utilisateur ;
5. une URL directe vers un module interdit est refusée côté serveur ;
6. le droit effectif d’un utilisateur est calculé correctement à partir des règles générales, de ses groupes, des règles directes et de l’héritage des ressources ;
7. l’interface d’ACL permet de comprendre l’origine d’un droit effectif ;
8. l’ouverture ou l’activation d’un onglet met à jour la zone centrale, le bandeau entièrement fourni par le module, la barre d’état et l’URL sans recharger toute l’interface ;
9. les boutons d’action respectent les permissions et appellent les traitements attendus ;
10. les boutons précédent et suivant du navigateur conservent une navigation cohérente ;
11. les erreurs et chargements sont visibles et compréhensibles ;
12. un module peut être activé, désactivé ou placé en maintenance ;
13. l’ajout d’un module conforme au contrat ne nécessite pas de modifier un autre module ;
14. un module peut lire un jeu de données partagé par un autre module sans contourner les permissions de l’utilisateur ;
15. une donnée déclarée privée à un module n’est pas exposée dans le catalogue commun et ne peut pas être obtenue par l’API intermodule ;
16. une modification de données partagées est validée par le service du module responsable avant enregistrement ;
17. la désactivation d’un module ne supprime pas ses données et signale les dépendances concernées ;
18. le module fictif valide les composants visuels, les onglets et les comportements dynamiques communs ;
19. le chat envoie et reçoit les nouveaux messages par AJAX sans recharger la page, sans doublon et sans requêtes simultanées inutiles ;
20. les tags, relations et pièces jointes permettent de relier des informations partagées issues de modules différents ;
21. un fichier joint n’est accessible qu’après contrôle des ACL par le serveur ;
22. les actions sensibles apparaissent dans le journal d’activité et celui-ci peut être filtré et trié par action ;
23. les notifications d’information, de succès, d’avertissement et d’erreur utilisent le toaster commun selon les règles prévues ;
24. les protections contre les principales attaques web sont testées ;
25. la sauvegarde et la restauration cohérentes de SQLite et des fichiers joints sont vérifiées sur un environnement d’essai ;
26. le serveur de développement intégré à PHP peut être lancé au moyen des commandes ou lanceurs fournis, sans Docker, Apache ni service SQLite séparé ;
27. l’installation locale peut être transférée vers un serveur compatible en ne modifiant que la configuration d’environnement ;
28. les formulaires, boutons, bandeaux et tableaux utilisent les composants du CSS commun dans tous les modules ;
29. une feuille propre à un module est chargée avant son affichage, conservée tant qu’un onglet l’utilise, puis retirée sans modifier la présentation des autres modules ;
30. plusieurs modules peuvent rester ouverts dans des onglets, un seul onglet existe par module et la fermeture protège les modifications non enregistrées ;
31. la présence d’un module doté d’un manifeste valide dans le répertoire prévu fait apparaître son entrée dans la colonne de gauche au prochain chargement complet de l’interface ;
32. les accès directs déclarés par un module apparaissent dans son arborescence dans l’ordre prévu ;
33. la sélection d’un accès direct ouvre ou réutilise le bon onglet, charge la bonne route, actualise les zones communes et respecte les ACL propres à cette entrée et à ses actions ;
34. l’ordre personnalisé des modules et de leurs accès est conservé dans le fichier de configuration et reste applicable après une mise à jour ;
35. un manifeste invalide désactive uniquement le module concerné et produit une erreur compréhensible pour l’administrateur ;
36. une indisponibilité du stockage n’empêche pas la découverte des manifestes, mais interdit par défaut l’accès aux contenus faute de pouvoir vérifier l’authentification et les ACL ;
37. un jeu de données SQLite de référence peut être migré vers MariaDB au moyen de l’outil prévu sans perte d’enregistrements ni de relations.

## 15. Livrables prévus pour la phase de développement

- code source du noyau ;
- schéma SQLite, migrations versionnées et adaptateur de persistance ;
- outil documenté de migration de SQLite vers MariaDB ;
- environnement de développement fondé sur `php -S`, avec routeur local, lanceurs Windows/Linux et données persistantes ;
- module d’administration ;
- module d’accueil ou de démonstration servant de référence ;
- feuille de style et composants communs ;
- conventions CSS et gestionnaire de ressources dynamiques des modules ;
- contrat technique de création d’un module ;
- schéma du `manifest.json` et format du fichier de configuration de l’arborescence et de l’ordre des modules ;
- documentation du catalogue de données, des tags, des relations et des pièces jointes ;
- documentation d’installation et de configuration ;
- documentation d’exploitation, sauvegarde et restauration ;
- jeux de tests et procès-verbal de recette ;
- journal des versions.

## 16. Décisions de cadrage retenues

Afin de rendre la première version simple à utiliser et à administrer, les choix suivants sont retenus. Ils pourront être révisés dans une version ultérieure du cahier des charges, mais ne constituent plus des prérequis bloquants pour commencer le développement.

| Sujet | Choix retenu pour la première version |
|---|---|
| Nom | **Atelier**, nom définitif du projet et de la webapplication ; identifiant technique `atelier`. |
| Dimensionnement | Environ 20 comptes, au plus 5 utilisateurs simultanés, 100 000 lignes dans une table métier courante et 5 Go de fichiers joints. |
| Comptes | Création et import éventuel uniquement par un administrateur ; aucune inscription libre. |
| Mot de passe oublié | Réinitialisation par un administrateur avec mot de passe temporaire et changement obligatoire à la connexion suivante ; aucun courriel en version 1. |
| Authentification externe | Aucun fournisseur d’identité, SSO, OAuth, SAML ou annuaire externe. |
| ACL | Ressource la plus précise, puis règle utilisateur, puis règles de groupes ; à égalité, le refus prévaut ; absence de règle = refus. |
| Notes | Notes personnelles uniquement en version 1 ; partage reporté. |
| Écrans | Ordinateur uniquement, largeur cible minimale de 1280 pixels ; pas de recette téléphone ou tablette. |
| Identité visuelle | Thème clair, neutre et sobre, fondé sur des variables CSS ; pas de thème sombre ni de logo obligatoire en version 1. |
| Langue et formats | Interface uniquement en français ; UTF-8 ; dates `jj/mm/aaaa`, heure sur 24 heures et fuseau `Europe/Paris` par défaut. |
| Import | Pas d’import universel. Chaque module qui en a besoin proposera un import CSV UTF-8 avec aperçu, validation et rapport d’erreurs. |
| Export et impression | Export CSV pour les tableaux qui le justifient et feuille de style d’impression pour le navigateur ; aucun générateur PDF générique en version 1. |
| Données sensibles | Données internes ordinaires, comptes, ACL, journaux et fichiers ; aucune donnée de santé, bancaire ou autre catégorie particulièrement sensible n’est prévue. Un module introduisant de telles données nécessitera un complément de spécification. |
| Suppression | Suppression logique et corbeille de 30 jours pour les données métier compatibles ; purge définitive réservée aux personnes autorisées. Le journal d’activité suit sa propre conservation. |
| Sauvegardes | Sauvegarde cohérente de SQLite, des fichiers joints et de la configuration : 7 sauvegardes quotidiennes, 4 hebdomadaires et 12 mensuelles après mise en production ; restauration testée au moins chaque trimestre. En local, une commande de sauvegarde et de restauration sera fournie. |
| Fonctionnement hors ligne et intégrations | Pas de mode hors ligne, PWA, API publique ni intégration externe en version 1. |
| Notifications | Toasters dans l’application uniquement ; aucune notification par courriel, SMS ou navigateur. |
| Journal d’activité | Événements fonctionnels et de sécurité décrits à la section 9, conservation de 12 mois, consultation paginée, tri et filtres cumulables. |
| Environnement local | Serveur intégré à PHP 8.4 lancé avec `php -S`, SQLite et lanceurs simples sous Windows et Linux ; aucun Docker ni Apache local. |
| Hébergement | Serveur personnel ou offre OVH compatible Apache/PHP et écriture dans les répertoires de données ; MariaDB pourra remplacer SQLite si la charge le justifie. |
| Chat | Un seul salon général, messages texte uniquement et polling toutes les trois secondes lorsque l’onglet est actif. |
| Conservation du chat | Conservation pendant 12 mois, puis purge automatique ; suppression anticipée possible par un modérateur et journalisée. |
| Pièces jointes | 20 Mo maximum par fichier, 500 Mo par utilisateur et 5 Go au total. PDF, images JPEG/PNG/WebP, texte/CSV et formats bureautiques courants sont autorisés ; exécutables, scripts et archives sont refusés en version 1. |
| Tags partagés | Création libre par les utilisateurs autorisés à modifier la donnée ; unicité insensible à la casse, espaces superflus supprimés et caractère `#` non stocké. Renommage, fusion et suppression réservés à la permission `tags.manage`. |
| Tags privés | Un module peut disposer de tags internes distincts, non visibles et non fusionnables depuis le catalogue partagé. |
| Modules | Installation par dépôt contrôlé du dossier, validation du manifeste puis activation ; aucun catalogue en ligne ni téléversement de code depuis l’interface. |
| Onglets | Un seul onglet ouvert par module et par fenêtre ; les accès directs réutilisent cet onglet ; pas de restauration des onglets après déconnexion. |

## 17. Architecture retenue pour la première version

- application interne, entièrement inaccessible sans authentification locale ;
- utilisation sur ordinateur uniquement ;
- architecture monolithique modulaire en PHP natif, compatible avec Apache en production ;
- persistance initiale dans SQLite, sans serveur de base de données séparé, avec évolution préparée vers MariaDB ;
- échanges dynamiques réalisés en AJAX avec JavaScript natif et `fetch` ;
- notifications temporaires centralisées dans le toaster commun ;
- feuille de style commune chargée en permanence pour les composants standards ;
- feuilles de style particulières déclarées, isolées, chargées à l’ouverture et retirées lorsque plus aucun onglet ne les utilise ;
- modules ouverts dans des onglets refermables, avec un seul onglet par module ;
- bandeau supérieur entièrement produit par le module de l’onglet actif ;
- colonne de gauche reconstruite depuis les manifestes à chaque chargement complet et présentée comme une arborescence de modules et de pages ;
- activation, mode maintenance, groupe et ordre personnalisés stockés dans un fichier protégé indépendant de la base ;
- données explicitement classées comme partagées entre modules ou privées au module ;
- accès transversal aux seules données partagées, au moyen d’un catalogue et des services des modules propriétaires ;
- informations partagées reliables au moyen d’identifiants communs, de tags, de relations typées et de pièces jointes ;
- fichiers joints conservés sur le système de fichiers avec leurs métadonnées et associations dans la base ;
- données techniques statiques, temporaires ou régénérables pouvant être conservées dans des fichiers ;
- aucune restriction organisationnelle prédéfinie par établissement, navire, service ou groupe ;
- droits entièrement paramétrables au moyen des utilisateurs, groupes, ressources et ACL ;
- environnement de développement fourni autour du serveur intégré à PHP et de SQLite, sans Docker ni Apache local ;
- déploiement possible chez OVH ou sur serveur personnel ;
- premier développement centré sur le socle, la gestion des utilisateurs et ACL, le journal d’activité, le bloc-notes, le module fictif de démonstration et le chat élémentaire.

## 18. Historique du document

| Version | Date | Modification |
|---|---|---|
| 0.8 | 22/09/2026 | Adoption définitive du nom Atelier et remplacement de l’environnement Docker/Apache local par le serveur de développement intégré à PHP avec SQLite |
| 0.7 | 22/09/2026 | Distinction entre données partagées et privées au module, suppression de l’identité externe, menu arborescent, bandeau entièrement fourni par le module, onglets refermables, ordinateur uniquement, SQLite évolutif vers MariaDB, microserveur Apache/PHP, toaster commun, module de journal consultable et choix de cadrage de la version 1 |
| 0.6 | 19/07/2026 | Remplacement du registre MariaDB de navigation par des manifestes de modules et ajout d’un ordre d’affichage personnalisable conservé dans un fichier protégé |
| 0.5 | 19/07/2026 | Définition de la colonne dynamique, du registre de navigation, du menu accordéon et des accès directs déclarés lors de l’installation des modules |
| 0.4 | 19/07/2026 | Définition du CSS commun, des feuilles propres aux modules, de leur isolation et de leur chargement et déchargement dynamiques |
| 0.3 | 19/07/2026 | Ajout du concept de wiki interactif, des tags, relations et pièces jointes, confirmation d’AJAX, politique de stockage et ajout du chat élémentaire par polling |
| 0.2 | 19/07/2026 | Précision de la finalité métier, données partagées entre modules, ACL paramétrables, choix de MariaDB, hébergement local puis distant et définition des deux premiers modules |
| 0.1 | 19/07/2026 | Création du cahier des charges initial à partir des principes d’interface, d’authentification et de modularité |
