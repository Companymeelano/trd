package ir.meelano.hybrid.web

import android.webkit.JavascriptInterface
import ir.meelano.hybrid.core.BridgeContract
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch

/** Snapshot of the shell handed to the page as the bootstrap payload. */
data class BridgeSnapshot(
    val appVersion: String,
    val hostRoot: String,
    val mode: String,
    val hasToken: Boolean,
    val symbol: String,
    val timeframe: String
)

/**
 * The object injected into every page the shell opens, as `window.MeelanoBridge`
 * (see [BridgeContract.JS_NAMESPACE]). Its JavaScript counterpart is
 * `assets/www/hybrid-bridge.js`; `tests/android_hybrid_static_check.py` fails the
 * build if a `@JavascriptInterface` method here stops being called there, or if
 * the action allow-lists drift apart.
 *
 * Calls run on the WebView's JavaBridge thread, never on the UI thread, and
 * answers are pushed back with [deliver] (a `window.MeelanoHybrid.deliver(...)`
 * script posted onto the WebView).
 */
class MeelanoBridge(
    private val scope: CoroutineScope,
    private val api: BridgeApi,
    private val snapshot: () -> BridgeSnapshot,
    private val settingsReply: () -> String,
    private val deliver: (String) -> Unit,
    private val onSignal: (String) -> Unit,
    private val onLog: (String, String) -> Unit
) {

    /** `{bridge, app, host, mode, token, symbol, timeframe}` — token value never included. */
    @JavascriptInterface
    fun bootstrap(): String {
        val snap = snapshot()
        return BridgeContract.bootstrap(
            appVersion = snap.appVersion,
            hostRoot = snap.hostRoot,
            mode = snap.mode,
            hasToken = snap.hasToken,
            symbol = snap.symbol,
            timeframe = snap.timeframe
        )
    }

    /**
     * Fire-and-forget from the page: the reply arrives later through
     * `window.MeelanoHybrid.deliver`, correlated by [callbackId].
     */
    @JavascriptInterface
    fun request(callbackId: String?, action: String?, paramsJson: String?) {
        val id = callbackId.orEmpty().trim()
        val requested = action.orEmpty().trim()
        if (id.isEmpty()) return

        if (!BridgeContract.isAllowed(requested)) {
            reply(BridgeContract.envelope(id, false, requested, "", BridgeContract.ERR_UNKNOWN_ACTION))
            return
        }

        if (EndpointMap.isNativeOnly(requested)) {
            reply(BridgeContract.envelope(id, true, requested, settingsReply(), null))
            return
        }

        val params = paramsJson.orEmpty().trim().ifBlank { "{}" }
        scope.launch {
            val envelope = when (val result = api.call(requested, params)) {
                is BridgeResult.Ok -> BridgeContract.envelope(id, true, requested, result.body, null)
                is BridgeResult.Err -> BridgeContract.envelope(id, false, requested, "", result.code)
            }
            reply(envelope)
        }
    }

    /** The page hands a signal/journal entry back to the shell (offline console). */
    @JavascriptInterface
    fun postSignal(payloadJson: String?) {
        val payload = payloadJson.orEmpty().trim()
        if (payload.isNotEmpty()) onSignal(payload)
    }

    @JavascriptInterface
    fun log(level: String?, message: String?) {
        onLog(level.orEmpty().trim().ifBlank { "info" }, message.orEmpty())
    }

    private fun reply(envelopeJson: String) {
        deliver(BridgeContract.deliverScript(envelopeJson))
    }
}
