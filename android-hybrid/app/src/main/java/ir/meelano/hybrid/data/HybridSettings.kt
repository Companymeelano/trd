package ir.meelano.hybrid.data

import android.content.Context
import android.content.SharedPreferences
import androidx.core.content.edit
import ir.meelano.hybrid.core.HybridConfig

/**
 * Private, on-device storage for the hybrid shell. One host, one token, one set
 * of analysis defaults shared by *both* surfaces: the native screens and the
 * embedded web console read the same values through the bridge, which is the
 * point of shipping them in a single APK.
 *
 * Credentials never leave the app sandbox (`allowBackup=false` in the manifest)
 * and the token is never handed to JavaScript.
 */
class HybridSettings(context: Context) {

    private val prefs: SharedPreferences =
        context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    /** Exactly what the user typed; kept so the field can be re-edited. */
    var hostInput: String
        get() = prefs.getString(KEY_HOST, "") ?: ""
        set(value) = prefs.edit { putString(KEY_HOST, value.trim()) }

    /** Normalized host root, e.g. `https://host/trader/`. */
    val hostRoot: String
        get() = HybridConfig.normalizeHost(hostInput)

    var apiToken: String
        get() = prefs.getString(KEY_TOKEN, "") ?: ""
        set(value) = prefs.edit { putString(KEY_TOKEN, value.trim()) }

    var symbol: String
        get() = prefs.getString(KEY_SYMBOL, DEFAULT_SYMBOL) ?: DEFAULT_SYMBOL
        set(value) = prefs.edit { putString(KEY_SYMBOL, value.trim().uppercase()) }

    var timeframe: String
        get() = prefs.getString(KEY_TIMEFRAME, DEFAULT_TIMEFRAME) ?: DEFAULT_TIMEFRAME
        set(value) = prefs.edit { putString(KEY_TIMEFRAME, value.trim()) }

    /** "Stay native" toggle: keeps the status tab off the WebView. */
    var preferNative: Boolean
        get() = prefs.getBoolean(KEY_PREFER_NATIVE, false)
        set(value) = prefs.edit { putBoolean(KEY_PREFER_NATIVE, value) }

    fun apiBase(): String = HybridConfig.apiBase(hostRoot)

    fun consoleUrl(): String = HybridConfig.consoleUrl(hostRoot)

    fun healthUrl(): String = HybridConfig.healthUrl(hostRoot)

    fun hasHost(): Boolean = HybridConfig.isConfigured(hostRoot)

    fun hasToken(): Boolean = apiToken.isNotBlank()

    fun isSecure(): Boolean = HybridConfig.isSecure(hostRoot)

    companion object {
        const val PREFS_NAME = "meelano_hybrid"
        const val DEFAULT_SYMBOL = "BTCUSDT"
        const val DEFAULT_TIMEFRAME = "1h"

        val TIMEFRAMES = listOf("15m", "1h", "4h", "1d")
        val SYMBOL_PRESETS = listOf(
            "BTCUSDT", "ETHUSDT", "BNBUSDT", "SOLUSDT",
            "XRPUSDT", "ADAUSDT", "DOGEUSDT", "AVAXUSDT",
            "LINKUSDT", "TONUSDT", "TRXUSDT", "DOTUSDT"
        )

        private const val KEY_HOST = "host"
        private const val KEY_TOKEN = "api_token"
        private const val KEY_SYMBOL = "symbol"
        private const val KEY_TIMEFRAME = "timeframe"
        private const val KEY_PREFER_NATIVE = "prefer_native"
    }
}
