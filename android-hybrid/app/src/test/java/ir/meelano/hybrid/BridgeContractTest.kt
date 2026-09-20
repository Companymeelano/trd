package ir.meelano.hybrid

import ir.meelano.hybrid.core.BridgeContract
import ir.meelano.hybrid.web.EndpointMap
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The Kotlin half of the JavaScript contract. `tests/hybrid-bridge.test.mjs`
 * covers the JavaScript half and `tests/android_hybrid_static_check.py` asserts
 * the two halves agree on names; these tests pin the wire format itself.
 */
class BridgeContractTest {

    @Test
    fun `bootstrap payload parses and never carries the token`() {
        val payload = BridgeContract.bootstrap(
            appVersion = "6.0.0",
            hostRoot = "https://panel.example.com/trader/",
            mode = "web",
            hasToken = true,
            symbol = "BTCUSDT",
            timeframe = "1h"
        )
        val json = JSONObject(payload)
        assertEquals(BridgeContract.BRIDGE_VERSION, json.getInt("bridge"))
        assertEquals("6.0.0", json.getString("app"))
        assertEquals("https://panel.example.com/trader/", json.getString("host"))
        assertEquals("web", json.getString("mode"))
        assertTrue(json.getBoolean("token"))
        assertEquals("BTCUSDT", json.getString("symbol"))
        assertEquals("1h", json.getString("timeframe"))
        assertFalse("the token value must never reach the page", payload.contains("api_token"))
    }

    @Test
    fun `hostile strings cannot break out of the payload`() {
        val payload = BridgeContract.bootstrap(
            appVersion = "6.0.0",
            hostRoot = "https://x.test/\"</script><script>alert(1)</script>",
            mode = "web",
            hasToken = false,
            symbol = "BTC\"USDT",
            timeframe = "1h\n"
        )
        val json = JSONObject(payload)
        assertTrue(json.getString("host").contains("</script>"))
        assertEquals("BTC\"USDT", json.getString("symbol"))
        assertEquals("1h\n", json.getString("timeframe"))
        assertFalse(payload.contains("</script>"))
        assertFalse(payload.contains("\\\\u003c/script"))
    }

    @Test
    fun `jsonString escapes the mandatory and the embedding-hostile characters`() {
        assertEquals("\"plain\"", BridgeContract.jsonString("plain"))
        assertEquals("\"q\\\"q\"", BridgeContract.jsonString("q\"q"))
        assertEquals("\"a\\\\b\"", BridgeContract.jsonString("a\\b"))
        assertEquals("\"l1\\nl2\"", BridgeContract.jsonString("l1\nl2"))
        assertEquals("\"\\u003cb\\u003e\"", BridgeContract.jsonString("<b>"))
        assertEquals("\"a\\u0026b\"", BridgeContract.jsonString("a&b"))
        assertEquals("\"\\u0001\"", BridgeContract.jsonString("\u0001"))
        assertEquals("\"fa\\u2028line\"", BridgeContract.jsonString("fa\u2028line"))
        // Every escaped form must still round-trip through a real JSON parser.
        assertEquals("<b>&\"\\\n", JSONObject("{\"v\":" + BridgeContract.jsonString("<b>&\"\\\n") + "}").getString("v"))
    }

    @Test
    fun `success envelope carries the body verbatim`() {
        val envelope = BridgeContract.envelope("r7", true, "health", "{\"status\":\"ok\"}", null)
        val json = JSONObject(envelope)
        assertEquals("r7", json.getString("id"))
        assertTrue(json.getBoolean("ok"))
        assertEquals("health", json.getString("action"))
        assertEquals("ok", json.getJSONObject("data").getString("status"))
        assertTrue(json.isNull("error"))
    }

    @Test
    fun `error envelope carries a code and no data`() {
        val envelope = BridgeContract.envelope("r8", false, "analyze", "", BridgeContract.ERR_NATIVE)
        val json = JSONObject(envelope)
        assertFalse(json.getBoolean("ok"))
        assertEquals(BridgeContract.ERR_NATIVE, json.getString("error"))
        assertTrue(json.isNull("data"))
    }

    @Test
    fun `a blank body becomes an empty object, not invalid json`() {
        val json = JSONObject(BridgeContract.envelope("r9", true, "settings", "  ", null))
        assertEquals(0, json.getJSONObject("data").length())
    }

    @Test
    fun `deliver script targets the published global`() {
        val script = BridgeContract.deliverScript("{\"id\":\"r1\"}")
        assertTrue(script.startsWith("window." + BridgeContract.JS_GLOBAL))
        assertTrue(script.contains(".deliver({\"id\":\"r1\"});"))
    }

    @Test
    fun `the allow-list equals the endpoint map plus the shell-only action`() {
        assertEquals(
            BridgeContract.ALLOWED_ACTIONS.sorted(),
            EndpointMap.allActions()
        )
        assertTrue(BridgeContract.isAllowed("settings"))
        assertFalse(BridgeContract.isAllowed("../etc/passwd"))
        assertFalse(BridgeContract.isAllowed(""))
        assertEquals("settings", EndpointMap.ACTION_SETTINGS)
        assertEquals(EndpointMap.METHOD_GET, EndpointMap.forAction("health")?.method)
        assertEquals(EndpointMap.METHOD_POST, EndpointMap.forAction("analyze")?.method)
        assertTrue(EndpointMap.forAction("health")?.auth == false)
        assertTrue(EndpointMap.forAction("notify")?.auth == true)
        assertTrue(EndpointMap.isNativeOnly("settings"))
    }
}
