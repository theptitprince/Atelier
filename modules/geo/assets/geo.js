/**
 * Module Coordonnées GPS : compléments côté client, sans logique métier.
 *  - aperçu de l'interprétation des coordonnées saisies (action « parse ») ;
 *  - copie des coordonnées dans le presse-papiers ;
 *  - recherche d'informations à rattacher (action « search-info ») puis soumission du formulaire « link » ;
 *  - affichage du rapport d'import CSV.
 * Formulaires, erreurs de champs et directives restent gérés par le noyau.
 */
(function () {
  'use strict';

  Atelier.modules.register('geo', {
    render(ctx) {
      if (ctx.data.bound) return;
      ctx.data.bound = true;
      const util = ctx.util;

      // ----- Aperçu des coordonnées -----
      const preview = util.debounce(async (input) => {
        const card = ctx.root.querySelector('[data-geo-preview]');
        if (!card) return;
        const value = input.value.trim();
        if (!value) { card.hidden = true; return; }
        const idInput = input.form ? input.form.querySelector('input[name="id"]') : null;
        try {
          const envelope = await ctx.api.post('parse', { coordinates: value, id: idInput ? idInput.value : null });
          const data = envelope.data || {};
          card.querySelector('[data-geo-preview-decimal]').textContent = data.decimal || '';
          card.querySelector('[data-geo-preview-dms]').textContent = data.dms || '';
          const osm = card.querySelector('[data-geo-preview-osm]');
          if (osm) osm.href = data.osm || '#';
          const nearbyWrap = card.querySelector('[data-geo-preview-nearby]');
          const list = card.querySelector('[data-geo-preview-nearby-list]');
          if (nearbyWrap && list) {
            list.innerHTML = '';
            (data.nearby || []).forEach((p) => {
              list.appendChild(util.el('li', { class: 'list__item' }, [
                util.el('a', { href: '#', 'data-route': 'show/' + p.id, class: 'grow', text: p.name }),
                util.el('span', { class: 'text-muted text-small', text: p.distance }),
              ]));
            });
            nearbyWrap.hidden = !(data.nearby || []).length;
          }
          card.hidden = false;
          const field = input.closest('.field');
          if (field) { field.classList.remove('is-invalid'); const err = field.querySelector('.field__error'); if (err) err.textContent = ''; }
        } catch (err) {
          card.hidden = true;
          if (err && err.kind === 'validation' && input.form) Atelier.forms.showErrors(input.form, err.fields);
          else ctx.toast.fromError(err);
        }
      }, 350);
      ctx.on(ctx.root, 'change', '[data-geo-coordinates]', (e) => preview(e.target));
      ctx.on(ctx.root, 'click', '[data-geo-check]', (e, button) => {
        const input = button.closest('form').querySelector('[data-geo-coordinates]');
        if (input) preview(input);
      });

      // ----- Copie -----
      ctx.on(ctx.root, 'click', '[data-geo-copy]', async (e, button) => {
        const text = button.dataset.geoCopy || '';
        try {
          await navigator.clipboard.writeText(text);
          ctx.toast.success('Coordonnées copiées : ' + text);
        } catch (err) {
          ctx.toast.warning('Copie impossible dans ce navigateur.');
        }
      });

      // ----- Rattachement d'une information -----
      const searchInfo = util.debounce(async (input) => {
        const form = input.closest('form[data-geo-link]');
        const results = form ? form.querySelector('[data-geo-link-results]') : null;
        if (!results) return;
        const q = input.value.trim();
        if (q.length < 2) { results.hidden = true; results.innerHTML = ''; return; }
        try {
          const envelope = await ctx.api.post('search-info', { q });
          const items = (envelope.data && envelope.data.items) || [];
          results.innerHTML = '';
          if (!items.length) {
            results.appendChild(util.el('li', { class: 'list__item text-muted', text: 'Aucune information trouvée.' }));
          }
          items.forEach((item) => {
            results.appendChild(util.el('li', { class: 'list__item', role: 'option', tabindex: '0', 'data-geo-link-pick': item.id }, [
              util.icon('puzzle', 'text-muted'),
              util.el('span', { class: 'grow' }, [
                util.el('strong', { text: item.label }),
                ' ',
                util.el('span', { class: 'text-muted text-small', text: '· ' + item.module + ' · ' + item.dataset }),
              ]),
              util.icon('plus'),
            ]));
          });
          results.hidden = false;
        } catch (err) { ctx.toast.fromError(err); }
      }, 300);
      ctx.on(ctx.root, 'input', '[data-geo-link-search]', (e) => searchInfo(e.target));
      const pick = (item) => {
        const form = item.closest('form[data-geo-link]');
        if (!form) return;
        form.querySelector('[data-geo-link-id]').value = item.dataset.geoLinkPick;
        form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      };
      ctx.on(ctx.root, 'click', '[data-geo-link-pick]', (e, item) => { e.preventDefault(); pick(item); });
      ctx.on(ctx.root, 'keydown', '[data-geo-link-pick]', (e, item) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(item); } });

      // ----- Rapport d'import -----
      ctx.on(ctx.root, 'atelier:submitted', 'form[data-geo-import]', (e) => {
        const data = e.detail && e.detail.envelope ? e.detail.envelope.data : null;
        const report = ctx.root.querySelector('[data-geo-import-report]');
        if (!data || !report) return;
        const errors = data.errors || [];
        report.querySelector('[data-geo-import-summary]').textContent = data.created + ' point(s) importé(s), ' + errors.length + ' ligne(s) rejetée(s).';
        const wrap = report.querySelector('[data-geo-import-errors]');
        const body = report.querySelector('[data-geo-import-errors-body]');
        body.innerHTML = '';
        errors.forEach((row) => {
          body.appendChild(util.el('tr', {}, [
            util.el('td', { text: String(row.line) }),
            util.el('td', { text: row.name || '' }),
            util.el('td', { text: row.message }),
          ]));
        });
        wrap.hidden = !errors.length;
        report.hidden = false;
        report.scrollIntoView({ behavior: 'smooth', block: 'start' });
        const form = e.target.closest ? e.target.closest('form') : null;
        if (form && typeof form.reset === 'function') form.reset();
      });
    },
  });
})();
