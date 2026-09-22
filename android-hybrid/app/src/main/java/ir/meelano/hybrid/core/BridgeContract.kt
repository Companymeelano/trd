package ir.meelano.hybrid.core

/**
 * The JSON contract shared with `assets/www/hybrid-bridge.js`.
 *
 * The page never talks to the PHP core directly: it calls the injected
 * `MeelanoBridge` object, which forwards to the native OkHttp client. That keeps
 * the API token out of JavaScript and sidesteps CORS entirely. This object owns
 * the wire format on the Kotlin side; `tests/hybrid-bridge.test.mjs` asserts the
 * JavaScript side and `tests/android_hybrid_static_check.py` asserts that both
 * sides agree on namespace, actions and method names.
 *
 * No `org.json` on purpose, so the payload can be unit-tested on the JVM.
 */
object BridgeContract {

    /** Name the Java object is injected under (`addJavascriptInterface`). */
    const val JS_NAMESPACE = "MeelanoBridge"

    /** Global the bundled script publishes into the page. */
    const val JS_GLOBAL = "MeelanoHybrid"

    const val BRIDGE_VERSION = 1

    /** Endpoints a page is allowed to reach through the bridge. */
    val ALLOWED_ACTIONS: List<String> = listOf(
        "health",
        "auth-check",
        "history",
        "scan",
        "analyze",
        "backtest",
        "notify",
        "settings"
    )

    /** Error codes the page can switch on. */
    const val ERR_UNKNOWN_ACTION = "UNKNOWN_ACTION"
    const val ERR_BAD_PARAMS = "BAD_PARAMS"
    const val ERR_NATIVE = "NATIVE_ERROR"

    fun isAllowed(action: String): Boolean = ALLOWED_ACTIONS.contains(action)

    /**
     * Minimal JSON string encoder. Escapes the mandatory characters plus `<`,
     * `>`, `&`, U+2028 and U+2029 so the payload is safe both as JSON and when
     * it is embedded in a `<script>` block or passed to `evaluateJavascript`.
     */
    fun jsonString(value: String): String {
        val out = StringBuilder(value.length + 16)
        out.append('"')
        for (ch in value) {
            when (ch) {
                '"' -> out.append("\\\"")
                '\\' -> out.append("\\\\")
                '\n' -> out.append("\\n")
                '\r' -> out.append("\\r")
                '\t' -> out.append("\\t")
                '\b' -> out.append("\\b")
                '\u000C' -> out.append("\\f")
                '<' -> out.append("\\u003c")
                '>' -> out.append("\\u003e")
                '&' -> out.append("\\u0026")
                '\u2028' -> out.append("\\u2028")
                '\u2029' -> out.append("\\u2029")
                else -> if (ch.code < 0x20) {
                    out.append("\\u").append(ch.code.toString(16).padStart(4, '0'))
                } else {
                    out.append(ch)
                }
            }
        }
        out.append('"')
        return out.toString()
    }

    private fun jsonBool(value: Boolean): String = if (value) "true" else "false"

    /**
     * First payload handed to the page so it can adapt without a round trip:
     * bridge version, app version, host root, which surface is showing and the
     * last analysis parameters. Note that the token itself is never sent.
     */
    fun bootstrap(
        appVersion: String,
        hostRoot: String,
        mode: String,
        hasToken: Boolean,
        symbol: String,
        timeframe: String
    ): String = buildString {
        append('{')
        append("\"bridge\":").append(BRIDGE_VERSION).append(',')
        append("\"app\":").append(jsonString(appVersion)).append(',')
        append("\"host\":").append(jsonString(hostRoot)).append(',')
        append("\"mode\":").append(jsonString(mode)).append(',')
        append("\"token\":").append(jsonBool(hasToken)).append(',')
        append("\"symbol\":").append(jsonString(symbol)).append(',')
        append("\"timeframe\":").append(jsonString(timeframe))
        append('}')
    }

    /**
     * Reply envelope delivered back into the page with
     * `window.MeelanoHybrid.deliver(...)`. `body` must already be valid JSON
     * (it is embedded verbatim); a blank body becomes `{}`.
     */
    fun envelope(id: String, ok: Boolean, action: String, body: String, errorKey: String?): String = buildString {
        append('{')
        append("\"id\":").append(jsonString(id)).append(',')
        append("\"ok\":").append(jsonBool(ok)).append(',')
        append("\"action\":").append(jsonString(action)).append(',')
        if (ok) {
            append("\"data\":").append(body.ifBlank { "{}" })
            append(",\"error\":null")
        } else {
            append("\"data\":null")
            append(",\"error\":").append(jsonString(errorKey ?: ERR_NATIVE))
        }
        append('}')
    }

    /**
     * `window.MeelanoHybrid.deliver(<envelope>)` — the single entry point the
     * Kotlin side uses to answer an asynchronous bridge call.
     */
    fun deliverScript(envelopeJson: String): String =
        "window.$JS_GLOBAL&&window.$JS_GLOBAL.deliver($envelopeJson);"
}
