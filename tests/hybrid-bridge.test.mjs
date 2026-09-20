/*
 * Executes the JavaScript half of the hybrid bridge
 * (android-hybrid/app/src/main/assets/www/hybrid-bridge.js) inside Node against
 * a fake injected `MeelanoBridge` object, then asserts the contract the Kotlin
 * side implements:
 *
 *   - the published global / injected namespace match BridgeContract.kt
 *   - the action allow-list matches BridgeContract.kt and EndpointMap.kt
 *   - unknown actions and bad params are refused before touching the app
 *   - a request is answered by window.MeelanoHybrid.deliver(envelope), by id
 *   - with no native object (plain browser) every call degrades instead of
 *     throwing
 *
 * Usage:  node tests/hybrid-bridge.test.mjs
 */
import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');
const bridgePath = join(root, 'android-hybrid', 'app', 'src', 'main', 'assets', 'www', 'hybrid-bridge.js');
const offlinePath = join(root, 'android-hybrid', 'app', 'src', 'main', 'assets', 'www', 'offline.html');
const contractPath = join(
  root, 'android-hybrid', 'app', 'src', 'main', 'java', 'ir', 'meelano', 'hybrid', 'core', 'BridgeContract.kt'
);
const endpointPath = join(
  root, 'android-hybrid', 'app', 'src', 'main', 'java', 'ir', 'meelano', 'hybrid', 'web', 'EndpointMap.kt'
);

const require = createRequire(import.meta.url);
const bridge = require(bridgePath);

const contract = readFileSync(contractPath, 'utf8');
const endpoints = readFileSync(endpointPath, 'utf8');
const offline = readFileSync(offlinePath, 'utf8');

/** A stand-in for the Java object injected as window.MeelanoBridge. */
function fakeNative() {
  const calls = [];
  return {
    calls,
    bootstrap: () => JSON.stringify({
      bridge: 1, app: '6.0.0', host: 'https://panel.example.com/trader/',
      mode: 'web', token: true, symbol: 'BTCUSDT', timeframe: '1h'
    }),
    request: (id, action, params) => calls.push({ id, action, params }),
    postSignal: (payload) => calls.push({ postSignal: payload }),
    log: (level, message) => calls.push({ level, message })
  };
}

test('the module publishes the contract the Kotlin side expects', () => {
  assert.equal(typeof bridge.create, 'function');
  assert.equal(bridge.BRIDGE_VERSION, 1);

  const namespace = /const val JS_NAMESPACE = "([^"]+)"/.exec(contract);
  const published = /const val JS_GLOBAL = "([^"]+)"/.exec(contract);
  assert.ok(namespace, 'BridgeContract.kt declares JS_NAMESPACE');
  assert.ok(published, 'BridgeContract.kt declares JS_GLOBAL');
  assert.equal(bridge.JS_NAMESPACE, namespace[1]);
  assert.match(readFileSync(bridgePath, 'utf8'), new RegExp(`window\\.${published[1]}\\s*=`));
});

test('the action allow-list is identical in Kotlin, the endpoint map and JS', () => {
  const kotlinBlock = contract.split('val ALLOWED_ACTIONS')[1].split(')')[0];
  const kotlinActions = [...kotlinBlock.matchAll(/"([a-z-]+)"/g)].map((m) => m[1]).sort();
  const endpointActions = [...endpoints.matchAll(/"([a-z-]+)"\s+to\s+Endpoint\(/g)].map((m) => m[1]);
  const settingsConst = /const val ACTION_SETTINGS = "([^"]+)"/.exec(endpoints);
  assert.ok(settingsConst, 'EndpointMap declares ACTION_SETTINGS');
  const allActions = [...endpointActions, settingsConst[1]].sort();

  assert.deepEqual([...bridge.ALLOWED_ACTIONS].sort(), kotlinActions);
  assert.deepEqual([...bridge.ALLOWED_ACTIONS].sort(), allActions);
  assert.equal(bridge.ALLOWED_ACTIONS.length, 8);
});

test('bootstrap returns the parsed shell payload', () => {
  const api = bridge.create(fakeNative());
  const info = api.bootstrap();
  assert.equal(info.bridge, 1);
  assert.equal(info.mode, 'web');
  assert.equal(info.host, 'https://panel.example.com/trader/');
  assert.equal(info.token, true);
  assert.equal(info.symbol, 'BTCUSDT');
  assert.ok(!JSON.stringify(info).includes('api_token'), 'the token value never crosses the bridge');
});

test('without a native object every call degrades instead of throwing', () => {
  const api = bridge.create(null);
  assert.equal(api.hasNative(), false);
  assert.equal(api.bootstrap(), null);
  assert.equal(api.postSignal({ a: 1 }), false);
  assert.equal(api.log('info', 'hello'), false);
  return api.request('health', {}).then((env) => {
    assert.equal(env.ok, false);
    assert.equal(env.error, bridge.ERR_NO_BRIDGE);
    assert.equal(env.action, 'health');
    assert.ok(env.id, 'the envelope still carries an id');
  });
});

test('unknown actions never reach the app', async () => {
  const native = fakeNative();
  const api = bridge.create(native);
  for (const action of ['../etc/passwd', 'deleteEverything', '', 'HEALTH']) {
    const env = await api.request(action, {});
    assert.equal(env.ok, false, `refused: ${action}`);
    assert.equal(env.error, bridge.ERR_UNKNOWN_ACTION);
  }
  assert.equal(native.calls.length, 0);
});

