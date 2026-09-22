<?php
/**
 * Cas d'erreur : chaque exception du noyau déclenchée volontairement, route inexistante, module
 * inexistant, action réservée, session expirée, route brute.
 * Variables : $isAdmin (bool), $canExecuteSecret (bool), $secretResource (string), $module, $baseUrl, $e.
 */
$icon = static fn (string $name, string $cls = ''): string => '<svg class="icon' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
$cases = [
    ['error/validation', 'ValidationException', '422', 'validation', 'Erreurs par champ ; sans formulaire autour du bouton, seul le message général est affiché (toast avertissement).'],
    ['error/forbidden', 'ForbiddenException', '403', 'forbidden', 'Connecté mais sans le droit requis. Le refus est journalisé par le noyau.'],
    ['error/not-found', 'NotFoundException', '404', 'not_found', 'Ressource introuvable.'],
    ['error/conflict', 'ConflictException', '409', 'conflict', 'Donnée modifiée entre-temps ou contrainte violée.'],
    ['error/unavailable', 'ModuleUnavailableException', '503', 'unavailable', 'Module inactif, en maintenance ou en erreur ; une référence d’incident est générée.'],
    ['error/service', 'moduleService(\'inexistant\')', '503', 'unavailable', 'Appel réel de l’API intermodule vers un module absent : exception levée par le noyau lui-même.'],
];
?>
<div class="module module-demo">
    <div class="alert alert--info"><?= $icon('info') ?><div>Chaque bouton provoque une erreur <strong>attendue</strong>. Le noyau normalise la réponse (<code>{ ok: false, error: { type }, errorId }</code>), affiche un toast au bon niveau et, pour une vue, remplace le contenu par un bloc d’état avec « Réessayer » et « Fermer l’onglet ».</div></div>

    <div class="split split--wide">
        <div>
            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('error') ?> Exceptions déclenchées par une action</h3></div>
                <div class="card__body card__body--flush">
                    <table class="table">
                        <thead><tr><th>Exception</th><th>HTTP</th><th><code>error.type</code></th><th>Effet</th><th class="col-actions"></th></tr></thead>
                        <tbody>
                        <?php foreach ($cases as [$route, $label, $status, $type, $effect]): ?>
                            <tr>
                                <td><code><?= $e($label) ?></code></td>
                                <td><span class="badge badge--muted mono"><?= $e($status) ?></span></td>
                                <td><code><?= $e($type) ?></code></td>
                                <td class="text-small text-muted"><?= $e($effect) ?></td>
                                <td class="col-actions"><button type="button" class="btn btn--sm" data-action="<?= $e($route) ?>">Déclencher</button></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td><code>\LogicException</code> (PHP brute)</td>
                            <td><span class="badge badge--muted mono">500</span></td>
                            <td><code>server</code></td>
                            <td class="text-small text-muted">Message générique + référence d’incident ; détail dans <code>var/logs</code>. Route réservée à <code>admin</code>.</td>
                            <td class="col-actions">
                                <?php if ($isAdmin): ?>
                                    <button type="button" class="btn btn--sm btn--outline-danger" data-action="error/server">Déclencher</button>
                                <?php else: ?>
                                    <button type="button" class="btn btn--sm" data-action="error/server" title="Sans la permission admin, le noyau répond 403 avant d’exécuter l’action">Tester (403 attendu)</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('edit') ?> Validation avec champs</h3></div>
                <div class="card__body">
                    <p class="text-small text-muted">La même action, dans un formulaire qui possède les champs <code>name</code> et <code>email</code> : les erreurs s’affichent près des champs et dans un résumé.</p>
                    <form data-action="error/validation" novalidate>
                        <div class="form-grid">
                            <div class="field"><label class="field__label" for="err-name">Nom</label><input class="input" id="err-name" name="name" type="text" value="Sera refusé"><span class="field__error"></span></div>
                            <div class="field"><label class="field__label" for="err-email">E-mail</label><input class="input" id="err-email" name="email" type="email" value="pas-un-email"><span class="field__error"></span></div>
                        </div>
                        <div class="form-actions"><button type="submit" class="btn btn--primary">Soumettre (échec garanti)</button></div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('key') ?> Action réservée <code>action/secret</code></h3></div>
                <div class="card__body">
                    <p class="text-small text-muted">Ressource ACL <code><?= $e($secretResource) ?></code> déclarée dans <code>resources[]</code> du manifeste, permission <code>execute</code>. Le noyau vérifie le droit <strong>avant</strong> d’appeler le gestionnaire ; le bouton est toujours affiché ici, volontairement, pour montrer que masquer un bouton n’est jamais suffisant.</p>
                    <div class="flex flex--wrap">
                        <button type="button" class="btn btn--primary" data-action="secret"><?= $icon('key') ?> Exécuter l’action réservée</button>
                        <span>Votre droit actuel : <?= $canExecuteSecret ? '<span class="badge badge--success">execute accordé</span>' : '<span class="badge badge--danger">execute refusé → 403</span>' ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('eye') ?> Erreurs sur une vue</h3></div>
                <div class="card__body">
                    <ul class="list">
                        <li class="list__item">
                            <span class="grow"><strong>Route inexistante</strong> dans ce module (<code>data-route="nexistepas"</code>) → 404, bloc « Introuvable ».</span>
                            <a class="btn btn--sm" href="#" data-route="nexistepas">Ouvrir</a>
                        </li>
                        <li class="list__item">
                            <span class="grow"><strong>Module inexistant</strong> (<code>data-open-module="inexistant"</code>) → nouvel onglet en erreur « indisponible ».</span>
                            <a class="btn btn--sm" href="#" data-open-module="inexistant">Ouvrir</a>
                        </li>
                        <li class="list__item">
                            <span class="grow"><strong>Erreur serveur sur une vue</strong> (<code>errors/server</code>, permission <code>admin</code>) → 500, bloc « Erreur technique » avec référence.</span>
                            <?php if ($isAdmin): ?>
                                <a class="btn btn--sm btn--outline-danger" href="#" data-route="errors/server">Ouvrir</a>
                            <?php else: ?>
                                <a class="btn btn--sm" href="#" data-route="errors/server" title="Vous n’êtes pas administrateur : 403 « Accès refusé » attendu">Ouvrir (403)</a>
                            <?php endif; ?>
                        </li>
                        <li class="list__item">
                            <span class="grow"><strong>Vue avec paramètre invalide</strong> : <code>shared?item=999999</code> → NotFoundException levée par le gestionnaire.</span>
                            <a class="btn btn--sm" href="#" data-route="shared?item=999999">Ouvrir</a>
                        </li>
                    </ul>
                    <p class="text-small text-muted mt-3 mb-0">Après une erreur de vue, utilisez « Réessayer », la colonne de gauche ou le bouton Précédent du navigateur pour revenir.</p>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('lock') ?> Session expirée</h3></div>
                <div class="card__body">
                    <p class="text-small">Le noyau répond <code>401</code> (<code>error.type = auth</code>) et le client ouvre une fenêtre non fermable « Session expirée » qui ramène à la connexion puis sur la vue courante. Pour la simuler :</p>
                    <ol class="text-small">
                        <li>Ouvrez les outils de développement (F12) → Application → Cookies, et supprimez le cookie de session d’Atelier ; ou connectez-vous dans un autre navigateur privé puis déconnectez-vous-y.</li>
                        <li>Revenez ici et cliquez sur n’importe quel bouton (par exemple <button type="button" class="btn btn--sm" data-action="notify" data-params='{"level": "ok"}'>celui-ci</button>).</li>
                        <li>La fenêtre « Session expirée » apparaît ; après reconnexion, vous revenez sur <code>/m/demo/errors</code>.</li>
                    </ol>
                    <p class="text-small text-muted mb-0">Le client avertit aussi deux minutes avant l’expiration par inactivité (toast avec « Rester connecté »).</p>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('download') ?> Route brute</h3></div>
                <div class="card__body">
                    <p class="text-small text-muted">Une route <code>raw</code> renvoie une <code>Response</code> complète (ici un fichier texte). Le lien porte l’attribut <code>download</code> : le noyau ne l’intercepte pas et le navigateur télécharge.</p>
                    <div class="flex flex--wrap">
                        <a class="btn" href="<?= $e($module->url('sample.txt')) ?>" download><?= $icon('download') ?> Télécharger atelier-demo.txt</a>
                        <a class="btn btn--ghost" href="<?= $e($module->url('sample.txt')) ?>" target="_blank" rel="noopener"><?= $icon('external') ?> Ouvrir dans un nouvel onglet</a>
                        <a class="btn btn--ghost" href="<?= $e($module->url('export.csv')) ?>" download><?= $icon('download') ?> Export CSV complet</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
