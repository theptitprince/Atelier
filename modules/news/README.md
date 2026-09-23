# Module Actualités (`news`)

Veille d'actualité à partir de flux choisis (RSS 2.0, RSS 1.0, Atom, JSON Feed), classés par **catégorie** (au niveau du flux) et par **centre d'intérêt** (mots-clés appliqués à chaque entrée), avec lecture personnelle et **archivage à la demande** des faits à conserver.

## Fonctions

- **Fil d'actualité** : entrées des flux actifs, du plus récent au plus ancien ; filtres par catégorie, centre d'intérêt, flux, non lues, recherche ; lecture marquée à l'ouverture de la source ou d'un clic ; « Tout marquer lu » sur la sélection affichée ; indicateur de non lues dans la colonne (badge).
- **Récupération en tâche de fond** : `console cron:run` (lanceurs `cron.bat` / `cron.sh`, à planifier toutes les 5 à 15 minutes) appelle le hook `cron()` du module, qui récupère les flux dont la fréquence propre est échue (15 minutes à 7 jours, réglable par flux) puis télécharge les articles en attente ; 50 flux et 40 articles au plus par passage, deux minutes de budget chacun. À défaut de planification, les flux périmés sont récupérés à l'ouverture du fil (trois au plus, huit secondes) et l'écran « Flux suivis » signale l'absence de tâche de fond. Requêtes conditionnelles (`ETag`, `Last-Modified`), 5 Mo et 10 s au plus par ressource, adresses internes refusées, HTML ramené à du texte.
- **Copie locale des articles** (option par flux, activée par défaut) : la page de chaque entrée est téléchargée et son texte principal extrait (`ArticleExtractor` : scripts, navigation, pieds de page et encarts retirés, bloc le plus dense retenu, titres et listes conservés), puis stocké en base ; « Lire » affiche cette copie même si la source a disparu. Un fait archivé est copié immédiatement s'il ne l'était pas ; « Télécharger maintenant » relance une copie en échec.
- **Archives** : « Archiver » conserve un fait hors rétention avec une note (éditeur BBCode commun), des tags partagés (saisie commune) et l'inscrit au registre commun (`news.archive`) : il peut alors être relié à un point GPS, recevoir des pièces jointes ou être référencé par une page. « Désarchiver » retire l'inscription.
- **Flux suivis** : bouton « Flux suggérés » (vingt sources publiques classées : presse générale, international, sciences, environnement, technologie, sécurité, administration ; les flux déjà suivis sont ignorés), ajout par adresse (titre, site et description lus depuis le flux), catégorie, fréquence (15 minutes à 7 jours), copie locale des articles (option), rétention (1 à 3 650 jours), suspension, récupération manuelle, suppression (les archives sont conservées).
- **Catégories et centres d'intérêt** : couleurs, ordre ; mots-clés séparés par des virgules, insensibles à la casse et aux accents, sur mots entiers, `-mot` pour exclure ; recalcul des correspondances à l'enregistrement. Le compteur affiché à côté d'un centre d'intérêt, comme le nombre annoncé après un recalcul, ne compte que les entrées vivantes : un fait mis à la corbeille n'y figure plus, mais sa correspondance est conservée pour le cas où il serait restauré.
- **Rétention** : `maintenance:purge` supprime les entrées non archivées plus anciennes que la rétention de leur flux.
- **Corbeille** (1.2.0) : retirer un flux ou supprimer un fait archivé (bouton « Supprimer » de la fiche d'archive, permission `archive`) est une suppression **logique** (`deleted_at`, `deleted_by`), restaurable pendant `trash.retention_days` (30 jours) depuis l'écran « Corbeille » du module (permission `delete`) ou la corbeille globale (module `trash`, `TrashProviderInterface`, identifiants `feed:<id>` et `archive:<id>`, jeux `news.feed` et `news.archive`). Un flux en corbeille disparaît des listes, du badge, des tâches de fond et du fil (ses entrées non archivées sont masquées, non supprimées) ; ses faits archivés restent consultables et le flux **ne peut pas être purgé** tant qu'il en porte (`409`, purge de rétention ignorée) : les faits doivent être purgés ou le flux restauré. Un fait archivé en corbeille quitte les archives, le service intermodule et le registre à la purge seulement (note, tags, relations, pièces jointes conservés jusque-là). Restauration : `update` pour un flux, `archive` pour un fait ; suppression définitive : `delete`. Les entrées lues / non lues du cache restent gérées par la rétention des flux, pas par la corbeille. « Désarchiver » reste un changement d'état (retour au fil), non une suppression. Recréer un flux dont l'adresse est en corbeille est refusé (restaurer plutôt).

## Prérequis réseau

Le serveur doit pouvoir joindre les sites sources (extension `curl`). Si PHP ne dispose d'aucun certificat racine (PHP portable : erreur « unable to get local issuer certificate »), déposez le bundle Mozilla `cacert.pem` (https://curl.se/docs/caextract.html) dans `var/config/cacert.pem` : il est utilisé automatiquement lorsque `curl.cainfo` n'est pas configuré.

## Droits

| Permission | Effet |
|---|---|
| `open` | Fil, archives, lecture personnelle, actualisation |
| `archive` (propre au module) | Archiver, annoter, taguer, désarchiver, mettre un fait archivé en corbeille et le restaurer |
| `create` / `update` / `delete` | Flux, catégories, centres d'intérêt ; `update` restaure un flux, `delete` ouvre la corbeille du module et supprime définitivement |
| `read` sur `atelier/news/data/archive` | Accès intermodule aux faits archivés |

## Service intermodule (`$this->ctx->moduleService('news')`)

```php
$news = $this->ctx->moduleService('news');
$news->find(12);            // fait archivé (title, url, summary, published_at, archived_at, archive_note, feed_title, category_name, interests) ou null
$news->search('ariane', 10); // faits archivés correspondants (titre, résumé, note)
$news->infoId(12);          // identifiant du registre commun, pour relations / tags / pièces jointes
```

## Données

Tables `news_category`, `news_interest`, `news_feed` (`refresh_minutes`, `retention_days`, `fetch_content`), `news_item` (entrées ; `content`, `content_status`, `content_fetched_at` pour la copie locale ; `is_archived`, `archived_at`, `archived_by`, `archive_note`), `news_item_interest`, `news_read` (lecture par utilisateur). Jeu partagé `news.archive` (faits archivés, lecture seule) ; jeu privé `news.feed`. Migration `003_soft_delete` : `deleted_at` (indexée) et `deleted_by` sur `news_feed` et `news_item`.

Hook `purge()` (`console maintenance:purge`) : rétention des entrées, puis purge physique des flux (sans fait archivé) et des faits archivés en corbeille depuis plus de `trash.retention_days` jours.
