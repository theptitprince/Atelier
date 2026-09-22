/*
 * Module Démonstration — JavaScript du module (natif, sans dépendance).
 *
 * Le noyau prend déjà en charge : data-route, data-action (+ confirm/prompt), form[data-action],
 * data-auto-submit, data-track-dirty, data-save-shortcut, data-open-module, data-subtabs, erreurs de
 * validation près des champs, toasts des messages serveur et directives (refresh, navigate, close,
 * status, dirty, banner). Ce fichier n'ajoute que les démonstrations purement clientes :
 *   - journal des hooks du cycle de vie (console + tableau de l'écran Cycle de vie) ;
 *   - compteur ctx.interval suspendu/repris avec l'onglet ;
 *   - boutons [data-demo="…"] : toasts, barre d'état, progression, dialogues, occupation, ressources ;
 *   - sélection des lignes du tableau et affichage des résultats d'actions (atelier:submitted).
 *
 * Règles : écouteurs via ctx.on (délégation sur ctx.root, qui survit aux changements de vue),
 * minuteries via ctx.interval / ctx.timeout : le noyau libère tout à la fermeture de l'onglet.
 */
(function () {
  'use strict';

  var MAX_LOG = 60;
  var BASE = (Atelier.config && Atelier.config.baseUrl) || '';
  var esc = Atelier.util.escape;

  // ---------------------------------------------------------------------------
  // Journal des hooks
  // ---------------------------------------------------------------------------
  function logHook(ctx, name, detail) {
    ctx.data.hooks = ctx.data.hooks || [];
    ctx.data.hooks.push({ time: Atelier.util.formatTime(new Date()), name: name, detail: detail || '' });
    if (ctx.data.hooks.length > MAX_LOG) ctx.data.hooks.splice(0, ctx.data.hooks.length - MAX_LOG);
    console.info('[demo] hook ' + name + (detail ? ' — ' + detail : ''));
    renderHookLog(ctx);
  }

  function renderHookLog(ctx) {
    var body = ctx.root && ctx.root.querySelector('[data-demo-hook-log]');
    if (!body) return;
    var hooks = ctx.data.hooks || [];
    if (!hooks.length) { body.innerHTML = '<tr><td colspan="3" class="table__empty">Journal vide.</td></tr>'; return; }
    body.innerHTML = hooks.slice().reverse().map(function (h) {
      return '<tr><td class="mono text-muted text-nowrap">' + esc(h.time) + '</td><td><code>' + esc(h.name) + '</code></td><td class="text-small">' + esc(h.detail) + '</td></tr>';
    }).join('');
  }

  // ---------------------------------------------------------------------------
  // Compteur de l'écran Cycle de vie (ctx.interval : suspendu quand l'onglet est masqué)
  // ---------------------------------------------------------------------------
  function startCounter(ctx) {
    stopCounter(ctx);
    ctx.data.counter = 0;
    ctx.data.stopCounter = ctx.interval(function () {
      ctx.data.counter += 1;
      var node = ctx.root.querySelector('[data-demo-counter]');
      if (node) node.textContent = String(ctx.data.counter);
      if (ctx.data.counter % 10 === 0) console.debug('[demo] tick', ctx.data.counter);
    }, 1000);
  }

  function stopCounter(ctx) {
    if (ctx.data.stopCounter) { ctx.data.stopCounter(); ctx.data.stopCounter = null; }
  }

  function setCounterState(ctx, running) {
    var badge = ctx.root && ctx.root.querySelector('[data-demo-counter-state]');
    if (!badge) return;
    badge.textContent = running ? 'en cours' : 'suspendu';
    badge.className = 'badge badge--dot ' + (running ? 'badge--success' : 'badge--warning');
  }

  // ---------------------------------------------------------------------------
  // Rendu par écran (dispatch sur view.route)
  // ---------------------------------------------------------------------------
  var screens = {
    lifecycle: function (ctx, view) {
      startCounter(ctx);
      setCounterState(ctx, true);
      renderHookLog(ctx);
      var state = ctx.root.querySelector('[data-demo-state]');
      if (state) state.textContent = JSON.stringify(view.state || {}, null, 2);
    },
    tables: function (ctx) {
      updateSelection(ctx);
    },
    forms: function (ctx) {
      var focus = ctx.root.querySelector('#f-name');
      if (focus) focus.focus();
    },
  };

  function updateSelection(ctx) {
    var boxes = ctx.root.querySelectorAll('input[name="ids[]"]');
    var checked = 0;
    Array.prototype.forEach.call(boxes, function (box) {
      var row = box.closest('tr');
      if (row) row.classList.toggle('is-selected', box.checked);
      if (box.checked) checked += 1;
    });
    var counter = ctx.root.querySelector('[data-demo-selected-count]');
    if (counter) counter.textContent = String(checked);
    var all = ctx.root.querySelector('[data-demo-select-all]');
    if (all) { all.checked = boxes.length > 0 && checked === boxes.length; all.indeterminate = checked > 0 && checked < boxes.length; }
  }

  // ---------------------------------------------------------------------------
  // Démonstrations déclenchées par [data-demo="…"]
  // ---------------------------------------------------------------------------
  var demos = {
    // Toasts
    toast: function (ctx, el) {
      var level = el.dataset.level || 'info';
      var labels = { info: 'Information : tout se passe bien.', success: 'Enregistré avec succès.', warning: 'Attention : vérifiez les données.', error: 'Échec de l’opération.' };
      ctx.toast[level](labels[level] || labels.info);
    },
    'toast-grouped': function (ctx) { ctx.toast.info('Message répété : le toast se regroupe et affiche un compteur.'); },
    'toast-sticky': function (ctx) { ctx.toast.info('Cette information reste affichée jusqu’à sa fermeture (sticky: true).', { sticky: true, title: 'Persistant' }); },
    'toast-temporary': function (ctx) {
      var node = ctx.toast.warning('Cet avertissement disparaît de lui-même dans 3 secondes (sticky: false).', { sticky: false });
      // Les avertissements ont une durée par défaut de 5 s : on le ferme nous-mêmes à 3 s pour la démonstration.
      if (node) ctx.timeout(function () { var close = node.querySelector('.toast__close'); if (close) close.click(); }, 3000);
    },
    'toast-ref': function (ctx) { ctx.toast.error('Le traitement a échoué côté serveur (simulation).', { errorId: 'ERR-DEMO-' + Date.now().toString(36).toUpperCase(), title: 'Erreur avec référence' }); },
    'toast-clear': function (ctx) { ctx.toast.clear(); },

    // Barre d'état
    'status-ctx': function (ctx) { ctx.status('Texte contextuel de l’onglet posé à ' + Atelier.util.formatTime(new Date()) + ' (ctx.status)'); },
    'status-message': function (ctx, el) {
      var level = el.dataset.level || 'success';
      Atelier.status.message({ success: 'Message global de succès (8 s).', warning: 'Message global d’avertissement (8 s).', error: 'Message global d’erreur (8 s).' }[level], level);
    },
    progress: function (ctx, el) {
      if (ctx.data.stopProgress) return;
      var value = 0;
      var local = ctx.root.querySelector('[data-demo-progress-local]');
      el.classList.add('is-busy');
      Atelier.status.progress(0);
      ctx.data.stopProgress = ctx.interval(function () {
        value = Math.min(100, value + 2);
        Atelier.status.progress(value);
        if (local) local.value = value;
        if (value >= 100) {
          ctx.data.stopProgress(); ctx.data.stopProgress = null;
          el.classList.remove('is-busy');
          ctx.toast.success('Progression terminée.');
          ctx.timeout(function () { Atelier.status.progress(null); }, 800);
        }
      }, 60);
    },
    'progress-indeterminate': function (ctx) {
      Atelier.status.progress(true);
      ctx.status('Traitement de durée inconnue…');
      ctx.timeout(function () { Atelier.status.progress(null); ctx.status('Terminé'); }, 3000);
    },

    // Occupation + appel manuel de l'API
    busy: function (ctx, el) {
      el.classList.add('is-busy');
      ctx.busy(true);
      ctx.status('Appel long en cours…');
      ctx.api.post('slow', { seconds: 2 })
        .then(function (envelope) { ctx.toast.success(envelope.message || 'Terminé.'); ctx.status('Appel long terminé'); })
        .catch(function (err) { ctx.toast.fromError(err); })
        .finally(function () { ctx.busy(false); el.classList.remove('is-busy'); });
    },

    // Dialogues
    'dialog-confirm': function (ctx) {
      ctx.dialog.confirm({ title: 'Confirmation', message: 'Voulez-vous continuer ?', confirmLabel: 'Continuer' }).then(function (ok) { showDialogResult(ctx, 'confirm → ' + ok); });
    },
    'dialog-confirm-danger': function (ctx) {
      ctx.dialog.confirm({ title: 'Suppression', message: 'Supprimer définitivement cet élément fictif ?', confirmLabel: 'Supprimer', danger: true }).then(function (ok) { showDialogResult(ctx, 'confirm(danger) → ' + ok); });
    },
    'dialog-alert': function (ctx) {
      ctx.dialog.alert({ title: 'Information', message: 'Ceci est une simple alerte modale.' }).then(function () { showDialogResult(ctx, 'alert → fermé'); });
    },
    'dialog-prompt': function (ctx) {
      ctx.dialog.prompt({ title: 'Saisie', message: 'Comment vous appelez-vous ?', label: 'Prénom', value: 'Atelier' }).then(function (value) { showDialogResult(ctx, 'prompt → ' + (value === null ? 'annulé' : JSON.stringify(value))); });
    },
    'dialog-open': function (ctx) {
      var el = Atelier.util.el;
      var name = el('input', { class: 'input', type: 'text', name: 'name', value: 'Lampe', autofocus: true });
      var color = el('select', { class: 'select', name: 'color' }, [el('option', { value: 'bleu', text: 'Bleu' }), el('option', { value: 'vert', text: 'Vert' }), el('option', { value: 'rouge', text: 'Rouge' })]);
      var body = el('div', {}, [
        el('p', { class: 'text-muted text-small', text: 'Dialogue libre : le module fournit le corps et les boutons ; le noyau gère l’ouverture, le focus, Échap et Entrée.' }),
        el('div', { class: 'form-grid' }, [
          el('div', { class: 'field' }, [el('label', { class: 'field__label', text: 'Nom' }), name]),
          el('div', { class: 'field' }, [el('label', { class: 'field__label', text: 'Couleur' }), color]),
        ]),
      ]);
      ctx.dialog.open({
        title: 'Dialogue libre (ctx.dialog.open)',
        body: body,
        buttons: [{ label: 'Annuler', value: 'cancel' }, { label: 'Envoyer', value: 'send', primary: true }],
        resolveWith: function (v) { return v === 'send' ? { name: name.value, color: color.value } : null; },
      }).then(function (result) { showDialogResult(ctx, 'open → ' + (result ? JSON.stringify(result) : 'annulé')); });
    },

    // Accessibilité
    announce: function (ctx) {
      Atelier.announce('Annonce de démonstration émise à ' + Atelier.util.formatTime(new Date()));
      ctx.status('Annonce envoyée à la zone aria-live (rien de visible, c’est normal)');
    },

    // Cycle de vie
    dirty: function (ctx) { ctx.setDirty(true); ctx.status('Onglet marqué modifié : essayez de le fermer'); },
    clean: function (ctx) { ctx.setDirty(false); ctx.status('Marque « modifié » retirée'); },
    'counter-reset': function (ctx) { startCounter(ctx); var node = ctx.root.querySelector('[data-demo-counter]'); if (node) node.textContent = '0'; },
    'hooks-clear': function (ctx) { ctx.data.hooks = []; renderHookLog(ctx); },
    'missing-css': function (ctx, el) {
      var url = BASE + '/module-assets/demo/assets/inexistant.css';
      el.classList.add('is-busy');
      Atelier.resources.acquire(url, 'css')
        .then(function () { ctx.toast.warning('La feuille a été chargée : elle existe donc (inattendu).'); })
        .catch(function (err) { ctx.toast.fromError(err); ctx.status('Ressource manquante détectée : ' + url); })
        .finally(function () { el.classList.remove('is-busy'); });
    },
    'resource-count': function (ctx) {
      var tab = Atelier.tabs.get('demo');
      var css = tab ? tab.assets.filter(function (u) { return u.indexOf('/assets/demo.css') !== -1; })[0] : null;
      if (!css) { ctx.toast.warning('Feuille demo.css introuvable dans les ressources de l’onglet.'); return; }
      ctx.toast.info('demo.css : ' + Atelier.resources.count(css) + ' référence(s) — ' + css, { sticky: true });
    },

    // Formulaires
    'quantity-plus': function (ctx) {
      var input = ctx.root.querySelector('#f-quantity');
      if (!input) return;
      input.value = String((parseInt(input.value, 10) || 0) + 10);
      input.dispatchEvent(new Event('input', { bubbles: true })); // déclenche data-track-dirty
    },
  };

  function showDialogResult(ctx, text) {
    var node = ctx.root.querySelector('[data-demo-dialog-result]');
    if (node) node.textContent = text;
    ctx.status(text);
  }

  // ---------------------------------------------------------------------------
  // Affichage des résultats d'actions (événement atelier:submitted émis par le noyau)
  // ---------------------------------------------------------------------------
  function onSubmitted(ctx, form, envelope) {
    if (form.hasAttribute('data-demo-results')) {
      var body = ctx.root.querySelector('[data-demo-results-body]');
      var data = envelope.data || {};
      if (!body) return;
      if (!data.rows || !data.rows.length) {
        body.innerHTML = '<tr><td colspan="4" class="table__empty">Aucun article' + (data.q ? ' pour « ' + esc(data.q) + ' »' : '') + '.</td></tr>';
        return;
      }
      body.innerHTML = data.rows.map(function (r) {
        return '<tr><td class="col-num mono">' + r.id + '</td><td><a href="#" data-route="shared?item=' + r.id + '">' + esc(r.name) + '</a></td><td><span class="badge badge--muted">' + esc(r.category) + '</span></td><td class="col-num">' + r.quantity + '</td></tr>';
      }).join('') + (data.total > data.rows.length ? '<tr><td colspan="4" class="text-muted text-small">… et ' + (data.total - data.rows.length) + ' autre(s) — voir l’écran Tableaux.</td></tr>' : '');
    }
    if (form.id === 'demo-main-form') {
      var box = ctx.root.querySelector('[data-demo-form-result]');
      if (box) { box.hidden = false; box.querySelector('code').textContent = JSON.stringify(envelope.data, null, 2); }
    }
  }

  // ---------------------------------------------------------------------------
  // Enregistrement du module : tous les hooks du cycle de vie
  // ---------------------------------------------------------------------------
  Atelier.modules.register('demo', {
    mount: function (ctx) {
      ctx.data.hooks = [];
      logHook(ctx, 'mount', 'onglet créé');
      // Délégation sur la racine du panneau : survit aux changements de vue, libérée avec l'onglet.
      ctx.on(ctx.root, 'click', '[data-demo]', function (e, el) {
        var demo = demos[el.dataset.demo];
        if (demo) { e.preventDefault(); demo(ctx, el); }
      });
      ctx.on(ctx.root, 'change', '[data-demo-select-all]', function (e, el) {
        Array.prototype.forEach.call(ctx.root.querySelectorAll('input[name="ids[]"]'), function (box) { box.checked = el.checked; });
        updateSelection(ctx);
      });
      ctx.on(ctx.root, 'change', 'input[name="ids[]"]', function () { updateSelection(ctx); });
      ctx.on(ctx.root, 'click', '[data-demo-bulk]', function (e, el) {
        var op = ctx.root.querySelector('[data-demo-bulk-op]');
        if (op) op.value = el.dataset.demoBulk; // le clic précède la soumission du formulaire
      });
      ctx.on(ctx.root, 'atelier:submitted', 'form[data-action]', function (e, form) { onSubmitted(ctx, form, e.detail.envelope); });
      ctx.on(ctx.root, 'atelier:action', '[data-demo-banner-counter]', function (e, el) {
        var n = ((e.detail.envelope.data || {}).n || 1) + 1; // incrémente le paramètre du prochain appel
        el.dataset.params = JSON.stringify({ n: n });
      });
    },
    render: function (ctx, view) {
      var route = Atelier.util.splitRoute(view.route || '').path || 'index';
      logHook(ctx, 'render', 'route « ' + (view.route || 'index') + ' »');
      if (route !== 'lifecycle') { stopCounter(ctx); }
      if (screens[route]) screens[route](ctx, view);
      if (window.MiniSparkline) MiniSparkline.render(ctx.root);
    },
    suspend: function (ctx) { logHook(ctx, 'suspend', 'onglet masqué : minuteries suspendues'); setCounterState(ctx, false); },
    resume: function (ctx) { logHook(ctx, 'resume', 'onglet réactivé : minuteries reprises'); setCounterState(ctx, true); },
    beforeClose: function (ctx) { logHook(ctx, 'beforeClose', 'fermeture demandée (retourne true)'); return true; },
    unmount: function (ctx) { stopCounter(ctx); logHook(ctx, 'unmount', 'onglet détruit : écouteurs et minuteries libérés par le noyau'); },
  });
})();
