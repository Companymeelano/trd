/*
 * MeeLano hybrid bridge — the JavaScript half of the native contract.
 *
 * Loaded into every page the shell opens (remote console *and* the bundled
 * offline console) from app/src/main/assets/www/hybrid-bridge.js.
 *
 * The page never calls the PHP core directly: it asks this object, which
 * forwards to the injected `MeelanoBridge` Java object. The API token stays on
 * the Kotlin side, so it is never visible to the page.
 *
 * Dual-mode on purpose:
 *   - in the WebView  → publishes `window.MeelanoHybrid`
 *   - in Node (tests) → `module.exports` (tests/hybrid-bridge.test.mjs)
 */
(function (globalScope) {
  'use strict';

  var BRIDGE_VERSION = 1;
  var JS_NAMESPACE = 'MeelanoBridge';
  var ALLOWED_ACTIONS = [
    'health',
    'auth-check',
    'history',
    'scan',
    'analyze',
    'backtest',
    'notify',
    'settings'
  ];

  var ERR_NO_BRIDGE = 'NO_BRIDGE';
  var ERR_UNKNOWN_ACTION = 'UNKNOWN_ACTION';
  var ERR_BAD_PARAMS = 'BAD_PARAMS';
  var ERR_TIMEOUT = 'BRIDGE_TIMEOUT';
  var ERR_NATIVE = 'NATIVE_ERROR';

  function isAllowedAction(action) {
    return ALLOWED_ACTIONS.indexOf(action) !== -1;
  }

  function safeParse(text, fallback) {
    if (typeof text !== 'string' || text.length === 0) return fallback;
    try {
      return JSON.parse(text);
    } catch (err) {
      return fallback;
    }
  }

  function errorEnvelope(id, action, code) {
    return { id: id, ok: false, action: action, data: null, error: code };
  }

  /**
   * Creates a bridge bound to `native` (the injected Java object, or null when
   * the page runs outside the app). Async requests are correlated by id: the
   * Kotlin side answers through `deliver(envelope)`.
   */
  function create(native) {
    var pending = {};
    var listeners = {};
    var seq = 0;

    function emit(event, payload) {
      var list = listeners[event];
      if (!list) return;
      for (var i = 0; i < list.length; i += 1) {
        try {
          list[i](payload);
        } catch (err) {
          /* a broken page listener must not break the bridge */
        }
      }
    }

    function on(event, callback) {
      if (typeof callback !== 'function') return function () {};
      if (!listeners[event]) listeners[event] = [];
      listeners[event].push(callback);
      return function off() {
        var list = listeners[event];
        if (!list) return;
        var at = list.indexOf(callback);
        if (at !== -1) list.splice(at, 1);
      };
    }

    function hasNative() {
      return native !== null && typeof native !== 'undefined';
    }

    /** First payload from the app: {bridge, app, host, mode, token, symbol, timeframe}. */
    function bootstrap() {
      if (!hasNative() || typeof native.bootstrap !== 'function') return null;
      var raw = native.bootstrap();
      var parsed = safeParse(raw, null);
      if (parsed && parsed.bridge === BRIDGE_VERSION) emit('ready', parsed);
      return parsed;
    }

    function nextId() {
      seq += 1;
      return 'r' + seq;
    }

    /**
     * Calls the PHP core through the app. Resolves with the API payload, or
     * resolves an error envelope when the action is unknown, the parameters are
     * not an object, or no native bridge is attached (browser preview).
     */
    function request(action, params, timeoutMs) {
      var id = nextId();
      if (!isAllowedAction(action)) {
        return Promise.resolve(errorEnvelope(id, action, ERR_UNKNOWN_ACTION));
      }
      var body = params;
      if (body === undefined || body === null) body = {};
      if (typeof body !== 'object' || Array.isArray(body)) {
        return Promise.resolve(errorEnvelope(id, action, ERR_BAD_PARAMS));
      }
      if (!hasNative() || typeof native.request !== 'function') {
        return Promise.resolve(errorEnvelope(id, action, ERR_NO_BRIDGE));
      }

      return new Promise(function (resolve) {
        var settled = false;
        var timer = null;
        var budget = typeof timeoutMs === 'number' && timeoutMs > 0 ? timeoutMs : 120000;

        pending[id] = function (envelope) {
          if (settled) return;
          settled = true;
          if (timer) clearTimeout(timer);
          delete pending[id];
          resolve(envelope);
        };

        timer = setTimeout(function () {
          if (settled) return;
          settled = true;
          delete pending[id];
          resolve(errorEnvelope(id, action, ERR_TIMEOUT));
        }, budget);

        try {
          native.request(id, action, JSON.stringify(body));
        } catch (err) {
          // The injected object threw: report it instead of hanging the page.
          if (!settled) {
            settled = true;
            clearTimeout(timer);
            delete pending[id];
            resolve(errorEnvelope(id, action, ERR_NATIVE));
          }
        }
      });
    }

    /** Called from Kotlin: `window.MeelanoHybrid.deliver({...})`. */
    function deliver(envelope) {
      var data = typeof envelope === 'string' ? safeParse(envelope, null) : envelope;
      if (!data || typeof data !== 'object') return false;
      emit('message', data);
      if (data.action === 'signal') emit('signal', data.data || null);
      var handler = data.id ? pending[data.id] : null;
      if (typeof handler === 'function') {
        handler(data);
        return true;
      }
      return false;
    }

    /** The page pushes what it has (demo signal, offline journal entry) to the app. */
    function postSignal(payload) {
      if (!hasNative() || typeof native.postSignal !== 'function') return false;
      try {
        native.postSignal(JSON.stringify(payload || {}));
        return true;
      } catch (err) {
        return false;
      }
    }

    function log(level, message) {
      if (!hasNative() || typeof native.log !== 'function') return false;
      try {
        native.log(String(level || 'info'), String(message === undefined ? '' : message));
        return true;
      } catch (err) {
        return false;
      }
    }

    return {
      bootstrap: bootstrap,
      request: request,
      deliver: deliver,
      postSignal: postSignal,
      log: log,
      on: on,
      emit: emit,
      isAllowedAction: isAllowedAction,
      hasNative: hasNative,
      pendingCount: function () { return Object.keys(pending).length; }
    };
  }

  var lib = {
    create: create,
    BRIDGE_VERSION: BRIDGE_VERSION,
    JS_NAMESPACE: JS_NAMESPACE,
    ALLOWED_ACTIONS: ALLOWED_ACTIONS,
    ERR_NO_BRIDGE: ERR_NO_BRIDGE,
    ERR_UNKNOWN_ACTION: ERR_UNKNOWN_ACTION,
    ERR_BAD_PARAMS: ERR_BAD_PARAMS,
    ERR_TIMEOUT: ERR_TIMEOUT,
    ERR_NATIVE: ERR_NATIVE
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = lib;
  }
  if (typeof window !== 'undefined') {
    var injected = typeof window[JS_NAMESPACE] !== 'undefined' ? window[JS_NAMESPACE] : null;
    window.MeelanoHybrid = create(injected);
    if (typeof window.dispatchEvent === 'function') {
      window.dispatchEvent(new Event('meelano-bridge-ready'));
    }
  }
}(typeof globalThis !== 'undefined' ? globalThis : this));
