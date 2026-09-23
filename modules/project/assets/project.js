/*
 * Projets — comportements complémentaires (JavaScript natif).
 *
 * Le noyau gère les formulaires data-action, les confirmations, l'éditeur BBCode, la saisie de tags
 * et l'affichage des erreurs. Il reste au module :
 *  1. alimenter le sélecteur de cible d'une relation à partir de l'action GET « lookup?q= »
 *     (recherche transversale limitée aux jeux partagés lisibles) ;
 *  2. proposer « Localisé à » quand la cible choisie est un point GPS.
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

  function text(value) {
    return String(value == null ? '' : value);
  }

  /** Remplit le <select data-project-target> avec les résultats de lookup. */
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
      option.value = text(row.id);
      option.dataset.dataset = text(row.dataset);
      option.textContent = text(row.label) + ' (' + text(row.module_name || row.module) + ' · ' + text(row.dataset_name || row.dataset) + ')';
      select.appendChild(option);
    });
    if (results.length === 1) {
      select.value = text(results[0].id);
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  Atelier.modules.register('project', {
    mount(ctx) {
      var lookup = debounce(function (input) {
        var form = input.closest('form');
        var select = form ? form.querySelector('select[data-project-target]') : null;
        var hint = form ? form.querySelector('[data-project-lookup-hint]') : null;
        if (!select) return;
        var term = input.value.trim();
        if (!term) { fillTargets(select, [], ''); return; }
        var url = 'lookup?q=' + encodeURIComponent(term) + (input.dataset.exclude ? '&exclude=' + encodeURIComponent(input.dataset.exclude) : '');
        ctx.api.get(url).then(function (envelope) {
          if (input.value.trim() !== term) return; // réponse obsolète
          var results = (envelope && envelope.data && envelope.data.results) || [];
          fillTargets(select, results, term);
          if (hint) {
            hint.textContent = results.length
              ? results.length + ' information' + (results.length > 1 ? 's' : '') + ' proposée' + (results.length > 1 ? 's' : '') + '.'
              : 'Aucune information visible ne correspond.';
          }
        }).catch(function (err) {
          if (ctx.toast.fromError) ctx.toast.fromError(err); else ctx.toast.warning('Recherche indisponible.');
        });
      }, LOOKUP_DELAY);

      ctx.on(ctx.root, 'input', 'input[data-project-lookup]', function (e, input) { lookup(input); });
      ctx.on(ctx.root, 'keydown', 'input[data-project-lookup]', function (e) {
        // Entrée dans le champ de recherche ne doit pas relier avant le choix de la cible.
        if (e.key === 'Enter') e.preventDefault();
      });

      // Cible = point GPS → type « Localisé à » ; sinon retour au défaut « Fait partie du projet ».
      ctx.on(ctx.root, 'change', 'select[data-project-target]', function (e, select) {
        var form = select.closest('form');
        var type = form ? form.querySelector('select[name="type"]') : null;
        if (!type) return;
        var option = select.options[select.selectedIndex];
        var dataset = option ? option.dataset.dataset : '';
        if (dataset === 'geo.point') type.value = 'located_at';
        else if (type.value === 'located_at') type.value = 'part_of';
      });
    },
  });
})();
