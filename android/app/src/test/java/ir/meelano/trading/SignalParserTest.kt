package ir.meelano.trading

import ir.meelano.trading.data.AppSettings
import ir.meelano.trading.data.DemoData
import ir.meelano.trading.data.Metrics
import ir.meelano.trading.data.SignalParser
import ir.meelano.trading.data.SignalText
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class SignalParserTest {

    @Test
    fun parsesFullAnalyzePayload() {
        val signal = SignalParser.signal(JSONObject(DemoData.ANALYZE_PAYLOAD))
        assertEquals("BTCUSDT", signal.symbol)
        assertEquals("1h", signal.timeframe)
        assertEquals("ACCEPT", signal.decision)
        assertEquals(84.15, signal.finalScore, 0.001)
        assertEquals(82.5, signal.confidence, 0.001)
        assertEquals(63437.77, signal.price!!, 0.001)
        assertEquals(4, signal.gates.size)
        assertEquals(2, signal.plan.takeProfits.size)
        assertEquals(62410.2, signal.plan.stopLoss!!, 0.001)
        assertEquals(2.5, signal.plan.riskReward!!, 0.001)
        assertEquals(63120.45, signal.plan.entryMin!!, 0.001)
        assertEquals("NORMAL", signal.btcStatus)
        assertEquals(0.12, signal.orderBookImbalance!!, 0.001)
        assertEquals("binance", signal.provider)
        assertFalse(signal.stale)
        assertEquals(250, signal.candles)
    }

    @Test
    fun toleratesStringNumbersLikeSqliteRows() {
        val payload = JSONObject(
            """
            {
              "symbol": "ETHUSDT",
              "final_score": "61.4",
              "decision": "WATCH",
              "price": "1800.5",
              "results": {
                "quantitative": { "decision": "ACCEPT", "score": "70", "metrics": { "price": "1800.5" } }
              }
            }
            """.trimIndent()
        )
        val signal = SignalParser.signal(payload)
        assertEquals(61.4, signal.finalScore, 0.001)
        assertEquals(1800.5, signal.price!!, 0.001)
        assertEquals(70.0, signal.gates.first().score, 0.001)
    }

    @Test
    fun parsesHistoryItems() {
        val items = SignalParser.historyItems(JSONObject(DemoData.HISTORY_PAYLOAD))
        assertEquals(3, items.size)
        assertEquals("BTCUSDT", items[0].symbol)
        assertEquals(84.15, items[0].score!!, 0.001)
        assertEquals("quantitative", items[2].rejectedBy)
        assertEquals("2026-08-23 11:31:04", items[0].createdAt)
    }

    @Test
    fun parsesBacktestResult() {
        val payload = JSONObject(
            """
            {
              "status": "success",
              "result": {
                "total_trades": 46,
                "win_rate": 58.7,
                "profit_factor": 1.94,
                "profit_pct": 12.35,
                "max_drawdown": 4.82,
                "final_equity": 1123.5,
                "engine": "four-gate-pipeline"
              }
            }
            """.trimIndent()
        )
        val result = SignalParser.backtest(payload)
        assertEquals(46, result.totalTrades)
        assertEquals(58.7, result.winRate, 0.001)
        assertEquals(1.94, result.profitFactor, 0.001)
        assertEquals("four-gate-pipeline", result.engine)
    }

    @Test
    fun flattensGateMetricsIncludingNestedObjects() {
        val signal = SignalParser.signal(JSONObject(DemoData.ANALYZE_PAYLOAD))
        val technical = signal.gates.first { it.gate == "technical" }
        val rows = Metrics.flatten(technical.metrics)
        assertTrue(rows.isNotEmpty())
        assertTrue(rows.any { it.first == "rsi" })
        assertTrue(rows.any { it.first == "ichimoku.above_cloud" && it.second == "Above Cloud" })
        assertTrue(rows.any { it.first == "macd.histogram" })
    }

    @Test
    fun buildsTelegramSignalText() {
        val signal = SignalParser.signal(JSONObject(DemoData.ANALYZE_PAYLOAD))
        val text = SignalText.build(signal)
        assertTrue(text.startsWith("<div dir=\"rtl\">"))
        assertTrue(text.contains("BTCUSDT"))
        assertTrue(text.contains("62,410.2"))
        assertTrue(text.contains("65,032.75"))
        assertTrue(text.contains("Decision: <strong>ACCEPT</strong>"))
    }

    @Test
    fun normalizesApiBaseUrl() {
        assertEquals("https://host/trader/api/", AppSettings.normalizeApiBase("https://host/trader"))
        assertEquals("https://host/trader/api/", AppSettings.normalizeApiBase("https://host/trader/api/"))
        assertEquals("https://host/trader/api/", AppSettings.normalizeApiBase("https://host/trader/api"))
        assertEquals("https://host/trader/api/", AppSettings.normalizeApiBase("host/trader/api/index.php"))
        assertEquals("http://host/api/", AppSettings.normalizeApiBase("http://host"))
        assertEquals("", AppSettings.normalizeApiBase("   "))
    }

    @Test
    fun healthAndAuthParsing() {
        val health = SignalParser.health(
            JSONObject(
                """{"status":"ok","version":"4.0.0","database":"connected","config":"valid","php":"8.1.2","pdo_sqlite":true,"time":"2026-08-23T11:34:00+00:00"}"""
            )
        )
        assertEquals("ok", health.status)
        assertEquals("connected", health.database)
        assertEquals(true, health.pdoSqlite)

        assertTrue(SignalParser.authenticated(JSONObject("""{"status":"success","authenticated":true}""")))
        assertFalse(SignalParser.authenticated(JSONObject("""{"status":"error","authenticated":false}""")))
    }
}
