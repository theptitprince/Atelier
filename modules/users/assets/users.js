/*
 * Module Utilisateurs et droits — comportements JavaScript facultatifs.
 * Le HTML déclaratif du noyau (data-route, data-action, formulaires) couvre l'essentiel ;
 * ce fichier n'ajoute que :
 *   - le pliage / dépliage de l'arborescence des ressources ACL ;
 *   - la génération côté client d'un mot de passe temporaire (bouton « Générer ») ;
 *   - la copie dans le presse-papiers et l'affichage unique du mot de passe temporaire
 *     renvoyé par une action (création de compte, réinitialisation).
 * La confirmation avant de quitter une fiche modifiée est gérée par le noyau (data-track-dirty).
 */
(function () {
  'use strict';

  var ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

  /** Mot de passe lisible du même format que PasswordPolicy::generateTemporary() : 4 groupes de 4. */
  function generatePassword() {
    var bytes = new Uint32Array(16);
    window.crypto.getRandomValues(bytes);
    var groups = [];
    for (var g = 0; g < 4; g++) {
      var group = '';
      for (var i = 0; i < 4; i++) {
        group += ALPHABET[bytes[g * 4 + i] % ALPHABET.length];
      }
      groups.push(group);
    }
    return groups.join('-');
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var area = document.createElement('textarea');
      area.value = text;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.opacity = '0';
      document.body.appendChild(area);
      area.select();
      try {
        document.execCommand('copy') ? resolve() : reject(new Error('copy'));
      } catch (e) {
        reject(e);
      } finally {
        area.remove();
      }
    });
  }

  /** Affiche, une seule fois, un mot de passe temporaire renvoyé par le serveur. */
  function showPendingPassword(ctx) {
    var pending = ctx.data.pendingPassword;
    if (!pending) return;
    ctx.data.pendingPassword = null;
    var slot = ctx.root.querySelector('[data-password-slot]') || ctx.root.querySelector('.module-users') || ctx.root;
    var util = ctx.util;
    var code = util.el('code', { text: pending.password });
    var copyBtn = util.el('button', { type: 'button', class: 'btn btn--sm', title: 'Copier dans le presse-papiers' }, [util.icon('copy'), 'Copier']);
    copyBtn.addEventListener('click', function () {
      copyText(pending.password).then(function () { ctx.toast.success('Mot de passe copié dans le presse-papiers.'); }, function () { ctx.toast.warning('Copie impossible : sélectionnez le mot de passe et copiez-le manuellement.'); });
    });
    var closeBtn = util.el('button', { type: 'button', class: 'btn btn--sm btn--ghost', title: 'Masquer' }, [util.icon('close')]);
    var box = util.el('div', { class: 'alert alert--warning', role: 'status' }, [
      util.icon('key'),
      util.el('div', { class: 'grow' }, [
        util.el('p', { class: 'alert__title', text: 'Mot de passe temporaire de « ' + (pending.username || 'ce compte') + ' »' }),
        util.el('div', { class: 'users-password-box' }, [code, copyBtn, closeBtn]),
        util.el('p', { class: 'text-muted text-small mb-0', text: 'Il n’est affiché qu’une seule fois et ne peut pas être retrouvé : transmettez-le par un canal sûr. La personne devra le changer à sa prochaine connexion.' }),
      ]),
    ]);
    closeBtn.addEventListener('click', function () { box.remove(); });
    slot.prepend(box);
  }

  function rememberPassword(ctx, envelope) {
    var data = envelope && envelope.data;
    if (data && data.temporaryPassword) {
      ctx.data.pendingPassword = { password: data.temporaryPassword, username: data.username || '' };
    }
  }

  Atelier.modules.register('users', {
    mount: function (ctx) {
      var root = ctx.root;

      // Arbre ACL : plier / déplier un nœud.
      ctx.on(root, 'click', '[data-tree-toggle]', function (e, toggle) {
        e.preventDefault();
        e.stopPropagation();
        var li = toggle.closest('li');
        if (!li) return;
        var open = !li.classList.contains('is-open');
        li.classList.toggle('is-open', open);
        // L'état est annoncé par le treeitem, c'est-à-dire le lien focalisable de la ligne.
        var item = li.querySelector(':scope > .tree__row > [role="treeitem"]');
        if (item) item.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Replier' : 'Déplier');
      });
      ctx.on(root, 'click', '[data-tree-expand-all]', function (e, button) {
        e.preventDefault();
        var tree = root.querySelector('[data-acl-tree]');
        if (!tree) return;
        var collapse = button.dataset.state === 'open';
        tree.querySelectorAll('[role="treeitem"][aria-expanded]').forEach(function (item) {
          item.setAttribute('aria-expanded', collapse ? 'false' : 'true');
          var li = item.closest('li');
          if (li) li.classList.toggle('is-open', !collapse);
          var toggle = li && li.querySelector(':scope > .tree__row [data-tree-toggle]');
          if (toggle) toggle.setAttribute('aria-expanded', collapse ? 'false' : 'true');
        });
        button.dataset.state = collapse ? 'closed' : 'open';
        button.textContent = collapse ? 'Tout' : 'Replier';
      });

      // Génération et copie d'un mot de passe dans le formulaire de création.
      ctx.on(root, 'click', '[data-generate-password]', function (e, button) {
        e.preventDefault();
        var form = button.closest('form');
        var field = form && form.querySelector('[data-password-field]');
        if (!field) return;
        field.value = generatePassword();
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.focus();
      });
      ctx.on(root, 'click', '[data-copy-password]', function (e, button) {
        e.preventDefault();
        var form = button.closest('form');
        var field = form && form.querySelector('[data-password-field]');
        if (!field || !field.value) { ctx.toast.info('Aucun mot de passe à copier.'); return; }
        copyText(field.value).then(function () { ctx.toast.success('Mot de passe copié dans le presse-papiers.'); }, function () { ctx.toast.warning('Copie impossible : sélectionnez le mot de passe et copiez-le manuellement.'); });
      });

      // Mot de passe temporaire renvoyé par une action : mémorisé puis affiché après le rechargement de la vue.
      ctx.on(root, 'atelier:action', function (e) { rememberPassword(ctx, e.detail && e.detail.envelope); });
      ctx.on(root, 'atelier:submitted', function (e) { rememberPassword(ctx, e.detail && e.detail.envelope); });
      ctx.on(ctx.banner, 'atelier:action', function (e) { rememberPassword(ctx, e.detail && e.detail.envelope); });
    },

    render: function (ctx) {
      showPendingPassword(ctx);
    },
  });
})();
