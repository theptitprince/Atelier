<?php

declare(strict_types=1);

namespace Atelier\Tests\View;

use Atelier\Testing\TestCase;

/**
 * Garde-fous sur le balisage construit par le client (public/assets/js/atelier.js). Ces contrôles
 * sont volontairement textuels : ils ne remplacent pas un essai dans le navigateur, mais ils
 * empêchent le retour de deux défauts d'accessibilité repérés en audit.
 */
final class ClientMarkupTest extends TestCase
{
    private function client(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/atelier.js');
    }

    /**
     * Non-régression : l'onglet était un <button> contenant un <span role="button"> de fermeture,
     * soit du contenu interactif imbriqué (HTML invalide) ; le lecteur d'écran n'annonçait qu'un
     * seul contrôle et la fermeture n'était plus exposée.
     */
    public function testTabIsNotAButtonContainingAnotherControl(): void
    {
        $js = $this->client();
        $this->assertTrue(str_contains($js, "util.el('div', { class: 'tab', id: 'tab-' + id, role: 'tab'"), 'onglet = <div role="tab">');
        $this->assertFalse(str_contains($js, "util.el('button', { type: 'button', class: 'tab', id: 'tab-'"), 'plus de <button class="tab">');
        $this->assertTrue(str_contains($js, "util.el('button', { type: 'button', class: 'tab__close'"), 'fermeture = vrai <button>');
        $this->assertFalse(str_contains($js, "class: 'tab__close', role: 'button'"), 'plus de faux bouton');
    }

    /**
     * Non-régression : role="treeitem" et aria-expanded étaient portés par le <li>, alors que
     * l'élément focusable est la ligne <a class="nav__row"> ; l'état déplié ou replié n'était
     * donc jamais annoncé. Le chevron n'est plus un <button> dans un <a>.
     */
    public function testTreeRoleIsOnTheFocusableRow(): void
    {
        $js = $this->client();
        $this->assertSame(2, substr_count($js, "class: 'nav__row', role: 'treeitem'"), 'module et entrée : rôle sur la ligne');
        $this->assertTrue(str_contains($js, "row.setAttribute('aria-expanded'"), 'état déplié porté par la ligne');
        $this->assertFalse(str_contains($js, "class: 'nav__item nav__item--child', role: 'treeitem'"), 'plus de rôle sur le <li>');
        $this->assertFalse(str_contains($js, "class: 'nav__item nav__item--module', role: 'treeitem'"), 'plus de rôle sur le <li>');
        $this->assertFalse(str_contains($js, "util.el('button', { type: 'button', class: 'nav__chevron'"), 'plus de <button> dans le <a>');
    }

    /** La feuille de style suit le déplacement des attributs, sinon l'onglet courant ne se voit plus. */
    public function testStylesheetFollowsTheNewAttributes(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/atelier.css');
        $this->assertTrue(str_contains($css, '.nav__row[aria-current]'), 'ligne courante mise en avant');
        $this->assertTrue(str_contains($css, '.nav__row[aria-expanded="true"] > .nav__chevron'), 'chevron pivoté selon la ligne');
    }

    /**
     * Non-régression : les arbres rendus côté serveur portaient le même défaut que la colonne,
     * le rôle et l'état étant posés sur le <li> au lieu de l'élément focalisable. L'arbre des
     * droits est le cas réel, la vitrine du module Démonstration sert de référence aux modules.
     */
    public function testServerRenderedTreesCarryTheRoleOnTheFocusableElement(): void
    {
        $root = dirname(__DIR__, 2);
        $acl = (string) file_get_contents($root . '/modules/users/templates/acl.php');
        $this->assertTrue(str_contains($acl, '<a class="users-tree__label" role="treeitem"'), 'le lien porte le rôle');
        $this->assertFalse(str_contains($acl, 'data-path="\' . $e($path) . \'" role="treeitem"'), 'plus de rôle sur le <li>');
        $this->assertTrue(str_contains($acl, '" role="none">'), '<li> et ligne neutralisés');

        $users = (string) file_get_contents($root . '/modules/users/assets/users.js');
        $this->assertTrue(str_contains($users, ':scope > .tree__row > [role="treeitem"]'), 'l’état est écrit sur le treeitem');
        $this->assertFalse(str_contains($users, "li.setAttribute('aria-expanded'"), 'plus d’état sur le <li>');

        $demo = (string) file_get_contents($root . '/modules/demo/templates/components.php');
        $this->assertTrue(str_contains($demo, '<ul class="tree" role="tree"'), 'la vitrine déclare un arbre');
        $this->assertTrue(str_contains($demo, '<span role="treeitem" aria-expanded="true" tabindex="0">'), 'un seul élément atteignable au clavier');
    }
}
