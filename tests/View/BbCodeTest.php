<?php

declare(strict_types=1);

namespace Atelier\Tests\View;

use Atelier\Testing\TestCase;
use Atelier\View\BbCode;

/**
 * Rendu BBCode : conversions courantes, et surtout filtrage des URL. Le composant JavaScript
 * d'aperçu (public/assets/js/atelier.js, module « bbcode ») doit produire exactement les mêmes
 * chaînes : toute évolution d'un côté se reporte de l'autre.
 */
final class BbCodeTest extends TestCase
{
    public function testEscapesEverythingAndConvertsSimpleTags(): void
    {
        $this->assertSame('', BbCode::toHtml(null));
        $this->assertSame('', BbCode::toHtml("  \n "));
        $this->assertSame(
            '<div class="bb">L&apos;été &amp; &lt;script&gt;alert(1)&lt;/script&gt; <strong>gras</strong> <em>ital</em></div>',
            BbCode::toHtml("L'été & <script>alert(1)</script> [b]gras[/b] [i]ital[/i]")
        );
        $this->assertSame('<div class="bb"><h2>Titre</h2>texte</div>', BbCode::toHtml("[h1]Titre[/h1]\ntexte"));
        $this->assertSame(
            '<div class="bb"><ul class="bb-list"><li>un &amp; deux</li><li>trois</li></ul></div>',
            BbCode::toHtml("[list]\n[*] un & deux\n[*] trois\n[/list]")
        );
    }

    /**
     * Non-régression : l'expression régulière excluait « & », donc une URL à plusieurs paramètres
     * (échappée en « &amp; » avant conversion) n'était pas reconnue et le [url=…] restait affiché brut.
     */
    public function testUrlWithAmpersandBecomesALink(): void
    {
        $this->assertSame(
            '<div class="bb"><a href="https://ex.fr/a?b=1&amp;c=2" rel="noopener" target="_blank">texte</a></div>',
            BbCode::toHtml('[url=https://ex.fr/a?b=1&c=2]texte[/url]')
        );
        $this->assertSame(
            '<div class="bb"><a href="https://ex.fr/a?b=1&amp;c=2&amp;d=3#ancre" rel="noopener" target="_blank">multi</a></div>',
            BbCode::toHtml('[url=https://ex.fr/a?b=1&c=2&d=3#ancre]multi[/url]')
        );
        // Forme sans libellé (auto-lien) et chemin interne.
        $this->assertSame(
            '<div class="bb"><a href="https://ex.fr/a?b=1&amp;c=2" rel="noopener" target="_blank">https://ex.fr/a?b=1&amp;c=2</a></div>',
            BbCode::toHtml('[url]https://ex.fr/a?b=1&c=2[/url]')
        );
        $this->assertSame(
            '<div class="bb"><a href="/interne?x=1&amp;y=2" rel="noopener" target="_blank">relatif</a></div>',
            BbCode::toHtml('[url=/interne?x=1&y=2]relatif[/url]')
        );
        // L'URL peut être entourée de guillemets dans la source.
        $this->assertSame(
            '<div class="bb"><a href="https://ex.fr/a?b=1&amp;c=2" rel="noopener" target="_blank">guillemets</a></div>',
            BbCode::toHtml('[url="https://ex.fr/a?b=1&c=2"]guillemets[/url]')
        );
    }

    /**
     * Même limite que pour les URL : la capture du nom d'auteur excluait « & », si bien qu'une
     * citation attribuée à « Dupont & Fils » restait affichée en texte brut.
     */
    public function testQuoteAuthorMayContainAnAmpersand(): void
    {
        $this->assertSame(
            '<div class="bb"><blockquote class="bb-quote"><cite>Dupont &amp; Fils</cite>Le texte cité.</blockquote></div>',
            BbCode::toHtml('[quote=Dupont & Fils]Le texte cité.[/quote]')
        );
        // Le nom est échappé avant la conversion : il ne peut pas rouvrir de balise.
        $this->assertSame(
            '<div class="bb"><blockquote class="bb-quote"><cite>&lt;b&gt;x&lt;/b&gt;</cite>Corps.</blockquote></div>',
            BbCode::toHtml('[quote=<b>x</b>]Corps.[/quote]')
        );
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/atelier.js');
        $this->assertTrue(str_contains($js, '\\[quote=(?:&quot;)?([^\\]]{1,80}?)(?:&quot;)?\\]'), 'aperçu client aligné sur le serveur');
    }

    /** Le filtrage reste entier : une URL refusée ne laisse que le libellé, jamais de balise. */
    public function testDangerousUrlsAreRejected(): void
    {
        $refused = [
            '[url=javascript:alert(1)]xss[/url]' => 'xss',
            '[url=javascript:alert(1)&x=2]xss[/url]' => 'xss',                 // avec esperluette
            '[url=JavaScript:alert(1)]xss[/url]' => 'xss',                     // casse mélangée
            '[url=https://ex.fr/"onmouseover="alert(1)]guillemet[/url]' => 'guillemet',
            '[url=https://ex.fr/a b]espace[/url]' => 'espace',
            '[url=//evil.fr/a]protocole[/url]' => 'protocole',                 // relatif au protocole
            '[url=data:text/html;base64,PHM=]data[/url]' => 'data',
            '[url=https://ex.fr/<script>]balise[/url]' => 'balise',
        ];
        foreach ($refused as $source => $expected) {
            $html = BbCode::toHtml($source);
            $this->assertSame('<div class="bb">' . $expected . '</div>', $html, $source);
            $this->assertFalse(str_contains($html, '<a '), 'aucun lien pour ' . $source);
        }
        // Forme sans libellé : le texte refusé reste affiché tel quel, échappé.
        $this->assertSame('<div class="bb">javascript:alert(1)</div>', BbCode::toHtml('[url]javascript:alert(1)[/url]'));
    }

    /**
     * Garde-fou de parité : l'aperçu client doit appliquer les mêmes règles d'URL et le même
     * échappement que le serveur. Sans cela, l'aperçu affiche un lien que le rendu enregistré
     * n'affichera pas (ou l'inverse).
     */
    public function testClientPreviewSharesTheSameUrlRules(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/atelier.js');
        $this->assertTrue(str_contains($js, '\\[url=(?:&quot;)?([^\\]]+?)(?:&quot;)?\\]'), 'même capture d’URL que le serveur');
        $this->assertFalse(str_contains($js, '[^\\]\\s&]+?'), 'l’ancienne capture qui s’arrêtait à « & » a disparu');
        $this->assertTrue(str_contains($js, "replace(/'/g, '&apos;')"), 'apostrophe échappée comme htmlspecialchars(ENT_HTML5)');
        $this->assertTrue(str_contains($js, "/[\\s\"'<>]/.test(url)"), 'mêmes caractères refusés dans une URL');
        $this->assertTrue(str_contains($js, "url.startsWith('/') && !url.startsWith('//')"), 'mêmes chemins internes acceptés');
    }

    public function testToTextStripsTags(): void
    {
        $this->assertSame('', BbCode::toText(null));
        $this->assertSame('un lien vers ex.fr', BbCode::toText('[b]un[/b] [url=https://ex.fr/a?b=1&c=2]lien[/url] vers ex.fr'));
        $this->assertSame('• un • deux', BbCode::toText('[list][*]un[*]deux[/list]'));
    }
}
