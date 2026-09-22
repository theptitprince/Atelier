/*
 * Corbeille — comportements complémentaires (JavaScript natif).
 *
 * Le noyau prend en charge les actions unitaires (data-action, data-confirm, data-prompt),
 * la soumission du formulaire groupé (form[data-action]) et les filtres (data-auto-submit).
 * Il reste au module : la case « tout sélectionner », le compteur de sélection, l'activation
 * des boutons groupés selon les droits des éléments cochés, et le choix de l'action du
 * formulaire (restore-many / purge-many) en fonction du bouton cliqué.
 */
(function () {
  'use strict';

  function boxes(root) {
    return Array.prototype.slice.call(root.querySelectorAll('form[data-trash-bulk] input[name="ids[]"]'));
  }

  /** Met à jour lignes sélectionnées, compteur, case globale et état des boutons groupés. */
  function updateSelection(root) {
    var all = boxes(root);
    var checked = all.filter(function (box) { return box.checked; });
    all.forEach(function (box) {
      var row = box.closest('tr');
      if (row) row.classList.toggle('is-selected', box.checked);
    });
    var counter = root.querySelector('[data-trash-selected-count]');
    if (counter) counter.textContent = String(checked.length);
    var master = root.querySelector('[data-trash-select-all]');
    if (master) {
      master.checked = all.length > 0 && checked.length === all.length;
      master.indeterminate = checked.length > 0 && checked.length < all.length;
    }
    var canRestore = checked.length > 0 && checked.every(function (box) { return box.dataset.canRestore === '1'; });
    var canPurge = checked.length > 0 && checked.every(function (box) { return box.dataset.canPurge === '1'; });
    Array.prototype.forEach.call(root.querySelectorAll('[data-bulk="restore-many"]'), function (btn) { btn.disabled = !canRestore; });
    Array.prototype.forEach.call(root.querySelectorAll('[data-bulk="purge-many"]'), function (btn) { btn.disabled = !canPurge; });
  }

  Atelier.modules.register('trash', {
    mount: function (ctx) {
      // Délégation sur la racine du panneau : survit aux rechargements de la vue.
      ctx.on(ctx.root, 'change', '[data-trash-select-all]', function (e, el) {
        boxes(ctx.root).forEach(function (box) { box.checked = el.checked; });
        updateSelection(ctx.root);
      });
      ctx.on(ctx.root, 'change', 'form[data-trash-bulk] input[name="ids[]"]', function () {
        updateSelection(ctx.root);
      });
      // Le clic précède l'événement submit : on fixe l'action et la confirmation du formulaire.
      ctx.on(ctx.root, 'click', '[data-bulk]', function (e, el) {
        var form = el.closest('form[data-trash-bulk]');
        if (!form) return;
        form.dataset.action = el.dataset.bulk;
        if (el.dataset.bulkConfirm) {
          form.dataset.confirm = el.dataset.bulkConfirm;
          form.setAttribute('data-danger', '');
        } else {
          delete form.dataset.confirm;
          form.removeAttribute('data-danger');
        }
      });
    },
    render: function (ctx) {
      updateSelection(ctx.root);
    },
  });
})();
