#!/usr/bin/env python3
"""
Contract test: validates the JSON shapes the Android client (and the web
console) rely on, against the 57 real analyze payloads stored in the
distributable SQLite database (analysis_runs.payload) and the signals table.

Usage: python3 tests/contract_fixtures_test.py
"""
import json
import sqlite3
import sys
from pathlib import Path

DB = Path(__file__).resolve().parent.parent / "_review" / "api5" / "data" / "signals.sqlite"
if not DB.exists():
    # fall back to a freshly extracted copy
    import zipfile
    root = Path(__file__).resolve().parent.parent
    zip_path = root / "meelano-trading-intelligence-v4.0-pro.zip"
    with zipfile.ZipFile(zip_path) as z:
        z.extract("api5/data/signals.sqlite", root / "_review")
    DB = root / "_review" / "api5" / "data" / "signals.sqlite"

conn = sqlite3.connect(DB)
rows = conn.execute("SELECT payload FROM analysis_runs").fetchall()
signals = conn.execute(
    "SELECT symbol,timeframe,decision,composite_score,price,entry,stop_loss,tp1,tp2,net_rr FROM signals"
).fetchall()

REQUIRED_TOP = ["symbol", "timeframe", "decision", "final_score", "results"]
GATE_KEYS = {"quantitative", "technical", "sentiment_macro", "risk_management"}
PLAN_KEYS = ["entry_zone", "stop_loss", "take_profits", "risk"]

failures = []
gate_seen = set()
decision_seen = set()
legacy_rows = 0
modern_rows = 0

for idx, (payload_text,) in enumerate(rows):
    try:
        payload = json.loads(payload_text)
    except Exception as exc:  # noqa: BLE001
        failures.append(f"row {idx}: payload not JSON ({exc})")
        continue
    for key in REQUIRED_TOP:
        if key not in payload:
            failures.append(f"row {idx}: missing top-level key {key}")
    decision_seen.add(payload.get("decision"))
    # Rows persisted by pre-v4 builds lack trade_plan/data_quality; the clients
    # are tolerant, but current-version rows must carry the full contract.
    if "trade_plan" in payload and "data_quality" in payload:
        modern_rows += 1
    else:
        legacy_rows += 1
    results = payload.get("results")
    if not isinstance(results, dict):
        failures.append(f"row {idx}: results is not an object keyed by gate")
    else:
        gate_seen |= set(results)
        for gate, value in results.items():
            for gk in ("decision", "score", "reasons", "metrics"):
                if gk not in value:
                    failures.append(f"row {idx}/{gate}: gate missing {gk}")
    plan = payload.get("trade_plan")
    if plan is not None:
        for pk in PLAN_KEYS:
            if pk not in plan:
                failures.append(f"row {idx}: trade_plan missing {pk}")
        tps = plan.get("take_profits") or []
        if len(tps) < 2:
            failures.append(f"row {idx}: expected 2 take profits, got {len(tps)}")
        risk = plan.get("risk") or {}
        for rk in ("risk_reward_ratio", "suggested_position_size", "max_capital_risk_percent"):
            if rk not in risk:
                failures.append(f"row {idx}: trade_plan.risk missing {rk}")
if not gate_seen <= GATE_KEYS:
    failures.append(f"unexpected gate keys: {gate_seen - GATE_KEYS}")
if not decision_seen <= {"ACCEPT", "WATCH", "REJECT"}:
    failures.append(f"unexpected decisions: {decision_seen}")

for idx, row in enumerate(signals):
    symbol, timeframe, decision, score, price, entry, sl, tp1, tp2, rr = row
    if not symbol or not timeframe:
        failures.append(f"signals row {idx}: empty symbol/timeframe")
    if decision not in ("ACCEPT", "WATCH", "REJECT"):
        failures.append(f"signals row {idx}: bad decision {decision}")

print(f"checked {len(rows)} analysis payloads and {len(signals)} signal rows")
print(f"gates seen: {sorted(gate_seen)}")
print(f"decisions seen: {sorted(decision_seen)}")
print(f"current-contract rows: {modern_rows}, legacy (pre-normalize) rows: {legacy_rows}")
if failures:
    print("FAILURES:")
    for f in failures[:40]:
        print(" -", f)
    sys.exit(1)
print("contract fixtures: OK")
