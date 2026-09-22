<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Modules\News\ArticleExtractor;
use Atelier\Testing\TestCase;

final class NewsArticleExtractorTest extends TestCase
{
    public function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/modules/news/src/ArticleExtractor.php';
    }

    public function testExtractsMainTextAndDropsChrome(): void
    {
        $html = <<<HTML
<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Un article — Journal</title>
<meta property="og:title" content="Un article"><meta property="og:image" content="https://exemple.fr/img.jpg">
<script>var x = "pas moi";</script><style>.a{}</style></head>
<body>
<header><nav><a href="/">Accueil</a> <a href="/rubrique">Rubrique</a> Menu principal avec beaucoup de texte de navigation inutile pour le lecteur.</nav></header>
<div class="sidebar"><p>Encart publicitaire long qui ne doit surtout pas apparaître dans le texte extrait de cet article de test.</p></div>
<article>
<h1>Un article</h1>
<p>Premier paragraphe de l’article, suffisamment long pour compter dans le score de densité du bloc principal.</p>
<p>Deuxième paragraphe avec un <a href="x">lien</a> et du <b>gras</b>, lui aussi assez long pour être retenu dans l’extraction.</p>
<h2>Sous-titre</h2>
<ul><li>Premier point d’une liste à puces</li><li>Second point</li></ul>
<div class="share">Partager sur les réseaux sociaux</div>
</article>
<footer><p>Mentions légales, contact, plan du site et autres informations de pied de page très longues.</p></footer>
</body></html>
HTML;
        $article = ArticleExtractor::extract($html);
        $this->assertSame('Un article', $article['title']);
        $this->assertSame('https://exemple.fr/img.jpg', $article['image']);
        $text = $article['text'];
        $this->assertStringContains("## Un article\n\nPremier paragraphe", $text);
        $this->assertStringContains('lien et du gras', $text);
        $this->assertStringContains("## Sous-titre\n\n• Premier point", $text);
        $this->assertFalse(str_contains($text, 'pas moi'));
        $this->assertFalse(str_contains($text, 'Menu principal'));
        $this->assertFalse(str_contains($text, 'publicitaire'));
        $this->assertFalse(str_contains($text, 'Partager'));
        $this->assertFalse(str_contains($text, 'Mentions légales'));
        $this->assertFalse(str_contains($text, '<'));
    }

    public function testLatin1PageIsConverted(): void
    {
        $html = mb_convert_encoding('<html><head><meta charset="iso-8859-1"><title>T</title></head><body><main><p>Été à Nîmes : un paragraphe assez long pour être conservé par l’extracteur de contenu.</p></main></body></html>', 'ISO-8859-1', 'UTF-8');
        $article = ArticleExtractor::extract($html);
        $this->assertStringContains('Été à Nîmes', $article['text']);
    }
}
