<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Error\ForbiddenException;
use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Security\Acl\AclService;
use Atelier\Support\Clock;
use Atelier\Testing\TestCase;

/**
 * Module Entretien : équipements, tâches, replanification après intervention, rappels (badge, tableau
 * de bord, iCalendar), pièces jointes, corbeille, exports et service intermodule.
 */
final class MaintenanceModuleTest extends TestCase
{
    private Application $app;
    private int $userId;

    public function setUp(): void
    {
        $_SESSION = [];
        Clock::freeze(new \DateTimeImmutable('2026-09-22 10:00:00', new \DateTimeZone('UTC')));
        $this->app = $this->application();
        $this->app->synchronizer()->syncAll();
        $this->userId = $this->app->users->create(['username' => 'alice', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
    }

    public function tearDown(): void
    {
        Clock::freeze(null);
    }

    private function headers(): array
    {
        return ['X-Atelier-Request' => 'json', 'X-CSRF-Token' => $this->app->csrf->token()];
    }

    private function allowAll(): void
    {
        $this->app->acl->setRule('user', $this->userId, AclService::module('maintenance'), 'admin', 'allow');
        $this->app->acl->clearCache();
    }

    private function post(string $route, array $data): array
    {
        $response = $this->app->handle(Request::create('POST', '/m/maintenance/' . $route, [], $data, $this->headers()));
        $json = $response->decodedJson();
        $json['_status'] = $response->status();
        return $json;
    }

    private function view(string $route, array $query = []): array
    {
        $response = $this->app->handle(Request::create('GET', '/m/maintenance/' . $route, $query, [], $this->headers()));
        $json = $response->decodedJson();
        $json['_status'] = $response->status();
        return $json;
    }

    private function createCar(): int
    {
        $result = $this->post('asset-save', ['name' => 'Voiture', 'category' => 'vehicle', 'brand' => 'Peugeot', 'model' => '308', 'identifier' => 'AB-123-CD', 'meter_unit' => 'km', 'meter_value' => '61 200', 'tags' => 'auto, famille']);
        $this->assertSame(200, $result['_status'], json_encode($result));
        return (int) $result['data']['id'];
    }

    public function testModuleIsDiscoveredAndLockedWithoutRights(): void
    {
        $descriptor = $this->app->modules->get('maintenance');
        $this->assertNotNull($descriptor);
        $this->assertTrue($descriptor->isUsable(), implode(' ', $descriptor->errors));
        $this->assertSame(403, $this->app->handle(Request::create('GET', '/m/maintenance/dashboard', [], [], $this->headers()))->status());
        foreach (['maintenance.asset', 'maintenance.job', 'maintenance.log'] as $code) {
            $this->assertNotNull($this->app->shared->catalog->findShared($code), $code . ' est catalogué comme partagé');
        }
    }

    public function testAssetLifecycleValidationAndTrash(): void
    {
        $this->allowAll();
        $id = $this->createCar();

        // Validation : nom vide, catégorie inconnue, compteur non numérique.
        $result = $this->post('asset-save', ['name' => '', 'category' => 'spaceship', 'meter_unit' => 'km', 'meter_value' => 'abc']);
        $this->assertSame(422, $result['_status']);
        $fields = $result['error']['fields'];
        $this->assertTrue(isset($fields['name']) && isset($fields['category']) && isset($fields['meter_value']));

        // Registre commun et tags.
        $info = $this->app->shared->registry->find('maintenance.asset', (string) $id);
        $this->assertNotNull($info);
        $this->assertSame('Voiture', $info['label']);
        $this->assertCount(2, $this->app->shared->tags->tagsOf((string) $info['id']));

        // Liste et fiche.
        $content = $this->view('assets', ['q' => 'peugeot'])['data']['content'];
        $this->assertStringContains('Voiture', $content);
        $this->assertStringContains('module-maintenance', $content);
        $this->assertStringContains('61 200 km', $content);
        $show = $this->view('asset/' . $id);
        $this->assertSame(200, $show['_status']);
        $this->assertStringContains('AB-123-CD', $show['data']['content']);
        $this->assertStringContains('famille', $show['data']['content']);

        // Relevé du compteur (bouton avec saisie) : avertissement si inférieur.
        $this->assertSame(200, $this->post('asset-meter', ['id' => $id, 'meter_value' => '61500'])['_status']);
        $lower = $this->post('asset-meter', ['id' => $id, 'meter_value' => '60000']);
        $this->assertSame(200, $lower['_status']);
        $this->assertStringContains('inférieur', $lower['message']);
        $this->assertSame(422, $this->post('asset-meter', ['id' => $id, 'meter_value' => '-5'])['_status']);

        // Modification conserve le compteur, met à jour le registre.
        $this->assertSame(200, $this->post('asset-save', ['id' => $id, 'name' => 'Voiture familiale', 'category' => 'vehicle', 'meter_unit' => 'km', 'meter_value' => '60000', 'tags' => 'auto'])['_status']);
        $this->assertSame('Voiture familiale', $this->app->shared->registry->find('maintenance.asset', (string) $id)['label']);
        $this->assertCount(1, $this->app->shared->tags->tagsOf((string) $info['id']));

        // Corbeille, restauration, purge.
        $this->assertSame(200, $this->post('asset-delete', ['id' => $id])['_status']);
        $this->assertSame(404, $this->view('asset/' . $id)['_status']);
        $this->assertStringContains('Voiture familiale', $this->view('trash')['data']['content']);
        $this->assertSame(200, $this->post('asset-restore', ['id' => $id])['_status']);
        $this->assertSame(200, $this->view('asset/' . $id)['_status']);
        $this->post('asset-delete', ['id' => $id]);
        $this->assertSame(200, $this->post('asset-purge', ['id' => $id])['_status']);
        $this->assertNull($this->app->shared->registry->find('maintenance.asset', (string) $id));
    }

    public function testJobSchedulingRemindersAndCompletion(): void
    {
        $this->allowAll();
        $car = $this->createCar();

        // Tâche périodique : échéances déduites (aujourd'hui + 365 j ; 61 200 + 15 000 km).
        $result = $this->post('job-save', ['asset_id' => $car, 'title' => 'Vidange', 'kind' => 'preventive', 'priority' => 'normal', 'interval_days' => '365', 'interval_meter' => '15000', 'lead_days' => '14', 'lead_meter' => '500', 'parts' => "Huile 5W30\nFiltre", 'contacts' => 'Garage Martin', 'estimated_cost' => '120,50', 'tags' => 'moteur']);
        $this->assertSame(200, $result['_status'], json_encode($result));
        $vidange = (int) $result['data']['id'];
        $card = $this->view('job/' . $vidange)['data']['content'];
        $this->assertStringContains('22/09/2027 ou 76 200 km', $card);
        $this->assertStringContains('À jour', $card);
        $this->assertStringContains('120,50 €', $card);
        $this->assertStringContains('Huile 5W30', $card);
        $this->assertStringContains('Garage Martin', $card);

        // Validation : tâche planifiée sans aucune échéance ni périodicité.
        $none = $this->post('job-save', ['asset_id' => $car, 'title' => 'Sans échéance', 'kind' => 'preventive']);
        $this->assertSame(422, $none['_status']);
        $this->assertTrue(isset($none['error']['fields']['next_due_at']));

        // Tâche en retard (échéance passée) et panne (corrective, jamais périodique).
        $ct = (int) $this->post('job-save', ['asset_id' => $car, 'title' => 'Contrôle technique', 'kind' => 'preventive', 'interval_days' => '730', 'next_due_at' => '2026-09-10', 'lead_days' => '30'])['data']['id'];
        $defect = (int) $this->post('job-save', ['asset_id' => $car, 'title' => 'Voyant moteur', 'kind' => 'corrective', 'priority' => 'urgent', 'interval_days' => '10', 'next_due_at' => '2026-09-25'])['data']['id'];
        $defectCard = $this->view('job/' . $defect)['data']['content'];
        $this->assertStringContains('Panne', $defectCard);
        $this->assertFalse(str_contains($defectCard, 'tous les 10 jours'), 'une panne ne conserve pas de périodicité');

        // Rappels : badge, tableau de bord, liste filtrée « en rappel », pannes.
        $badge = $this->app->handle(Request::create('GET', '/core/badges', [], [], $this->headers()))->decodedJson()['data']['badges']['maintenance'];
        $this->assertSame(2, $badge['count'], 'contrôle technique en retard + panne bientôt');
        $dashboard = $this->view('dashboard')['data']['content'];
        $this->assertStringContains('Contrôle technique', $dashboard);
        $this->assertStringContains('dépassée de 12 jours', $dashboard);
        $alerts = $this->view('jobs', ['state' => 'alert'])['data']['content'];
        $this->assertStringContains('Contrôle technique', $alerts);
        $this->assertFalse(str_contains($alerts, 'Vidange'), 'la vidange n’est pas en rappel');
        $this->assertTrue(strpos($alerts, 'Contrôle technique') < strpos($alerts, 'Voyant moteur'), 'les plus pressantes d’abord');
        $defects = $this->view('defects')['data']['content'];
        $this->assertStringContains('Voyant moteur', $defects);
        $this->assertFalse(str_contains($defects, 'Vidange'));

        // Réalisation de la vidange : replanification et compteur de l'équipement mis à jour.
        $done = $this->post('log-save', ['asset_id' => $car, 'job_id' => $vidange, 'done_at' => '2026-09-20', 'meter_value' => '62000', 'cost' => '135', 'performed_by' => 'Garage Martin', 'notes' => '[b]RAS[/b]']);
        $this->assertSame(200, $done['_status'], json_encode($done));
        $this->assertSame('open', $done['data']['rescheduled']['status']);
        $this->assertSame('2027-09-20', $done['data']['rescheduled']['next_due_at']);
        $this->assertSame(77000, $done['data']['rescheduled']['next_due_meter']);
        $this->assertSame('job/' . $vidange, $done['directives']['navigate']);
        $this->assertStringContains('62 000 km', $this->view('asset/' . $car)['data']['content']);
        $this->assertStringContains('20/09/2027 ou 77 000 km', $this->view('job/' . $vidange)['data']['content']);

        // Réparation de la panne : tâche clôturée, badge décrémenté.
        $repaired = $this->post('log-save', ['asset_id' => $car, 'job_id' => $defect, 'done_at' => '2026-09-22', 'meter_value' => '62010', 'title' => 'Sonde lambda remplacée']);
        $this->assertSame('closed', $repaired['data']['rescheduled']['status']);
        $this->assertStringContains('Clôturée', $this->view('job/' . $defect)['data']['content']);
        $this->assertSame(1, $this->app->handle(Request::create('GET', '/core/badges', [], [], $this->headers()))->decodedJson()['data']['badges']['maintenance']['count']);

        // Validation intervention : date future refusée, tâche d'un autre équipement refusée.
        $this->assertSame(422, $this->post('log-save', ['asset_id' => $car, 'done_at' => '2030-01-01', 'title' => 'Futur'])['_status']);
        $boiler = (int) $this->post('asset-save', ['name' => 'Chaudière', 'category' => 'heating'])['data']['id'];
        $this->assertSame(422, $this->post('log-save', ['asset_id' => $boiler, 'job_id' => $vidange, 'done_at' => '2026-09-22', 'title' => 'x'])['_status']);

        // Historique : filtres, total, export CSV ; calendrier iCalendar (permission export sur la ressource).
        $history = $this->view('history', ['asset' => $car]);
        $this->assertStringContains('Sonde lambda remplacée', $history['data']['content']);
        $this->assertStringContains('135,00 €', $history['data']['content']);
        // Le droit « admin » du module couvre l'export ; un simple lecteur en est privé.
        $reader = $this->app->users->create(['username' => 'bob', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->acl->setRule('user', $reader, AclService::module('maintenance'), 'open', 'allow');
        $this->app->acl->clearCache();
        $this->app->auth->logout();
        $this->app->auth->login('bob', 'Mot-de-passe-solide', '127.0.0.1');
        $this->assertSame(403, $this->app->handle(Request::create('GET', '/m/maintenance/export.csv', [], [], $this->headers()))->status());
        $this->assertSame(200, $this->app->handle(Request::create('GET', '/m/maintenance/history', [], [], $this->headers()))->status());
        $this->app->auth->logout();
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
        $csv = $this->app->handle(Request::create('GET', '/m/maintenance/export.csv', ['year' => 2026], [], $this->headers()));
        $this->assertSame(200, $csv->status(), $csv->body());
        $this->assertStringContains('Garage Martin', $csv->body());
        $this->assertStringContains('RAS', $csv->body());
        $ics = $this->app->handle(Request::create('GET', '/m/maintenance/reminders.ics', [], [], $this->headers()));
        $this->assertSame(200, $ics->status());
        $this->assertStringContains('BEGIN:VEVENT', $ics->body());
        $this->assertStringContains('DTSTART;VALUE=DATE:20270920', $ics->body());
        $this->assertStringContains('TRIGGER:-P14D', $ics->body());
        $this->assertFalse(str_contains($ics->body(), 'Voyant moteur'), 'les tâches clôturées ne sont pas exportées');

        // Fiche imprimable (route brute HTML).
        $print = $this->app->handle(Request::create('GET', '/m/maintenance/job/' . $vidange . '/print', [], [], $this->headers()));
        $this->assertSame(200, $print->status());
        $this->assertStringContains('<!DOCTYPE html>', $print->body());
        $this->assertStringContains('Huile 5W30', $print->body());

        // Clôture / réouverture / suppression : l'historique est conservé.
        $this->assertSame(200, $this->post('job-close', ['id' => $ct])['_status']);
        $this->assertSame(200, $this->post('job-reopen', ['id' => $ct])['_status']);
        $deleted = $this->post('job-delete', ['id' => $vidange]);
        $this->assertSame(200, $deleted['_status']);
        $this->assertStringContains('corbeille', $deleted['message']);
        // Suppression logique (1.2.0) : la tâche disparaît des listes mais reste inscrite au registre jusqu'à la purge.
        $this->assertNotNull($this->app->shared->registry->find('maintenance.job', (string) $vidange));
        $this->assertSame(404, $this->view('job/' . $vidange)['_status']);
        $this->assertFalse(str_contains($this->view('jobs')['data']['content'], 'Vidange'), 'la tâche en corbeille disparaît de la liste');
        $this->assertStringContains('Vidange', $this->view('history')['data']['content']);
        $this->assertStringContains('Vidange', $this->view('trash')['data']['content']);
        $this->assertSame(200, $this->post('trash-restore', ['id' => 'job:' . $vidange])['_status']);
        $this->assertSame(200, $this->view('job/' . $vidange)['_status']);
        $this->post('job-delete', ['id' => $vidange]);
        $this->assertSame(200, $this->post('trash-purge', ['id' => 'job:' . $vidange])['_status']);
        $this->assertNull($this->app->shared->registry->find('maintenance.job', (string) $vidange));
        $this->assertStringContains('Vidange', $this->view('history')['data']['content'], 'les interventions de la tâche purgée restent dans l’historique');
    }

    public function testAttachmentsOnAssetJobAndLog(): void
    {
        $this->allowAll();
        $car = $this->createCar();
        $job = (int) $this->post('job-save', ['asset_id' => $car, 'title' => 'Plaquettes', 'kind' => 'preventive', 'next_due_meter' => '80000'])['data']['id'];
        $log = (int) $this->post('log-save', ['asset_id' => $car, 'done_at' => '2026-09-01', 'title' => 'Pneus', 'cost' => '400'])['data']['id'];

        $upload = function (string $target, int $id, string $name): array {
            $file = tempnam(sys_get_temp_dir(), 'att');
            file_put_contents($file, 'Facture ' . $name);
            $files = ['files' => ['name' => [$name], 'type' => ['text/plain'], 'tmp_name' => [$file], 'error' => [UPLOAD_ERR_OK], 'size' => [filesize($file)]]];
            $request = new Request('POST', '/m/maintenance/attach', [], ['target' => $target, 'id' => $id, 'description' => 'Facture'], $files, [], array_change_key_case($this->headers(), CASE_LOWER), ['REMOTE_ADDR' => '127.0.0.1']);
            $response = $this->app->handle($request);
            $json = $response->decodedJson();
            $json['_status'] = $response->status();
            return $json;
        };
        $first = $upload('asset', $car, 'notice.txt');
        $this->assertSame(200, $first['_status'], json_encode($first));
        $this->assertSame(200, $upload('job', $job, 'procedure.txt')['_status']);
        $result = $upload('log', $log, 'facture-pneus.txt');
        $this->assertSame(200, $result['_status'], json_encode($result));
        $this->assertSame(422, $upload('unknown', $car, 'x.txt')['_status']);

        $this->assertStringContains('notice.txt', $this->view('asset/' . $car)['data']['content']);
        $this->assertStringContains('procedure.txt', $this->view('job/' . $job)['data']['content']);
        $this->assertStringContains('facture-pneus.txt', $this->view('log/' . $log . '/edit')['data']['content']);
        $history = $this->view('history')['data']['content'];
        $this->assertStringContains('i-paperclip', $history);

        // Téléchargement contrôlé par le noyau, puis retrait (suppression logique).
        $attachmentId = $result['data']['files'][0]['id'];
        $download = $this->app->handle(Request::create('GET', '/files/' . $attachmentId, [], [], $this->headers()));
        $this->assertSame(200, $download->status());
        $this->assertSame(200, $this->post('attachment-delete', ['id' => $attachmentId])['_status']);
        $this->assertNull($this->app->shared->attachments->find($attachmentId));

        // Une pièce jointe étrangère au module ne peut pas être retirée par lui.
        $foreign = $this->app->shared->attachments->storeContent("Fichier texte étranger au module.
", 'autre.txt', null, $this->userId);
        $this->assertSame(403, $this->post('attachment-delete', ['id' => $foreign['id']])['_status']);
    }

    public function testServiceChecksDatasetRights(): void
    {
        $this->allowAll();
        $car = $this->createCar();
        $this->post('job-save', ['asset_id' => $car, 'title' => 'Contrôle technique', 'kind' => 'preventive', 'next_due_at' => '2026-09-25', 'lead_days' => '10']);

        $context = $this->app->context(Request::create('GET', '/'));
        $this->app->modules->reset();
        $this->app->modules->discover();
        $service = $context->moduleService('maintenance');
        $this->assertSame('Voiture', $service->assetLabel($this->userId, $car));
        $this->assertCount(1, $service->assets($this->userId));
        $reminders = $service->reminders($this->userId);
        $this->assertCount(1, $reminders);
        $this->assertSame('soon', $reminders[0]['state']);
        $this->assertSame(3, $reminders[0]['days_left']);
        $this->assertSame(1, $service->reminderCount());

        $this->app->acl->setRule('user', $this->userId, AclService::module('maintenance') . '/data/job', 'read', 'deny');
        $this->app->acl->clearCache();
        $this->assertThrows(ForbiddenException::class, fn () => $service->reminders($this->userId));
        $this->assertCount(1, $service->assets($this->userId));
    }

    /**
     * Non-régression : la liste déroulante des années proposait l'année d'un équipement en corbeille,
     * alors que l'historique n'affiche rien pour cette année — filtre sans résultat.
     */
    public function testHistoryYearsIgnoreTrashedAssets(): void
    {
        $this->allowAll();
        $car = $this->createCar();
        $mower = (int) $this->post('asset-save', ['name' => 'Tondeuse', 'category' => 'garden'])['data']['id'];
        $this->post('log-save', ['asset_id' => $car, 'done_at' => '2026-03-04', 'title' => 'Vidange moteur']);
        $this->post('log-save', ['asset_id' => $mower, 'done_at' => '2019-06-08', 'title' => 'Affûtage de la lame']);
        $this->assertStringContains('<option value="2019"', $this->view('history')['data']['content']);

        // Équipement en corbeille : ses interventions quittent l'historique, l'année quitte le filtre.
        $this->assertSame(200, $this->post('asset-delete', ['id' => $mower])['_status']);
        $history = $this->view('history')['data']['content'];
        $this->assertFalse(str_contains($history, 'Affûtage de la lame'));
        $this->assertFalse(str_contains($history, '<option value="2019"'), 'une année proposée doit donner au moins un résultat');
        $this->assertStringContains('<option value="2026"', $history);
        $this->assertStringContains('Aucune intervention', $this->view('history', ['year' => 2019])['data']['content']);

        // Restauré, l'équipement rend son année au filtre.
        $this->assertSame(200, $this->post('trash-restore', ['id' => 'asset:' . $mower])['_status']);
        $this->assertStringContains('<option value="2019"', $this->view('history')['data']['content']);
    }

    public function testSeedIsIdempotent(): void
    {
        $this->allowAll();
        $context = $this->app->context(Request::create('GET', '/'));
        $module = $this->app->modules->instance('maintenance');
        $module->boot($context);
        $this->assertStringContains('2 équipements', $module->seed());
        $this->assertStringContains('déjà présents', $module->seed());
        $dashboard = $this->view('dashboard')['data']['content'];
        $this->assertStringContains('Chaudière gaz', $dashboard);
        $this->assertStringContains('Entretien annuel de la chaudière', $dashboard);
    }
}
