<?php

declare(strict_types=1);

namespace Atelier\Http;

use Atelier\Support\Json;

/**
 * Réponse HTTP. Fabriques : html(), json(), redirect(), file(), empty().
 *
 * Toute réponse JSON dynamique suit l'enveloppe commune :
 *   { "ok": bool, "data": mixed, "message": string|null, "errorId": string|null, "error": {...}|null }
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    private ?string $filePath = null;
    private bool $deleteFileAfterSend = false;

    /** @var list<array{name: string, value: string, options: array<string, mixed>}> */
    private array $cookies = [];

    public function __construct(private string $body = '', private int $status = 200)
    {
    }

    public static function html(string $body, int $status = 200): self
    {
        return (new self($body, $status))->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public static function text(string $body, int $status = 200): self
    {
        return (new self($body, $status))->withHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public static function raw(string $body, string $contentType, int $status = 200): self
    {
        return (new self($body, $status))->withHeader('Content-Type', $contentType);
    }

    /**
     * Enveloppe JSON de succès.
     */
    public static function json(mixed $data = null, ?string $message = null, int $status = 200): self
    {
        return self::jsonRaw([
            'ok' => true,
            'data' => $data,
            'message' => $message,
            'errorId' => null,
            'error' => null,
        ], $status);
    }

    /**
     * Enveloppe JSON d'erreur.
     *
     * @param array<string, mixed> $error détails normalisés : type, fields, resource, module...
     */
    public static function jsonError(string $message, string $kind, int $status, ?string $errorId = null, array $error = []): self
    {
        return self::jsonRaw([
            'ok' => false,
            'data' => null,
            'message' => $message,
            'errorId' => $errorId,
            'error' => ['type' => $kind] + $error,
        ], $status);
    }

    public static function jsonRaw(mixed $payload, int $status = 200): self
    {
        return (new self(Json::encode($payload), $status))
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return (new self('', $status))->withHeader('Location', $location);
    }

    public static function empty(int $status = 204): self
    {
        return new self('', $status);
    }

    /**
     * Téléchargement d'un fichier existant, servi par PHP après contrôle des droits.
     */
    public static function file(string $path, string $downloadName, string $mime = 'application/octet-stream', bool $inline = false, bool $deleteAfterSend = false): self
    {
        $response = new self('', 200);
        $response->filePath = $path;
        $response->deleteFileAfterSend = $deleteAfterSend;
        $disposition = $inline ? 'inline' : 'attachment';
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $downloadName) ?? 'fichier';
        $ascii = str_replace(['"', '\\'], '_', $ascii);
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Content-Disposition', sprintf("%s; filename=\"%s\"; filename*=UTF-8''%s", $disposition, $ascii, rawurlencode($downloadName)))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    /** @param array<string, mixed> $options */
    public function withCookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function isJson(): bool
    {
        return str_starts_with($this->header('Content-Type') ?? '', 'application/json');
    }

    /** @return array<string, mixed> */
    public function decodedJson(): array
    {
        return Json::decodeArray($this->body);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            foreach ($this->cookies as $cookie) {
                setcookie($cookie['name'], $cookie['value'], $cookie['options']);
            }
        }
        if ($this->filePath !== null) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            readfile($this->filePath);
            if ($this->deleteFileAfterSend) {
                @unlink($this->filePath);
            }
            return;
        }
        echo $this->body;
    }
}
