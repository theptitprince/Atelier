<?php

declare(strict_types=1);

namespace Atelier\Modules\Wiki;

use Atelier\Modules\ModuleContext;
use Atelier\Support\Str;
use Atelier\View\BbCode;

/**
 * Rendu d'une page : BBCode commun du noyau, complété par la syntaxe wiki :
 *   [[Titre ou slug]] / [[cible|Libellé]]      lien interne (rouge si la page n'existe pas encore)
 *   [file=ID] / [file=ID|Libellé] / [file=ID|Libellé|largeur]   image (affichée) ou fichier joint (lien)
 *   [point=ID] / [point=ID|Libellé]            point GPS du module geo (fiche et carte)
 * Les compléments sont remplacés par des jetons avant le rendu BBCode (qui échappe tout HTML)
 * puis réinjectés : aucun HTML saisi n'est jamais rendu tel quel.
 */
final class WikiRenderer
{
    private const TOKEN = '%%WIKI%d%%';

    public function __construct(private readonly ModuleContext $ctx, private readonly WikiRepository $repository)
    {
    }

    /** Cibles des liens internes d'un contenu, sous forme de slugs (pour les rétroliens). @return list<string> */
    public static function linkTargets(string $content): array
    {
        preg_match_all('/\[\[([^\]|]+?)(?:\|[^\]]*)?\]\]/u', $content, $matches);
        $slugs = [];
        foreach ($matches[1] as $target) {
            $slugs[] = WikiRepository::slugify(trim($target));
        }
        return array_values(array_unique($slugs));
    }

    public function toHtml(?string $content): string
    {
        $content = (string) $content;
        if (trim($content) === '') {
            return '';
        }
        $tokens = [];
        $content = preg_replace_callback('/\[\[([^\]|]+?)(?:\|([^\]]*))?\]\]/u', function (array $m) use (&$tokens): string {
            $tokens[] = $this->pageLink(trim($m[1]), isset($m[2]) ? trim($m[2]) : '');
            return sprintf(self::TOKEN, count($tokens) - 1);
        }, $content) ?? $content;
        $content = preg_replace_callback('/\[file=([0-9a-f]{32})(?:\|([^\]|]*))?(?:\|(\d{2,4}))?\]/i', function (array $m) use (&$tokens): string {
            $tokens[] = $this->fileEmbed(strtolower($m[1]), trim($m[2] ?? ''), isset($m[3]) ? (int) $m[3] : null);
            return sprintf(self::TOKEN, count($tokens) - 1);
        }, $content) ?? $content;
        $content = preg_replace_callback('/\[point=(\d{1,10})(?:\|([^\]]*))?\]/', function (array $m) use (&$tokens): string {
            $tokens[] = $this->pointLink((int) $m[1], trim($m[2] ?? ''));
            return sprintf(self::TOKEN, count($tokens) - 1);
        }, $content) ?? $content;

        $html = BbCode::toHtml($content);
        foreach ($tokens as $i => $replacement) {
            $html = str_replace(sprintf(self::TOKEN, $i), $replacement, $html);
        }
        return $html;
    }

    private function pageLink(string $target, string $label): string
    {
        $slug = WikiRepository::slugify($target);
        $page = $this->repository->findBySlug($slug) ?? $this->repository->findByTitle($target);
        if ($page !== null) {
            return '<a href="#" class="wiki__link" data-route="show/' . Str::e($page['slug']) . '">' . Str::e($label !== '' ? $label : $page['title']) . '</a>';
        }
        return '<a href="#" class="wiki__link wiki__link--missing" data-route="new?title=' . rawurlencode($target) . '" title="Page à créer : ' . Str::e($target) . '">' . Str::e($label !== '' ? $label : $target) . '</a>';
    }

    private function fileEmbed(string $id, string $label, ?int $width): string
    {
        $file = $this->ctx->shared->attachments->find($id);
        $base = $this->ctx->baseUrl() . '/files/' . $id;
        if ($file === null) {
            return '<span class="wiki__missing-file" title="Pièce jointe introuvable">[fichier ' . Str::e(substr($id, 0, 8)) . '… absent]</span>';
        }
        $name = $label !== '' ? $label : (string) $file['original_name'];
        if (str_starts_with((string) $file['mime'], 'image/')) {
            $style = $width !== null ? ' style="max-width:' . max(40, min(2000, $width)) . 'px"' : '';
            return '<a href="' . Str::e($base . '?inline=1') . '" target="_blank" rel="noopener noreferrer" class="wiki__figure"><img class="wiki__image" src="' . Str::e($base . '?inline=1') . '" alt="' . Str::e($name) . '" loading="lazy"' . $style . '></a>';
        }
        return '<a href="' . Str::e($base) . '" download class="wiki__file"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-paperclip"></use></svg> ' . Str::e($name) . ' <span class="text-muted text-small">(' . Str::e(Str::humanSize((int) $file['size'])) . ')</span></a>';
    }

    private function pointLink(int $id, string $label): string
    {
        try {
            $point = $this->ctx->moduleService('geo')->find($id);
        } catch (\Throwable) {
            $point = null;
        }
        if ($point === null) {
            return '<span class="wiki__missing-file">[point GPS n° ' . $id . ' indisponible]</span>';
        }
        $text = $label !== '' ? $label : $point['label'];
        return '<a href="#" class="wiki__point" data-open-module="geo" data-open-route="show/' . $id . '" title="' . Str::e($point['dms']) . '"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-map-pin"></use></svg> ' . Str::e($text) . '</a>';
    }
}
