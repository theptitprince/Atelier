<?php
/**
 * Profil : informations du compte, modification du nom affiché / email, changement de mot de passe.
 * @var array<string, mixed> $user
 * @var list<array<string, mixed>> $groups
 * @var string $lastLogin
 * @var string $passwordChangedAt
 * @var string $createdAt
 * @var bool $mustChangePassword
 */
?>
<div class="module module-profile">
    <div class="profile__grid">
        <section class="card profile__account" aria-labelledby="profile-account-title">
            <div class="card__header">
                <h2 class="card__title" id="profile-account-title">
                    <svg class="icon" aria-hidden="true"><use href="#i-user"></use></svg> Informations du compte
                </h2>
                <span class="badge badge--<?= $user['status'] === 'active' ? 'success' : 'muted' ?>"><?= $user['status'] === 'active' ? 'Actif' : $e($user['status']) ?></span>
            </div>
            <div class="card__body">
                <dl class="dl">
                    <dt>Identifiant</dt>
                    <dd><code><?= $e($user['username']) ?></code></dd>

                    <dt>Nom affiché</dt>
                    <dd><?= $e($user['display_name']) ?></dd>

                    <dt>Email</dt>
                    <dd><?= $user['email'] !== null && $user['email'] !== '' ? $e($user['email']) : '<span class="text-muted">Non renseigné</span>' ?></dd>

                    <dt>Groupes</dt>
                    <dd>
                        <?php if ($groups === []): ?>
                            <span class="text-muted">Aucun groupe</span>
                        <?php else: ?>
                            <span class="chips">
                                <?php foreach ($groups as $group): ?>
                                    <span class="chip" title="<?= $e($group['description'] ?? '') ?>"><?= $e($group['label']) ?></span>
                                <?php endforeach; ?>
                            </span>
                        <?php endif; ?>
                    </dd>

                    <dt>Compte créé le</dt>
                    <dd><?= $e($createdAt) ?></dd>

                    <dt>Dernière connexion</dt>
                    <dd><?= $e($lastLogin) ?></dd>

                    <dt>Mot de passe modifié le</dt>
                    <dd><?= $e($passwordChangedAt) ?></dd>
                </dl>
            </div>
        </section>

        <section class="card" aria-labelledby="profile-edit-title">
            <div class="card__header">
                <h2 class="card__title" id="profile-edit-title">
                    <svg class="icon" aria-hidden="true"><use href="#i-edit"></use></svg> Modifier mes informations
                </h2>
            </div>
            <form class="card__body" data-action="save" data-track-dirty data-save-shortcut novalidate>
                <div class="field">
                    <label class="field__label" for="profile-display-name">Nom affiché <span class="required" aria-hidden="true">*</span></label>
                    <input class="input" type="text" id="profile-display-name" name="display_name" value="<?= $e($user['display_name']) ?>" maxlength="100" required autocomplete="name">
                    <span class="field__help">Affiché dans la colonne de gauche, le journal et les modules collaboratifs.</span>
                    <span class="field__error"></span>
                </div>
                <div class="field">
                    <label class="field__label" for="profile-email">Email</label>
                    <input class="input" type="email" id="profile-email" name="email" value="<?= $e($user['email'] ?? '') ?>" maxlength="190" autocomplete="email" placeholder="prenom.nom@exemple.fr">
                    <span class="field__help">Facultatif. Utilisé par les modules qui envoient des notifications.</span>
                    <span class="field__error"></span>
                </div>
                <div class="form-actions form-actions--end">
                    <button type="submit" class="btn btn--primary">
                        <svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg> Enregistrer
                    </button>
                </div>
            </form>
        </section>

        <section class="card" aria-labelledby="profile-password-title">
            <div class="card__header">
                <h2 class="card__title" id="profile-password-title">
                    <svg class="icon" aria-hidden="true"><use href="#i-key"></use></svg> Changer le mot de passe
                </h2>
            </div>
            <form class="card__body" data-action="password" autocomplete="off" novalidate>
                <?php if ($mustChangePassword): ?>
                    <div class="alert alert--warning" role="status">
                        <svg class="icon" aria-hidden="true"><use href="#i-warning"></use></svg>
                        <div>Votre mot de passe est temporaire : vous devez le modifier.</div>
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label class="field__label" for="profile-current-password">Mot de passe actuel</label>
                    <input class="input" type="password" id="profile-current-password" name="current_password" autocomplete="current-password"<?= $mustChangePassword ? '' : ' required' ?>>
                    <span class="field__error"></span>
                </div>
                <div class="field">
                    <label class="field__label" for="profile-password">Nouveau mot de passe</label>
                    <input class="input" type="password" id="profile-password" name="password" autocomplete="new-password" required>
                    <span class="field__help">Respectez la politique de mots de passe en vigueur ; le nouveau mot de passe doit différer de l’actuel.</span>
                    <span class="field__error"></span>
                </div>
                <div class="field">
                    <label class="field__label" for="profile-password-confirmation">Confirmation</label>
                    <input class="input" type="password" id="profile-password-confirmation" name="password_confirmation" autocomplete="new-password" required>
                    <span class="field__error"></span>
                </div>
                <div class="form-actions form-actions--end">
                    <button type="submit" class="btn btn--primary">
                        <svg class="icon" aria-hidden="true"><use href="#i-key"></use></svg> Modifier le mot de passe
                    </button>
                </div>
            </form>
        </section>
    </div>
</div>
