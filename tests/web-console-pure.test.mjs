/*
 * Executes the pure (DOM-free) helpers of the web console (server/index.html)
 * inside Node with a minimal DOM stub, then asserts their behaviour against
 * real API payload shapes. Usage:  node tests/web-console-pure.test.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const html = readFileSync(join(here, '..', 'server', 'index.html'), 'utf8');
const match = html.match(/<script>([\s\S]*?)<\/script>/);
assert.ok(match, 'script block found in index.html');
const source = match[1];

const elementStub = () => ({
  classList: { toggle() {}, add() {}, remove() {} },
  style: {},
  appendChild() {},
  replaceChildren() {},
  addEventListener() {},
  setAttribute() {},
  append() {},
  removeChild() {},
  firstChild: null,
  value: '',
  textContent: '',
  dataset: {}
});

globalThis.window = { location: { href: 'https://example.com/trader/' } };
globalThis.document = {
  readyState: 'loading',
  title: '',
  addEventListener() {},
  querySelectorAll: () => [],
  getElementById: () => elementStub(),
  createElement: () => elementStub(),
  createTextNode: (t) => ({ text: t })
};
globalThis.localStorage = {
  store: new Map(),
  getItem(k) { return this.store.has(k) ? this.store.get(k) : null; },
  setItem(k, v) { this.store.set(k, String(v)); },
  removeItem(k) { this.store.delete(k); }
};

const api = new Function(`${source}\nreturn { normalizeSignal, normalizeResults, findGate, planFrom, extractItems, fmt, pct, decisionClass, signalText, escapeHTML };`)();

const analyzePayload = {
  status: 'success',
  symbol: 'BTCUSDT',
  timeframe: '1h',
  decision: 'ACCEPT',
  confidence: 82.5,
  final_score: 84.15,
  price: 63437.77,
  rejected_by: null,
  warnings: ['ATR volatility is elevated; position sizing should remain conservative.'],
  trade_plan: {
    entry_zone: { min: 63120.45, max: 63755.1 },
    stop_loss: 62410.2,
    take_profits: [{ price: 65032.75, rr: 2.5 }, { price: 65986.6, rr: 4.0 }],
    risk: { max_capital_risk_percent: 1, risk_reward_ratio: 2.5, suggested_position_size: 0.0148 }
  },
  results: {
    quantitative: { gate: 'quantitative', decision: 'ACCEPT', score: 85, reasons: [], metrics: { price: 63437.77, volume_ratio: 1.34 } },
    technical: { gate: 'technical', decision: 'ACCEPT', score: 88, reasons: [], metrics: { rsi: 61.4 } },
    sentiment_macro: { gate: 'sentiment_macro', decision: 'ACCEPT', score: 75, reasons: [], metrics: { btc: { status: 'NORMAL' } } },
    risk_management: { gate: 'risk_management', decision: 'ACCEPT', score: 85, reasons: [], metrics: { atr_pct: 1.14 } }
  },
  data_quality: { provider: 'binance', stale: false, candles: 250 }
};

// normalizeSignal passes the payload through untouched
const root = api.normalizeSignal(analyzePayload);
assert.equal(root.symbol, 'BTCUSDT');
assert.equal(api.normalizeSignal({ data: analyzePayload }).symbol, 'BTCUSDT');
assert.equal(api.normalizeSignal([{ symbol: 'ETHUSDT' }]).symbol, 'ETHUSDT');

// normalizeResults understands the object-keyed results map
const gates = api.normalizeResults(analyzePayload);
assert.equal(gates.length, 4);
assert.ok(api.findGate(gates, 'sentiment_macro'));
assert.ok(api.findGate(gates, 'risk_management'));
assert.equal(api.findGate(gates, 'technical').score, 88);

// planFrom resolves the nested trade plan
const plan = api.planFrom(analyzePayload);
assert.equal(plan.stop_loss, 62410.2);
assert.equal(plan.take_profits.length, 2);

// extractItems handles history and scan envelopes
const history = { status: 'success', items: [{ id: 1, symbol: 'BTCUSDT', decision: 'ACCEPT', final_score: 84.15 }] };
assert.equal(api.extractItems(history).length, 1);
const scan = { status: 'success', quote: 'USDT', items: [analyzePayload] };
assert.equal(api.extractItems(scan).length, 1);

// formatting helpers
assert.equal(api.fmt(1234.5678, 2), '1,234.57');
assert.equal(api.fmt(null), '—');
assert.equal(api.pct(12.5, 1), '12.5%');
assert.equal(api.decisionClass('ACCEPT'), 'good');
assert.equal(api.decisionClass('REJECT'), 'bad');

// telegram text builder
const text = api.signalText(analyzePayload);
assert.ok(text.startsWith('<div dir="rtl">'));
assert.ok(text.includes('BTCUSDT'));
assert.ok(text.includes('62,410.2'));

// the history table now prefers final_score (regression guard)
assert.ok(source.includes('item?.final_score ?? item?.composite_score ?? item?.score'), 'history score key fixed');
// heavy endpoints get longer timeouts (regression guard)
assert.ok(source.includes("timeout: 240000"), 'scan/backtest timeout raised');

console.log('web console pure-helper tests: OK');
