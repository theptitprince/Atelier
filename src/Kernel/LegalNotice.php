<?php

/*
 * Atelier, par ETDEL — https://github.com/theptitprince/Atelier
 * Copyright (C) 2026 ETDEL — licence GPL-3.0-or-later
 * Conditions additionnelles (article 7) : voir le fichier NOTICE.
 */

declare(strict_types=1);

namespace Atelier\Kernel;

use Atelier\Support\Str;

/**
 * Mentions légales d'Atelier : mention d'attribution exigée par les conditions additionnelles
 * de la licence (article 7 de la GPL v3, fichier NOTICE), copyright, licence, absence de
 * garantie et éléments tiers. Elles s'affichent sur la page de connexion, dans la fenêtre
 * « À propos » (barre latérale), dans l'aide de la console et dans les paramètres généraux.
 *
 * Ces textes sont écrits en dur : ils ne dépendent jamais du nom affiché de l'application, que
 * la configuration (app.name, ATELIER_APP_NAME) ou le paramètre app_display_name peuvent changer.
 */
final class LegalNotice
{
    /** Mention à conserver, intacte et visible, dans toute copie ou version modifiée. */
    public const MENTION = 'Atelier, par ETDEL — https://github.com/theptitprince/Atelier';
    public const REPOSITORY_URL = 'https://github.com/theptitprince/Atelier';
    public const COPYRIGHT = 'Copyright © 2026 ETDEL';
    public const LICENSE = 'GPL-3.0-or-later';
    public const LICENSE_URL = 'https://www.gnu.org/licenses/gpl-3.0.html';
    public const NOTICE_URL = 'https://github.com/theptitprince/Atelier/blob/main/NOTICE';

    /**
     * Éléments tiers : ils gardent leur propre licence, les conditions additionnelles ne s'appliquent pas à eux.
     *
     * @var list<array{0: string, 1: string, 2: string}> [nom, usage, licence et mentions]
     */
    public const THIRD_PARTY = [
        ['Leaflet 1.9.4', 'module Carte', 'licence BSD-2-Clause, © 2010-2023 Volodymyr Agafonkin, © 2010-2011 CloudMade (texte dans modules/map/assets/vendor/leaflet/LICENSE)'],
        ['mini-sparkline 1.0.0', 'module Démonstration', 'licence MIT'],
        ['Fonds de carte OpenStreetMap et OpenSeaMap', 'module Carte, chargés depuis leurs serveurs', 'données © contributeurs OpenStreetMap sous licence ODbL, OpenSeaMap sous licence CC BY-SA ; mentions affichées sur la carte'],
    ];

    /** Ligne courte : la mention, la licence et l'absence de garantie (console, paramètres généraux). */
    public static function line(): string
    {
        return self::MENTION . ' · ' . self::LICENSE . ' · sans garantie';
    }

    /** La même ligne en HTML échappé, l'adresse du dépôt devenant un lien (page de connexion). */
    public static function lineHtml(): string
    {
        return self::mentionHtml() . ' · ' . Str::e(self::LICENSE) . ' · sans garantie';
    }

    /**
     * Contenu HTML, échappé, de la fenêtre « À propos ». Le texte du fichier NOTICE est repris
     * tel quel lorsqu'il est présent à la racine de l'installation.
     */
    public static function html(string $rootPath): string
    {
        $html = '<p class="about__mention"><strong>' . self::mentionHtml() . "</strong></p>\n";
        $html .= '<p>' . Str::e(self::COPYRIGHT) . "</p>\n";
        $html .= '<p>Atelier est un logiciel libre : vous pouvez le redistribuer et le modifier selon les termes de la '
            . 'GNU General Public License publiée par la Free Software Foundation, version 3 de la licence ou '
            . '(à votre choix) toute version ultérieure (' . Str::e(self::LICENSE) . '), avec des conditions '
            . "additionnelles (article 7).</p>\n";
        $html .= "<p><strong>Ce programme est fourni SANS AUCUNE GARANTIE</strong>, dans les limites permises par la loi.</p>\n";

        $html .= "<h3>Licence et conditions additionnelles</h3>\n<ul>\n";
        $html .= '<li>Texte de la licence : fichier <code>LICENSE</code> des sources et ' . self::link(self::LICENSE_URL) . ".</li>\n";
        $html .= '<li>Conditions additionnelles (article 7) : fichier <code>NOTICE</code> des sources et ' . self::link(self::NOTICE_URL) . ".</li>\n";
        $html .= '<li><strong>Attribution</strong> : toute copie ou version modifiée doit conserver, intacte et visible, '
            . "la mention ci-dessus dans les mentions légales qu’elle affiche et dans sa documentation.</li>\n";
        $html .= '<li><strong>Origine</strong> : une version modifiée doit le dire et ne pas se présenter comme '
            . "l’original, ni comme approuvée par ETDEL.</li>\n</ul>\n";

        $html .= "<h3>Éléments tiers</h3>\n";
        $html .= "<p>Ils gardent leur propre licence ; les conditions additionnelles ne s’appliquent pas à eux.</p>\n<ul>\n";
        foreach (self::THIRD_PARTY as [$name, $usage, $licence]) {
            $html .= '<li><strong>' . Str::e($name) . '</strong> (' . Str::e($usage) . ') : ' . Str::e($licence) . ".</li>\n";
        }
        $html .= "</ul>\n";

        $notice = $rootPath . '/NOTICE';
        if (is_file($notice)) {
            $text = str_replace("\r\n", "\n", (string) file_get_contents($notice));
            $html .= '<details class="about__notice"><summary>Texte complet du fichier NOTICE</summary><pre>'
                . Str::e(rtrim($text)) . "</pre></details>\n";
        }
        return $html;
    }

    /** La mention en HTML échappé, l'adresse du dépôt devenant un lien. */
    private static function mentionHtml(): string
    {
        return str_replace(Str::e(self::REPOSITORY_URL), self::link(self::REPOSITORY_URL), Str::e(self::MENTION));
    }

    /** Lien externe ouvert dans un nouvel onglet, l'adresse servant de libellé. */
    private static function link(string $url): string
    {
        return '<a href="' . Str::e($url) . '" target="_blank" rel="noopener noreferrer">' . Str::e($url) . '</a>';
    }
}
