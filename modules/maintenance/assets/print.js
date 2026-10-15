/*
 * Atelier, par ETDEL — https://github.com/theptitprince/Atelier
 * Copyright (C) 2026 ETDEL — licence GPL-3.0-or-later
 * Conditions additionnelles (article 7) : voir le fichier NOTICE.
 */
/* Fiche d'intervention imprimable : bouton « Imprimer » (la CSP interdit les gestionnaires en ligne). */
(function () {
  'use strict';
  var button = document.getElementById('maintenance-print');
  if (button) {
    button.addEventListener('click', function () { window.print(); });
  }
})();
