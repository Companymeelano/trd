package ir.meelano.trading.data

/**
 * Offline sample payload shaped exactly like a real `analyze.php` response.
 * Used by "load demo signal" in Settings so the whole UI can be explored
 * without a reachable server.
 */
object DemoData {

    const val ANALYZE_PAYLOAD = """
{
  "status": "success",
  "symbol": "BTCUSDT",
  "timeframe": "1h",
  "decision": "ACCEPT",
  "confidence": 82.5,
  "trade_plan": {
    "entry_zone": { "min": 63120.45, "max": 63755.1 },
    "stop_loss": 62410.2,
    "take_profits": [
      { "price": 65032.75, "rr": 2.5 },
      { "price": 65986.6, "rr": 4.0 }
    ],
    "risk": {
      "max_capital_risk_percent": 1.0,
      "risk_reward_ratio": 2.5,
      "suggested_position_size": 0.0148,
      "position_size_usd": 940.5,
      "risk_amount": 10.0
    }
  },
  "entry_zone": { "min": 63120.45, "max": 63755.1 },
  "stop_loss": 62410.2,
  "take_profits": [
    { "price": 65032.75, "rr": 2.5 },
    { "price": 65986.6, "rr": 4.0 }
  ],
  "risk": {
    "max_capital_risk_percent": 1.0,
    "risk_reward_ratio": 2.5,
    "suggested_position_size": 0.0148,
    "position_size_usd": 940.5,
    "risk_amount": 10.0
  },
  "experts": {
    "quantitative": { "score": 85, "status": "PASS" },
    "technical": { "score": 88, "status": "PASS" },
    "sentiment": { "score": 75, "status": "PASS" },
    "risk_management": { "score": 85, "status": "PASS" }
  },
  "warnings": [
    "ATR volatility is elevated; position sizing should remain conservative."
  ],
  "rejected_by": null,
  "final_score": 84.15,
  "price": 63437.77,
  "data_quality": { "provider": "binance", "stale": false, "candles": 250 },
  "results": {
    "quantitative": {
      "gate": "quantitative",
      "decision": "ACCEPT",
      "score": 85,
      "reasons": [],
      "metrics": {
        "price": 63437.77,
        "volume_ratio": 1.34,
        "momentum_pct": 0.82,
        "atr_pct": 1.14,
        "divergence": "NONE",
        "whale": {
          "order_book_imbalance": 0.12,
          "volume_spike": 1.31,
          "bias": "BALANCED"
        }
      },
      "trade_plan": null
    },
    "technical": {
      "gate": "technical",
      "decision": "ACCEPT",
      "score": 88,
      "reasons": [],
      "metrics": {
        "ema9": 63210.4,
        "ema21": 62890.15,
        "ema50": 62104.9,
        "rsi": 61.4,
        "macd": { "macd": 214.6, "signal": 168.2, "histogram": 46.4 },
        "ichimoku": {
          "tenkan": 63301.2,
          "kijun": 62755.8,
          "span_a": 63028.5,
          "span_b": 61980.3,
          "above_cloud": true
        }
      },
      "trade_plan": null
    },
    "sentiment_macro": {
      "gate": "sentiment_macro",
      "decision": "ACCEPT",
      "score": 75,
      "reasons": [],
      "metrics": {
        "btc": {
          "status": "NORMAL",
          "score": 75,
          "reason": "BTC market regime is not classified as critical.",
          "rsi": 58.2,
          "momentum_pct": 0.64
        },
        "news_status": "normal"
      },
      "trade_plan": null
    },
    "risk_management": {
      "gate": "risk_management",
      "decision": "ACCEPT",
      "score": 85,
      "reasons": [
        "ATR volatility is elevated; position sizing should remain conservative."
      ],
      "metrics": {
        "atr": 723.4,
        "atr_pct": 1.14,
        "max_atr_pct": 8.0,
        "risk_per_unit": 1027.55,
        "risk_pct": 0.01,
        "risk_amount": 10.0,
        "risk_reward_ratio": 2.5,
        "suggested_position_size": 0.0148,
        "position_size_usd": 940.5,
        "max_capital_risk_percent": 1.0
      },
      "trade_plan": {
        "entry_zone": { "min": 63120.45, "max": 63755.1 },
        "stop_loss": 62410.2,
        "take_profits": [
          { "price": 65032.75, "rr": 2.5 },
          { "price": 65986.6, "rr": 4.0 }
        ],
        "risk": {
          "max_capital_risk_percent": 1.0,
          "risk_reward_ratio": 2.5,
          "suggested_position_size": 0.0148,
          "position_size_usd": 940.5,
          "risk_amount": 10.0
        }
      }
    }
  }
}
"""

    const val HISTORY_PAYLOAD = """
{
  "status": "success",
  "items": [
    { "id": 57, "symbol": "BTCUSDT", "timeframe": "1h", "decision": "ACCEPT", "final_score": 84.15, "rejected_by": null, "created_at": "2026-08-23 11:31:04" },
    { "id": 56, "symbol": "ETHUSDT", "timeframe": "1h", "decision": "WATCH", "final_score": 61.4, "rejected_by": null, "created_at": "2026-08-23 11:20:41" },
    { "id": 55, "symbol": "SOLUSDT", "timeframe": "4h", "decision": "REJECT", "final_score": 32.0, "rejected_by": "quantitative", "created_at": "2026-08-23 10:58:12" }
  ]
}
"""
}
