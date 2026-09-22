/*
 * Bloc-notes — comportements complémentaires (JavaScript natif).
 *
 * Le noyau prend déjà en charge l'enregistrement (formulaire data-action="save"), le raccourci
 * Ctrl+S (data-save-shortcut), l'indicateur « modifié » (data-track-dirty), la confirmation de
 * suppression et l'affichage des erreurs de validation. Il ne reste au module que le compteur
 * de caractères du contenu.
 */
(function () {
  'use strict';

  var NEAR_LIMIT_RATIO = 0.9;

  function format(number) {
    try { return number.toLocaleString('fr-FR'); } catch (e) { return String(number); }
  }

  /** Met à jour le compteur associé à un champ marqué data-counted (dans le même .field). */
  function updateCounter(area) {
    var field = area.closest('.field');
    var counter = field ? field.querySelector('[data-counter]') : null;
    if (!counter) return;
    var max = parseInt(area.getAttribute('maxlength') || '0', 10);
    var length = area.value.length;
    counter.textContent = max > 0 ? format(length) + ' / ' + format(max) + ' caractères' : format(length) + ' caractères';
    counter.classList.toggle('is-near-limit', max > 0 && length >= max * NEAR_LIMIT_RATIO);
  }

  function refreshAll(root) {
    if (!root) return;
    root.querySelectorAll('[data-counted]').forEach(updateCounter);
  }

  Atelier.modules.register('notes', {
    mount(ctx) {
      // Délégation sur la racine du panneau : survit aux changements de vue.
      ctx.on(ctx.root, 'input', '[data-counted]', function () { updateCounter(this); });
    },
    render(ctx) {
      refreshAll(ctx.root);
    },
  });
})();
