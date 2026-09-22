/**
 * Module Actualités : compléments côté client, sans logique métier.
 *  - l'ouverture d'une entrée (lien vers la source) la marque comme lue ;
 *  - le bouton œil bascule l'état lu / non lu sans recharger la vue ;
 *  - le lien « Joindre un fichier » d'une archive résout l'identifiant de registre à la volée.
 */
(function () {
  'use strict';

  Atelier.modules.register('news', {
    render(ctx) {
      if (ctx.data.bound) return;
      ctx.data.bound = true;

      function apply(card, read) {
        card.classList.toggle('is-read', read);
        card.classList.toggle('is-unread', !read);
        const toggle = card.querySelector('[data-news-toggle]');
        if (toggle) {
          toggle.title = read ? 'Marquer non lue' : 'Marquer lue';
          toggle.setAttribute('aria-label', toggle.title);
          const use = toggle.querySelector('use');
          if (use) use.setAttribute('href', read ? '#i-eye-off' : '#i-check');
        }
      }

      async function mark(id, read) {
        const card = ctx.root.querySelector('[data-news-item="' + id + '"]');
        try {
          const envelope = await ctx.api.post('read', { id, read });
          if (card) apply(card, !!(envelope.data && envelope.data.read));
          if (envelope.data && typeof envelope.data.unread === 'number') {
            const badge = document.querySelector('[data-badge="news"]');
            if (badge) { badge.textContent = envelope.data.unread ? String(envelope.data.unread) : ''; badge.hidden = !envelope.data.unread; }
          }
        } catch (err) { ctx.toast.fromError(err); }
      }

      ctx.on(ctx.root, 'click', '[data-news-open]', (e, link) => {
        const card = link.closest('[data-news-item]');
        if (card && card.classList.contains('is-unread')) mark(parseInt(link.dataset.newsOpen, 10), true);
      });
      ctx.on(ctx.root, 'click', '[data-news-toggle]', (e, button) => {
        e.preventDefault();
        const card = button.closest('[data-news-item]');
        mark(parseInt(button.dataset.newsToggle, 10), !(card && card.classList.contains('is-read')));
      });
    },
  });
})();
