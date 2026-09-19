package ir.meelano.trading.data

import org.json.JSONArray
import org.json.JSONObject

/**
 * Tolerant parser for the MeeLano core API payloads. Mirrors the defensive
 * normalization used by the web console (normalizeSignal / findGate / planFrom)
 * so the Android client renders exactly what the PHP engine produces.
 */
object SignalParser {

    private val GATE_ALIASES = mapOf(
        "quantitative" to listOf("quantitative", "q"),
        "technical" to listOf("technical", "t"),
        "sentiment_macro" to listOf("sentiment_macro", "sentiment", "macro", "s"),
        "risk_management" to listOf("risk_management", "risk", "r")
    )

    fun root(payload: JSONObject): JSONObject {
        return when (val data = payload.opt("data")) {
            is JSONObject -> data
            is JSONArray -> data.optJSONObject(0) ?: payload
            else -> payload
        }
    }

    /** results/gates may arrive as an object keyed by gate name or as an array. */
    private fun resultPairs(root: JSONObject): List<Pair<String, JSONObject>> {
        val raw = root.opt("results") ?: root.opt("gates") ?: return emptyList()
        val pairs = mutableListOf<Pair<String, JSONObject>>()
        when (raw) {
            is JSONArray -> raw.objects().forEach { obj ->
                pairs += (obj.strOrNull("gate") ?: obj.strOrNull("name") ?: "") to obj
            }
            is JSONObject -> raw.keys().forEach { key ->
                raw.optJSONObject(key)?.let { pairs += key to it }
            }
        }
        return pairs
    }

    fun findGate(pairs: List<Pair<String, JSONObject>>, canonical: String): JSONObject? {
        val wanted = GATE_ALIASES[canonical] ?: listOf(canonical)
        return pairs.firstOrNull { (key, obj) ->
            val names = listOf(
                key,
                obj.strOrNull("gate").orEmpty(),
                obj.strOrNull("name").orEmpty(),
                obj.strOrNull("key").orEmpty(),
                obj.strOrNull("id").orEmpty()
            ).map { it.lowercase() }
            wanted.any { it in names }
        }?.second
    }

    fun signal(payload: JSONObject): Signal {
        val rootObj = root(payload)
        val pairs = resultPairs(rootObj)
        val gates = pairs.map { (key, obj) ->
            GateResult(
                gate = obj.strOrNull("gate") ?: key,
                decision = obj.strOrNull("decision") ?: "WAIT",
                score = obj.numOrZero("score"),
                reasons = obj.strListOrEmpty("reasons"),
                metrics = obj.objOrNull("metrics")
            )
        }

        val plan = tradePlan(rootObj)
        val sentiment = findGate(pairs, "sentiment_macro")
        val quantitative = findGate(pairs, "quantitative")
        val btc = sentiment?.objOrNull("metrics")?.objOrNull("btc")
            ?: rootObj.objOrNull("btc_guard")
        val whale = quantitative?.objOrNull("metrics")?.objOrNull("whale")

        val rejectedRaw = rootObj.opt("rejected_by")
        val rejectedBy = when (rejectedRaw) {
            is JSONArray -> rejectedRaw.objects().mapNotNull { it.strOrNull("gate") ?: it.strOrNull("name") }
                .ifEmpty { (0 until rejectedRaw.length()).mapNotNull { rejectedRaw.opt(it)?.toString() } }
                .joinToString(" · ").takeIf { it.isNotEmpty() }
            else -> rootObj.strOrNull("rejected_by")
        }

        val quality = rootObj.objOrNull("data_quality")

        return Signal(
            symbol = rootObj.strOrNull("symbol").orEmpty(),
            timeframe = rootObj.strOrNull("timeframe").orEmpty(),
            decision = rootObj.strOrNull("decision") ?: "WAIT",
            finalScore = rootObj.numOrNull("final_score") ?: rootObj.numOrZero("score"),
            confidence = rootObj.numOrNull("confidence") ?: rootObj.numOrZero("conf"),
            price = rootObj.numOrNull("price")
                ?: rootObj.numOrNull("last_price")
                ?: rootObj.numOrNull("close")
                ?: quantitative?.objOrNull("metrics")?.numOrNull("price"),
            rejectedBy = rejectedBy,
            warnings = rootObj.strListOrEmpty("warnings"),
            gates = gates,
            plan = plan,
            btcStatus = btc?.strOrNull("status"),
            btcReason = btc?.strOrNull("reason"),
            newsStatus = sentiment?.objOrNull("metrics")?.strOrNull("news_status"),
            orderBookImbalance = whale?.numOrNull("order_book_imbalance")
                ?: quantitative?.objOrNull("metrics")?.numOrNull("order_book_imbalance"),
            provider = quality?.strOrNull("provider"),
            stale = quality?.boolOrNull("stale") ?: false,
            candles = quality?.numOrNull("candles")?.toInt() ?: 0,
            error = rootObj.strOrNull("error") ?: rootObj.strOrNull("message")
        )
    }

