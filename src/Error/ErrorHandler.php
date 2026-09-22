<?php

declare(strict_types=1);

namespace Atelier\Error;

use Atelier\Activity\ActivityLog;
use Atelier\Http\Request;
use Atelier\Http\Response;
use Atelier\Logging\Logger;
use Atelier\Persistence\QueryException;
use Atelier\Support\Str;
use Atelier\View\Template;
use Throwable;

/**
 * Traitement homogène des erreurs : classification, journalisation avec référence d'incident,
 * réponse JSON (requêtes dynamiques) ou HTML (navigation directe), sans fuite d'information
 * technique hors mode débogage.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly Logger $logger,
        private readonly Template $template,
        private readonly bool $debug,
        private readonly ?ActivityLog $activity = null,
        private readonly string $baseUrl = '',
    ) {
    }

    public function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', $this->debug ? '1' : '0');
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    /**
     * Convertit une exception en réponse HTTP.
     */
    public function handle(Throwable $e, Request $request): Response
    {
        [$kind, $status, $message, $payload, $errorId] = $this->classify($e);

        if ($request->wantsJson() || $request->isAtelierRequest()) {
            $error = $payload;
            if ($this->debug) {
                $error['debug'] = $this->debugInfo($e);
            }
            return Response::jsonError($message, $kind, $status, $errorId, $error);
        }

        if ($kind === 'auth') {
            $target = $this->baseUrl . '/login';
            if ($request->path() !== '/' && $request->isGet()) {
                $target .= '?next=' . rawurlencode($request->path());
            }
            if (!empty($payload['expired'])) {
                $target .= (str_contains($target, '?') ? '&' : '?') . 'expired=1';
            }
            return Response::redirect($target);
        }

        $html = $this->template->render('core::error', [
            'kind' => $kind,
            'status' => $status,
            'message' => $message,
            'errorId' => $errorId,
            'debug' => $this->debug ? $this->debugInfo($e) : null,
            'baseUrl' => $this->baseUrl,
        ]);
        return Response::html($html, $status);
    }

    /**
     * @return array{0: string, 1: int, 2: string, 3: array<string, mixed>, 4: string|null}
     */
    public function classify(Throwable $e): array
    {
        if ($e instanceof AtelierException) {
            $errorId = null;
            if ($e->kind() === 'server' || $e->kind() === 'unavailable') {
                $errorId = Str::errorReference();
                $this->logger->exception($e, $errorId);
            } elseif ($e->kind() === 'forbidden') {
                $this->activity?->record('core', 'access.denied', ActivityLog::DENIED, $e->payload()['resource'] ?? null, $e->getMessage(), $e->payload());
            }
            return [$e->kind(), $e->httpStatus(), $e->getMessage(), $e->payload(), $errorId];
        }

        $errorId = Str::errorReference();
        $this->logger->exception($e, $errorId, $e instanceof QueryException ? ['sql' => $e->sql(), 'params' => $e->params()] : []);
        $this->activity?->record('core', 'error.server', ActivityLog::ERROR, null, $e::class . ': ' . Str::truncate($e->getMessage(), 200), [], $errorId);

        if ($e instanceof QueryException && $e->isConstraintViolation()) {
            return ['conflict', 409, 'L’opération viole une contrainte d’intégrité (doublon ou référence utilisée).', [], $errorId];
        }
        return ['server', 500, 'Une erreur technique est survenue. Référence : ' . $errorId, [], $errorId];
    }

    /** @return array<string, mixed> */
    private function debugInfo(Throwable $e): array
    {
        $info = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 30),
        ];
        if ($e instanceof QueryException) {
            $info['sql'] = $e->sql();
        }
        if ($e->getPrevious() !== null) {
            $info['previous'] = $e->getPrevious()::class . ': ' . $e->getPrevious()->getMessage();
        }
        return $info;
    }
}
