<?php
/** @var array $definitions @var array $values @var list<string> $timezones @var list<int> $pageSizes @var array $readonly @var bool $canEdit */
?>
<div class="module module-settings">
    <div class="split split--wide">
        <div class="card">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-sliders"></use></svg> Paramètres dynamiques</h2></div>
            <div class="card__body">
                <?php if (!$canEdit): ?>
                    <div class="alert alert--info"><svg class="icon" aria-hidden="true"><use href="#i-info"></use></svg><div>Consultation seule : la modification des paramètres requiert le droit d’administration.</div></div>
                <?php endif; ?>
                <form data-action="save" data-track-dirty data-save-shortcut novalidate>
                    <?php foreach ($definitions as $name => [$type, $default, $label, $help]): $value = $values[$name]; ?>
                        <div class="field">
                            <label class="field__label" for="setting-<?= $e($name) ?>"><?= $e($label) ?></label>
                            <?php if ($type === 'text'): ?>
                                <textarea class="textarea" id="setting-<?= $e($name) ?>" name="<?= $e($name) ?>" rows="3" maxlength="2000"<?= $canEdit ? '' : ' disabled' ?>><?= $e($value) ?></textarea>
                            <?php elseif ($type === 'timezone'): ?>
                                <select class="select" id="setting-<?= $e($name) ?>" name="<?= $e($name) ?>"<?= $canEdit ? '' : ' disabled' ?>>
                                    <?php foreach ($timezones as $tz): ?>
                                        <option value="<?= $e($tz) ?>"<?= $tz === $value ? ' selected' : '' ?>><?= $e($tz) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($type === 'choice'): ?>
                                <select class="select" id="setting-<?= $e($name) ?>" name="<?= $e($name) ?>"<?= $canEdit ? '' : ' disabled' ?>>
                                    <?php foreach ($pageSizes as $size): ?>
                                        <option value="<?= $size ?>"<?= (int) $value === $size ? ' selected' : '' ?>><?= $size ?> lignes</option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($type === 'int'): ?>
                                <input class="input" type="number" id="setting-<?= $e($name) ?>" name="<?= $e($name) ?>" value="<?= $e($value) ?>" min="1" max="<?= $name === 'activity_retention_months' ? 60 : 365 ?>"<?= $canEdit ? '' : ' disabled' ?>>
                            <?php else: ?>
                                <input class="input" type="text" id="setting-<?= $e($name) ?>" name="<?= $e($name) ?>" value="<?= $e($value) ?>" maxlength="100"<?= $canEdit ? '' : ' disabled' ?>>
                            <?php endif; ?>
                            <span class="field__help"><?= $e($help) ?></span>
                            <span class="field__error"></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($canEdit): ?>
                        <div class="form-actions">
                            <button type="submit" class="btn btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg> Enregistrer</button>
                            <span class="text-muted text-small">Ctrl+S enregistre également.</span>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-database"></use></svg> Configuration de l’environnement</h2></div>
            <div class="card__body">
                <p class="text-muted text-small">Valeurs issues des fichiers de configuration (<code>config/app.php</code>, <code>config/env.local.php</code>) et des variables <code>ATELIER_*</code>. Elles se modifient sur le serveur, pas depuis l’application.</p>
                <dl class="dl">
                    <?php foreach ($readonly as $label => $value): ?>
                        <dt><?= $e($label) ?></dt><dd class="mono text-small"><?= $e($value) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </div>
    </div>
</div>
