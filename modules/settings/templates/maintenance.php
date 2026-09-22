<?php
/** @var array<string, string> $indicators @var array<string, string> $retentions */
?>
<div class="module module-settings">
    <div class="split split--wide">
        <div class="card">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-activity"></use></svg> Indicateurs</h2></div>
            <div class="card__body">
                <dl class="dl">
                    <?php foreach ($indicators as $label => $value): ?>
                        <dt><?= $e($label) ?></dt><dd><?= $e($value) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </div>
        <div>
            <div class="card">
                <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Rétentions</h2></div>
                <div class="card__body">
                    <dl class="dl mb-3">
                        <?php foreach ($retentions as $label => $value): ?>
                            <dt><?= $e($label) ?></dt><dd><?= $e($value) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                    <p class="text-muted text-small">Supprime les entrées du journal d’activité, les journaux techniques et les pièces jointes en corbeille au-delà des durées ci-dessus. Les modules appliquent leurs propres rétentions via la console (<code>maintenance:purge</code>).</p>
                    <button type="button" class="btn btn--outline-danger" data-action="purge" data-confirm="Appliquer les rétentions maintenant ? Les données purgées ne sont pas récupérables." data-danger>
                        <svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Appliquer les rétentions
                    </button>
                </div>
            </div>
            <div class="card">
                <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> Manifestes des modules</h2></div>
                <div class="card__body">
                    <p class="text-muted text-small">Force la resynchronisation des ressources, permissions et du catalogue au prochain chargement complet de l’interface.</p>
                    <button type="button" class="btn" data-action="clear-cache">
                        <svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> Vider le cache des manifestes
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
