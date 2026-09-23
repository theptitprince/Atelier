/**
 * Module Fichiers joints : compléments côté client, sans logique métier.
 *  - zone de dépôt (glisser-déposer) et file d'attente des fichiers choisis ;
 *  - téléversement fichier par fichier (chaque refus est rapporté individuellement) avec nom
 *    d'affichage (suffixé d'un numéro d'ordre s'il y a plusieurs fichiers), tags, dossier ;
 *  - sélection multiple dans la liste pour « Déplacer vers » un dossier ;
 *  - recherche d'une information à rattacher (action « search-info ») pour le téléversement et la fiche.
 * Les contrôles de type, de taille, de quota et de droits sont réalisés par le serveur.
 */
(function () {
  'use strict';

  function humanSize(bytes) {
    const units = ['o', 'Ko', 'Mo', 'Go'];
    let size = bytes; let i = 0;
    while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
    return (i === 0 ? String(bytes) : size.toFixed(1).replace('.', ',')) + ' ' + units[i];
  }

  function fieldValue(form, name) {
    const field = form.querySelector('[name="' + name + '"]');
    return field ? field.value : '';
  }

  Atelier.modules.register('attachments', {
    render(ctx) {
      updateBulk(ctx.root);
      if (ctx.data.bound) return;
      ctx.data.bound = true;
      const util = ctx.util;
      let queue = [];

      function renderQueue(root) {
        const list = root.querySelector('[data-attachments-queue]');
        if (!list) return;
        list.innerHTML = '';
        queue.forEach((entry, index) => {
          const item = util.el('li', { class: 'list__item', 'data-state': entry.state }, [
            util.icon(entry.state === 'done' ? 'success' : (entry.state === 'error' ? 'error' : 'file')),
            util.el('span', { class: 'grow truncate', text: entry.file.name, title: entry.file.name }),
            util.el('span', { class: 'text-muted text-small text-nowrap', text: humanSize(entry.file.size) }),
            util.el('span', { class: 'attachments__queue-status', text: entry.message || '' }),
            entry.state === 'pending' ? util.el('button', { type: 'button', class: 'btn btn--sm btn--icon btn--ghost', title: 'Retirer', 'aria-label': 'Retirer ' + entry.file.name, onclick: () => { queue.splice(index, 1); renderQueue(root); } }, [util.icon('close')]) : null,
          ]);
          list.appendChild(item);
        });
        list.hidden = queue.length === 0;
      }

      function addFiles(root, files) {
        Array.from(files || []).forEach((file) => {
          if (!queue.some((q) => q.file.name === file.name && q.file.size === file.size && q.state === 'pending')) queue.push({ file, state: 'pending', message: '' });
        });
        renderQueue(root);
      }

      // ----- Zone de dépôt -----
      ctx.on(ctx.root, 'click', '[data-attachments-dropzone]', (e, zone) => {
        if (e.target.closest('input')) return;
        const input = zone.querySelector('[data-attachments-input]');
        if (input) input.click();
      });
      ctx.on(ctx.root, 'keydown', '[data-attachments-dropzone]', (e, zone) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); const input = zone.querySelector('[data-attachments-input]'); if (input) input.click(); }
      });
      ctx.on(ctx.root, 'change', '[data-attachments-input]', (e, input) => { addFiles(ctx.root, input.files); input.value = ''; });
      ctx.on(ctx.root, 'dragover', '[data-attachments-dropzone]', (e, zone) => { e.preventDefault(); zone.classList.add('is-over'); });
      ctx.on(ctx.root, 'dragleave', '[data-attachments-dropzone]', (e, zone) => { zone.classList.remove('is-over'); });
      ctx.on(ctx.root, 'drop', '[data-attachments-dropzone]', (e, zone) => {
        e.preventDefault();
        zone.classList.remove('is-over');
        if (e.dataTransfer) addFiles(ctx.root, e.dataTransfer.files);
      });

      // ----- Téléversement séquentiel -----
      ctx.on(ctx.root, 'submit', 'form[data-attachments-upload]', async (e, form) => {
        e.preventDefault();
        e.stopPropagation();
        if (form.dataset.submitting) return;
        const pending = queue.filter((q) => q.state === 'pending');
        Atelier.forms.clearErrors(form);
        if (!pending.length) { Atelier.forms.showErrors(form, { files: 'Choisissez au moins un fichier.' }); return; }
        form.dataset.submitting = '1';
        const submit = form.querySelector('[type="submit"]');
        if (submit) { submit.classList.add('is-busy'); submit.disabled = true; }
        ctx.busy(true);
        const label = fieldValue(form, 'label').trim();
        let done = 0; let failed = 0;
        try {
          for (let i = 0; i < pending.length; i++) {
            const entry = pending[i];
            entry.state = 'busy'; entry.message = 'Envoi…';
            renderQueue(ctx.root);
            Atelier.status.progress(Math.round((i / pending.length) * 100));
            const body = new FormData();
            body.append('files[]', entry.file, entry.file.name);
            // Chaque fichier part dans sa propre requête : le suffixe d'ordre est donc calculé ici.
            body.append('label', label && pending.length > 1 ? label + ' (' + (i + 1) + ')' : label);
            body.append('tags', fieldValue(form, 'tags'));
            body.append('folder_id', fieldValue(form, 'folder_id'));
            body.append('description', fieldValue(form, 'description'));
            body.append('info_id', fieldValue(form, 'info_id'));
            try {
              const envelope = await ctx.api.post('upload', body);
              const data = envelope.data || {};
              if (data.files && data.files.length) { entry.state = 'done'; entry.message = 'Téléversé'; done++; }
              else { entry.state = 'error'; entry.message = (data.errors && data.errors[0] && data.errors[0].message) || 'Refusé'; failed++; }
            } catch (err) {
              entry.state = 'error';
              entry.message = err && err.fields && err.fields.files ? err.fields.files : (err && err.message) || 'Échec';
              failed++;
              if (err && err.kind === 'auth') throw err;
              if (err && err.kind === 'validation' && err.fields && (err.fields.info_id || err.fields.description || err.fields.label || err.fields.tags || err.fields.folder_id)) { Atelier.forms.showErrors(form, err.fields); break; }
            }
            renderQueue(ctx.root);
          }
        } finally {
          Atelier.status.progress(false);
          delete form.dataset.submitting;
          if (submit) { submit.classList.remove('is-busy'); submit.disabled = false; }
          ctx.busy(false);
        }
        if (done && !failed) {
          ctx.toast.success(done + ' fichier(s) téléversé(s).');
          queue = [];
          ctx.navigate(form.dataset.attachmentsCancel || 'list');
        } else if (done) {
          ctx.toast.warning(done + ' fichier(s) téléversé(s), ' + failed + ' refusé(s) : voir la liste ci-dessus.');
        } else if (failed) {
          ctx.toast.error('Aucun fichier n’a été accepté.');
        }
      });

      // ----- Sélection multiple (déplacement vers un dossier) -----
      ctx.on(ctx.root, 'change', '[data-attachments-select-all]', (e, master) => {
        const form = master.closest('form');
        if (!form) return;
        form.querySelectorAll('[data-attachments-select]').forEach((cb) => { cb.checked = master.checked; });
        updateBulk(ctx.root);
      });
      ctx.on(ctx.root, 'change', '[data-attachments-select]', () => updateBulk(ctx.root));

      // ----- Recherche d'une information à rattacher -----
      const search = util.debounce(async (input) => {
        const wrap = input.closest('.field') || input.closest('form');
        const results = wrap ? wrap.querySelector('[data-attachments-link-results]') : null;
        if (!results) return;
        const q = input.value.trim();
        if (q.length < 2) { results.hidden = true; results.innerHTML = ''; return; }
        try {
          const envelope = await ctx.api.post('search-info', { q });
          if (input.value.trim() !== q) return; // réponse obsolète : la saisie a changé
          const items = (envelope.data && envelope.data.items) || [];
          results.innerHTML = '';
          if (!items.length) results.appendChild(util.el('li', { class: 'list__item text-muted', text: 'Aucune information trouvée.' }));
          items.forEach((item) => {
            results.appendChild(util.el('li', { class: 'list__item', role: 'option', tabindex: '0', 'data-attachments-pick': item.id, 'data-label': item.label + ' · ' + item.module }, [
              util.icon('puzzle', 'text-muted'),
              util.el('span', { class: 'grow' }, [util.el('strong', { text: item.label }), ' ', util.el('span', { class: 'text-muted text-small', text: '· ' + item.module + ' · ' + item.dataset })]),
              util.icon('link'),
            ]));
          });
          results.hidden = false;
        } catch (err) { ctx.toast.fromError(err); }
      }, 300);
      ctx.on(ctx.root, 'input', '[data-attachments-link-search]', (e) => search(e.target));

      function pick(item) {
        const form = item.closest('form');
        if (!form) return;
        const hidden = form.querySelector('[data-attachments-link-id]');
        hidden.value = item.dataset.attachmentsPick;
        const results = item.closest('[data-attachments-link-results]');
        if (results) { results.hidden = true; results.innerHTML = ''; }
        if (form.hasAttribute('data-attachments-upload')) {
          // Formulaire de téléversement : afficher la cible choisie, masquer la recherche.
          const target = form.querySelector('[data-attachments-target]');
          const label = form.querySelector('[data-attachments-target-label]');
          const wrap = form.querySelector('[data-attachments-search-wrap]');
          if (label) label.textContent = item.dataset.label;
          if (target) target.hidden = false;
          if (wrap) { wrap.hidden = true; const input = wrap.querySelector('input'); if (input) input.value = ''; }
        } else {
          form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
      }
      ctx.on(ctx.root, 'click', '[data-attachments-pick]', (e, item) => { e.preventDefault(); pick(item); });
      ctx.on(ctx.root, 'keydown', '[data-attachments-pick]', (e, item) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(item); } });
      ctx.on(ctx.root, 'click', '[data-attachments-target-clear]', (e, button) => {
        const form = button.closest('form');
        if (!form) return;
        form.querySelector('[data-attachments-link-id]').value = '';
        const target = form.querySelector('[data-attachments-target]');
        const wrap = form.querySelector('[data-attachments-search-wrap]');
        if (target) target.hidden = true;
        if (wrap) wrap.hidden = false;
      });
    },
  });

  /** Met à jour le compteur de sélection et l'état du bouton « Déplacer » de la liste. */
  function updateBulk(root) {
    const form = root.querySelector('form[data-attachments-bulk]');
    if (!form) return;
    const boxes = Array.from(form.querySelectorAll('[data-attachments-select]'));
    const checked = boxes.filter((cb) => cb.checked).length;
    const counter = form.querySelector('[data-attachments-selected-count]');
    if (counter) counter.textContent = checked + ' sélectionné' + (checked > 1 ? 's' : '');
    const submit = form.querySelector('[data-attachments-bulk-submit]');
    if (submit) submit.disabled = checked === 0;
    const master = form.querySelector('[data-attachments-select-all]');
    if (master) { master.checked = boxes.length > 0 && checked === boxes.length; master.indeterminate = checked > 0 && checked < boxes.length; }
  }
})();
