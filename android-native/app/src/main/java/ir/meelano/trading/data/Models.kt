package ir.meelano.trading.data

import org.json.JSONObject

enum class TriState { UNKNOWN, OK, BAD }

data class GateResult(
    val gate: String,
    val decision: String,
    val score: Double,
    val reasons: List<String>,
    val metrics: JSONObject?
)

data class TakeProfit(val price: Double?, val rr: Double?)

data class TradePlan(
    val entryMin: Double?,
    val entryMax: Double?,
    val stopLoss: Double?,
    val takeProfits: List<TakeProfit>,
    val riskReward: Double?,
    val positionSize: Double?,
    val positionUsd: Double?,
    val riskAmount: Double?,
    val maxRiskPercent: Double?
) {
    companion object {
        val EMPTY = TradePlan(null, null, null, emptyList(), null, null, null, null, null)
    }
}

data class Signal(
    val symbol: String,
    val timeframe: String,
    val decision: String,
    val finalScore: Double,
    val confidence: Double,
    val price: Double?,
    val rejectedBy: String?,
    val warnings: List<String>,
    val gates: List<GateResult>,
    val plan: TradePlan,
    val btcStatus: String?,
    val btcReason: String?,
    val newsStatus: String?,
    val orderBookImbalance: Double?,
    val provider: String?,
    val stale: Boolean,
    val candles: Int,
    val error: String?
)

data class HistoryItem(
    val id: Long,
    val symbol: String,
    val timeframe: String,
    val decision: String,
    val score: Double?,
    val rejectedBy: String?,
    val createdAt: String?
)

data class BacktestResult(
    val totalTrades: Int,
    val winRate: Double,
    val profitFactor: Double,
    val profitPct: Double,
    val maxDrawdown: Double,
    val finalEquity: Double,
    val engine: String?
)

data class HealthStatus(
    val status: String?,
    val version: String?,
    val database: String?,
    val config: String?,
    val php: String?,
    val pdoSqlite: Boolean?,
    val time: String?
)
