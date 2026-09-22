/*
 * Explorateur — comportements complémentaires (JavaScript natif).
 *
 * Le noyau gère déjà les formulaires data-action, la soumission automatique, la saisie de tags,
 * les confirmations et l'affichage des erreurs. Il reste au module :
 *  1. soumettre le formulaire de recherche quand le filtre « tag » (composant data-tags-input,
 *     qui n'émet que des événements « input ») change ;
 *  2. alimenter le sélecteur de cible d'une relation à partir de l'action GET « lookup?q= ».
 */
(function () {
  'use strict';

  var LOOKUP_DELAY = 220;

  function debounce(fn, wait) {
    var timer = null;
    return function () {
      var args = arguments;
      clearTimeout(timer);
      timer = setTimeout(function () { fn.apply(null, args); }, wait);
    };
  }

  function escapeText(value) {
    return String(value == null ? '' : value);
  }

  /** Remplit le <select data-explorer-target> avec les résultats de lookup. */
  function fillTargets(select, results, term) {
    select.innerHTML = '';
    var placeholder = document.createElement('option');
    placeholder.value = '';
    if (!results.length) {
      placeholder.textContent = term ? 'Aucune information visible ne correspond à « ' + term + ' »' : '— Recherchez une information ci-dessus —';
      select.appendChild(placeholder);
      return;
    }
    placeholder.textContent = '— ' + results.length + ' résultat' + (results.length > 1 ? 's' : '') + ' : choisir —';
    select.appendChild(placeholder);
    results.forEach(function (row) {
      var option = document.createElement('option');
      option.value = escapeText(row.id);
      option.textContent = escapeText(row.label) + ' (' + escapeText(row.dataset_name || row.dataset) + ')';
      select.appendChild(option);
    });
    if (results.length === 1) select.value = escapeText(results[0].id);
  }

  Atelier.modules.register('explorer', {
    mount(ctx) {
      // 1. Filtre par tag : le composant commun émet « input » sur le champ caché → soumission.
      ctx.on(ctx.root, 'input', 'form[data-auto-submit] input[data-tags-input][name="tag"]', function (e, input) {
        var form = input.closest('form');
        if (form && typeof form.requestSubmit === 'function') form.requestSubmit();
      });

      // 2. Recherche de cible pour une relation.
      var lookup = debounce(function (input) {
        var form = input.closest('form');
        var select = form ? form.querySelector('select[data-explorer-target]') : null;
        var hint = form ? form.querySelector('[data-explorer-lookup-hint]') : null;
        if (!select) return;
        var term = input.value.trim();
        if (!term) { fillTargets(select, [], ''); return; }
        var url = 'lookup?q=' + encodeURIComponent(term) + (input.dataset.exclude ? '&exclude=' + encodeURIComponent(input.dataset.exclude) : '');
        ctx.api.get(url).then(function (envelope) {
          if (input.value.trim() !== term) return; // réponse obsolète
          var results = (envelope && envelope.data && envelope.data.results) || [];
          fillTargets(select, results, term);
          if (hint) hint.textContent = results.length ? results.length + ' information' + (results.length > 1 ? 's' : '') + ' visible' + (results.length > 1 ? 's' : '') + ' proposée' + (results.length > 1 ? 's' : '') + '.' : 'Aucune information visible ne correspond.';
        }).catch(function (err) {
          ctx.toast.fromError ? ctx.toast.fromError(err) : ctx.toast.warning('Recherche indisponible.');
        });
      }, LOOKUP_DELAY);

      ctx.on(ctx.root, 'input', 'input[data-explorer-lookup]', function (e, input) { lookup(input); });
      ctx.on(ctx.root, 'keydown', 'input[data-explorer-lookup]', function (e) {
        // Entrée dans le champ de recherche ne doit pas soumettre la relation avant le choix de la cible.
        if (e.key === 'Enter') e.preventDefault();
      });
    },
  });
})();
