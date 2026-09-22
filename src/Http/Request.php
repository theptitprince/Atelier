<?php

declare(strict_types=1);

namespace Atelier\Http;

use Atelier\Support\Json;

/**
 * Représentation immuable de la requête HTTP entrante.
 *
 * Le client Atelier envoie l'en-tête X-Atelier-Request: json pour toute requête dynamique ;
 * son absence indique une navigation directe du navigateur (page complète attendue).
 */
final class Request
{
    /** @var array<string, mixed>|null corps JSON décodé à la demande */
    private ?array $jsonBody = null;
    private bool $jsonParsed = false;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, mixed> $cookies
     * @param array<string, string> $headers en minuscules
     * @param array<string, mixed> $server
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $post,
        private readonly array $files,
        private readonly array $cookies,
        private readonly array $headers,
        private readonly array $server,
        private readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(string $basePath = ''): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }
        $path = '/' . ltrim($path, '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key])) {
                $headers[$name] = (string) $_SERVER[$key];
            }
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $rawBody = '';
        if ($method !== 'GET' && $method !== 'HEAD') {
            $rawBody = (string) file_get_contents('php://input');
        }

        return new self($method, $path, $_GET, $_POST, $_FILES, $_COOKIE, $headers, $_SERVER, $rawBody);
    }

    /**
     * Fabrique de test.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    public static function create(string $method, string $path, array $query = [], array $post = [], array $headers = [], string $rawBody = ''): self
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }
        return new self(strtoupper($method), '/' . ltrim($path, '/'), $query, $post, [], [], $normalized, ['REMOTE_ADDR' => '127.0.0.1'], $rawBody);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isGet(): bool
    {
        return $this->method === 'GET' || $this->method === 'HEAD';
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isWrite(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return list<string> segments non vides du chemin */
    public function segments(): array
    {
        return array_values(array_filter(explode('/', $this->path), static fn (string $s): bool => $s !== ''));
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** Requête dynamique émise par le client Atelier (fetch). */
    public function isAtelierRequest(): bool
    {
        return $this->header('x-atelier-request') !== null;
    }

    public function wantsJson(): bool
    {
        if ($this->isAtelierRequest()) {
            return true;
        }
        $accept = $this->header('accept', '');
        return $accept !== null && str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function allQuery(): array
    {
        return $this->query;
    }

    /**
     * Valeur d'entrée : corps JSON prioritaire, puis champs de formulaire, puis query string.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();
        if ($json !== null && array_key_exists($key, $json)) {
            return $json[$key];
        }
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        return $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->input($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key);
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /** @return array<mixed> */
    public function arrayInput(string $key): array
    {
        $value = $this->input($key, []);
        return is_array($value) ? $value : [];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->post, $this->json() ?? []);
    }

    /** @return array<string, mixed>|null */
    public function json(): ?array
    {
        if (!$this->jsonParsed) {
            $this->jsonParsed = true;
            $type = $this->header('content-type', '') ?? '';
            if ($this->rawBody !== '' && str_contains($type, 'application/json')) {
                try {
                    $decoded = Json::decode($this->rawBody);
                    $this->jsonBody = is_array($decoded) ? $decoded : null;
                } catch (\Throwable) {
                    $this->jsonBody = null;
                }
            }
        }
        return $this->jsonBody;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /** @return array<string, mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) && isset($file['tmp_name']) && $file['tmp_name'] !== '' ? $file : null;
    }

    /** @return array<string, mixed> */
    public function files(): array
    {
        return $this->files;
    }

    public function cookie(string $name): ?string
    {
        $value = $this->cookies[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return $this->header('user-agent', '') ?? '';
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }
        return strtolower($this->header('x-forwarded-proto', '') ?? '') === 'https';
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }
}
