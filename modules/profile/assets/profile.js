/**
 * Module Mon profil : compléments côté client, sans logique métier.
 *  - après enregistrement des préférences, applique immédiatement le repli de la colonne ;
 *  - après modification du profil, reflète le nouveau nom affiché dans le pied de la colonne.
 * Tout le reste (formulaires, erreurs de champs, directives) est géré par le noyau.
 */
(function () {
  'use strict';

  Atelier.modules.register('profile', {
    render(ctx) {
      // Les écouteurs posés via ctx.on() vivent jusqu'à la fermeture de l'onglet : on ne les pose qu'une fois,
      // en délégation sur la racine du panneau, qui persiste d'une vue à l'autre.
      if (ctx.data.bound) return;
      ctx.data.bound = true;

      ctx.on(ctx.root, 'atelier:submitted', 'form[data-action="save-preferences"]', (e) => {
        const data = e.detail && e.detail.envelope ? e.detail.envelope.data : null;
        if (data && typeof data.sidebarCollapsed === 'boolean') Atelier.nav.setCollapsed(data.sidebarCollapsed);
      });

      ctx.on(ctx.root, 'atelier:submitted', 'form[data-action="save"]', (e) => {
        const data = e.detail && e.detail.envelope ? e.detail.envelope.data : null;
        if (data && typeof data.displayName === 'string') {
          const label = document.querySelector('.sidebar__username');
          if (label) label.textContent = data.displayName;
        }
      });

      ctx.on(ctx.root, 'atelier:submitted', 'form[data-action="password"]', (e) => {
        const data = e.detail && e.detail.envelope ? e.detail.envelope.data : null;
        if (data && data.csrfToken && Atelier.api && typeof Atelier.api.setToken === 'function') Atelier.api.setToken(data.csrfToken);
        const form = e.target.closest ? e.target.closest('form') : null;
        if (form && typeof form.reset === 'function') form.reset();
      });
    },
  });
})();
