<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Security\Acl\AclService;
use Atelier\Shared\TagService;
use Atelier\Testing\TestCase;

/**
 * Vues transversales (module Tags, Explorateur, éléments liés) face à la corbeille et aux
 * informations privées.
 *
 * Non-régression : le registre commun ignorait la corbeille, si bien qu'un élément supprimé
 * restait listé sous ses tags, dans l'Explorateur et dans les éléments liés, avec un lien menant
 * à une erreur 404. Et une note taguée restait introuvable par son tag, même pour son auteur.
 * Ces tests posent l'état de corbeille directement dans le registre : ils vérifient le noyau et
 * les vues transversales indépendamment du branchement propre à chaque module.
 */
final class TransversalViewsTest extends TestCase
{
    private Application $app;
    private int $alice;

    public function setUp(): void
    {
        $_SESSION = [];
        $this->app = $this->application();
        $this->app->synchronizer()->syncAll();
        $this->alice = $this->app->users->create(['username' => 'alice', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->acl->setRule('user', $this->alice, AclService::ROOT, 'admin', 'allow');
        $this->app->acl->clearCache();
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
    }

    private function headers(): array
    {
        return ['X-Atelier-Request' => 'json', 'X-CSRF-Token' => $this->app->csrf->token()];
    }

    /** @return array{status: int, content: string} */
    private function view(string $path, array $query = []): array
    {
        $response = $this->app->handle(Request::create('GET', $path, $query, [], $this->headers()));
        $json = $response->decodedJson();
        return ['status' => $response->status(), 'content' => (string) ($json['data']['content'] ?? $json['message'] ?? '')];
    }

    private function tagId(string $name): int
    {
        return (int) $this->app->shared->tags->findOrCreate($name, TagService::SHARED, $this->alice)['id'];
    }

    public function testTrashedInfoLeavesEveryTransversalViewAndComesBackOnRestore(): void
    {
        $shared = $this->app->shared;
        $page = $shared->registry->register('wiki.page', '9001', 'Page transversale', $this->alice);
        $project = $shared->registry->register('project.project', '9002', 'Projet transversal', $this->alice);
        $shared->tags->attach($page, 'transversal', TagService::SHARED, $this->alice);
        $shared->relations->relate('related', $project, $page, $this->alice);
        $tagId = $this->tagId('transversal');

        // Vivante : présente partout.
        $this->assertStringContains('Page transversale', $this->view('/m/tags/detail/' . $tagId)['content']);
        $this->assertStringContains('Page transversale', $this->view('/m/explorer/search', ['tag' => 'transversal'])['content']);
        $this->assertCount(1, $shared->relations->relationsOf($project));
        $this->assertCount(1, $shared->registry->search('transversale', ['wiki.page']));

        $shared->registry->trash('wiki.page', '9001');
        $this->assertTrue($shared->registry->isTrashed('wiki.page', '9001'));

        // En corbeille : absente des vues, mais rien n'est effacé.
        $this->assertFalse(str_contains($this->view('/m/tags/detail/' . $tagId)['content'], 'Page transversale'), 'module Tags');
        $this->assertFalse(str_contains($this->view('/m/explorer/search', ['tag' => 'transversal'])['content'], 'Page transversale'), 'recherche de l’Explorateur');
        $detail = $this->view('/m/explorer/info/' . $page);
        $this->assertSame(404, $detail['status'], 'fiche de l’Explorateur');
        $this->assertStringContains('en corbeille', $detail['content']);
        $this->assertCount(0, $shared->relations->relationsOf($project), 'éléments liés');
        $this->assertSame(0, $shared->relations->countFor($project));
        $this->assertCount(1, $shared->relations->relationsOf($project, true), 'la relation elle-même est conservée');
        $this->assertCount(0, $shared->registry->search('transversale', ['wiki.page']), 'sélecteurs');
        $this->assertCount(0, $shared->tags->infosWithTag($tagId));

        // Le compteur affiché tombe à zéro, mais le tag n'est pas « inutilisé » : le supprimer
        // ferait perdre son tag à la page au moment de sa restauration.
        $row = array_values(array_filter($shared->tags->all(), static fn (array $t): bool => (int) $t['id'] === $tagId))[0];
        $this->assertSame(0, (int) $row['live_count']);
        $this->assertSame(1, (int) $row['usage_count']);
        $this->app->handle(Request::create('POST', '/m/tags/delete-unused', [], [], $this->headers()));
        $this->assertNotNull($shared->tags->find($tagId), 'un tag porté par un élément en corbeille n’est pas supprimé');

        // Restauration : tout revient tel quel.
        $shared->registry->restore('wiki.page', '9001');
        $this->assertFalse($shared->registry->isTrashed('wiki.page', '9001'));
        $this->assertStringContains('Page transversale', $this->view('/m/tags/detail/' . $tagId)['content']);
        $this->assertCount(1, $shared->relations->relationsOf($project));
        $this->assertSame(200, $this->view('/m/explorer/info/' . $page)['status']);
    }

    public function testPrivateInfosAreFoundByTheirTagOnlyByTheirAuthor(): void
    {
        $shared = $this->app->shared;
        $bob = $this->app->users->create(['username' => 'bob', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $mine = $shared->registry->register('notes.note', '7001', 'Ma note taguée', $this->alice);
        $theirs = $shared->registry->register('notes.note', '7002', 'Note de Bob', $bob);
        $shared->tags->attach($mine, 'perso', TagService::SHARED, $this->alice);
        $shared->tags->attach($theirs, 'perso', TagService::SHARED, $bob);

        $content = $this->view('/m/tags/detail/' . $this->tagId('perso'))['content'];
        $this->assertStringContains('Ma note taguée', $content, 'l’auteur retrouve sa note par son tag');
        $this->assertFalse(str_contains($content, 'Note de Bob'), 'jamais la note d’un autre');

        // L'Explorateur reste le navigateur des seules données partagées.
        $this->assertFalse(str_contains($this->view('/m/explorer/search', ['tag' => 'perso'])['content'], 'Ma note taguée'));
    }
}
