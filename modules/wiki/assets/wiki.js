/**
 * Module Pages : compléments d'édition côté client, sans logique métier.
 *  - insertion de [[Titre]], [file=…] et [point=…] à la position du curseur de l'éditeur ;
 *  - recherche de pages (action lookup) et de lieux (module geo, lookup) pour les lier ;
 *  - gestion de la liste des lieux rattachés (champs cachés points[]).
 */
(function () {
  'use strict';

  function insertAtCursor(textarea, text) {
    const start = textarea.selectionStart != null ? textarea.selectionStart : textarea.value.length;
    const end = textarea.selectionEnd != null ? textarea.selectionEnd : start;
    const before = textarea.value.slice(0, start);
    const after = textarea.value.slice(end);
    const pad = before.length && !/\s$/.test(before) ? ' ' : '';
    textarea.value = before + pad + text + after;
    const pos = before.length + pad.length + text.length;
    textarea.focus();
    textarea.setSelectionRange(pos, pos);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  Atelier.modules.register('wiki', {
    render(ctx) {
      if (ctx.data.bound) return;
      ctx.data.bound = true;
      const util = ctx.util;
      const editor = () => ctx.root.querySelector('#wiki-content');

      ctx.on(ctx.root, 'click', '[data-wiki-insert]', (e, button) => {
        e.preventDefault();
        const textarea = editor();
        if (textarea) insertAtCursor(textarea, button.dataset.wikiInsert);
      });

      // ----- Pages à lier -----
      const searchPages = util.debounce(async (input) => {
        const results = ctx.root.querySelector('[data-wiki-page-results]');
        if (!results) return;
        const q = input.value.trim();
        if (q.length < 2) { results.hidden = true; results.innerHTML = ''; return; }
        try {
          const envelope = await ctx.api.get('lookup?q=' + encodeURIComponent(q));
          const items = (envelope.data && envelope.data.items) || [];
          results.innerHTML = '';
          if (!items.length) results.appendChild(util.el('li', { class: 'list__item text-muted', text: 'Aucune page trouvée : [[' + q + ']] créera la page.' }));
          items.forEach((item) => results.appendChild(util.el('li', { class: 'list__item', role: 'option', tabindex: '0', 'data-wiki-insert': '[[' + item.title + ']]' }, [util.icon('book', 'text-muted'), util.el('span', { class: 'grow', text: item.title }), util.icon('plus')])));
          results.hidden = false;
        } catch (err) { ctx.toast.fromError(err); }
      }, 300);
      ctx.on(ctx.root, 'input', '[data-wiki-page-search]', (e) => searchPages(e.target));

      // ----- Lieux -----
      const searchPoints = util.debounce(async (input) => {
        const results = ctx.root.querySelector('[data-wiki-point-results]');
        if (!results) return;
        const q = input.value.trim();
        if (q.length < 2) { results.hidden = true; results.innerHTML = ''; return; }
        try {
          const envelope = await Atelier.api.get('/m/geo/lookup?q=' + encodeURIComponent(q));
          const items = (envelope.data && envelope.data.items) || [];
          results.innerHTML = '';
          if (!items.length) results.appendChild(util.el('li', { class: 'list__item text-muted', text: 'Aucun point trouvé.' }));
          items.forEach((item) => results.appendChild(util.el('li', { class: 'list__item', role: 'option', tabindex: '0', 'data-wiki-point-pick': String(item.id), 'data-label': item.label, 'data-dms': item.dms }, [util.icon('map-pin', 'text-muted'), util.el('span', { class: 'grow' }, [util.el('strong', { text: item.label }), ' ', util.el('span', { class: 'text-muted text-small mono', text: item.dms + (item.distance ? ' · ' + item.distance : '') })]), util.icon('plus')])));
          results.hidden = false;
        } catch (err) { ctx.toast.fromError(err); }
      }, 300);
      ctx.on(ctx.root, 'input', '[data-wiki-point-search]', (e) => searchPoints(e.target));

      function pickPoint(item) {
        const list = ctx.root.querySelector('[data-wiki-points]');
        if (!list) return;
        const id = item.dataset.wikiPointPick;
        if (list.querySelector('[data-wiki-point="' + id + '"]')) { ctx.toast.info('Ce lieu est déjà rattaché.'); return; }
        list.appendChild(util.el('li', { class: 'list__item', 'data-wiki-point': id }, [
          util.el('input', { type: 'hidden', name: 'points[]', value: id }),
          util.el('span', { class: 'grow' }, [item.dataset.label, util.el('br'), util.el('span', { class: 'mono text-muted text-small', text: item.dataset.dms })]),
          util.el('button', { type: 'button', class: 'btn btn--sm btn--icon btn--ghost', 'data-wiki-insert': '[point=' + id + ']', title: 'Insérer dans le texte' }, [util.icon('plus')]),
          util.el('button', { type: 'button', class: 'btn btn--sm btn--icon btn--ghost', 'data-wiki-point-remove': '', title: 'Retirer' }, [util.icon('close')]),
        ]));
        const results = ctx.root.querySelector('[data-wiki-point-results]');
        const input = ctx.root.querySelector('[data-wiki-point-search]');
        if (results) { results.hidden = true; results.innerHTML = ''; }
        if (input) input.value = '';
        ctx.setDirty(true);
      }
      ctx.on(ctx.root, 'click', '[data-wiki-point-pick]', (e, item) => { e.preventDefault(); pickPoint(item); });
      ctx.on(ctx.root, 'keydown', '[data-wiki-point-pick], [data-wiki-page-results] [data-wiki-insert]', (e, item) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); item.click(); } });
      ctx.on(ctx.root, 'click', '[data-wiki-point-remove]', (e, button) => { e.preventDefault(); const li = button.closest('[data-wiki-point]'); if (li) { li.remove(); ctx.setDirty(true); } });
    },
  });
})();
