<?php

declare(strict_types=1);

namespace Atelier\Modules\News;

use Atelier\Logging\Logger;
use Atelier\Support\Clock;

/**
 * Récupération et intégration des flux : requêtes conditionnelles, analyse, enregistrement des
 * nouvelles entrées, mise à jour de l'état du flux. Une erreur réseau ou de format est consignée
 * sur le flux (last_status, last_error) sans interrompre les autres.
 */
final class NewsSync
{
    public const MAX_ITEMS_PER_FETCH = 200;

    public function __construct(
        private readonly NewsRepository $repository,
        private readonly FeedFetcher $fetcher,
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * Récupère un flux et enregistre ses nouvelles entrées.
     *
     * @param array<string, mixed> $feed
     * @return array{status: string, new: int, message: string, feed: array<string, mixed>}
     */
    public function refresh(array $feed, int $timeout = 10, bool $conditional = true): array
    {
        $id = (int) $feed['id'];
        $now = Clock::utc();
        try {
            $response = $this->fetcher->fetch((string) $feed['url'], $conditional ? ($feed['etag'] ?? null) : null, $conditional ? ($feed['last_modified'] ?? null) : null, $timeout);
            if ($response['status'] === 304) {
                $this->repository->updateFeedStatus($id, ['last_fetched_at' => $now, 'last_success_at' => $now, 'last_status' => 'ok', 'last_error' => null]);
                return ['status' => 'unchanged', 'new' => 0, 'message' => 'Aucune modification depuis la dernière récupération.', 'feed' => $feed];
            }
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException('Réponse HTTP ' . $response['status'] . ' du serveur.');
            }
            $parsed = FeedParser::parse($response['body']);
            $items = array_slice($parsed['items'], 0, self::MAX_ITEMS_PER_FETCH);
            $new = $this->repository->storeItems($id, $items, $this->repository->interests());
            $status = [
                'last_fetched_at' => $now,
                'last_success_at' => $now,
                'last_status' => 'ok',
                'last_error' => null,
                'etag' => $response['etag'] !== null ? mb_substr($response['etag'], 0, 500, 'UTF-8') : null,
                'last_modified' => $response['lastModified'] !== null ? mb_substr($response['lastModified'], 0, 250, 'UTF-8') : null,
                'format' => $parsed['format'],
            ];
            if ((string) $feed['title'] === '' && $parsed['title'] !== '') {
                $status['title'] = mb_substr($parsed['title'], 0, 250, 'UTF-8');
            }
            if (empty($feed['site_url']) && $parsed['site_url'] !== null) {
                $status['site_url'] = $parsed['site_url'];
            }
            if (empty($feed['description']) && $parsed['description'] !== null) {
                $status['description'] = $parsed['description'];
            }
            $this->repository->updateFeedStatus($id, $status);
            return ['status' => 'ok', 'new' => $new, 'message' => $new === 0 ? 'Aucune nouvelle entrée.' : $new . ' nouvelle(s) entrée(s).', 'feed' => $status + $feed];
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 500, 'UTF-8');
            $this->repository->updateFeedStatus($id, ['last_fetched_at' => $now, 'last_status' => 'error', 'last_error' => $message]);
            $this->logger?->warning('Flux d’actualité en erreur : ' . $feed['url'], ['feed_id' => $id, 'error' => $message]);
            return ['status' => 'error', 'new' => 0, 'message' => $message, 'feed' => $feed];
        }
    }

    /**
     * Rafraîchit les flux périmés dans la limite d'un nombre et d'un budget de temps.
     *
     * @return list<array{status: string, new: int, message: string, feed: array<string, mixed>}>
     */
    public function refreshStale(int $maxFeeds, float $budgetSeconds, int $timeout = 6): array
    {
        $start = microtime(true);
        $results = [];
        foreach ($this->repository->staleFeeds($maxFeeds) as $feed) {
            if (microtime(true) - $start > $budgetSeconds) {
                break;
            }
            $results[] = $this->refresh($feed, $timeout);
        }
        return $results;
    }

    /**
     * Rafraîchit tous les flux actifs (action explicite).
     *
     * @return list<array{status: string, new: int, message: string, feed: array<string, mixed>}>
     */
    public function refreshAll(int $timeout = 8): array
    {
        $results = [];
        foreach ($this->repository->feeds(true) as $feed) {
            $results[] = $this->refresh($feed, $timeout);
        }
        return $results;
    }

    /**
     * Télécharge la page d'une entrée et en conserve le texte principal (copie locale).
     *
     * @param array<string, mixed> $item
     * @return array{status: string, message: string}
     */
    public function fetchContent(array $item, int $timeout = 10): array
    {
        $id = (int) $item['id'];
        $url = (string) ($item['url'] ?? '');
        if ($url === '') {
            $this->repository->saveContent($id, null, 'skipped', 'Entrée sans lien.', null);
            return ['status' => 'skipped', 'message' => 'Entrée sans lien.'];
        }
        try {
            $response = $this->fetcher->fetch($url, null, null, $timeout, 'text/html, application/xhtml+xml;q=0.9, */*;q=0.5');
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException('Réponse HTTP ' . $response['status'] . ' de la source.');
            }
            $type = strtolower($response['contentType']);
            if ($type !== '' && !str_contains($type, 'html') && !str_contains($type, 'xml')) {
                throw new \RuntimeException('La source n’est pas une page HTML (' . $type . ').');
            }
            $article = ArticleExtractor::extract($response['body']);
            if (mb_strlen($article['text'], 'UTF-8') < 80) {
                throw new \RuntimeException('Aucun texte exploitable trouvé dans la page.');
            }
            $this->repository->saveContent($id, $article['text'], 'ok', null, empty($item['image_url']) ? FeedParser::url((string) $article['image']) : null);
            return ['status' => 'ok', 'message' => mb_strlen($article['text'], 'UTF-8') . ' caractères conservés.'];
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 500, 'UTF-8');
            $this->repository->saveContent($id, null, 'error', $message, null);
            return ['status' => 'error', 'message' => $message];
        }
    }

    /**
     * Télécharge les articles en attente (flux avec copie locale activée), dans une limite de nombre et de temps.
     *
     * @return array{done: int, errors: int, skipped: int}
     */
    public function fetchPendingContent(int $maxItems, float $budgetSeconds, int $timeout = 10): array
    {
        $start = microtime(true);
        $result = ['done' => 0, 'errors' => 0, 'skipped' => 0];
        foreach ($this->repository->pendingContent($maxItems) as $item) {
            if (microtime(true) - $start > $budgetSeconds) {
                break;
            }
            $status = $this->fetchContent($item, $timeout)['status'];
            $result[$status === 'ok' ? 'done' : ($status === 'error' ? 'errors' : 'skipped')]++;
        }
        return $result;
    }

    /**
     * Vérifie une adresse de flux sans l'enregistrer (validation du formulaire).
     *
     * @return array{format: string, title: string, site_url: ?string, description: ?string, items: list<array<string, mixed>>}
     */
    public function probe(string $url, int $timeout = 10): array
    {
        $response = $this->fetcher->fetch($url, null, null, $timeout);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Réponse HTTP ' . $response['status'] . ' du serveur.');
        }
        return FeedParser::parse($response['body']);
    }
}
