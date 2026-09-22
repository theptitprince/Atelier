/* Fiche d'intervention imprimable : bouton « Imprimer » (la CSP interdit les gestionnaires en ligne). */
(function () {
  'use strict';
  var button = document.getElementById('maintenance-print');
  if (button) {
    button.addEventListener('click', function () { window.print(); });
  }
})();