    private fun tradePlan(root: JSONObject): TradePlan {
        val planObj = root.objOrNull("trade_plan") ?: root
        val entry = planObj.objOrNull("entry_zone")
        val risk = planObj.objOrNull("risk")
        val tps = when (val raw = planObj.opt("take_profits")) {
            is JSONArray -> raw.objects().map { TakeProfit(it.numOrNull("price"), it.numOrNull("rr")) }
            else -> emptyList()
        }
        return TradePlan(
            entryMin = entry?.numOrNull("min"),
            entryMax = entry?.numOrNull("max"),
            stopLoss = planObj.numOrNull("stop_loss"),
            takeProfits = tps,
            riskReward = risk?.numOrNull("risk_reward_ratio"),
            positionSize = risk?.numOrNull("suggested_position_size"),
            positionUsd = risk?.numOrNull("position_size_usd"),
            riskAmount = risk?.numOrNull("risk_amount"),
            maxRiskPercent = risk?.numOrNull("max_capital_risk_percent")
        )
    }

    fun historyItems(payload: JSONObject): List<HistoryItem> {
        return itemsArray(payload).mapNotNull { item ->
            HistoryItem(
                id = item.numOrNull("id")?.toLong() ?: 0L,
                symbol = item.strOrNull("symbol").orEmpty(),
                timeframe = item.strOrNull("timeframe").orEmpty(),
                decision = item.strOrNull("decision").orEmpty(),
                score = item.numOrNull("final_score")
                    ?: item.numOrNull("composite_score")
                    ?: item.numOrNull("score"),
                rejectedBy = item.strOrNull("rejected_by"),
                createdAt = item.strOrNull("created_at")
                    ?: item.strOrNull("createdAt")
                    ?: item.strOrNull("timestamp")
            )
        }
    }

    fun scanSignals(payload: JSONObject): List<Signal> =
        itemsArray(payload).map { signal(it) }

    private fun itemsArray(payload: JSONObject): List<JSONObject> {
        val rootObj = root(payload)
        val candidates = listOf(
            payload.optJSONArray("items"),
            payload.objOrNull("data")?.optJSONArray("items"),
            rootObj.optJSONArray("items"),
            rootObj.objOrNull("data")?.optJSONArray("items")
        )
        candidates.firstOrNull { it != null }?.let { return it.objects() }
        if (payload.optJSONArray("data") != null) return payload.optJSONArray("data")!!.objects()
        return emptyList()
    }

    fun backtest(payload: JSONObject): BacktestResult {
        val rootObj = root(payload)
        val result = rootObj.objOrNull("result")
            ?: rootObj.objOrNull("data")?.objOrNull("result")
            ?: (if (rootObj.has("win_rate") || rootObj.has("total_trades")) rootObj else null)
            ?: payload.objOrNull("data")
            ?: rootObj
        return BacktestResult(
            totalTrades = result.numOrNull("total_trades")?.toInt() ?: 0,
            winRate = result.numOrZero("win_rate"),
            profitFactor = result.numOrZero("profit_factor"),
            profitPct = result.numOrNull("profit_pct") ?: result.numOrZero("net_pnl_pct"),
            maxDrawdown = result.numOrZero("max_drawdown"),
            finalEquity = result.numOrZero("final_equity"),
            engine = result.strOrNull("engine")
        )
    }

    fun health(payload: JSONObject): HealthStatus = HealthStatus(
        status = payload.strOrNull("status"),
        version = payload.strOrNull("version"),
        database = payload.strOrNull("database") ?: payload.strOrNull("db") ?: payload.strOrNull("sqlite"),
        config = payload.strOrNull("config"),
        php = payload.strOrNull("php"),
        pdoSqlite = payload.boolOrNull("pdo_sqlite"),
        time = payload.strOrNull("time")
    )

    fun authenticated(payload: JSONObject): Boolean =
        payload.boolOrNull("authenticated") ?: (payload.strOrNull("status") == "success")
}
