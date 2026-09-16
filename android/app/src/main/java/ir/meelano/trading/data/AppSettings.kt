package ir.meelano.trading.data

import android.content.Context
import android.content.SharedPreferences
import androidx.core.content.edit

/**
 * Private, on-device storage for the core connection settings and the last
 * used analysis form. Credentials never leave the app sandbox and are
 * excluded from cloud backups (see AndroidManifest allowBackup=false).
 */
class AppSettings(context: Context) {

    private val prefs: SharedPreferences =
        context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    var baseUrl: String
        get() = prefs.getString(KEY_BASE_URL, "") ?: ""
        set(value) = prefs.edit { putString(KEY_BASE_URL, value.trim()) }

    var apiToken: String
        get() = prefs.getString(KEY_API_TOKEN, "") ?: ""
        set(value) = prefs.edit { putString(KEY_API_TOKEN, value.trim()) }

    var telegramToken: String
        get() = prefs.getString(KEY_TG_TOKEN, "") ?: ""
        set(value) = prefs.edit { putString(KEY_TG_TOKEN, value.trim()) }

    var telegramChatId: String
        get() = prefs.getString(KEY_TG_CHAT, "") ?: ""
        set(value) = prefs.edit { putString(KEY_TG_CHAT, value.trim()) }

    var webhookUrl: String
        get() = prefs.getString(KEY_WEBHOOK, "") ?: ""
        set(value) = prefs.edit { putString(KEY_WEBHOOK, value.trim()) }

    var symbol: String
        get() = prefs.getString(KEY_SYMBOL, DEFAULT_SYMBOL) ?: DEFAULT_SYMBOL
        set(value) = prefs.edit { putString(KEY_SYMBOL, value.trim().uppercase()) }

    var timeframe: String
        get() = prefs.getString(KEY_TIMEFRAME, DEFAULT_TIMEFRAME) ?: DEFAULT_TIMEFRAME
        set(value) = prefs.edit { putString(KEY_TIMEFRAME, value.trim()) }

    var capital: String
        get() = prefs.getString(KEY_CAPITAL, "") ?: ""
        set(value) = prefs.edit { putString(KEY_CAPITAL, value.trim()) }

    var riskPercent: String
        get() = prefs.getString(KEY_RISK, DEFAULT_RISK) ?: DEFAULT_RISK
        set(value) = prefs.edit { putString(KEY_RISK, value.trim()) }

    var newsStatus: String
        get() = prefs.getString(KEY_NEWS, DEFAULT_NEWS) ?: DEFAULT_NEWS
        set(value) = prefs.edit { putString(KEY_NEWS, value.trim()) }

    /** Normalized API base URL ending with "/" (e.g. https://host/trader/api/). */
    fun apiBase(): String = normalizeApiBase(baseUrl)

    fun hasToken(): Boolean = apiToken.isNotBlank()

    companion object {
        const val PREFS_NAME = "meelano_settings"
        const val DEFAULT_SYMBOL = "BTCUSDT"
        const val DEFAULT_TIMEFRAME = "1h"
        const val DEFAULT_RISK = "1"
        const val DEFAULT_NEWS = "normal"

        val TIMEFRAMES = listOf("15m", "1h", "4h", "1d")
        val NEWS_STATUSES = listOf("normal", "cpi")
        val SYMBOL_PRESETS = listOf(
            "BTCUSDT", "ETHUSDT", "BNBUSDT", "SOLUSDT",
            "XRPUSDT", "ADAUSDT", "DOGEUSDT", "AVAXUSDT",
            "LINKUSDT", "TONUSDT", "TRXUSDT", "DOTUSDT"
        )

        private const val KEY_BASE_URL = "base_url"
        private const val KEY_API_TOKEN = "api_token"
        private const val KEY_TG_TOKEN = "tg_token"
        private const val KEY_TG_CHAT = "tg_chat"
        private const val KEY_WEBHOOK = "webhook"
        private const val KEY_SYMBOL = "symbol"
        private const val KEY_TIMEFRAME = "timeframe"
        private const val KEY_CAPITAL = "capital"
        private const val KEY_RISK = "risk"
        private const val KEY_NEWS = "news"

        /**
         * Accepts `https://host/trader`, `https://host/trader/api` or
         * `https://host/trader/api/index.php` and normalizes all of them to
         * `https://host/trader/api/` which is what the PHP core expects.
         */
        fun normalizeApiBase(raw: String): String {
            var value = raw.trim()
            if (value.isEmpty()) return ""
            if (value.endsWith("/index.php", ignoreCase = true)) {
                value = value.substring(0, value.length - "/index.php".length)
            }
            while (value.endsWith("/")) value = value.dropLast(1)
            val lowered = value.lowercase()
            if (!lowered.startsWith("http://") && !lowered.startsWith("https://")) {
                value = "https://$value"
            }
            return if (value.endsWith("/api", ignoreCase = true)) "$value/" else "$value/api/"
        }
    }
}
