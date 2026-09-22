/**
 * Module Carte : carte Leaflet des points GPS avec ce qui leur est relié.
 *  - fonds OpenStreetMap / OpenSeaMap (carte marine = OSM + balisage), balisage en surimpression ;
 *  - calques par tag, par module lié, « avec pièces jointes », « sans lien » ;
 *  - liste triable et filtrable, synchronisée avec la carte (clic → recentrage + fenêtre d'information) ;
 *  - préférences (fond, balisage, position, tri) enregistrées côté serveur.
 * Toutes les données viennent de l'action « points » ; aucune logique métier ici.
 */
(function () {
  'use strict';

  const TILES = {
    osm: { url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', attribution: '© contributeurs OpenStreetMap', maxZoom: 19 },
    seamarks: { url: 'https://tiles.openseamap.org/seamark/{z}/{x}/{y}.png', attribution: '© OpenSeaMap', maxZoom: 18 },
  };

  function distanceKm(a, b) {
    const R = 6371.0088; const toRad = (d) => d * Math.PI / 180;
    const dLat = toRad(b.lat - a.lat); const dLon = toRad(b.lon - a.lon);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
  }
  function fmtDistance(km) { return km < 1 ? Math.round(km * 1000) + ' m' : km.toFixed(km < 10 ? 2 : km < 100 ? 1 : 0).replace('.', ',') + ' km'; }
  function normalize(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

  Atelier.modules.register('map', {
    render(ctx) {
      const canvas = ctx.root.querySelector('[data-map-canvas]');
      if (!canvas) return;
      if (typeof L === 'undefined') { ctx.toast.error('Bibliothèque de carte (Leaflet) non chargée.'); return; }
      const util = ctx.util;
      const state = ctx.state || {};
      const prefs = Object.assign({ base: 'osm', seamarks: false, lat: 46.6, lon: 2.5, zoom: 6, sort: 'name' }, state.prefs || {});
      if (state.tileBase) L.Icon.Default.imagePath = state.tileBase + 'images/';

      // Une carte précédente (rendu antérieur de la vue) est détruite proprement.
      if (ctx.data.map) { try { ctx.data.map.remove(); } catch (e) { /* ignoré */ } ctx.data.map = null; }

      const map = L.map(canvas, { zoomControl: true, attributionControl: true }).setView([prefs.lat, prefs.lon], prefs.zoom);
      ctx.data.map = map;
      const base = L.tileLayer(TILES.osm.url, { attribution: TILES.osm.attribution, maxZoom: TILES.osm.maxZoom }).addTo(map);
      const seamarks = L.tileLayer(TILES.seamarks.url, { attribution: TILES.seamarks.attribution, maxZoom: TILES.seamarks.maxZoom, opacity: 0.95 });
      const markers = L.featureGroup().addTo(map);
      let points = [];
      let moduleLabels = {};
      let filters = { tags: new Set(), modules: new Set(), attachments: false, unlinked: false };
      let query = '';
      let sort = prefs.sort;
      let selected = null;

      function applyBase(key, withSeamarks) {
        const marine = key === 'seamap' || withSeamarks;
        if (marine && !map.hasLayer(seamarks)) seamarks.addTo(map);
        if (!marine && map.hasLayer(seamarks)) map.removeLayer(seamarks);
      }
      applyBase(prefs.base, prefs.seamarks);

      const savePrefs = util.debounce(() => {
        const c = map.getCenter();
        const baseInput = ctx.root.querySelector('[data-map-base]:checked');
        const seam = ctx.root.querySelector('[data-map-seamarks]');
        ctx.api.post('prefs', { base: baseInput ? baseInput.value : 'osm', seamarks: !!(seam && seam.checked), lat: c.lat, lon: c.lng, zoom: map.getZoom(), sort }).catch(() => { /* silencieux */ });
      }, 800);
      map.on('moveend zoomend', savePrefs);

      ctx.on(ctx.root, 'change', '[data-map-base]', (e, input) => { applyBase(input.value, ctx.root.querySelector('[data-map-seamarks]').checked); savePrefs(); });
      ctx.on(ctx.root, 'change', '[data-map-seamarks]', (e, input) => { applyBase(ctx.root.querySelector('[data-map-base]:checked').value, input.checked); savePrefs(); });
      ctx.on(ctx.root, 'change', '[data-map-sort]', (e, select) => { sort = select.value; renderList(); savePrefs(); });
      ctx.on(ctx.root, 'input', '[data-map-search]', (e, input) => { query = normalize(input.value.trim()); renderAll(); });
      ctx.on(ctx.banner, 'click', '[data-map-reload]', () => load());

      function visible(p) {
        if (query) {
          const hay = normalize([p.name, p.code, p.address, p.description, p.tags.join(' '), p.links.map((l) => l.label).join(' ')].join(' '));
          if (!hay.includes(query)) return false;
        }
        if (filters.tags.size && !p.tags.some((t) => filters.tags.has(t))) return false;
        if (filters.modules.size && !p.links.some((l) => filters.modules.has(l.module))) return false;
        if (filters.attachments && !p.attachments) return false;
        if (filters.unlinked && (p.links.length || p.attachments)) return false;
        return true;
      }

      function popupFor(p) {
        const root = util.el('div', {}, [
          util.el('div', { class: 'map__popup-title', text: p.name + (p.code ? ' [' + p.code + ']' : '') }),
          util.el('p', { class: 'mono text-small', text: p.dms }),
          p.address ? util.el('p', { text: p.address }) : null,
          p.altitude != null ? util.el('p', { class: 'text-muted text-small', text: 'Altitude : ' + Math.round(p.altitude) + ' m' }) : null,
          p.tags.length ? util.el('div', { class: 'chips' }, p.tags.map((t) => util.el('span', { class: 'chip', text: t }))) : null,
        ]);
        if (p.links.length) {
          root.appendChild(util.el('p', { class: 'text-muted text-small mt-2 mb-0', text: 'Relié à :' }));
          root.appendChild(util.el('ul', { class: 'map__popup-links' }, p.links.map((l) => util.el('li', {}, [
            util.icon('puzzle', 'icon--sm text-muted'),
            util.el('a', { href: '#', 'data-open-module': l.module, 'data-open-route': l.dataset === 'news.archive' ? 'archive/' + l.key : (l.dataset === 'wiki.page' ? 'show/' + l.key : null), text: l.label, title: (moduleLabels[l.module] || l.module) + ' · ' + l.dataset }),
          ]))));
        }
        if (p.attachments) root.appendChild(util.el('p', { class: 'text-muted text-small', text: p.attachments + ' pièce(s) jointe(s)' }));
        root.appendChild(util.el('div', { class: 'map__popup-actions' }, [
          util.el('a', { href: '#', class: 'btn btn--sm', 'data-open-module': 'geo', 'data-open-route': 'show/' + p.id }, [util.icon('map-pin'), 'Fiche du point']),
          util.el('a', { href: 'https://www.openstreetmap.org/?mlat=' + p.lat + '&mlon=' + p.lon + '#map=15/' + p.lat + '/' + p.lon, class: 'btn btn--sm btn--ghost', target: '_blank', rel: 'noopener noreferrer' }, [util.icon('external'), 'OSM']),
        ]));
        return root;
      }

      function renderMarkers() {
        markers.clearLayers();
        points.forEach((p) => {
          if (!visible(p)) { p.marker = null; return; }
          const marker = L.marker([p.lat, p.lon], { title: p.name });
          marker.bindPopup(() => popupFor(p), { maxWidth: 320 });
          marker.on('click', () => select(p.id, false));
          marker.addTo(markers);
          p.marker = marker;
        });
      }

      function sorted() {
        const center = map.getCenter();
        const list = points.filter(visible);
        const c = { lat: center.lat, lon: center.lng };
        list.sort((a, b) => {
          if (sort === 'updated') return String(b.updated_at).localeCompare(String(a.updated_at));
          if (sort === 'links') return (b.links.length + b.attachments) - (a.links.length + a.attachments) || a.name.localeCompare(b.name);
          if (sort === 'distance') return distanceKm(c, a) - distanceKm(c, b);
          return a.name.localeCompare(b.name, 'fr');
        });
        return list;
      }

      function renderList() {
        const listEl = ctx.root.querySelector('[data-map-list]');
        const countEl = ctx.root.querySelector('[data-map-count]');
        if (!listEl) return;
        listEl.innerHTML = '';
        const center = map.getCenter();
        const list = sorted();
        list.forEach((p) => {
          const meta = [p.dms];
          if (sort === 'distance') meta.push(fmtDistance(distanceKm({ lat: center.lat, lon: center.lng }, p)));
          if (p.links.length) meta.push(p.links.length + ' lien(s)');
          if (p.attachments) meta.push(p.attachments + ' fichier(s)');
          listEl.appendChild(util.el('li', { class: 'list__item', role: 'option', tabindex: '0', 'data-map-point': String(p.id), 'aria-selected': selected === p.id ? 'true' : 'false' }, [
            util.el('span', { text: p.name + (p.code ? ' [' + p.code + ']' : '') }),
            util.el('span', { class: 'map__list-meta' }, meta.map((m) => util.el('span', { text: m }))),
          ]));
        });
        if (countEl) countEl.textContent = String(list.length);
        if (!list.length) listEl.appendChild(util.el('li', { class: 'list__item text-muted', text: points.length ? 'Aucun point ne correspond aux filtres.' : 'Aucun point référencé.' }));
      }

      function renderLayers() {
        const box = ctx.root.querySelector('[data-map-layers]');
        if (!box) return;
        box.innerHTML = '';
        const tags = new Map(); const modules = new Map();
        let withFiles = 0; let unlinked = 0;
        points.forEach((p) => {
          p.tags.forEach((t) => tags.set(t, (tags.get(t) || 0) + 1));
          p.links.forEach((l) => modules.set(l.module, (modules.get(l.module) || 0) + 1));
          if (p.attachments) withFiles++;
          if (!p.links.length && !p.attachments) unlinked++;
        });
        const check = (label, count, checked, onChange) => util.el('label', {}, [
          util.el('input', { type: 'checkbox', checked: checked || null, onchange: (e) => { onChange(e.target.checked); renderAll(); } }),
          util.el('span', { class: 'grow', text: label }),
          util.el('span', { class: 'badge badge--muted', text: String(count) }),
        ]);
        box.appendChild(util.el('h5', { text: 'Liens' }));
        box.appendChild(check('Avec pièces jointes', withFiles, filters.attachments, (v) => { filters.attachments = v; }));
        box.appendChild(check('Sans lien', unlinked, filters.unlinked, (v) => { filters.unlinked = v; }));
        if (modules.size) {
          box.appendChild(util.el('h5', { text: 'Reliés au module' }));
          Array.from(modules.keys()).sort().forEach((m) => box.appendChild(check(moduleLabels[m] || m, modules.get(m), filters.modules.has(m), (v) => { v ? filters.modules.add(m) : filters.modules.delete(m); })));
        }
        if (tags.size) {
          box.appendChild(util.el('h5', { text: 'Tags' }));
          Array.from(tags.keys()).sort((a, b) => a.localeCompare(b, 'fr')).forEach((t) => box.appendChild(check(t, tags.get(t), filters.tags.has(t), (v) => { v ? filters.tags.add(t) : filters.tags.delete(t); })));
        }
        if (!tags.size && !modules.size) box.appendChild(util.el('p', { class: 'text-muted text-small mb-0', text: 'Ajoutez des tags ou des rattachements aux points pour obtenir des calques.' }));
      }

      function renderAll() { renderMarkers(); renderList(); }

      function select(id, fly) {
        selected = id;
        ctx.root.querySelectorAll('[data-map-point]').forEach((li) => li.setAttribute('aria-selected', String(li.dataset.mapPoint) === String(id) ? 'true' : 'false'));
        const p = points.find((x) => x.id === id);
        if (!p) return;
        if (fly) {
          map.flyTo([p.lat, p.lon], Math.max(map.getZoom(), 13), { duration: 0.6 });
          if (p.marker) map.once('moveend', () => p.marker.openPopup());
        }
        ctx.status(p.name + ' · ' + p.dms);
      }
      ctx.on(ctx.root, 'click', '[data-map-point]', (e, li) => select(parseInt(li.dataset.mapPoint, 10), true));
      ctx.on(ctx.root, 'keydown', '[data-map-point]', (e, li) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select(parseInt(li.dataset.mapPoint, 10), true); } });
      map.on('moveend', () => { if (sort === 'distance') renderList(); });

      async function load() {
        ctx.busy(true);
        try {
          const envelope = await ctx.api.get('points');
          points = (envelope.data && envelope.data.points) || [];
          moduleLabels = (envelope.data && envelope.data.modules) || {};
          renderLayers();
          renderAll();
          if (state.focus) {
            select(state.focus, true);
          } else if (points.length && !ctx.data.fitted) {
            ctx.data.fitted = true;
            if (!(state.prefs && state.prefs.lat !== undefined && Object.keys(state.prefs).length)) map.fitBounds(markers.getBounds().pad(0.2));
          }
          ctx.status(points.length + ' point(s) affiché(s)');
        } catch (err) { ctx.toast.fromError(err); } finally { ctx.busy(false); }
      }
      load();
      setTimeout(() => map.invalidateSize(), 50);
    },
    resume(ctx) { if (ctx.data.map) setTimeout(() => ctx.data.map.invalidateSize(), 30); },
    unmount(ctx) { if (ctx.data.map) { try { ctx.data.map.remove(); } catch (e) { /* ignoré */ } ctx.data.map = null; } },
  });
})();
