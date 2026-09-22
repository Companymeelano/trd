package ir.meelano.hybrid.web

/**
 * Maps a bridge action to a PHP core endpoint.
 *
 * `settings` is deliberately *not* here: it is answered by the shell itself
 * from `HybridSettings`, so a page can read the device-side defaults without
 * touching the server. `tests/android_hybrid_static_check.py` asserts that this
 * map plus the native-only actions equal the allow-list published in
 * `assets/www/hybrid-bridge.js` and in `BridgeContract.ALLOWED_ACTIONS`.
 */
data class Endpoint(val path: String, val method: String, val auth: Boolean)

object EndpointMap {

    const val METHOD_GET = "GET"
    const val METHOD_POST = "POST"

    /** Action handled locally by the shell instead of over HTTP. */
    const val ACTION_SETTINGS = "settings"

    val SERVER_ACTIONS: Map<String, Endpoint> = mapOf(
        "health" to Endpoint("health.php", METHOD_GET, false),
        "auth-check" to Endpoint("auth-check.php", METHOD_GET, true),
        "history" to Endpoint("history.php", METHOD_GET, true),
        "scan" to Endpoint("scan.php", METHOD_POST, true),
        "analyze" to Endpoint("analyze.php", METHOD_POST, true),
        "backtest" to Endpoint("backtest.php", METHOD_POST, true),
        "notify" to Endpoint("notify.php", METHOD_POST, true)
    )

    val NATIVE_ONLY_ACTIONS: List<String> = listOf(ACTION_SETTINGS)

    fun forAction(action: String): Endpoint? = SERVER_ACTIONS[action]

    fun isNativeOnly(action: String): Boolean = NATIVE_ONLY_ACTIONS.contains(action)

    /** Everything a page may ask for, server-backed or shell-backed. */
    fun allActions(): List<String> = (SERVER_ACTIONS.keys + NATIVE_ONLY_ACTIONS).sorted()
}
