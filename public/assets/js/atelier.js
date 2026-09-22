/*
 * Atelier — noyau JavaScript (natif, sans dépendance).
 *
 * Responsabilités : appels fetch avec enveloppe commune et CSRF, colonne des modules,
 * onglets (un par module), bandeau fourni par le module, barre d'état, toaster, boîtes de
 * dialogue, gestion des ressources CSS/JS par comptage de références, historique de navigation,
 * cycle de vie des modules (mount/render/suspend/resume/beforeClose/unmount), comportements
 * déclaratifs (data-route, data-action, formulaires) et expiration de session.
 *
 * Un module enregistre son code avec :
 *   Atelier.modules.register('notes', { mount(ctx) {}, render(ctx, view) {}, suspend(ctx) {},
 *                                        resume(ctx) {}, beforeClose(ctx) {}, unmount(ctx) {} });
 */
(function () {
  'use strict';

  const configEl = document.getElementById('atelier-config');
  const CONFIG = configEl ? JSON.parse(configEl.textContent) : {};
  const BASE = CONFIG.baseUrl || '';

  // ---------------------------------------------------------------------------
  // Utilitaires
  // ---------------------------------------------------------------------------
  const util = {
    escape(text) {
      return String(text == null ? '' : text)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    },
    el(tag, attrs, children) {
      const node = document.createElement(tag);
      if (attrs) {
        Object.keys(attrs).forEach((key) => {
          const value = attrs[key];
          if (value == null || value === false) return;
          if (key === 'class') node.className = value;
          else if (key === 'text') node.textContent = value;
          else if (key === 'html') node.innerHTML = value;
          else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2), value);
          else if (key === 'dataset') Object.assign(node.dataset, value);
          else node.setAttribute(key, value === true ? '' : value);
        });
      }
      (children || []).forEach((child) => {
        if (child == null) return;
        node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
      });
      return node;
    },
    icon(name, cls) {
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('class', 'icon' + (cls ? ' ' + cls : ''));
      svg.setAttribute('aria-hidden', 'true');
      const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
      use.setAttribute('href', '#i-' + name);
      svg.appendChild(use);
      return svg;
    },
    iconHtml(name, cls) {
      return '<svg class="icon' + (cls ? ' ' + cls : '') + '" aria-hidden="true"><use href="#i-' + util.escape(name) + '"></use></svg>';
    },
    formatTime(date) {
      const d = date || new Date();
      return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0') + ':' + String(d.getSeconds()).padStart(2, '0');
    },
    debounce(fn, wait) {
      let timer = null;
      return function () {
        const args = arguments;
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(null, args), wait);
      };
    },
    storage(kind) {
      try { return kind === 'local' ? window.localStorage : window.sessionStorage; } catch (e) { return null; }
    },
    splitRoute(route) {
      const raw = String(route || '');
      const q = raw.indexOf('?');
      return q === -1 ? { path: raw, query: '' } : { path: raw.slice(0, q), query: raw.slice(q + 1) };
    },
  };

  function announce(text) {
    const node = document.getElementById('sr-announcer');
    if (!node) return;
    node.textContent = '';
    setTimeout(() => { node.textContent = text; }, 30);
  }

  // ---------------------------------------------------------------------------
  // Erreur applicative côté client
  // ---------------------------------------------------------------------------
  class AtelierError extends Error {
    constructor(message, kind, status, errorId, details) {
      super(message || 'Une erreur est survenue.');
      this.kind = kind || 'server';
      this.status = status || 0;
      this.errorId = errorId || null;
      this.details = details || {};
      this.fields = (details && details.fields) || {};
    }
  }

  // ---------------------------------------------------------------------------
  // API fetch
  // ---------------------------------------------------------------------------
  let csrfToken = CONFIG.csrfToken || (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  let lastActivity = Date.now();

  const api = {
    url(path) {
      if (/^https?:\/\//.test(path)) return path;
      return BASE + (path.startsWith('/') ? path : '/' + path);
    },
    async request(method, path, body, options) {
      options = options || {};
      const headers = { 'X-Atelier-Request': 'json', 'Accept': 'application/json' };
      if (method !== 'GET' && method !== 'HEAD') headers[CONFIG.csrfHeader || 'X-CSRF-Token'] = csrfToken;
      let payload = undefined;
      if (body instanceof FormData) {
        payload = body;
      } else if (body !== undefined && body !== null) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
      }
      let response;
      try {
        response = await fetch(api.url(path), { method, headers, body: payload, credentials: 'same-origin', signal: options.signal, cache: 'no-store' });
      } catch (e) {
        if (e && e.name === 'AbortError') throw e;
        status.setConnection(false);
        throw new AtelierError('Impossible de joindre le serveur. Vérifiez votre connexion.', 'network', 0);
      }
      status.setConnection(true);
      lastActivity = Date.now();
      status.touch();

      const type = response.headers.get('Content-Type') || '';
      if (!type.includes('application/json')) {
        if (response.ok) return { ok: true, data: await response.text(), raw: true };
        throw new AtelierError('Réponse inattendue du serveur (' + response.status + ').', 'server', response.status);
      }
      const envelope = await response.json();
      if (envelope && envelope.ok === false) {
        const error = envelope.error || {};
        const err = new AtelierError(envelope.message, error.type, response.status, envelope.errorId, error);
        if (err.kind === 'auth') session.expired(error.expired);
        if (err.kind === 'csrf') session.refreshToken();
        throw err;
      }
      return envelope;
    },
    get(path, options) { return api.request('GET', path, undefined, options); },
    post(path, body, options) { return api.request('POST', path, body || {}, options); },
    setToken(token) { if (token) csrfToken = token; },
  };

  // ---------------------------------------------------------------------------
  // Toaster
  // ---------------------------------------------------------------------------
  const toast = (function () {
    const container = document.getElementById('toaster');
    const ICONS = { info: 'info', success: 'success', warning: 'warning', error: 'error' };
    const DURATIONS = { info: 5000, success: 4000 };
    const active = new Map();

    function show(opts) {
      if (!container) return null;
      const level = ICONS[opts.level] ? opts.level : 'info';
      const message = String(opts.message || '');
      const key = level + '|' + message + '|' + (opts.errorId || '');
      if (active.has(key)) {
        const existing = active.get(key);
        existing.count += 1;
        const badge = existing.node.querySelector('.toast__count');
        badge.hidden = false;
        badge.textContent = '×' + existing.count;
        restart(existing);
        return existing.node;
      }
      const sticky = opts.sticky != null ? opts.sticky : (level === 'warning' || level === 'error');
      const node = util.el('div', { class: 'toast toast--' + level, role: level === 'error' || level === 'warning' ? 'alert' : 'status', tabindex: '0' });
      node.appendChild(util.icon(ICONS[level], 'toast__icon'));
      const body = util.el('div', { class: 'toast__body' });
      if (opts.title) body.appendChild(util.el('p', { class: 'toast__title', text: opts.title }));
      body.appendChild(util.el('p', { class: 'toast__message', text: message }));
      if (opts.errorId) body.appendChild(util.el('span', { class: 'toast__ref', text: 'Référence : ' + opts.errorId }));
      if (opts.actions && opts.actions.length) {
        const actions = util.el('div', { class: 'toast__actions' });
        opts.actions.forEach((action) => {
          actions.appendChild(util.el('button', { type: 'button', class: 'btn btn--sm', text: action.label, onclick: () => { action.onClick(); dismiss(node); } }));
        });
        body.appendChild(actions);
      }
      node.appendChild(body);
      node.appendChild(util.el('span', { class: 'toast__count', hidden: true }));
      node.appendChild(util.el('button', { type: 'button', class: 'toast__close', 'aria-label': 'Fermer la notification', onclick: () => dismiss(node) }, [util.icon('close')]));
      node.addEventListener('keydown', (e) => { if (e.key === 'Escape' || e.key === 'Delete') dismiss(node); });
      container.appendChild(node);
      const entry = { node, key, count: 1, timer: null, sticky, level };
      active.set(key, entry);
      restart(entry);
      announce(message);
      return node;
    }
    function restart(entry) {
      clearTimeout(entry.timer);
      if (!entry.sticky) entry.timer = setTimeout(() => dismiss(entry.node), DURATIONS[entry.level] || 5000);
    }
    function dismiss(node) {
      for (const [key, entry] of active) {
        if (entry.node === node) { active.delete(key); clearTimeout(entry.timer); break; }
      }
      node.classList.add('is-leaving');
      setTimeout(() => node.remove(), 150);
    }
    return {
      show,
      info: (message, opts) => show(Object.assign({ level: 'info', message }, opts)),
      success: (message, opts) => show(Object.assign({ level: 'success', message }, opts)),
      warning: (message, opts) => show(Object.assign({ level: 'warning', message }, opts)),
      error: (message, opts) => show(Object.assign({ level: 'error', message }, opts)),
      fromError(err) {
        if (!err) return;
        if (err.name === 'AbortError') return;
        if (err instanceof AtelierError) {
          if (err.kind === 'auth') return; // géré par la fenêtre de session
          const level = err.kind === 'validation' ? 'warning' : (err.kind === 'network' ? 'warning' : 'error');
          return show({ level, message: err.message, errorId: err.errorId });
        }
        console.error(err);
        return show({ level: 'error', message: 'Une erreur inattendue est survenue dans l’interface.' });
      },
      clear() { active.forEach((entry) => dismiss(entry.node)); },
    };
  })();

  // ---------------------------------------------------------------------------
  // Barre d'état
  // ---------------------------------------------------------------------------
  const status = (function () {
    const messageEl = document.getElementById('status-message');
    const progressWrap = document.getElementById('status-progress');
    const progressBar = document.getElementById('status-progress-bar');
    const moduleEl = document.getElementById('status-module');
    const connectionEl = document.getElementById('status-connection');
    const refreshEl = document.getElementById('status-refresh-time');
    let clearTimer = null;
    return {
      message(text, level, autoClear) {
        if (!messageEl) return;
        clearTimeout(clearTimer);
        messageEl.textContent = text || '';
        messageEl.className = 'statusbar__message' + (level ? ' is-' + level : '');
        if (text && autoClear !== false) clearTimer = setTimeout(() => this.clear(), 8000);
      },
      clear() { if (messageEl) { messageEl.textContent = ''; messageEl.className = 'statusbar__message'; } },
      progress(percent) {
        if (!progressWrap) return;
        if (percent == null || percent === false) { progressWrap.hidden = true; progressBar.removeAttribute('value'); return; }
        progressWrap.hidden = false;
        if (percent === true) progressBar.removeAttribute('value'); else progressBar.value = Math.max(0, Math.min(100, percent));
      },
      module(text) { if (moduleEl) moduleEl.textContent = text || ''; },
      setConnection(ok) {
        if (!connectionEl) return;
        connectionEl.classList.toggle('is-offline', !ok);
        const label = connectionEl.querySelector('.statusbar__label');
        if (label) label.textContent = ok ? 'Connecté' : 'Hors ligne';
      },
      touch() { if (refreshEl) refreshEl.textContent = util.formatTime(); },
    };
  })();

  // ---------------------------------------------------------------------------
  // Boîtes de dialogue (élément <dialog> natif)
  // ---------------------------------------------------------------------------
  const dialog = (function () {
    const root = document.getElementById('dialog');
    const titleEl = document.getElementById('dialog-title');
    const bodyEl = document.getElementById('dialog-body');
    const footerEl = document.getElementById('dialog-footer');
    let resolver = null;
    let currentOpts = null;

    function close(value) {
      if (!root || !root.open) return;
      root.close(typeof value === 'string' ? value : 'cancel');
    }
    if (root) {
      root.addEventListener('close', () => {
        const value = root.returnValue || 'cancel';
        const opts = currentOpts;
        currentOpts = null;
        root.classList.remove('dialog--wide');
        if (resolver) { const r = resolver; resolver = null; r(opts && opts.resolveWith ? opts.resolveWith(value) : value); }
      });
      root.addEventListener('cancel', (e) => {
        if (currentOpts && currentOpts.closable === false) e.preventDefault();
      });
      root.addEventListener('click', (e) => {
        if (e.target.closest('[data-dialog-close]')) { e.preventDefault(); if (!currentOpts || currentOpts.closable !== false) close('cancel'); }
      });
      document.getElementById('dialog-form').addEventListener('submit', (e) => {
        // La soumission par Entrée dans un formulaire de dialogue déclenche le bouton principal.
        const submitter = e.submitter;
        if (submitter && submitter.value) root.returnValue = submitter.value;
      });
    }

    function open(opts) {
      if (!root) return Promise.resolve('cancel');
      if (root.open) close('cancel');
      currentOpts = opts;
      titleEl.textContent = opts.title || '';
      bodyEl.innerHTML = '';
      if (typeof opts.body === 'string') bodyEl.innerHTML = opts.body;
      else if (opts.body instanceof Node) bodyEl.appendChild(opts.body);
      footerEl.innerHTML = '';
      (opts.buttons || []).forEach((btn) => {
        const button = util.el('button', {
          type: btn.submit === false ? 'button' : 'submit',
          value: btn.value,
          class: 'btn ' + (btn.primary ? (btn.danger ? 'btn--danger' : 'btn--primary') : ''),
          text: btn.label,
        });
        if (btn.submit === false) button.addEventListener('click', () => btn.onClick && btn.onClick(button));
        footerEl.appendChild(button);
      });
      root.querySelector('.dialog__close').hidden = opts.closable === false;
      root.classList.toggle('dialog--wide', !!opts.wide);
      root.returnValue = '';
      root.showModal();
      const focusTarget = bodyEl.querySelector('[autofocus], input, select, textarea') || footerEl.querySelector('.btn--primary, .btn--danger') || footerEl.querySelector('button');
      if (focusTarget) setTimeout(() => focusTarget.focus(), 20);
      return new Promise((resolve) => { resolver = resolve; });
    }

    return {
      open,
      close,
      body: () => bodyEl,
      confirm(opts) {
        opts = typeof opts === 'string' ? { message: opts } : (opts || {});
        return open({
          title: opts.title || 'Confirmation',
          body: util.el('p', { class: 'mb-0', text: opts.message || 'Confirmez-vous cette action ?' }),
          buttons: [
            { label: opts.cancelLabel || 'Annuler', value: 'cancel' },
            { label: opts.confirmLabel || 'Confirmer', value: 'ok', primary: true, danger: !!opts.danger },
          ],
          resolveWith: (v) => v === 'ok',
        });
      },
      alert(opts) {
        opts = typeof opts === 'string' ? { message: opts } : (opts || {});
        return open({ title: opts.title || 'Information', body: util.el('p', { class: 'mb-0', text: opts.message }), buttons: [{ label: 'Fermer', value: 'ok', primary: true }] });
      },
      prompt(opts) {
        opts = opts || {};
        const input = util.el('input', { class: 'input', type: opts.type || 'text', value: opts.value || '', autofocus: true, name: 'value', required: !!opts.required });
        const body = util.el('div', {}, [
          opts.message ? util.el('p', { text: opts.message }) : null,
          util.el('div', { class: 'field' }, [opts.label ? util.el('label', { class: 'field__label', text: opts.label }) : null, input]),
        ]);
        return open({ title: opts.title || 'Saisie', body, buttons: [{ label: 'Annuler', value: 'cancel' }, { label: opts.confirmLabel || 'Valider', value: 'ok', primary: true }], resolveWith: (v) => (v === 'ok' ? input.value : null) });
      },
    };
  })();

  // ---------------------------------------------------------------------------
  // Ressources CSS/JS par comptage de références
  // ---------------------------------------------------------------------------
  const resources = (function () {
    const refs = new Map(); // url => { count, element, promise }
    function acquire(url, type) {
      let entry = refs.get(url);
      if (entry) { entry.count += 1; return entry.promise; }
      entry = { count: 1, element: null, promise: null };
      entry.promise = new Promise((resolve, reject) => {
        let element;
        if (type === 'css') {
          element = util.el('link', { rel: 'stylesheet', href: url, 'data-atelier-resource': '' });
        } else {
          element = util.el('script', { src: url, 'data-atelier-resource': '' });
        }
        element.addEventListener('load', () => resolve(url));
        element.addEventListener('error', () => { refs.delete(url); element.remove(); reject(new AtelierError('Ressource indispensable non chargée : ' + url, 'unavailable')); });
        entry.element = element;
        document.head.appendChild(element);
      });
      refs.set(url, entry);
      return entry.promise;
    }
    function release(url) {
      const entry = refs.get(url);
      if (!entry) return;
      entry.count -= 1;
      if (entry.count <= 0) { entry.element && entry.element.remove(); refs.delete(url); }
    }
    async function acquireAll(assets) {
      const urls = [];
      for (const url of assets.css || []) { urls.push(url); }
      await Promise.all((assets.css || []).map((u) => acquire(u, 'css')));
      for (const url of assets.js || []) { urls.push(url); await acquire(url, 'js'); }
      return urls;
    }
    return { acquire, release, acquireAll, count: (url) => (refs.get(url) || { count: 0 }).count };
  })();

  // ---------------------------------------------------------------------------
  // Registre des modules (code JavaScript optionnel fourni par les modules)
  // ---------------------------------------------------------------------------
  const moduleDefs = new Map();
  const modules = {
    register(id, def) { moduleDefs.set(id, def || {}); const tab = tabs.get(id); if (tab && !tab.mounted) tabs.mountIfReady(tab); },
    get(id) { return moduleDefs.get(id) || null; },
  };

  // ---------------------------------------------------------------------------
  // Onglets, panneaux, bandeaux et cycle de vie
  // ---------------------------------------------------------------------------
  const tabs = (function () {
    const tabsEl = document.getElementById('tabs');
    const workspace = document.getElementById('workspace');
    const bannerEl = document.getElementById('banner');
    const emptyEl = document.getElementById('workspace-empty');
    const bannerPlaceholder = bannerEl.querySelector('.banner__placeholder');
    const open = new Map(); // moduleId => tab
    let activeId = null;

    function get(id) { return open.get(id) || null; }
    function active() { return activeId ? open.get(activeId) : null; }

    function createContext(tab) {
      const listeners = [];
      const timers = new Set();
      const ctx = {
        moduleId: tab.id,
        get root() { return tab.panel.querySelector('.panel__content'); },
        get banner() { return tab.bannerEl; },
        get info() { return tab.info; },
        get route() { return tab.route; },
        get view() { return tab.view; },
        get state() { return tab.view ? tab.view.state : {}; },
        data: {},
        api: {
          url: (route) => BASE + '/m/' + tab.id + (route ? '/' + String(route).replace(/^\//, '') : ''),
          get: (route, options) => api.get(ctx.api.url(route), options),
          post: (route, body, options) => api.post(ctx.api.url(route), body, options),
        },
        navigate: (route, options) => navigate(tab.id, route, options),
        refresh: () => navigate(tab.id, tab.route, { replace: true, force: true }),
        close: () => close(tab.id),
        setDirty: (dirty) => setDirty(tab.id, dirty),
        isDirty: () => tab.dirty,
        isActive: () => activeId === tab.id,
        status: (text) => { tab.statusText = text || ''; if (activeId === tab.id) status.module(tab.statusText); },
        busy: (on) => { const b = tab.bannerEl.querySelector('[data-banner-busy]'); if (b) b.hidden = !on; tab.panel.classList.toggle('is-busy', !!on); },
        toast, dialog, util, announce,
        on(target, event, selectorOrHandler, maybeHandler) {
          const selector = typeof selectorOrHandler === 'string' ? selectorOrHandler : null;
          const handler = selector ? maybeHandler : selectorOrHandler;
          const wrapped = selector ? (e) => { const match = e.target.closest(selector); if (match && target.contains(match)) handler.call(match, e, match); } : handler;
          target.addEventListener(event, wrapped);
          listeners.push({ target, event, wrapped });
          return () => target.removeEventListener(event, wrapped);
        },
        interval(fn, ms) {
          const timer = { fn, ms, id: null, kind: 'interval' };
          timer.id = tab.suspended ? null : setInterval(fn, ms);
          timers.add(timer);
          return () => { clearInterval(timer.id); timers.delete(timer); };
        },
        timeout(fn, ms) {
          const timer = { kind: 'timeout', id: setTimeout(() => { timers.delete(timer); fn(); }, ms) };
          timers.add(timer);
          return () => { clearTimeout(timer.id); timers.delete(timer); };
        },
        _suspendTimers() { timers.forEach((t) => { if (t.kind === 'interval' && t.id) { clearInterval(t.id); t.id = null; } }); },
        _resumeTimers() { timers.forEach((t) => { if (t.kind === 'interval' && !t.id) t.id = setInterval(t.fn, t.ms); }); },
        _dispose() {
          listeners.forEach((l) => l.target.removeEventListener(l.event, l.wrapped));
          listeners.length = 0;
          timers.forEach((t) => { if (t.kind === 'interval') clearInterval(t.id); else clearTimeout(t.id); });
          timers.clear();
        },
      };
      return ctx;
    }

    function callHook(tab, name, arg) {
      const def = tab.def || moduleDefs.get(tab.id);
      if (!def || typeof def[name] !== 'function') return undefined;
      try { return def[name](tab.ctx, arg); } catch (e) { console.error('[Atelier] Module ' + tab.id + ' — ' + name, e); toast.error('Erreur dans le module « ' + (tab.info ? tab.info.name : tab.id) + ' » (' + name + ').'); }
    }

    function mountIfReady(tab) {
      const def = moduleDefs.get(tab.id);
      if (!def || tab.mounted) return;
      tab.def = def;
      tab.mounted = true;
      callHook(tab, 'mount');
      if (tab.view) callHook(tab, 'render', tab.view);
    }

    function create(id, info) {
      const tab = {
        id, info: info || { id, name: id, icon: 'module' }, route: null, view: null, dirty: false, suspended: true,
        mounted: false, def: null, assets: [], statusText: '', requestSeq: 0, abort: null, loaded: false,
      };
      tab.panel = util.el('section', { class: 'panel', id: 'panel-' + id, role: 'tabpanel', 'aria-labelledby': 'tab-' + id, 'data-module': id }, [
        util.el('div', { class: 'panel__loader' }, [util.el('span', { class: 'spinner' }), 'Chargement…']),
        util.el('div', { class: 'panel__content' }),
      ]);
      workspace.appendChild(tab.panel);
      tab.bannerEl = util.el('div', { class: 'banner__module', 'data-module': id, hidden: true });
      bannerEl.appendChild(tab.bannerEl);
      tab.tabEl = util.el('button', { type: 'button', class: 'tab', id: 'tab-' + id, role: 'tab', 'aria-selected': 'false', 'aria-controls': 'panel-' + id, 'data-module': id, title: tab.info.name });
      tab.tabEl.appendChild(util.icon(tab.info.icon || 'module', 'icon--sm tab__icon'));
      tab.tabEl.appendChild(util.el('span', { class: 'tab__label', text: tab.info.name }));
      tab.tabEl.appendChild(util.el('span', { class: 'tab__dirty', title: 'Modifications non enregistrées' }));
      const closeBtn = util.el('span', { class: 'tab__close', role: 'button', tabindex: '0', title: 'Fermer l’onglet', 'aria-label': 'Fermer ' + tab.info.name });
      closeBtn.appendChild(util.icon('close', 'icon--sm'));
      closeBtn.addEventListener('click', (e) => { e.stopPropagation(); close(id); });
      closeBtn.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); close(id); } });
      tab.tabEl.appendChild(closeBtn);
      tab.tabEl.addEventListener('click', () => {
        // Un onglet déjà actif ramène à la page de base du module ; un onglet inactif s'active tel quel.
        const base = tab.info && tab.info.defaultRoute ? tab.info.defaultRoute : null;
        if (activeId === id && base && tab.route !== base) navigate(id, base);
        else activate(id, { push: true });
      });
      tab.tabEl.addEventListener('auxclick', (e) => { if (e.button === 1) { e.preventDefault(); close(id); } });
      tabsEl.appendChild(tab.tabEl);
      tab.ctx = createContext(tab);
      open.set(id, tab);
      behaviors.bind(tab);
      return tab;
    }

    function updateTabInfo(tab, info) {
      tab.info = info;
      tab.tabEl.title = info.name;
      tab.tabEl.querySelector('.tab__label').textContent = info.name;
      const use = tab.tabEl.querySelector('.tab__icon use');
      if (use) use.setAttribute('href', '#i-' + (info.icon || 'module'));
    }

    function activate(id, options) {
      options = options || {};
      const tab = open.get(id);
      if (!tab) return;
      if (activeId && activeId !== id) {
        const previous = open.get(activeId);
        if (previous) suspend(previous);
      }
      activeId = id;
      emptyEl.hidden = true;
      bannerPlaceholder.hidden = true;
      open.forEach((t) => {
        const isActive = t.id === id;
        t.panel.classList.toggle('is-active', isActive);
        t.bannerEl.hidden = !isActive;
        t.tabEl.setAttribute('aria-selected', isActive ? 'true' : 'false');
        t.tabEl.tabIndex = isActive ? 0 : -1;
      });
      status.module(tab.statusText);
      document.title = (tab.view && tab.view.title ? tab.view.title + ' — ' : '') + (CONFIG.appName || 'Atelier');
      nav.highlight(id, tab.route);
      resume(tab);
      if (options.push !== false) router.push(id, tab.route, options.replace);
      tab.tabEl.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }

    function suspend(tab) {
      if (tab.suspended) return;
      tab.suspended = true;
      if (!(tab.info && tab.info.keepAlive)) tab.ctx._suspendTimers();
      callHook(tab, 'suspend');
    }
    function resume(tab) {
      if (!tab.suspended) return;
      tab.suspended = false;
      tab.ctx._resumeTimers();
      callHook(tab, 'resume');
    }

    function setDirty(id, dirty) {
      const tab = open.get(id);
      if (!tab) return;
      tab.dirty = !!dirty;
      tab.tabEl.classList.toggle('is-dirty', tab.dirty);
    }

    async function close(id, options) {
      options = options || {};
      const tab = open.get(id);
      if (!tab) return true;
      if (!options.force) {
        const hook = callHook(tab, 'beforeClose');
        let allowed = hook === undefined ? true : await hook;
        if (allowed && tab.dirty) {
          allowed = await dialog.confirm({ title: 'Modifications non enregistrées', message: 'L’onglet « ' + tab.info.name + ' » contient des modifications non enregistrées. Fermer quand même ?', confirmLabel: 'Fermer sans enregistrer', danger: true });
        }
        if (!allowed) return false;
      }
      if (tab.abort) tab.abort.abort();
      suspend(tab);
      callHook(tab, 'unmount');
      tab.ctx._dispose();
      tab.assets.forEach((url) => resources.release(url));
      tab.panel.remove();
      tab.bannerEl.remove();
      tab.tabEl.remove();
      open.delete(id);
      const wasActive = activeId === id;
      if (wasActive) {
        activeId = null;
        const remaining = Array.from(open.keys());
        if (remaining.length) {
          activate(remaining[remaining.length - 1], { push: true });
        } else if (CONFIG.homeModule && id !== CONFIG.homeModule) {
          openModule(CONFIG.homeModule, null, {});
        } else {
          emptyEl.hidden = false;
          bannerPlaceholder.hidden = false;
          status.module('');
          document.title = CONFIG.appName || 'Atelier';
          nav.highlight(null);
          router.push(null, null);
        }
      }
      return true;
    }

    function renderError(tab, err) {
      const map = {
        forbidden: ['denied', 'lock', 'Accès refusé'],
        auth: ['denied', 'lock', 'Session expirée'],
        not_found: ['empty', 'search', 'Introuvable'],
        unavailable: ['unavailable', 'warning', 'Module indisponible'],
        network: ['unavailable', 'warning', 'Serveur injoignable'],
        server: ['error', 'error', 'Erreur technique'],
      };
      const [type, icon, title] = map[err.kind] || map.server;
      const content = tab.panel.querySelector('.panel__content');
      content.innerHTML = '';
      const state = util.el('div', { class: 'state state--' + type, role: 'alert' }, [
        util.icon(icon, 'icon--xl'),
        util.el('p', { class: 'state__title', text: title }),
        util.el('p', { class: 'state__message', text: err.message }),
        err.errorId ? util.el('p', { class: 'text-muted text-small', text: 'Référence : ' + err.errorId }) : null,
        util.el('div', { class: 'flex' }, [
          util.el('button', { type: 'button', class: 'btn', onclick: () => tab.ctx.refresh() }, [util.icon('refresh'), 'Réessayer']),
          util.el('button', { type: 'button', class: 'btn btn--ghost', onclick: () => close(tab.id, { force: true }) }, [util.icon('close'), 'Fermer l’onglet']),
        ]),
      ]);
      content.appendChild(state);
      if (!tab.bannerEl.children.length) {
        tab.bannerEl.innerHTML = '<div class="banner__title">' + util.iconHtml(tab.info.icon || 'module', 'icon--lg') + '<span>' + util.escape(tab.info.name) + '</span></div>';
      }
    }

    /**
     * Charge une route dans l'onglet (crée l'onglet si nécessaire) et l'active.
     */
    async function navigate(id, route, options) {
      options = options || {};
      let tab = open.get(id);
      const created = !tab;
      if (!tab) tab = create(id, options.info);
      if (tab.route === route && tab.loaded && !options.force) {
        activate(id, { push: options.push !== false, replace: options.replace });
        return tab;
      }
      if (tab.dirty && !options.force && tab.route !== route) {
        const ok = await dialog.confirm({ title: 'Modifications non enregistrées', message: 'Quitter cette vue sans enregistrer ?', confirmLabel: 'Quitter sans enregistrer', danger: true });
        if (!ok) { activate(id, { push: false }); return tab; }
        setDirty(id, false);
      }
      activate(id, { push: false });
      const seq = ++tab.requestSeq;
      if (tab.abort) tab.abort.abort();
      tab.abort = new AbortController();
      tab.panel.classList.add('is-loading');
      tab.tabEl.classList.add('is-loading');
      const url = BASE + '/m/' + id + (route ? '/' + String(route).replace(/^\//, '') : '');
      try {
        const envelope = await api.get(url, { signal: tab.abort.signal });
        if (seq !== tab.requestSeq || !open.has(id)) return tab; // réponse obsolète
        const view = envelope.data;
        const info = view.module;
        if (info) {
          updateTabInfo(tab, info);
          // Ressources du module : acquises avant l'affichage, conservées tant que l'onglet existe.
          const wanted = [].concat(info.assets.css || [], info.assets.js || []);
          const newAssets = wanted.filter((u) => !tab.assets.includes(u));
          if (newAssets.length) {
            await resources.acquireAll({ css: newAssets.filter((u) => (info.assets.css || []).includes(u)), js: newAssets.filter((u) => (info.assets.js || []).includes(u)) });
            if (seq !== tab.requestSeq || !open.has(id)) return tab;
            tab.assets = tab.assets.concat(newAssets);
          }
        }
        tab.route = view.route || route || (info && info.defaultRoute) || '';
        tab.view = view;
        tab.loaded = true;
        tab.bannerEl.innerHTML = view.banner || ('<div class="banner__title">' + util.iconHtml(tab.info.icon || 'module', 'icon--lg') + '<span>' + util.escape(view.title || tab.info.name) + '</span></div>');
        const content = tab.panel.querySelector('.panel__content');
        content.innerHTML = view.content || '';
        content.scrollTop = 0;
        setDirty(id, !!view.dirty);
        tab.statusText = (view.status && view.status.text) || '';
        if (activeId === id) {
          status.module(tab.statusText);
          document.title = (view.title ? view.title + ' — ' : '') + (CONFIG.appName || 'Atelier');
          nav.highlight(id, tab.route);
          router.push(id, tab.route, options.replace || options.push === false);
        }
        if (view.status && view.status.message) status.message(view.status.message, view.status.level || '');
        ui.enhance(content);
        ui.enhance(tab.bannerEl);
        if (!tab.mounted) mountIfReady(tab); else callHook(tab, 'render', view);
        behaviors.autofocus(content);
      } catch (err) {
        if (err && err.name === 'AbortError') return tab;
        if (seq !== tab.requestSeq || !open.has(id)) return tab;
        tab.loaded = false;
        renderError(tab, err instanceof AtelierError ? err : new AtelierError(err && err.message, 'server'));
        if (err instanceof AtelierError && err.kind === 'auth') { /* fenêtre de session */ } else if (!(err instanceof AtelierError)) console.error(err);
        if (activeId === id) router.push(id, route, true);
      } finally {
        if (seq === tab.requestSeq) { tab.panel.classList.remove('is-loading'); tab.tabEl.classList.remove('is-loading'); }
      }
      return tab;
    }

    function openModule(id, route, options) {
      const known = nav.moduleInfo(id);
      return navigate(id, route || (known && known.route) || null, Object.assign({ info: known ? { id, name: known.name, icon: known.icon } : undefined }, options || {}));
    }

    function all() { return Array.from(open.values()); }

    // Navigation clavier entre onglets
    tabsEl.addEventListener('keydown', (e) => {
      const ids = Array.from(open.keys());
      if (!ids.length) return;
      const index = ids.indexOf(activeId);
      if (e.key === 'ArrowRight') { e.preventDefault(); const next = ids[(index + 1) % ids.length]; activate(next, { push: true }); open.get(next).tabEl.focus(); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); const prev = ids[(index - 1 + ids.length) % ids.length]; activate(prev, { push: true }); open.get(prev).tabEl.focus(); }
      if (e.key === 'Delete' && activeId) { e.preventDefault(); close(activeId); }
    });

    return { get, active, activate, close, navigate, open: openModule, setDirty, all, mountIfReady, callHook };
  })();

  // ---------------------------------------------------------------------------
  // Historique de navigation (URL /m/{module}/{route})
  // ---------------------------------------------------------------------------
  const router = (function () {
    let suppress = false;
    function urlFor(module, route) {
      if (!module) return BASE + '/';
      return BASE + '/m/' + module + (route ? '/' + String(route).replace(/^\//, '') : '');
    }
    function push(module, route, replace) {
      if (suppress) return;
      const url = urlFor(module, route);
      const state = { module, route };
      const current = window.location.pathname + window.location.search;
      if (current === url) { history.replaceState(state, '', url); return; }
      if (replace) history.replaceState(state, '', url); else history.pushState(state, '', url);
    }
    function parse(pathname, search) {
      const path = pathname.startsWith(BASE) ? pathname.slice(BASE.length) : pathname;
      const match = path.match(/^\/m\/([a-z0-9_-]+)(?:\/(.*))?$/);
      if (!match) return { module: null, route: null };
      return { module: match[1], route: (match[2] || '') + (search || '') };
    }
    window.addEventListener('popstate', (e) => {
      const target = (e.state && e.state.module !== undefined) ? e.state : parse(window.location.pathname, window.location.search);
      suppress = true;
      try {
        if (!target.module) {
          const active = tabs.active();
          if (active && CONFIG.homeModule && active.id !== CONFIG.homeModule) tabs.open(CONFIG.homeModule, null, { push: false });
        } else {
          tabs.open(target.module, target.route, { push: false });
        }
      } finally { suppress = false; }
    });
    return { push, parse, urlFor };
  })();

  // ---------------------------------------------------------------------------
  // Colonne des modules (arborescence)
  // ---------------------------------------------------------------------------
  const nav = (function () {
    const container = document.getElementById('nav-tree');
    const app = document.getElementById('app');
    const toggle = document.getElementById('sidebar-toggle');
    const store = util.storage('session');
    const local = util.storage('local');
    let tree = CONFIG.tree || [];
    let openBranches = new Set();
    let badges = {};
    try { openBranches = new Set(JSON.parse((store && store.getItem('atelier.nav.open')) || '[]')); } catch (e) { openBranches = new Set(); }

    function persist() { try { store && store.setItem('atelier.nav.open', JSON.stringify(Array.from(openBranches))); } catch (e) { /* ignoré */ } }

    function moduleInfo(id) {
      for (const group of tree) for (const m of group.modules) if (m.id === id) return m;
      return null;
    }

    function stateIcon(state) {
      return { locked: 'lock', inactive: 'power', maintenance: 'tool', error: 'warning' }[state] || null;
    }
    function stateTitle(state) {
      return { locked: 'Accès non autorisé', inactive: 'Module désactivé', maintenance: 'Module en maintenance', error: 'Module indisponible (manifeste invalide)' }[state] || '';
    }

    function renderEntry(module, entry, depth) {
      const li = util.el('li', { class: 'nav__item nav__item--child', role: 'treeitem', 'data-module': module.id, 'data-route': entry.route, 'data-nav': entry.id });
      const hasChildren = entry.children && entry.children.length;
      const key = module.id + ':' + entry.id;
      if (hasChildren) { li.setAttribute('aria-expanded', openBranches.has(key) ? 'true' : 'false'); if (openBranches.has(key)) li.classList.add('is-open'); }
      const row = util.el('a', { class: 'nav__row', href: router.urlFor(module.id, entry.route), title: entry.description || entry.label, tabindex: '-1' });
      if (hasChildren) {
        const chevron = util.el('button', { type: 'button', class: 'nav__chevron', 'aria-expanded': openBranches.has(key) ? 'true' : 'false', 'aria-label': 'Développer ' + entry.label, tabindex: '-1' }, [util.icon('chevron-right', 'icon--sm')]);
        chevron.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); toggleBranch(li, key); });
        row.appendChild(chevron);
      } else {
        row.appendChild(util.el('span', { class: 'nav__chevron nav__chevron--spacer' }));
      }
      row.appendChild(util.icon(entry.icon || 'chevron-right', 'nav__icon icon--sm'));
      row.appendChild(util.el('span', { class: 'nav__label', text: entry.label }));
      row.addEventListener('click', (e) => { e.preventDefault(); tabs.open(module.id, entry.route, { push: true }); });
      li.appendChild(row);
      if (hasChildren && depth < 3) {
        const ul = util.el('ul', { class: 'nav__children', role: 'group' });
        entry.children.forEach((child) => ul.appendChild(renderEntry(module, child, depth + 1)));
        li.appendChild(ul);
      }
      return li;
    }

    function toggleBranch(li, key, force) {
      const open = force != null ? force : !li.classList.contains('is-open');
      li.classList.toggle('is-open', open);
      li.setAttribute('aria-expanded', open ? 'true' : 'false');
      const chevron = li.querySelector(':scope > .nav__row .nav__chevron');
      if (chevron) chevron.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) openBranches.add(key); else openBranches.delete(key);
      persist();
    }

    function render() {
      if (!container) return;
      container.innerHTML = '';
      if (!tree.length) { container.appendChild(util.el('p', { class: 'nav__empty', text: 'Aucun module installé.' })); return; }
      const root = util.el('ul', { class: 'nav__groups', role: 'tree' });
      tree.forEach((group) => {
        const li = util.el('li', { class: 'nav__group' });
        li.appendChild(util.el('span', { class: 'nav__group-label', text: group.label, id: 'nav-group-' + group.id }));
        const ul = util.el('ul', { role: 'group', 'aria-labelledby': 'nav-group-' + group.id });
        group.modules.forEach((module) => {
          const hasChildren = module.children && module.children.length;
          const key = module.id;
          const item = util.el('li', { class: 'nav__item nav__item--module', role: 'treeitem', 'data-module': module.id, 'data-state': module.state });
          if (hasChildren) { item.setAttribute('aria-expanded', openBranches.has(key) ? 'true' : 'false'); if (openBranches.has(key)) item.classList.add('is-open'); }
          const row = util.el('a', { class: 'nav__row', href: router.urlFor(module.id, null), title: module.state === 'active' ? (module.description || module.name) : module.name + ' — ' + stateTitle(module.state), tabindex: '-1', 'aria-disabled': module.state !== 'active' ? 'true' : null });
          if (hasChildren) {
            const chevron = util.el('button', { type: 'button', class: 'nav__chevron', 'aria-expanded': openBranches.has(key) ? 'true' : 'false', 'aria-label': 'Développer ' + module.name, tabindex: '-1' }, [util.icon('chevron-right', 'icon--sm')]);
            chevron.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); toggleBranch(item, key); });
            row.appendChild(chevron);
          } else {
            row.appendChild(util.el('span', { class: 'nav__chevron nav__chevron--spacer' }));
          }
          row.appendChild(util.icon(module.icon || 'module', 'nav__icon'));
          row.appendChild(util.el('span', { class: 'nav__label', text: module.name }));
          const badge = util.el('span', { class: 'nav__badge', hidden: true, 'data-badge': module.id });
          row.appendChild(badge);
          const icon = stateIcon(module.state);
          if (icon) row.appendChild(util.icon(icon, 'nav__state'));
          row.addEventListener('click', (e) => {
            e.preventDefault();
            if (module.state !== 'active') { toast.info(module.name + ' : ' + stateTitle(module.state).toLowerCase() + '.'); return; }
            tabs.open(module.id, null, { push: true });
          });
          item.appendChild(row);
          if (hasChildren) {
            const children = util.el('ul', { class: 'nav__children', role: 'group' });
            module.children.forEach((entry) => children.appendChild(renderEntry(module, entry, 2)));
            item.appendChild(children);
          }
          ul.appendChild(item);
        });
        li.appendChild(ul);
        root.appendChild(li);
      });
      container.appendChild(root);
      const firstRow = container.querySelector('.nav__row');
      if (firstRow) firstRow.tabIndex = 0;
      applyBadges();
      const active = tabs.active();
      if (active) highlight(active.id, active.route);
    }

    function highlight(moduleId, route) {
      if (!container) return;
      container.querySelectorAll('[aria-current]').forEach((n) => n.removeAttribute('aria-current'));
      if (!moduleId) return;
      const item = container.querySelector('.nav__item--module[data-module="' + moduleId + '"]');
      if (item) item.setAttribute('aria-current', 'true');
      if (route) {
        const path = util.splitRoute(route).path;
        const child = Array.from(container.querySelectorAll('.nav__item--child[data-module="' + moduleId + '"]')).find((n) => n.dataset.route === path);
        if (child) { if (item) item.removeAttribute('aria-current'); child.setAttribute('aria-current', 'true'); }
      }
    }

    function applyBadges() {
      Object.keys(badges).forEach((id) => {
        const node = container && container.querySelector('[data-badge="' + id + '"]');
        if (!node) return;
        const value = badges[id];
        const count = value && typeof value === 'object' ? value.count : value;
        node.hidden = !count;
        node.textContent = count > 99 ? '99+' : String(count || '');
        if (value && value.label) node.title = value.label;
      });
    }

    async function refresh() {
      try {
        const envelope = await api.get('/core/nav');
        tree = envelope.data.tree || [];
        render();
      } catch (err) { toast.fromError(err); }
    }

    async function pollBadges() {
      if (document.hidden) return;
      try {
        const envelope = await api.get('/core/badges');
        badges = envelope.data.badges || {};
        applyBadges();
      } catch (e) { /* silencieux */ }
    }

    // Clavier : flèches, Entrée, Home/End
    if (container) {
      container.addEventListener('keydown', (e) => {
        const rows = Array.from(container.querySelectorAll('.nav__row')).filter((r) => r.offsetParent !== null);
        const current = document.activeElement.closest('.nav__row');
        const index = rows.indexOf(current);
        const focusRow = (row) => { if (!row) return; rows.forEach((r) => (r.tabIndex = -1)); row.tabIndex = 0; row.focus(); };
        switch (e.key) {
          case 'ArrowDown': e.preventDefault(); focusRow(rows[Math.min(rows.length - 1, index + 1)]); break;
          case 'ArrowUp': e.preventDefault(); focusRow(rows[Math.max(0, index - 1)]); break;
          case 'Home': e.preventDefault(); focusRow(rows[0]); break;
          case 'End': e.preventDefault(); focusRow(rows[rows.length - 1]); break;
          case 'ArrowRight': {
            if (!current) return; e.preventDefault();
            const li = current.closest('.nav__item');
            const chevron = current.querySelector('.nav__chevron:not(.nav__chevron--spacer)');
            if (chevron && !li.classList.contains('is-open')) toggleBranch(li, li.dataset.nav ? li.dataset.module + ':' + li.dataset.nav : li.dataset.module, true);
            else focusRow(rows[index + 1]);
            break;
          }
          case 'ArrowLeft': {
            if (!current) return; e.preventDefault();
            const li = current.closest('.nav__item');
            if (li.classList.contains('is-open')) toggleBranch(li, li.dataset.nav ? li.dataset.module + ':' + li.dataset.nav : li.dataset.module, false);
            else { const parent = li.parentElement.closest('.nav__item'); if (parent) focusRow(parent.querySelector('.nav__row')); }
            break;
          }
          case 'Enter': case ' ': if (current) { e.preventDefault(); current.click(); } break;
          default: return;
        }
      });
    }

    // Repli de la colonne
    function setCollapsed(collapsed) {
      app.classList.toggle('sidebar-collapsed', collapsed);
      if (toggle) {
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.title = collapsed ? 'Déplier le menu' : 'Replier le menu';
        toggle.setAttribute('aria-label', toggle.title);
        toggle.querySelector('use').setAttribute('href', collapsed ? '#i-chevrons-right' : '#i-chevrons-left');
      }
      try { local && local.setItem('atelier.sidebar.collapsed', collapsed ? '1' : '0'); } catch (e) { /* ignoré */ }
    }
    if (toggle) toggle.addEventListener('click', () => setCollapsed(!app.classList.contains('sidebar-collapsed')));
    try { if (local && local.getItem('atelier.sidebar.collapsed') === '1') setCollapsed(true); } catch (e) { /* ignoré */ }

    return { render, refresh, highlight, moduleInfo, pollBadges, setCollapsed, tree: () => tree };
  })();

  // ---------------------------------------------------------------------------
  // Comportements déclaratifs dans les panneaux et bandeaux
  // ---------------------------------------------------------------------------
  const behaviors = (function () {
    function applyDirectives(tab, envelope) {
      const d = envelope.directives || {};
      if (envelope.message) toast.show({ level: envelope.level || 'success', message: envelope.message });
      if (d.status) tab.ctx.status(d.status);
      if (d.dirty !== undefined) tab.ctx.setDirty(!!d.dirty);
      if (d.banner) tab.bannerEl.innerHTML = d.banner;
      if (d.close) { tabs.close(tab.id, { force: true }); return; }
      if (d.navigate) tab.ctx.navigate(d.navigate);
      else if (d.refresh) tab.ctx.refresh();
    }

    async function runAction(tab, element) {
      const route = element.dataset.action;
      if (!route) return;
      if (element.dataset.confirm) {
        const ok = await dialog.confirm({ message: element.dataset.confirm, title: element.dataset.confirmTitle || 'Confirmation', confirmLabel: element.dataset.confirmLabel || 'Confirmer', danger: element.hasAttribute('data-danger') });
        if (!ok) return;
      }
      let params = {};
      if (element.dataset.params) { try { params = JSON.parse(element.dataset.params); } catch (e) { console.warn('data-params invalide', e); } }
      if (element.dataset.prompt) {
        const value = await dialog.prompt({ title: element.dataset.confirmTitle || 'Saisie', message: element.dataset.prompt, value: element.dataset.promptValue || '' });
        if (value === null) return;
        params[element.dataset.promptField || 'value'] = value;
      }
      element.classList.add('is-busy');
      element.setAttribute('aria-disabled', 'true');
      tab.ctx.busy(true);
      try {
        const envelope = await tab.ctx.api.post(route, params);
        applyDirectives(tab, envelope);
        tab.ctx._lastAction = envelope;
        element.dispatchEvent(new CustomEvent('atelier:action', { bubbles: true, detail: { route, envelope } }));
      } catch (err) {
        if (err instanceof AtelierError && err.kind === 'validation' && Object.keys(err.fields).length) {
          const form = element.closest('form');
          if (form) forms.showErrors(form, err.fields);
        }
        toast.fromError(err);
      } finally {
        element.classList.remove('is-busy');
        element.removeAttribute('aria-disabled');
        tab.ctx.busy(false);
      }
    }

    async function submitForm(tab, form) {
      if (form.dataset.submitting) return;
      const route = form.dataset.action;
      if (!route) return;
      forms.clearErrors(form);
      if (form.dataset.confirm) {
        const ok = await dialog.confirm({ message: form.dataset.confirm, danger: form.hasAttribute('data-danger') });
        if (!ok) return;
      }
      form.dataset.submitting = '1';
      const submitButtons = form.querySelectorAll('[type="submit"]');
      submitButtons.forEach((b) => { b.classList.add('is-busy'); b.disabled = true; });
      tab.ctx.busy(true);
      try {
        const isMultipart = form.enctype === 'multipart/form-data' || form.querySelector('input[type="file"]');
        const body = isMultipart ? new FormData(form) : forms.serialize(form);
        const envelope = await tab.ctx.api.post(route, body);
        if (!(envelope.directives && envelope.directives.dirty !== undefined)) tab.ctx.setDirty(false);
        applyDirectives(tab, envelope);
        form.dispatchEvent(new CustomEvent('atelier:submitted', { bubbles: true, detail: { envelope } }));
      } catch (err) {
        if (err instanceof AtelierError && err.kind === 'validation') {
          forms.showErrors(form, err.fields);
          if (!Object.keys(err.fields).length) toast.warning(err.message);
          else status.message(err.message, 'warning');
        } else {
          toast.fromError(err);
        }
      } finally {
        delete form.dataset.submitting;
        submitButtons.forEach((b) => { b.classList.remove('is-busy'); b.disabled = false; });
        tab.ctx.busy(false);
      }
    }

    function bind(tab) {
      const roots = [tab.panel, tab.bannerEl];
      roots.forEach((root) => {
        root.addEventListener('click', (e) => {
          const link = e.target.closest('a[data-route], a[href^="' + BASE + '/m/' + tab.id + '"], a[href^="/m/' + tab.id + '"]');
          if (link && !link.hasAttribute('data-external') && !link.hasAttribute('download') && !link.target) {
            if (link.getAttribute('aria-disabled') === 'true') { e.preventDefault(); return; }
            e.preventDefault();
            const route = link.dataset.route != null ? link.dataset.route : router.parse(link.pathname, link.search).route;
            tab.ctx.navigate(route);
            return;
          }
          const actor = e.target.closest('[data-action]:not(form)');
          if (actor && root.contains(actor)) {
            e.preventDefault();
            if (actor.getAttribute('aria-disabled') === 'true' || actor.disabled) return;
            runAction(tab, actor);
            return;
          }
          const moduleLink = e.target.closest('a[data-open-module]');
          if (moduleLink) { e.preventDefault(); tabs.open(moduleLink.dataset.openModule, moduleLink.dataset.openRoute || null, { push: true }); }
          const subtab = e.target.closest('[data-subtab]');
          if (subtab) {
            e.preventDefault();
            const group = subtab.closest('[data-subtabs]');
            if (group) {
              group.querySelectorAll('[data-subtab]').forEach((t) => t.setAttribute('aria-selected', t === subtab ? 'true' : 'false'));
              const container = document.getElementById(group.dataset.subtabs) || tab.panel;
              container.querySelectorAll('[data-subtab-panel]').forEach((p) => { p.hidden = p.dataset.subtabPanel !== subtab.dataset.subtab; });
            }
          }
        });
        root.addEventListener('submit', (e) => {
          const form = e.target;
          if (form.matches('form[data-action]')) { e.preventDefault(); submitForm(tab, form); }
        });
        root.addEventListener('input', (e) => {
          const form = e.target.closest('form[data-track-dirty]');
          if (form) tab.ctx.setDirty(true);
        });
        root.addEventListener('change', (e) => {
          const select = e.target.closest('select[data-route-select]');
          if (select) tab.ctx.navigate(select.value);
          const auto = e.target.closest('form[data-auto-submit]');
          if (auto && !e.target.matches('input[type="text"], input[type="search"]')) submitForm(tab, auto);
        });
        root.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' && e.target.matches('form[data-auto-submit] input[type="search"], form[data-auto-submit] input[type="text"]')) {
            e.preventDefault(); submitForm(tab, e.target.closest('form'));
          }
          if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            const form = tab.panel.querySelector('form[data-action][data-save-shortcut]');
            if (form) { e.preventDefault(); submitForm(tab, form); }
          }
        });
      });
    }

    function autofocus(content) {
      const target = content.querySelector('[autofocus]');
      if (target) setTimeout(() => target.focus(), 30);
    }

    return { bind, autofocus, applyDirectives, runAction, submitForm };
  })();

  // ---------------------------------------------------------------------------
  // Formulaires : sérialisation et erreurs de validation près des champs
  // ---------------------------------------------------------------------------
  const forms = {
    serialize(form) {
      const data = {};
      const fd = new FormData(form);
      for (const [key, value] of fd.entries()) {
        if (key.endsWith('[]')) { const k = key.slice(0, -2); (data[k] = data[k] || []).push(value); }
        else if (Object.prototype.hasOwnProperty.call(data, key)) { data[key] = [].concat(data[key], value); }
        else data[key] = value;
      }
      form.querySelectorAll('input[type="checkbox"]:not([name$="[]"])').forEach((cb) => { if (!cb.checked && !(cb.name in data)) data[cb.name] = ''; });
      return data;
    },
    clearErrors(form) {
      form.querySelectorAll('.field.is-invalid').forEach((f) => f.classList.remove('is-invalid'));
      form.querySelectorAll('.is-invalid').forEach((f) => f.classList.remove('is-invalid'));
      form.querySelectorAll('.field__error[data-generated]').forEach((f) => f.remove());
      form.querySelectorAll('[aria-invalid]').forEach((f) => f.removeAttribute('aria-invalid'));
      const summary = form.querySelector('.form-summary');
      if (summary) summary.remove();
    },
    showErrors(form, fields) {
      forms.clearErrors(form);
      const names = Object.keys(fields || {});
      if (!names.length) return;
      let first = null;
      names.forEach((name) => {
        const input = form.querySelector('[name="' + name + '"], [name="' + name + '[]"]');
        const field = input ? input.closest('.field') : null;
        if (field) {
          field.classList.add('is-invalid');
          let error = field.querySelector('.field__error');
          if (!error) { error = util.el('span', { class: 'field__error', 'data-generated': '' }); field.appendChild(error); }
          error.textContent = fields[name];
          error.id = error.id || ('err-' + name.replace(/[^a-z0-9_-]/gi, '-'));
          if (input) { input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', error.id); input.classList.add('is-invalid'); }
          if (!first) first = input;
        }
      });
      const summary = util.el('div', { class: 'alert alert--error form-summary', role: 'alert' }, [
        util.icon('error'),
        util.el('div', {}, [util.el('p', { class: 'alert__title', text: names.length === 1 ? 'Un champ est invalide' : names.length + ' champs sont invalides' }), util.el('ul', {}, names.map((n) => util.el('li', { text: fields[n] })))]),
      ]);
      form.prepend(summary);
      if (first) first.focus();
    },
  };

  // ---------------------------------------------------------------------------
  // Session : expiration, avertissement, mot de passe
  // ---------------------------------------------------------------------------
  const session = (function () {
    let expiredShown = false;
    let warned = false;
    const idle = (CONFIG.sessionIdleSeconds || 3600) * 1000;

    function expired(isExpired) {
      if (expiredShown) return;
      expiredShown = true;
      const next = window.location.pathname + window.location.search;
      dialog.open({
        title: isExpired ? 'Session expirée' : 'Connexion requise',
        closable: false,
        body: util.el('div', {}, [
          util.el('p', { text: isExpired ? 'Votre session a expiré après une période d’inactivité. Vos modifications non enregistrées ne peuvent pas être conservées : copiez-les si nécessaire avant de vous reconnecter.' : 'Vous devez vous reconnecter pour continuer.' }),
          util.el('p', { class: 'text-muted mb-0', text: 'Vous serez ramené sur la vue courante après connexion.' }),
        ]),
        buttons: [{ label: 'Se reconnecter', value: 'ok', primary: true }],
      }).then(() => { window.location.href = BASE + '/login?expired=' + (isExpired ? '1' : '0') + '&next=' + encodeURIComponent(next); });
    }

    async function refreshToken() {
      try { const envelope = await api.get('/core/session'); api.setToken(envelope.data.csrfToken); } catch (e) { /* ignoré */ }
    }

    function watch() {
      setInterval(() => {
        const elapsed = Date.now() - lastActivity;
        if (!warned && idle - elapsed < 120000 && idle - elapsed > 0) {
          warned = true;
          toast.warning('Votre session expirera dans moins de deux minutes par inactivité.', {
            actions: [{ label: 'Rester connecté', onClick: () => { warned = false; api.get('/core/ping').catch(() => {}); } }],
          });
        }
        if (elapsed >= idle + 5000 && !expiredShown) { api.get('/core/session').catch(() => {}); }
      }, 15000);
    }

    function passwordDialog(forced) {
      const body = util.el('div', {}, [
        forced ? util.el('div', { class: 'alert alert--warning' }, [util.icon('key'), util.el('div', { text: 'Votre mot de passe est temporaire : vous devez le remplacer avant de continuer.' })]) : null,
        forced ? null : util.el('div', { class: 'field' }, [util.el('label', { class: 'field__label', for: 'pw-current', text: 'Mot de passe actuel' }), util.el('input', { class: 'input', type: 'password', id: 'pw-current', name: 'current_password', autocomplete: 'current-password', required: true })]),
        util.el('div', { class: 'field' }, [util.el('label', { class: 'field__label', for: 'pw-new', text: 'Nouveau mot de passe' }), util.el('input', { class: 'input', type: 'password', id: 'pw-new', name: 'password', autocomplete: 'new-password', required: true, minlength: CONFIG.passwordMinLength || 12 }), util.el('span', { class: 'field__help', text: 'Au moins ' + (CONFIG.passwordMinLength || 12) + ' caractères.' })]),
        util.el('div', { class: 'field' }, [util.el('label', { class: 'field__label', for: 'pw-confirm', text: 'Confirmation' }), util.el('input', { class: 'input', type: 'password', id: 'pw-confirm', name: 'password_confirmation', autocomplete: 'new-password', required: true })]),
      ]);
      const buttons = [{ label: 'Enregistrer', value: 'save', primary: true, submit: false, onClick: submit }];
      if (!forced) buttons.unshift({ label: 'Annuler', value: 'cancel' });
      async function submit(button) {
        const form = document.getElementById('dialog-form');
        const data = forms.serialize(form);
        forms.clearErrors(form);
        button.classList.add('is-busy');
        try {
          const envelope = await api.post('/core/password', data);
          api.setToken(envelope.data && envelope.data.csrfToken);
          toast.success(envelope.message || 'Mot de passe modifié.');
          CONFIG.mustChangePassword = false;
          dialog.close('ok');
          if (forced) start();
        } catch (err) {
          if (err instanceof AtelierError && err.kind === 'validation') forms.showErrors(form, err.fields); else toast.fromError(err);
        } finally { button.classList.remove('is-busy'); }
      }
      return dialog.open({ title: 'Changer le mot de passe', body, buttons, closable: !forced });
    }

    return { expired, refreshToken, watch, passwordDialog };
  })();

  // ---------------------------------------------------------------------------
  // Composants d'interface communs : éditeur BBCode et saisie de tags
  // ---------------------------------------------------------------------------
  const bbcode = (function () {
    const SIMPLE = { b: 'strong', i: 'em', u: 'u', s: 's', h1: 'h2', h2: 'h3', h3: 'h4', center: 'div class="bb-center"', right: 'div class="bb-right"' };
    const COLORS = ['red', 'green', 'blue', 'orange', 'gray', 'grey', 'purple', 'teal', 'black'];
    function safeUrl(url) {
      url = String(url || '').trim();
      if (!url || /[\s"'<>]/.test(url)) return null;
      if (/^https?:\/\//i.test(url)) return url;
      if (url.startsWith('/') && !url.startsWith('//')) return url;
      return null;
    }
    /** Même algorithme que Atelier\View\BbCode::toHtml : échappement complet puis liste blanche. */
    function toHtml(source) {
      if (!source || !String(source).trim()) return '';
      let text = util.escape(String(source).replace(/\r\n?/g, '\n'));
      const codes = [];
      text = text.replace(/\[code\]([\s\S]*?)\[\/code\]/gi, (m, c) => { codes.push('<pre class="bb-code"><code>' + c.replace(/^\n+|\n+$/g, '') + '</code></pre>'); return ' CODE' + (codes.length - 1) + ' '; });
      Object.keys(SIMPLE).forEach((tag) => {
        const html = SIMPLE[tag];
        const close = html.split(' ')[0];
        text = text.replace(new RegExp('\\[' + tag + '\\]([\\s\\S]*?)\\[\\/' + tag + '\\]', 'gi'), '<' + html + '>$1</' + close + '>');
      });
      text = text.replace(/\[quote=(?:&quot;)?([^\]&]{1,80}?)(?:&quot;)?\]([\s\S]*?)\[\/quote\]/gi, '<blockquote class="bb-quote"><cite>$1</cite>$2</blockquote>');
      text = text.replace(/\[quote\]([\s\S]*?)\[\/quote\]/gi, '<blockquote class="bb-quote">$1</blockquote>');
      const decode = (s) => s.replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>');
      text = text.replace(/\[url=(?:&quot;)?([^\]\s&]+?)(?:&quot;)?\]([\s\S]*?)\[\/url\]/gi, (m, u, label) => { const href = safeUrl(decode(u)); return href ? '<a href="' + util.escape(href) + '" rel="noopener" target="_blank">' + label + '</a>' : label; });
      text = text.replace(/\[url\]([^\[]+?)\[\/url\]/gi, (m, u) => { const href = safeUrl(decode(u)); return href ? '<a href="' + util.escape(href) + '" rel="noopener" target="_blank">' + u + '</a>' : u; });
      text = text.replace(/\[color=(?:&quot;)?(#[0-9a-f]{3,6}|[a-z]+)(?:&quot;)?\]([\s\S]*?)\[\/color\]/gi, (m, c, inner) => { c = c.toLowerCase(); return (c.startsWith('#') || COLORS.includes(c)) ? '<span style="color:' + c + '">' + inner + '</span>' : inner; });
      text = text.replace(/\[size=small\]([\s\S]*?)\[\/size\]/gi, '<span class="bb-small">$1</span>').replace(/\[size=large\]([\s\S]*?)\[\/size\]/gi, '<span class="bb-large">$1</span>');
      text = text.replace(/\[list(=1)?\]([\s\S]*?)\[\/list\]/gi, (m, ordered, body) => { const items = body.split(/\[\*\]/).map((s) => s.trim()).filter(Boolean); const tag = ordered ? 'ol' : 'ul'; return '<' + tag + ' class="bb-list"><li>' + items.join('</li><li>') + '</li></' + tag + '>'; });
      text = text.replace(/\[hr\]/gi, '<hr>');
      text = text.replace(/\n{3,}/g, '\n\n').replace(/(<\/(?:h2|h3|h4|blockquote|ul|ol|div|pre)>|<hr>)\n+/g, '$1').replace(/\n+(<(?:h2|h3|h4|blockquote|ul|ol|div|pre|hr)\b)/g, '$1');
      text = text.replace(/\n/g, '<br>');
      text = text.replace(/ CODE(\d+) /g, (m, i) => codes[Number(i)] || '');
      return '<div class="bb">' + text + '</div>';
    }
    return { toHtml };
  })();

  const ui = (function () {
    const TOOLS = [
      { tag: 'b', label: 'Gras', icon: null, text: 'B', cls: 'editor__b', key: 'b' },
      { tag: 'i', label: 'Italique', text: 'I', cls: 'editor__i', key: 'i' },
      { tag: 'u', label: 'Souligné', text: 'U', cls: 'editor__u', key: 'u' },
      { tag: 's', label: 'Barré', text: 'S', cls: 'editor__s' },
      { sep: true },
      { tag: 'h1', label: 'Titre', text: 'H1' },
      { tag: 'h2', label: 'Sous-titre', text: 'H2' },
      { sep: true },
      { tag: 'quote', label: 'Citation', icon: 'chat' },
      { tag: 'code', label: 'Code', text: '</>' },
      { list: true, label: 'Liste à puces', icon: 'list' },
      { tag: 'url', label: 'Lien', icon: 'link', prompt: true },
      { hr: true, label: 'Séparateur', text: '—' },
    ];

    function wrapSelection(textarea, before, after) {
      const start = textarea.selectionStart, end = textarea.selectionEnd;
      const selected = textarea.value.slice(start, end);
      const insert = before + selected + after;
      textarea.setRangeText(insert, start, end, 'end');
      if (!selected) textarea.setSelectionRange(start + before.length, start + before.length);
      textarea.focus();
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /** Éditeur BBCode : barre d'outils, raccourcis Ctrl+B/I/U, aperçu. */
    function editor(textarea) {
      if (textarea.dataset.enhanced) return;
      textarea.dataset.enhanced = '1';
      const readonly = textarea.readOnly || textarea.disabled;
      const wrap = util.el('div', { class: 'editor' + (readonly ? ' editor--readonly' : '') });
      const toolbar = util.el('div', { class: 'editor__toolbar', role: 'toolbar', 'aria-label': 'Mise en forme' });
      TOOLS.forEach((tool) => {
        if (tool.sep) { toolbar.appendChild(util.el('span', { class: 'editor__sep' })); return; }
        const button = util.el('button', { type: 'button', class: 'btn btn--sm btn--ghost editor__tool ' + (tool.cls || ''), title: tool.label, 'aria-label': tool.label, disabled: readonly });
        if (tool.icon) button.appendChild(util.icon(tool.icon, 'icon--sm')); else button.textContent = tool.text;
        button.addEventListener('click', async () => {
          if (tool.list) { wrapSelection(textarea, '[list]\n[*] ', '\n[/list]'); return; }
          if (tool.hr) { wrapSelection(textarea, '\n[hr]\n', ''); return; }
          if (tool.prompt) {
            const url = await dialog.prompt({ title: 'Insérer un lien', label: 'Adresse (https://…)', value: 'https://' });
            if (!url) return;
            wrapSelection(textarea, '[url=' + url + ']', '[/url]');
            return;
          }
          wrapSelection(textarea, '[' + tool.tag + ']', '[/' + tool.tag + ']');
        });
        toolbar.appendChild(button);
      });
      toolbar.appendChild(util.el('span', { class: 'toolbar__spacer' }));
      const previewBtn = util.el('button', { type: 'button', class: 'btn btn--sm btn--ghost editor__tool', title: 'Aperçu', 'aria-pressed': 'false' }, [util.icon('eye', 'icon--sm'), ' Aperçu']);
      toolbar.appendChild(previewBtn);
      const help = util.el('details', { class: 'editor__help' }, [util.el('summary', { text: 'Aide BBCode' }), util.el('div', { class: 'text-small text-muted', html: '<code>[b]gras[/b]</code> <code>[i]italique[/i]</code> <code>[u]souligné[/u]</code> <code>[h1]Titre[/h1]</code> <code>[quote]citation[/quote]</code> <code>[code]code[/code]</code> <code>[url=https://…]lien[/url]</code> <code>[list][*] élément[/list]</code> <code>[list=1]…[/list]</code> <code>[color=red]…[/color]</code> <code>[hr]</code>' })]);
      const preview = util.el('div', { class: 'editor__preview prose', hidden: true });
      textarea.parentNode.insertBefore(wrap, textarea);
      wrap.appendChild(toolbar);
      wrap.appendChild(textarea);
      wrap.appendChild(preview);
      wrap.appendChild(help);
      textarea.classList.add('editor__textarea');
      previewBtn.addEventListener('click', () => {
        const show = preview.hidden;
        preview.innerHTML = show ? (bbcode.toHtml(textarea.value) || '<p class="text-muted">Aucun contenu.</p>') : '';
        preview.hidden = !show;
        textarea.hidden = show;
        previewBtn.setAttribute('aria-pressed', show ? 'true' : 'false');
        previewBtn.classList.toggle('is-active', show);
      });
      if (!readonly) {
        textarea.addEventListener('keydown', (e) => {
          if (!(e.ctrlKey || e.metaKey) || e.shiftKey) return;
          const tool = TOOLS.find((t) => t.key === e.key.toLowerCase());
          if (tool) { e.preventDefault(); wrapSelection(textarea, '[' + tool.tag + ']', '[/' + tool.tag + ']'); }
        });
      }
    }

    /**
     * Saisie de tags : puces, suggestions des tags existants (/core/tags), création libre.
     * L'input d'origine devient caché et conserve son name (valeurs séparées par des virgules).
     */
    function tagsInput(input) {
      if (input.dataset.enhanced) return;
      input.dataset.enhanced = '1';
      const readonly = input.readOnly || input.disabled;
      const scope = input.dataset.tagsScope || 'shared';
      const max = parseInt(input.dataset.tagsMax || '20', 10);
      let tags = String(input.value || '').split(',').map((t) => t.trim()).filter(Boolean);
      input.type = 'hidden';
      const wrap = util.el('div', { class: 'tagsinput' + (readonly ? ' tagsinput--readonly' : ''), role: 'group' });
      const chips = util.el('div', { class: 'tagsinput__chips' });
      const entry = util.el('input', { class: 'tagsinput__entry', type: 'text', placeholder: readonly ? '' : (input.placeholder || 'Ajouter un tag…'), autocomplete: 'off', 'aria-label': 'Nouveau tag', 'aria-autocomplete': 'list', disabled: readonly, id: input.id ? input.id + '-entry' : null });
      const list = util.el('ul', { class: 'tagsinput__suggestions', role: 'listbox', hidden: true });
      wrap.appendChild(chips); wrap.appendChild(entry); wrap.appendChild(list);
      input.parentNode.insertBefore(wrap, input.nextSibling);
      const label = input.id ? document.querySelector('label[for="' + input.id + '"]') : null;
      if (label && entry.id) label.setAttribute('for', entry.id);

      function sync() {
        input.value = tags.join(', ');
        chips.innerHTML = '';
        tags.forEach((tag) => {
          const chip = util.el('span', { class: 'chip' }, [util.icon('tag', 'icon--sm'), tag]);
          if (!readonly) chip.appendChild(util.el('button', { type: 'button', class: 'chip__remove', 'aria-label': 'Retirer ' + tag, onclick: () => { tags = tags.filter((t) => t !== tag); sync(); input.dispatchEvent(new Event('input', { bubbles: true })); } }, [util.icon('close', 'icon--sm')]));
          chips.appendChild(chip);
        });
      }
      function add(raw) {
        const value = String(raw || '').replace(/^#/, '').replace(/\s+/g, ' ').trim();
        if (!value) return;
        if (tags.some((t) => t.toLowerCase() === value.toLowerCase())) { entry.value = ''; return; }
        if (tags.length >= max) { toast.warning('Au maximum ' + max + ' tags.'); return; }
        tags.push(value); entry.value = ''; sync(); hide();
        input.dispatchEvent(new Event('input', { bubbles: true }));
      }
      let active = -1; let items = [];
      function hide() { list.hidden = true; list.innerHTML = ''; active = -1; items = []; entry.removeAttribute('aria-activedescendant'); }
      function render(suggestions) {
        list.innerHTML = '';
        items = suggestions.filter((s) => !tags.some((t) => t.toLowerCase() === s.name.toLowerCase()));
        const term = entry.value.trim();
        if (term && !items.some((s) => s.name.toLowerCase() === term.toLowerCase())) items.push({ name: term, create: true });
        if (!items.length) { hide(); return; }
        items.forEach((s, index) => {
          const li = util.el('li', { class: 'tagsinput__suggestion' + (s.create ? ' is-new' : ''), role: 'option', id: 'tagsug-' + index, 'aria-selected': 'false' }, [
            util.icon(s.create ? 'plus' : 'tag', 'icon--sm'),
            util.el('span', { text: s.create ? 'Créer « ' + s.name + ' »' : s.name }),
            s.count ? util.el('span', { class: 'badge badge--muted', text: String(s.count) }) : null,
          ]);
          li.addEventListener('mousedown', (e) => { e.preventDefault(); add(s.name); entry.focus(); });
          list.appendChild(li);
        });
        list.hidden = false; active = -1;
      }
      function highlight(index) {
        const options = list.querelectorAll ? [] : Array.from(list.children);
        options.forEach((o, i) => o.setAttribute('aria-selected', i === index ? 'true' : 'false'));
        active = index;
        if (index >= 0 && options[index]) { entry.setAttribute('aria-activedescendant', options[index].id); options[index].scrollIntoView({ block: 'nearest' }); }
      }
      const fetchSuggestions = util.debounce(async () => {
        const term = entry.value.trim();
        try {
          const envelope = await api.get('/core/tags?scope=' + encodeURIComponent(scope) + '&q=' + encodeURIComponent(term));
          if (document.activeElement === entry) render(envelope.data.tags || []);
        } catch (e) { /* suggestions indisponibles : la saisie libre reste possible */ }
      }, 180);
      if (!readonly) {
        entry.addEventListener('focus', fetchSuggestions);
        entry.addEventListener('input', fetchSuggestions);
        entry.addEventListener('blur', () => setTimeout(hide, 120));
        entry.addEventListener('keydown', (e) => {
          if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) fetchSuggestions(); else highlight(Math.min(items.length - 1, active + 1)); }
          else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(Math.max(0, active - 1)); }
          else if (e.key === 'Enter' || e.key === ',' || e.key === 'Tab' && entry.value.trim()) {
            if (e.key === 'Tab' && !entry.value.trim()) return;
            e.preventDefault();
            if (active >= 0 && items[active]) add(items[active].name); else add(entry.value);
          }
          else if (e.key === 'Backspace' && !entry.value && tags.length) { tags.pop(); sync(); input.dispatchEvent(new Event('input', { bubbles: true })); }
          else if (e.key === 'Escape') hide();
        });
        wrap.addEventListener('click', (e) => { if (e.target === wrap || e.target === chips) entry.focus(); });
      }
      sync();
    }

    /** Active les composants communs dans un fragment fraîchement inséré. */
    function enhance(root) {
      if (!root) return;
      root.querySelectorAll('textarea[data-editor="bbcode"]').forEach(editor);
      root.querySelectorAll('input[data-tags-input]').forEach(tagsInput);
    }
    return { enhance, editor, tagsInput, bbcode };
  })();

  // ---------------------------------------------------------------------------
  // Démarrage
  // ---------------------------------------------------------------------------
  let started = false;
  function start() {
    if (started) return;
    started = true;
    const initial = CONFIG.initial || {};
    const fromUrl = router.parse(window.location.pathname, window.location.search);
    const module = initial.module || fromUrl.module || CONFIG.homeModule;
    const route = initial.module ? (initial.route || null) + (window.location.search || '') : fromUrl.route;
    if (module) {
      if (CONFIG.homeModule && module !== CONFIG.homeModule) {
        // L'accueil occupe l'onglet initial ; la vue demandée s'ouvre par-dessus.
        tabs.open(CONFIG.homeModule, null, { push: false }).then(() => tabs.open(module, route || null, { replace: true }));
      } else {
        tabs.open(module, route || null, { replace: true });
      }
    }
    nav.pollBadges();
    setInterval(nav.pollBadges, 60000);
  }

  function init() {
    nav.render();
    status.touch();
    session.watch();
    document.querySelectorAll('[data-core-action]').forEach((button) => {
      button.addEventListener('click', () => {
        const action = button.dataset.coreAction;
        if (action === 'password') session.passwordDialog(false);
        if (action === 'profile') { if (nav.moduleInfo('profile')) tabs.open('profile', null, { push: true }); else session.passwordDialog(false); }
      });
    });
    const versionButton = document.getElementById('status-version');
    if (versionButton) {
      versionButton.addEventListener('click', async () => {
        versionButton.classList.add('is-busy');
        try {
          const envelope = await api.get('/core/changelog');
          const body = util.el('div', { class: 'changelog prose' });
          body.innerHTML = envelope.data.html; // HTML produit et échappé côté serveur
          dialog.open({ title: 'Journal des versions — Atelier v' + envelope.data.version, body, wide: true, buttons: [{ label: 'Fermer', value: 'ok', primary: true }] });
        } catch (err) { toast.fromError(err); } finally { versionButton.classList.remove('is-busy'); }
      });
    }
    const logoutForm = document.getElementById('logout-form');
    if (logoutForm) {
      logoutForm.addEventListener('submit', async (e) => {
        const dirty = tabs.all().filter((t) => t.dirty);
        if (dirty.length) {
          e.preventDefault();
          const ok = await dialog.confirm({ title: 'Modifications non enregistrées', message: 'Des onglets contiennent des modifications non enregistrées. Se déconnecter quand même ?', confirmLabel: 'Se déconnecter', danger: true });
          if (ok) { logoutForm.querySelector('input[name="_token"]').value = csrfToken; logoutForm.submit(); }
        } else {
          logoutForm.querySelector('input[name="_token"]').value = csrfToken;
        }
      });
    }
    window.addEventListener('beforeunload', (e) => {
      if (tabs.all().some((t) => t.dirty)) { e.preventDefault(); e.returnValue = ''; }
    });
    document.addEventListener('visibilitychange', () => {
      const active = tabs.active();
      if (!active) return;
      if (document.hidden) active.ctx._suspendTimers(); else active.ctx._resumeTimers();
    });
    if (CONFIG.mustChangePassword) session.passwordDialog(true); else start();
  }

  window.Atelier = Object.freeze({
    config: CONFIG, api, toast, status, dialog, tabs, nav, modules, forms, resources, util, ui, bbcode, announce, AtelierError,
    open: (moduleId, route) => tabs.open(moduleId, route, { push: true }),
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