test('non-object params are refused', async () => {
  const native = fakeNative();
  const api = bridge.create(native);
  for (const params of ['{"a":1}', 42, [1, 2]]) {
    const env = await api.request('scan', params);
    assert.equal(env.ok, false);
    assert.equal(env.error, bridge.ERR_BAD_PARAMS);
  }
  assert.equal(native.calls.length, 0);
});

test('a request round-trips through deliver and is correlated by id', async () => {
  const native = fakeNative();
  const api = bridge.create(native);

  const seen = [];
  api.on('message', (envelope) => seen.push(envelope));

  const pending = api.request('analyze', { symbol: 'BTCUSDT', timeframe: '1h' });
  assert.equal(native.calls.length, 1);
  const call = native.calls[0];
  assert.equal(call.action, 'analyze');
  assert.deepEqual(JSON.parse(call.params), { symbol: 'BTCUSDT', timeframe: '1h' });
  assert.equal(api.pendingCount(), 1);

  const delivered = api.deliver({
    id: call.id, ok: true, action: 'analyze',
    data: { decision: 'ACCEPT', final_score: 84.2 }, error: null
  });
  assert.equal(delivered, true, 'a known id is delivered');

  const env = await pending;
  assert.equal(env.ok, true);
  assert.equal(env.data.decision, 'ACCEPT');
  assert.equal(env.data.final_score, 84.2);
  assert.equal(api.pendingCount(), 0);
  assert.deepEqual(seen, [env]);
});

test('deliver accepts the JSON string form Kotlin actually sends', async () => {
  const native = fakeNative();
  const api = bridge.create(native);
  const pending = api.request('history', { limit: 5 });
  const id = native.calls[0].id;
  const raw = JSON.stringify({ id, ok: false, action: 'history', data: null, error: 'UNAUTHORIZED' });
  assert.equal(api.deliver(raw), true);
  const env = await pending;
  assert.equal(env.error, 'UNAUTHORIZED');
});

test('an unknown id is ignored and garbage is rejected', () => {
  const api = bridge.create(fakeNative());
  assert.equal(api.deliver({ id: 'nope', ok: true, action: 'health' }), false);
  assert.equal(api.deliver('not json at all'), false);
  assert.equal(api.deliver(null), false);
});

test('a native throw is turned into an error envelope', async () => {
  const api = bridge.create({
    bootstrap: () => 'not json',
    request: () => { throw new Error('bridge exploded'); }
  });
  assert.equal(api.bootstrap(), null, 'an unparsable bootstrap payload yields null');
  const env = await api.request('health', {});
  assert.equal(env.ok, false);
  assert.equal(env.error, bridge.ERR_NATIVE);
  assert.equal(api.pendingCount(), 0, 'no promise is left dangling');
});

test('a silent app times out instead of hanging the page', async () => {
  const api = bridge.create({ bootstrap: () => '{}', request: () => {} });
  const started = Date.now();
  const env = await api.request('scan', {}, 30);
  assert.equal(env.ok, false);
  assert.equal(env.error, bridge.ERR_TIMEOUT);
  assert.ok(Date.now() - started >= 25, 'the timeout budget was honoured');
  assert.equal(api.pendingCount(), 0);
});

test('signal pushes and listeners work both ways', async () => {
  const native = fakeNative();
  const api = bridge.create(native);

  const signals = [];
  const off = api.on('signal', (payload) => signals.push(payload));

  api.deliver({ id: 'ignored', ok: true, action: 'signal', data: { symbol: 'ETHUSDT' } });
  assert.deepEqual(signals, [{ symbol: 'ETHUSDT' }]);

  off();
  api.deliver({ id: 'ignored', ok: true, action: 'signal', data: { symbol: 'BNBUSDT' } });
  assert.equal(signals.length, 1, 'an unsubscribed listener stops receiving');

  assert.equal(api.postSignal({ source: 'offline-console', action: 'health' }), true);
  const pushed = native.calls.find((c) => c.postSignal);
  assert.ok(pushed, 'postSignal forwards to the app');
  assert.deepEqual(JSON.parse(pushed.postSignal), { source: 'offline-console', action: 'health' });

  api.log('warn', 'blocked navigation');
  const logged = native.calls.find((c) => c.level === 'warn');
  assert.ok(logged && logged.message === 'blocked navigation');
});

test('a throwing page listener cannot break the bridge', async () => {
  const native = fakeNative();
  const api = bridge.create(native);
  api.on('message', () => { throw new Error('page bug'); });
  const pending = api.request('health', {});
  api.deliver({ id: native.calls[0].id, ok: true, action: 'health', data: { status: 'ok' } });
  const env = await pending;
  assert.equal(env.data.status, 'ok');
});

test('the bundled offline console is wired to the same bridge', () => {
  assert.match(offline, /<script src="hybrid-bridge\.js"><\/script>/);
  assert.match(offline, /window\.MeelanoHybrid/);
  assert.match(offline, /meelano-ready/);
  assert.match(offline, /bridge\.request\('health'/);
  assert.match(offline, /bridge\.postSignal\(/);
});
