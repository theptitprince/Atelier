/**
 * Module « Tags partagés » : comportements de la vue Gestion.
 *  - case « tout sélectionner » (data-select-all) qui coche/décoche les lignes (data-select-item) ;
 *  - compteur de sélection (data-selection-count) ;
 *  - synchronisation des identifiants sélectionnés dans le bouton « Supprimer la sélection »
 *    (data-delete-selected → data-params) et activation des boutons data-needs-selection.
 * La bascule nuage/tableau et toutes les actions sont gérées par le noyau (data-subtabs, data-action, form[data-action]).
 */
(function () {
  'use strict';

  function syncSelection(root) {
    root.querySelectorAll('[data-selection]').forEach(function (table) {
      var form = table.closest('form') || root;
      var items = Array.prototype.slice.call(table.querySelectorAll('[data-select-item]'));
      var selected = items.filter(function (cb) { return cb.checked; });
      var ids = selected.map(function (cb) { return parseInt(cb.value, 10); }).filter(function (n) { return n > 0; });

      items.forEach(function (cb) {
        var row = cb.closest('tr');
        if (row) row.classList.toggle('is-selected', cb.checked);
      });

      var all = table.querySelector('[data-select-all]');
      if (all) {
        all.checked = items.length > 0 && selected.length === items.length;
        all.indeterminate = selected.length > 0 && selected.length < items.length;
      }

      var counter = form.querySelector('[data-selection-count]');
      if (counter) {
        counter.textContent = ids.length === 0 ? 'Aucun tag sélectionné' : ids.length + (ids.length > 1 ? ' tags sélectionnés' : ' tag sélectionné');
      }

      form.querySelectorAll('[data-needs-selection]').forEach(function (button) { button.disabled = ids.length === 0; });

      var deleteButton = form.querySelector('[data-delete-selected]');
      if (deleteButton) {
        deleteButton.dataset.params = JSON.stringify({ ids: ids });
        deleteButton.dataset.confirm = ids.length === 0
          ? 'Aucun tag sélectionné.'
          : 'Supprimer ' + (ids.length > 1 ? 'les ' + ids.length + ' tags sélectionnés' : 'le tag sélectionné') + ' ? Cette action retire le tag de toutes les informations et est irréversible.';
      }
    });
  }

  Atelier.modules.register('tags', {
    mount(ctx) {
      // Délégation sur la racine du panneau : survit aux changements de vue.
      ctx.on(ctx.root, 'change', '[data-select-all]', function () {
        var table = this.closest('[data-selection]');
        if (!table) return;
        var checked = this.checked;
        table.querySelectorAll('[data-select-item]').forEach(function (cb) { cb.checked = checked; });
        syncSelection(ctx.root);
      });
      ctx.on(ctx.root, 'change', '[data-select-item]', function () { syncSelection(ctx.root); });
    },
    render(ctx) {
      syncSelection(ctx.root);
    },
  });
})();
