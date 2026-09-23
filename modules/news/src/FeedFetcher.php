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
    public const MAX_REDIRECTS = 3;

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
        self::target($url, false);
    }

    /**
     * Valide une URL et détermine l'adresse réellement jointe.
     *
     * Les écritures numériques d'une IPv4 (décimale « 2130706433 », hexadécimale « 0x7f000001 »,
     * octale « 0177.0.0.1 », abrégée « 127.1 ») sont ramenées à leur forme canonique avant
     * contrôle puis refusées : curl les joint parfaitement alors que le garde-fou portait sur une
     * chaîne qui n'était pas reconnue comme une IP. Les noms sont résolus et *toutes* les adresses
     * obtenues sont contrôlées ; l'adresse retenue est ensuite épinglée côté curl (CURLOPT_RESOLVE)
     * pour que la connexion vise exactement ce qui a été validé (DNS-rebinding).
     *
     * @return array{host: string, port: int, ip: ?string, literal: bool}
     */
    private static function target(string $url, bool $mustResolve): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('L’adresse du flux doit être une URL http ou https valide.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Les identifiants dans l’adresse ne sont pas acceptés.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new InvalidArgumentException('Les adresses internes ne sont pas autorisées.');
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $literal = self::literalIp($host);
        if ($literal !== null) {
            self::assertPublicIp($literal);
            if ($literal !== trim($host, '[]')) {
                throw new InvalidArgumentException('L’adresse du flux doit utiliser un nom d’hôte ou une adresse IP en notation usuelle.');
            }
            return ['host' => $host, 'port' => $port, 'ip' => $literal, 'literal' => true];
        }
        $addresses = self::resolveHost($host);
        foreach ($addresses as $address) {
            self::assertPublicIp($address);
        }
        if ($addresses === [] && $mustResolve) {
            throw new RuntimeException('Récupération impossible : l’hôte « ' . $host . ' » est introuvable.');
        }
        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0] ?? null, 'literal' => false];
    }

    /**
     * Forme canonique d'une IP littérale, y compris les écritures numériques d'une IPv4.
     * Retourne null si l'hôte est un nom.
     */
    private static function literalIp(string $host): ?string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $inner = substr($host, 1, -1);
            return filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? $inner : null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        return self::numericIpv4($host);
    }

    /** « 2130706433 », « 0x7f000001 », « 0177.0.0.1 », « 127.1 » → « 127.0.0.1 ». Null si ce n'est pas une IPv4. */
    private static function numericIpv4(string $host): ?string
    {
        if ($host === '' || preg_match('/^[0-9a-fx.]+$/i', $host) !== 1) {
            return null;
        }
        $parts = explode('.', $host);
        if (count($parts) > 4) {
            return null;
        }
        $values = [];
        foreach ($parts as $part) {
            if (preg_match('/^0[xX][0-9a-fA-F]{1,8}$/', $part) === 1) {
                $values[] = (int) hexdec(substr($part, 2));
            } elseif (preg_match('/^0[0-7]{1,11}$/', $part) === 1) {
                $values[] = (int) octdec($part);
            } elseif (preg_match('/^[0-9]{1,10}$/', $part) === 1) {
                $values[] = (int) $part;
            } else {
                return null;
            }
        }
        $last = (int) array_pop($values);
        $limit = [0xFFFFFFFF, 0xFFFFFF, 0xFFFF, 0xFF][count($values)];
        if ($last < 0 || $last > $limit) {
            return null;
        }
        $address = $last;
        foreach ($values as $index => $value) {
            if ($value > 0xFF) {
                return null;
            }
            $address += $value << (8 * (3 - $index));
        }
        return long2ip($address);
    }

    /**
     * Adresses IPv4/IPv6 d'un nom d'hôte. Tableau vide si la résolution échoue.
     *
     * @return list<string>
     */
    private static function resolveHost(string $host): array
    {
        $addresses = [];
        $records = function_exists('dns_get_record') ? @dns_get_record($host, DNS_A | DNS_AAAA) : false;
        foreach (is_array($records) ? $records : [] as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (isset($record[$key]) && filter_var((string) $record[$key], FILTER_VALIDATE_IP) !== false) {
                    $addresses[] = (string) $record[$key];
                }
            }
        }
        if ($addresses === []) {
            $list = @gethostbynamel($host);
            foreach (is_array($list) ? $list : [] as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    $addresses[] = $address;
                }
            }
        }
        return array_values(array_unique($addresses));
    }

    private static function assertPublicIp(string $ip): void
    {
        // Une IPv4 encapsulée dans une IPv6 (::ffff:127.0.0.1, ::ffff:7f00:1, ::1) doit être jugée
        // sur son IPv4 : les filtres de PHP ne couvrent pas toutes ces écritures.
        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10))) {
            $marker = substr($packed, 10, 2);
            if ($marker === "\xff\xff" || $marker === "\0\0") {
                $ip = (string) inet_ntop(substr($packed, 12));
            }
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidArgumentException('Les adresses internes ne sont pas autorisées.');
        }
    }

    /**
     * Suit les redirections à la main : chaque saut repasse par les contrôles, alors que
     * CURLOPT_FOLLOWLOCATION laissait une redirection publique aboutir sur le réseau interne.
     *
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function curl(string $url, array $headers, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('L’extension PHP curl est requise pour récupérer les flux.');
        }
        for ($hop = 0; ; $hop++) {
            $response = $this->curlOnce($url, $headers, $timeout);
            $location = trim($response['headers']['location'] ?? '');
            if ($hop >= self::MAX_REDIRECTS || $location === '' || !in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                return $response;
            }
            $url = self::absoluteUrl($url, $location);
            self::assertSafeUrl($url);
        }
    }

    /** Résout une cible de redirection (absolue, relative au protocole, à la racine ou au chemin). */
    private static function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $scheme = (string) ($parts['scheme'] ?? 'http');
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        $authority = (string) ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $authority . $location;
        }
        $path = (string) ($parts['path'] ?? '/');
        $slash = strrpos($path, '/');
        $directory = $slash === false ? '/' : substr($path, 0, $slash + 1);
        return $scheme . '://' . $authority . $directory . $location;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function curlOnce(string $url, array $headers, int $timeout): array
    {
        $target = self::target($url, true);
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
        if (!$target['literal'] && $target['ip'] !== null) {
            // L'adresse validée est épinglée : curl ne refait pas la résolution, donc une seconde
            // réponse DNS pointant vers le réseau interne reste sans effet.
            $address = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
            curl_setopt($handle, CURLOPT_RESOLVE, [$target['host'] . ':' . $target['port'] . ':' . $address]);
        }
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
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
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // nouvelle réponse (100 Continue) : on ne garde que la dernière
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
