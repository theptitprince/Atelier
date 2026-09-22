/**
 * Module Budget : compléments côté client, sans logique métier.
 *  - sélection multiple et actions groupées dans le livre des opérations ;
 *  - « Enregistrer et saisir une autre » (champ caché posé avant la soumission) ;
 *  - navigation par mois dans le budget ;
 *  - repli/dépli du détail d'un mois du prévisionnel ;
 *  - rapport d'import CSV.
 * Formulaires, erreurs de champs et directives restent gérés par le noyau.
 */
(function () {
  'use strict';

  Atelier.modules.register('budget', {
    render(ctx) {
      if (ctx.data.bound) return;
      ctx.data.bound = true;
      const util = ctx.util;

      // ----- Sélection et actions groupées -----
      const syncBulk = (form) => {
        const checked = form.querySelectorAll('[data-budget-check]:checked');
        const bar = form.querySelector('[data-budget-bulk-bar]');
        const count = form.querySelector('[data-budget-bulk-count]');
        if (bar) bar.hidden = checked.length === 0;
        if (count) count.textContent = String(checked.length);
      };
      ctx.on(ctx.root, 'change', '[data-budget-check]', (e, input) => syncBulk(input.closest('form')));
      ctx.on(ctx.root, 'change', '[data-budget-check-all]', (e, input) => {
        const form = input.closest('form');
        form.querySelectorAll('[data-budget-check]').forEach((box) => { box.checked = input.checked; });
        syncBulk(form);
      });
      ctx.on(ctx.root, 'click', '[data-budget-op]', (e, button) => {
        const op = button.closest('form').querySelector('[data-budget-bulk-op]');
        if (op) op.value = button.dataset.budgetOp;
      });

      // ----- Enregistrer et saisir une autre -----
      ctx.on(ctx.root, 'click', '[data-budget-again]', (e, button) => {
        const form = button.closest('form');
        let field = form.querySelector('input[name="again"]');
        if (!field) {
          field = util.el('input', { type: 'hidden', name: 'again', value: '1' });
          form.appendChild(field);
        }
        field.value = '1';
      });

      // ----- Navigation par mois -----
      ctx.on(ctx.root, 'change', '[data-budget-month-nav]', (e, input) => {
        if (/^\d{4}-\d{2}$/.test(input.value)) ctx.navigate('envelopes?month=' + input.value);
      });

      // ----- Détail d'un mois projeté -----
      ctx.on(ctx.root, 'click', '[data-budget-toggle]', (e, button) => {
        const row = ctx.root.querySelector('#' + button.dataset.budgetToggle);
        if (!row) return;
        row.hidden = !row.hidden;
        button.setAttribute('aria-expanded', row.hidden ? 'false' : 'true');
      });

      // ----- Rapport d'import -----
      ctx.on(ctx.root, 'atelier:submitted', 'form[data-budget-import]', (e) => {
        const data = e.detail && e.detail.envelope ? e.detail.envelope.data : null;
        const report = ctx.root.querySelector('[data-budget-import-report]');
        if (!data || !report) return;
        const errors = data.errors || [];
        report.querySelector('[data-budget-import-summary]').textContent = data.created + ' opération(s) importée(s), ' + (data.skipped || 0) + ' doublon(s) ignoré(s), ' + errors.length + ' ligne(s) rejetée(s).';
        const wrap = report.querySelector('[data-budget-import-errors]');
        const body = report.querySelector('[data-budget-import-errors-body]');
        body.innerHTML = '';
        errors.forEach((row) => {
          body.appendChild(util.el('tr', {}, [
            util.el('td', { text: String(row.line) }),
            util.el('td', { text: row.label || '' }),
            util.el('td', { text: row.message }),
          ]));
        });
        wrap.hidden = !errors.length;
        report.hidden = false;
        const form = e.target.closest ? e.target.closest('form') : null;
        if (form && typeof form.reset === 'function') form.reset();
      });
    },
  });
})();
