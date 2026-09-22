# Module Actualités (`news`)

Veille d'actualité à partir de flux choisis (RSS 2.0, RSS 1.0, Atom, JSON Feed), classés par **catégorie** (au niveau du flux) et par **centre d'intérêt** (mots-clés appliqués à chaque entrée), avec lecture personnelle et **archivage à la demande** des faits à conserver.

## Fonctions

- **Fil d'actualité** : entrées des flux actifs, du plus récent au plus ancien ; filtres par catégorie, centre d'intérêt, flux, non lues, recherche ; lecture marquée à l'ouverture de la source ou d'un clic ; « Tout marquer lu » sur la sélection affichée ; indicateur de non lues dans la colonne (badge).
- **Récupération en tâche de fond** : `console cron:run` (lanceurs `cron.bat` / `cron.sh`, à planifier toutes les 5 à 15 minutes) appelle le hook `cron()` du module, qui récupère les flux dont la fréquence propre est échue (15 minutes à 7 jours, réglable par flux) puis télécharge les articles en attente ; 50 flux et 40 articles au plus par passage, deux minutes de budget chacun. À défaut de planification, les flux périmés sont récupérés à l'ouverture du fil (trois au plus, huit secondes) et l'écran « Flux suivis » signale l'absence de tâche de fond. Requêtes conditionnelles (`ETag`, `Last-Modified`), 5 Mo et 10 s au plus par ressource, adresses internes refusées, HTML ramené à du texte.
- **Copie locale des articles** (option par flux, activée par défaut) : la page de chaque entrée est téléchargée et son texte principal extrait (`ArticleExtractor` : scripts, navigation, pieds de page et encarts retirés, bloc le plus dense retenu, titres et listes conservés), puis stocké en base ; « Lire » affiche cette copie même si la source a disparu. Un fait archivé est copié immédiatement s'il ne l'était pas ; « Télécharger maintenant » relance une copie en échec.
- **Archives** : « Archiver » conserve un fait hors rétention avec une note (éditeur BBCode commun), des tags partagés (saisie commune) et l'inscrit au registre commun (`news.archive`) : il peut alors être relié à un point GPS, recevoir des pièces jointes ou être référencé par une page. « Désarchiver » retire l'inscription.
- **Flux suivis** : bouton « Flux suggérés » (vingt sources publiques classées : presse générale, international, sciences, environnement, technologie, sécurité, administration ; les flux déjà suivis sont ignorés), ajout par adresse (titre, site et description lus depuis le flux), catégorie, fréquence (15 minutes à 7 jours), copie locale des articles (option), rétention (1 à 3 650 jours), suspension, récupération manuelle, suppression (les archives sont conservées).
- **Catégories et centres d'intérêt** : couleurs, ordre ; mots-clés séparés par des virgules, insensibles à la casse et aux accents, sur mots entiers, `-mot` pour exclure ; recalcul des correspondances à l'enregistrement.
- **Rétention** : `maintenance:purge` supprime les entrées non archivées plus anciennes que la rétention de leur flux.

## Prérequis réseau

Le serveur doit pouvoir joindre les sites sources (extension `curl`). Si PHP ne dispose d'aucun certificat racine (PHP portable : erreur « unable to get local issuer certificate »), déposez le bundle Mozilla `cacert.pem` (https://curl.se/docs/caextract.html) dans `var/config/cacert.pem` : il est utilisé automatiquement lorsque `curl.cainfo` n'est pas configuré.

## Droits

| Permission | Effet |
|---|---|
| `open` | Fil, archives, lecture personnelle, actualisation |
| `archive` (propre au module) | Archiver, annoter, taguer, désarchiver |
| `create` / `update` / `delete` | Flux, catégories, centres d'intérêt |
| `read` sur `atelier/news/data/archive` | Accès intermodule aux faits archivés |

## Service intermodule (`$this->ctx->moduleService('news')`)

```php
$news = $this->ctx->moduleService('news');
$news->find(12);            // fait archivé (title, url, summary, published_at, archived_at, archive_note, feed_title, category_name, interests) ou null
$news->search('ariane', 10); // faits archivés correspondants (titre, résumé, note)
$news->infoId(12);          // identifiant du registre commun, pour relations / tags / pièces jointes
```

## Données

Tables `news_category`, `news_interest`, `news_feed` (`refresh_minutes`, `retention_days`, `fetch_content`), `news_item` (entrées ; `content`, `content_status`, `content_fetched_at` pour la copie locale ; `is_archived`, `archived_at`, `archived_by`, `archive_note`), `news_item_interest`, `news_read` (lecture par utilisateur). Jeu partagé `news.archive` (faits archivés, lecture seule) ; jeu privé `news.feed`.
