package ir.meelano.hybrid.web

import ir.meelano.hybrid.core.BridgeContract

/**
 * JavaScript fragments injected into a page after it finishes loading.
 *
 * The bundled `hybrid-bridge.js` source is injected first (from assets, so no
 * network is needed), then [readySnippet] publishes a `meelano-ready` DOM event.
 * The remote PHP console can listen for that event and use the bridge; the
 * bundled offline console does the same, so both surfaces share one code path.
 *
 * Pure string building — no Android types — so it is covered by
 * `tests/android_hybrid_static_check.py`.
 */
object Injector {

    /** Name of the DOM event a page waits for. */
    const val READY_EVENT = "meelano-ready"

    /** Injected after the bridge source; safe to run even if injection failed. */
    fun readySnippet(payloadJson: String): String =
        "(function(){try{" +
            "var b=window.${BridgeContract.JS_GLOBAL}.bootstrap();" +
            "document.dispatchEvent(new CustomEvent('$READY_EVENT',{detail:b}));" +
            "}catch(e){window.${BridgeContract.JS_GLOBAL}&&" +
            "window.${BridgeContract.JS_GLOBAL}.log('error','ready failed: '+e);}})();"

    /**
     * Logs a navigation the shell refused (target outside the host root and not
     * a bundled asset). The reason is JSON-encoded, so it can never break out of
     * the string literal.
     */
    fun blockedSnippet(reasonKey: String): String =
        "(function(){try{window.${BridgeContract.JS_GLOBAL}&&" +
            "window.${BridgeContract.JS_GLOBAL}.log('warn','blocked navigation: '+" +
            BridgeContract.jsonString(reasonKey) + ");}catch(e){}})();"
}
