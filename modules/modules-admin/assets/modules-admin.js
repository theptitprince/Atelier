/**
 * Gestion des modules — comportements JavaScript optionnels :
 *   1. rafraîchissement de la colonne de navigation après toute action réussie du module ;
 *   2. glisser-déposer HTML5 sur la vue « Ordre d'affichage » (groupes, modules, accès directs),
 *      qui envoie la liste ordonnée à l'action POST « reorder ».
 *
 * Tout le reste (boutons Monter / Descendre, changement de groupe, formulaires) fonctionne sans
 * JavaScript propre grâce aux attributs déclaratifs interprétés par le noyau.
 */
(function () {
  'use strict';
  if (!window.Atelier || !Atelier.modules) return;

  Atelier.modules.register('modules-admin', {
    mount(ctx) {
      // 1. Reconstruire la colonne de gauche dès qu'une action ou un formulaire du module aboutit.
      var refreshNav = function () { if (Atelier.nav && typeof Atelier.nav.refresh === 'function') Atelier.nav.refresh(); };
      ctx.on(ctx.root, 'atelier:action', refreshNav);
      ctx.on(ctx.root, 'atelier:submitted', refreshNav);
      ctx.on(ctx.banner, 'atelier:action', refreshNav);

      // 2. Glisser-déposer (délégation sur la racine : le contenu est remplacé à chaque rendu).
      var dragged = null; // { el, type, from }

      function zoneOf(el) { return el ? el.closest('[data-dropzone]') : null; }
      // Zone la plus proche acceptant l'élément glissé (remonte au-dessus des zones imbriquées d'un autre type).
      function acceptingZoneOf(el) {
        var zone = zoneOf(el);
        while (zone && !accepts(zone)) zone = zoneOf(zone.parentElement);
        return zone;
      }
      function items(zone) { return Array.prototype.filter.call(zone.children, function (c) { return c.hasAttribute('data-drag-id'); }); }
      function clearTargets() { ctx.root.querySelectorAll('[data-dropzone].is-drop-target').forEach(function (z) { z.classList.remove('is-drop-target'); }); }
      function accepts(zone) { return !!(dragged && zone && zone.dataset.dropzone === dragged.type && (dragged.type !== 'nav' || zone === dragged.from)); }

      ctx.on(ctx.root, 'dragstart', '[data-drag-type]', function (e, item) {
        // Un élément imbriqué (accès direct dans un module) : ne prendre que le plus proche.
        if (e.target !== item && e.target.closest('[data-drag-type]') !== item) return;
        if (e.target.closest('input, select, button, a, textarea')) { e.preventDefault(); return; }
        dragged = { el: item, type: item.dataset.dragType, from: zoneOf(item.parentElement) };
        item.classList.add('is-dragging');
        try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', item.dataset.dragId); } catch (err) { /* IE / restrictions */ }
        e.stopPropagation();
      });

      ctx.on(ctx.root, 'dragover', function (e) {
        if (!dragged) return;
        var zone = acceptingZoneOf(e.target);
        if (!zone) return;
        e.preventDefault();
        try { e.dataTransfer.dropEffect = 'move'; } catch (err) { /* ignoré */ }
        clearTargets();
        zone.classList.add('is-drop-target');

        // Positionnement en direct : avant ou après l'élément survolé selon la moitié traversée.
        var over = e.target.closest('[data-drag-id]');
        while (over && over.parentElement !== zone) over = over.parentElement ? over.parentElement.closest('[data-drag-id]') : null;
        if (over && over !== dragged.el) {
          var rect = over.getBoundingClientRect();
          var after = (e.clientY - rect.top) > rect.height / 2;
          zone.insertBefore(dragged.el, after ? over.nextSibling : over);
        } else if (!over && items(zone).indexOf(dragged.el) === -1) {
          zone.appendChild(dragged.el);
        }
      });

      ctx.on(ctx.root, 'dragleave', function (e) {
        var zone = zoneOf(e.target);
        if (zone && !zone.contains(e.relatedTarget)) zone.classList.remove('is-drop-target');
      });

      ctx.on(ctx.root, 'drop', function (e) {
        if (!dragged) return;
        var zone = acceptingZoneOf(e.target) || zoneOf(dragged.el.parentElement);
        if (!accepts(zone)) return;
        e.preventDefault();
        e.stopPropagation();
        finish(zone);
      });

      ctx.on(ctx.root, 'dragend', function () {
        // Dépôt hors zone : l'élément est peut-être déjà déplacé dans le DOM → on enregistre sa position réelle.
        if (dragged) {
          var zone = zoneOf(dragged.el.parentElement);
          if (zone && zone !== dragged.from) finish(zone);
          else if (zone && orderChanged(zone)) finish(zone);
          else cancel();
        }
      });

      function orderChanged(zone) {
        var current = items(zone).map(function (i) { return i.dataset.dragId; });
        return zone.dataset.initialOrder !== undefined && zone.dataset.initialOrder !== current.join(',');
      }

      function cancel() {
        if (dragged) dragged.el.classList.remove('is-dragging');
        clearTargets();
        dragged = null;
      }

      function finish(zone) {
        var payload = { type: dragged.type, ids: items(zone).map(function (i) { return i.dataset.dragId; }) };
        if (dragged.type === 'module') payload.group = zone.dataset.group;
        if (dragged.type === 'nav') payload.module = zone.dataset.module;
        cancel();
        var root = ctx.root.querySelector('[data-order-root]');
        if (root) root.classList.add('is-saving');
        ctx.busy(true);
        ctx.api.post('reorder', payload).then(function (envelope) {
          if (envelope && envelope.message) ctx.toast.show({ level: envelope.level || 'success', message: envelope.message });
          refreshNav();
          ctx.refresh();
        }).catch(function (err) {
          ctx.toast.fromError(err);
          ctx.refresh();
        }).finally(function () {
          ctx.busy(false);
        });
      }
    },

    render(ctx) {
      // Mémoriser l'ordre initial de chaque zone pour détecter un dépôt effectif sans changement.
      ctx.root.querySelectorAll('[data-dropzone]').forEach(function (zone) {
        zone.dataset.initialOrder = Array.prototype.filter.call(zone.children, function (c) { return c.hasAttribute('data-drag-id'); })
          .map(function (c) { return c.dataset.dragId; }).join(',');
      });
    },
  });
})();
