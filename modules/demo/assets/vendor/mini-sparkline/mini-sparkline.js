/*! mini-sparkline 1.0.0 — MIT — trace une mini-courbe SVG dans tout élément [data-sparkline="1,3,2,5"].
 *  Options : data-width (défaut 120), data-height (défaut 32). API : MiniSparkline.render(root), MiniSparkline.draw(node). */
(function (global) {
  'use strict';

  function draw(node) {
    var values = String(node.getAttribute('data-sparkline') || '').split(',').map(parseFloat).filter(isFinite);
    var w = parseInt(node.getAttribute('data-width') || '120', 10);
    var h = parseInt(node.getAttribute('data-height') || '32', 10);
    var pad = 3;
    node.innerHTML = '';
    node.classList.add('mini-sparkline-host');
    if (values.length < 2) { return; }
    var min = Math.min.apply(null, values);
    var max = Math.max.apply(null, values);
    var span = (max - min) || 1;
    var points = values.map(function (v, i) {
      return [pad + i * (w - 2 * pad) / (values.length - 1), h - pad - (v - min) * (h - 2 * pad) / span];
    });
    var line = points.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' ');
    var area = line + ' L' + points[points.length - 1][0].toFixed(1) + ' ' + (h - pad) + ' L' + points[0][0].toFixed(1) + ' ' + (h - pad) + ' Z';
    var last = points[points.length - 1];
    node.innerHTML =
      '<svg class="mini-sparkline" viewBox="0 0 ' + w + ' ' + h + '" width="' + w + '" height="' + h + '" aria-hidden="true" focusable="false">' +
        '<path class="mini-sparkline__area" d="' + area + '"/>' +
        '<path class="mini-sparkline__line" d="' + line + '"/>' +
        '<circle class="mini-sparkline__dot" cx="' + last[0].toFixed(1) + '" cy="' + last[1].toFixed(1) + '" r="2.5"/>' +
      '</svg>';
  }

  function render(root) {
    var nodes = (root || document).querySelectorAll('[data-sparkline]');
    Array.prototype.forEach.call(nodes, draw);
    return nodes.length;
  }

  global.MiniSparkline = { version: '1.0.0', draw: draw, render: render };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { render(document); });
  } else {
    render(document);
  }
})(window);
