<?php

declare(strict_types=1);

namespace Atelier\Modules\News;

use InvalidArgumentException;
use RuntimeException;

/**
 * Récupération HTTP d'un flux : délai borné, taille limitée, redirections limitées, requêtes
 * conditionnelles (ETag / Last-Modified) et garde-fou contre les adresses internes (SSRF).
 * Le transport peut être remplacé (tests, autre client HTTP).
 */
final class FeedFetcher
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const USER_AGENT = 'Atelier/1.0 (+veille RSS)';

    /** @var (callable(string, array<string, string>, int): array{status: int, headers: array<string, string>, body: string})|null */
    private $transport;

    /**
     * @param (callable(string, array<string, string>, int): array{status: int, headers: array<string, string>, body: string})|null $transport
     * @param string|null $caFile fichier de certificats racines (PEM) utilisé si PHP n'en configure aucun (curl.cainfo)
     */
    public function __construct(?callable $transport = null, private readonly ?string $caFile = null)
    {
        $this->transport = $transport;
    }

    /**
     * @return array{status: int, body: string, contentType: string, etag: ?string, lastModified: ?string}
     */
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null, int $timeout = 10, ?string $accept = null): array
    {
        self::assertSafeUrl($url);
        $headers = ['Accept' => $accept ?? 'application/rss+xml, application/atom+xml, application/feed+json, application/json, application/xml, text/xml;q=0.9, */*;q=0.5'];
        if ($etag !== null && $etag !== '') {
            $headers['If-None-Match'] = $etag;
        }
        if ($lastModified !== null && $lastModified !== '') {
            $headers['If-Modified-Since'] = $lastModified;
        }
        $response = $this->transport !== null ? ($this->transport)($url, $headers, $timeout) : $this->curl($url, $headers, $timeout);
        $responseHeaders = array_change_key_case($response['headers'], CASE_LOWER);
        return [
            'status' => (int) $response['status'],
            'body' => (string) $response['body'],
            'contentType' => (string) ($responseHeaders['content-type'] ?? ''),
            'etag' => isset($responseHeaders['etag']) ? (string) $responseHeaders['etag'] : null,
            'lastModified' => isset($responseHeaders['last-modified']) ? (string) $responseHeaders['last-modified'] : null,
        ];
    }

    /** Refuse les adresses non http(s) et celles qui visent le poste ou le réseau interne. */
    public static function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('L’adresse du flux doit être une URL http ou https valide.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Les identifiants dans l’adresse ne sont pas acceptés.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new InvalidArgumentException('Les adresses internes ne sont pas autorisées.');
        }
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            self::assertPublicIp($ip);
            return;
        }
        $resolved = gethostbyname($host);
        if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
            self::assertPublicIp($resolved);
        }
    }

    private static function assertPublicIp(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidArgumentException('Les adresses internes ne sont pas autorisées.');
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function curl(string $url, array $headers, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('L’extension PHP curl est requise pour récupérer les flux.');
        }
        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException('Initialisation de curl impossible.');
        }
        $responseHeaders = [];
        $body = '';
        $tooLarge = false;
        if ($this->caFile !== null && ini_get('curl.cainfo') === '' && is_file($this->caFile)) {
            curl_setopt($handle, CURLOPT_CAINFO, $this->caFile);
        }
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => array_map(static fn (string $k, string $v): string => $k . ': ' . $v, array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                } elseif (trim($line) === '' || str_starts_with($line, 'HTTP/')) {
                    if (str_starts_with($line, 'HTTP/')) {
                        $responseHeaders = []; // nouvelle réponse (redirection) : on repart des en-têtes de la dernière
                    }
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooLarge): int {
                $body .= $chunk;
                if (strlen($body) > self::MAX_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                return strlen($chunk);
            },
        ]);
        curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($tooLarge) {
            throw new RuntimeException('Le flux dépasse la taille maximale autorisée (' . (self::MAX_BYTES / 1024 / 1024) . ' Mo).');
        }
        if ($error !== '' && $status === 0) {
            throw new RuntimeException('Récupération impossible : ' . $error);
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
    }
}
