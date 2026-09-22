<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Error\NotFoundException;
use Atelier\Modules\RouteCollection;
use Atelier\Testing\TestCase;

final class RouteCollectionTest extends TestCase
{
    private RouteCollection $routes;

    public function setUp(): void
    {
        $this->routes = new RouteCollection();
        $this->routes->view('list', static fn () => 'list');
        $this->routes->view('edit/{id}', static fn () => 'edit', permission: 'update', resource: 'screen/edit');
        $this->routes->action('save', static fn () => 'save', permission: 'update');
        $this->routes->raw('files/{path*}', static fn () => 'files');
    }

    public function testStaticAndParameterizedMatch(): void
    {
        $match = $this->routes->match('list', 'GET');
        $this->assertSame('view', $match['route']['kind']);
        $match = $this->routes->match('edit/42', 'GET');
        $this->assertSame(['id' => '42'], $match['params']);
        $this->assertSame('screen/edit', $match['route']['resource']);
    }

    public function testCatchAllParameter(): void
    {
        $match = $this->routes->match('files/a/b/c.pdf', 'GET');
        $this->assertSame(['path' => 'a/b/c.pdf'], $match['params']);
    }

    public function testMethodMismatchAndUnknownRoute(): void
    {
        $this->assertThrows(NotFoundException::class, fn () => $this->routes->match('save', 'GET'), 'Méthode');
        $this->assertThrows(NotFoundException::class, fn () => $this->routes->match('nope', 'GET'), 'inconnue');
        $this->assertThrows(NotFoundException::class, fn () => $this->routes->match('edit/1/extra', 'GET'));
    }

    public function testHeadIsAcceptedForGetRoutes(): void
    {
        $this->assertSame('list', ($this->routes->match('list', 'HEAD')['route']['handler'])());
    }
}
