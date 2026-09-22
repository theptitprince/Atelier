<?php
/**
 * Création / édition d'un compte utilisateur.
 * @var array $user @var bool $isNew @var list<array> $groups @var list<int> $memberGroupIds @var array<string, bool> $rights
 * @var string|null $generatedPassword @var int $passwordMinLength @var string $tab @var int $currentUserId
 * @var bool $isRootAdmin @var string $rightsHtml
 * @var \Atelier\Modules\Users\UsersModule $module
 */
use Atelier\Modules\Users\UserPresenter;

$isRootAdmin ??= false;
$rightsHtml ??= '';
$showRights = !$isNew && $rights['admin'];
$isSelf = !$isNew && (int) $user['id'] === $currentUserId;
?>
<div class="module module-users">
    <div data-password-slot></div>

    <?php if ($showRights): ?>
        <nav class="subtabs" data-subtabs="users-edit-panels" role="tablist" aria-label="Sections de la fiche">
            <button type="button" class="subtabs__tab" role="tab" data-subtab="account" aria-selected="<?= $tab === 'account' ? 'true' : 'false' ?>">Compte</button>
            <button type="button" class="subtabs__tab" role="tab" data-subtab="rights" aria-selected="<?= $tab === 'rights' ? 'true' : 'false' ?>">Droits</button>
        </nav>
    <?php endif; ?>

    <div id="users-edit-panels">
    <div data-subtab-panel="account"<?= $tab !== 'account' ? ' hidden' : '' ?>>
        <?php if ($isSelf): ?>
            <div class="alert alert--info"><svg class="icon" aria-hidden="true"><use href="#i-info"></use></svg><div>Vous modifiez votre propre compte : la désactivation et la suppression sont désactivées ici.</div></div>
        <?php endif; ?>
        <?php if (!$isNew && $isRootAdmin): ?>
            <div class="alert alert--warning"><svg class="icon" aria-hidden="true"><use href="#i-shield"></use></svg><div>Ce compte dispose du droit d’administration sur toute l’application. Le noyau refuse toute modification qui laisserait l’application sans administrateur actif.</div></div>
        <?php endif; ?>

        <div class="users-form-layout">
        <form class="card users-form" data-action="save" data-track-dirty data-save-shortcut autocomplete="off" novalidate>
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-user"></use></svg><?= $isNew ? 'Nouveau compte' : 'Identité du compte' ?></h2></div>
            <div class="card__body">
                <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><?php endif; ?>
                <div class="form-grid">
                    <div class="field">
                        <label class="field__label" for="u-username">Identifiant <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="u-username" name="username" value="<?= $e($user['username']) ?>" required maxlength="64" pattern="[a-zA-Z0-9._\-]{2,64}" autocomplete="off" spellcheck="false"<?= $isNew ? ' autofocus' : '' ?>>
                        <span class="field__help">2 à 64 caractères : lettres, chiffres, point, tiret, souligné. Unique.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="u-display">Nom affiché <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="u-display" name="display_name" value="<?= $e($user['display_name']) ?>" required maxlength="120">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="u-email">Courriel</label>
                        <input class="input" id="u-email" name="email" type="email" value="<?= $e($user['email'] ?? '') ?>" maxlength="190" placeholder="facultatif">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="u-status">État</label>
                        <select class="select" id="u-status" name="status"<?= $isSelf ? ' disabled' : '' ?>>
                            <option value="active"<?= $user['status'] === 'active' ? ' selected' : '' ?>>Actif</option>
                            <option value="disabled"<?= $user['status'] === 'disabled' ? ' selected' : '' ?>>Désactivé</option>
                        </select>
                        <?php if ($isSelf): ?><input type="hidden" name="status" value="<?= $e($user['status']) ?>"><?php endif; ?>
                        <span class="field__error"></span>
                    </div>
                </div>

                <fieldset class="field">
                    <legend>Groupes</legend>
                    <?php if ($groups === []): ?>
                        <p class="text-muted mb-0">Aucun groupe défini.</p>
                    <?php else: ?>
                        <div class="users-groups-grid">
                            <?php foreach ($groups as $group): ?>
                                <label class="checkbox" title="<?= $e($group['description'] ?? '') ?>">
                                    <input type="checkbox" name="groups[]" value="<?= (int) $group['id'] ?>"<?= in_array((int) $group['id'], $memberGroupIds, true) ? ' checked' : '' ?>>
                                    <span><?= $e($group['label']) ?> <span class="text-muted">(<?= $e($group['name']) ?>)</span><?= (int) $group['is_system'] === 1 ? ' <span class="badge badge--muted">système</span>' : '' ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <span class="field__error"></span>
                </fieldset>

                <?php if ($isNew): ?>
                    <fieldset>
                        <legend>Mot de passe initial</legend>
                        <div class="field">
                            <label class="field__label" for="u-password">Mot de passe temporaire</label>
                            <div class="input-group">
                                <input class="input mono" id="u-password" name="password" type="text" value="<?= $e($generatedPassword ?? '') ?>" minlength="<?= (int) $passwordMinLength ?>" maxlength="200" autocomplete="new-password" spellcheck="false" data-password-field>
                                <button type="button" class="btn" data-generate-password title="Générer un autre mot de passe"><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg><span>Générer</span></button>
                                <button type="button" class="btn" data-copy-password title="Copier dans le presse-papiers"><svg class="icon" aria-hidden="true"><use href="#i-copy"></use></svg></button>
                            </div>
                            <span class="field__help">Au moins <?= (int) $passwordMinLength ?> caractères. Laissez vide pour qu’un mot de passe temporaire soit généré à l’enregistrement (il sera affiché une seule fois). Transmettez-le par un canal sûr.</span>
                            <span class="field__error"></span>
                        </div>
                        <label class="checkbox">
                            <input type="checkbox" name="must_change_password" value="1"<?= (int) $user['must_change_password'] === 1 ? ' checked' : '' ?>>
                            <span>Obliger le changement de mot de passe à la première connexion</span>
                        </label>
                    </fieldset>
                <?php endif; ?>
            </div>
            <div class="card__footer form-actions form-actions--end mt-0">
                <a class="btn btn--ghost" href="#" data-route="list">Annuler</a>
                <button type="submit" class="btn btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg><span><?= $isNew ? 'Créer le compte' : 'Enregistrer' ?></span></button>
            </div>
        </form>

        <?php if (!$isNew): ?>
            <aside class="card users-meta">
                <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-info"></use></svg>Informations</h2></div>
                <div class="card__body">
                    <dl class="dl">
                        <dt>État</dt><dd><?= UserPresenter::statusBadges($user) ?></dd>
                        <dt>Créé le</dt><dd><?= $e($datetime($user['created_at'])) ?></dd>
                        <dt>Modifié le</dt><dd><?= $e($datetime($user['updated_at'])) ?></dd>
                        <dt>Dernière connexion</dt><dd><?= $e($datetime($user['last_login_at']) ?: 'jamais') ?></dd>
                        <dt>Mot de passe changé le</dt><dd><?= $e($datetime($user['password_changed_at']) ?: 'jamais (mot de passe initial)') ?></dd>
                        <dt>Échecs de connexion</dt><dd><?= (int) $user['failed_attempts'] ?></dd>
                        <?php if (!empty($user['locked_until'])): ?><dt>Bloqué jusqu’à</dt><dd><?= $e($datetime($user['locked_until'])) ?></dd><?php endif; ?>
                        <?php if (!empty($user['disabled_at'])): ?><dt>Désactivé le</dt><dd><?= $e($datetime($user['disabled_at'])) ?></dd><?php endif; ?>
                    </dl>
                </div>
                <?php if ($rights['update']): ?>
                    <div class="card__footer">
                        <p class="text-muted text-small mb-0">Le bouton « Réinitialiser le mot de passe » du bandeau génère un mot de passe temporaire à transmettre à la personne ; elle devra le changer à sa prochaine connexion.</p>
                    </div>
                <?php endif; ?>
            </aside>
        <?php endif; ?>
        </div>
    </div>

    <?php if ($showRights): ?>
        <div data-subtab-panel="rights"<?= $tab !== 'rights' ? ' hidden' : '' ?>>
            <?= $rightsHtml ?>
        </div>
    <?php endif; ?>
    </div>
</div>
