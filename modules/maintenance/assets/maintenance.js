/**
 * Module Entretien : compléments côté client, sans logique métier.
 *  - formulaire d'équipement : affiche le relevé seulement si une unité de compteur est choisie ;
 *  - formulaire de tâche : masque la périodicité pour une panne, les champs « compteur » pour un
 *    équipement sans compteur, et propose un rappel anticipé par défaut selon l'unité ;
 *  - formulaire d'intervention : liste des tâches et unité du compteur selon l'équipement choisi,
 *    titre pré-rempli à partir de la tâche.
 * Validation, soumission, erreurs et directives restent gérées par le noyau.
 */
(function () {
  'use strict';

  function parseJson(value, fallback) {
    try { return value ? JSON.parse(value) : fallback; } catch (e) { return fallback; }
  }

  function setHidden(nodes, hidden) {
    nodes.forEach((node) => { node.hidden = hidden; });
  }

  Atelier.modules.register('maintenance', {
    render(ctx) {
      if (ctx.data.bound) return;
      ctx.data.bound = true;

      // ----- Équipement : unité de compteur -----
      ctx.on(ctx.root, 'change', '[data-maintenance-meter-unit]', (e, select) => {
        const form = select.closest('form');
        if (!form) return;
        setHidden(form.querySelectorAll('[data-maintenance-meter-field]'), !select.value);
      });

      // ----- Tâche : nature et équipement -----
      const syncJobForm = (form) => {
        const units = parseJson(form.dataset.assetUnits, {});
        const leads = parseJson(form.dataset.defaultLeadMeter, {});
        const kindInput = form.querySelector('[data-maintenance-job-kind]:checked');
        const corrective = kindInput && kindInput.value === 'corrective';
        const assetSelect = form.querySelector('[data-maintenance-job-asset]');
        const unit = assetSelect ? (units[assetSelect.value] || '') : '';
        form.querySelectorAll('[data-maintenance-unit]').forEach((node) => { node.textContent = unit; });
        form.querySelectorAll('[data-maintenance-preventive-only]').forEach((node) => {
          const meterOnly = node.hasAttribute('data-maintenance-meter-only');
          node.hidden = corrective || (meterOnly && !unit);
        });
        const title = form.querySelector('[data-maintenance-schedule-title]');
        if (title) title.textContent = corrective ? 'Délai de traitement' : 'Échéances et rappel';
        const dueLabel = form.querySelector('[data-maintenance-due-label]');
        if (dueLabel) dueLabel.textContent = corrective ? 'À traiter avant le' : 'Prochaine échéance (date)';
        const leadMeter = form.querySelector('input[name="lead_meter"]');
        if (leadMeter && unit && !leadMeter.value && leads[unit]) leadMeter.value = String(leads[unit]);
      };
      ctx.on(ctx.root, 'change', '[data-maintenance-job-form] [data-maintenance-job-kind], [data-maintenance-job-form] [data-maintenance-job-asset]', (e, input) => {
        syncJobForm(input.closest('form'));
      });

      // ----- Intervention : équipement → tâches et unité -----
      ctx.on(ctx.root, 'change', '[data-maintenance-log-asset]', (e, select) => {
        const form = select.closest('form');
        if (!form) return;
        const units = parseJson(form.dataset.assetUnits, {});
        const jobs = parseJson(form.dataset.jobsByAsset, {});
        const unit = units[select.value] || '';
        form.querySelectorAll('[data-maintenance-unit]').forEach((node) => { node.textContent = unit; });
        setHidden(form.querySelectorAll('[data-maintenance-meter-field]'), !unit);
        const jobSelect = form.querySelector('[data-maintenance-log-job]');
        if (jobSelect) {
          jobSelect.innerHTML = '';
          jobSelect.appendChild(ctx.util.el('option', { value: '', text: 'Aucune (intervention libre)' }));
          (jobs[select.value] || []).forEach((job) => {
            jobSelect.appendChild(ctx.util.el('option', { value: String(job.id), text: job.title + (job.kind === 'corrective' ? ' (panne)' : '') }));
          });
        }
      });
      ctx.on(ctx.root, 'change', '[data-maintenance-log-job]', (e, select) => {
        const form = select.closest('form');
        const title = form ? form.querySelector('[data-maintenance-log-title]') : null;
        const option = select.options[select.selectedIndex];
        if (title && !title.value && option && option.value) title.value = option.text.replace(/ \(panne\)$/, '');
      });
    },
  });
})();
