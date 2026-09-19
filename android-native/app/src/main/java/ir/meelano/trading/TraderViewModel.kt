package ir.meelano.trading

import android.app.Application
import androidx.annotation.StringRes
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import ir.meelano.trading.data.ApiClient
import ir.meelano.trading.data.ApiErrorKind
import ir.meelano.trading.data.ApiException
import ir.meelano.trading.data.AppSettings
import ir.meelano.trading.data.BacktestResult
import ir.meelano.trading.data.DemoData
import ir.meelano.trading.data.HealthStatus
import ir.meelano.trading.data.HistoryItem
import ir.meelano.trading.data.Signal
import ir.meelano.trading.data.SignalParser
import ir.meelano.trading.data.SignalText
import ir.meelano.trading.data.TriState
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

data class UiMessage(
    val id: Long,
    @StringRes val textRes: Int,
    val detail: String? = null
)

data class UiState(
    val tab: Int = TraderViewModel.TAB_DASHBOARD,
    val baseUrl: String = "",
    val apiToken: String = "",
    val telegramToken: String = "",
    val telegramChatId: String = "",
    val webhookUrl: String = "",
    val symbol: String = AppSettings.DEFAULT_SYMBOL,
    val timeframe: String = AppSettings.DEFAULT_TIMEFRAME,
    val capital: String = "",
    val riskPercent: String = AppSettings.DEFAULT_RISK,
    val newsStatus: String = AppSettings.DEFAULT_NEWS,
    val signal: Signal? = null,
    val signalAt: String? = null,
    val history: List<HistoryItem> = emptyList(),
    val historyLoaded: Boolean = false,
    val scan: List<Signal> = emptyList(),
    val backtest: BacktestResult? = null,
    val health: HealthStatus? = null,
    val healthState: TriState = TriState.UNKNOWN,
    val dbState: TriState = TriState.UNKNOWN,
    val authState: TriState = TriState.UNKNOWN,
    val busy: Set<String> = emptySet(),
    val message: UiMessage? = null
) {
    fun isBusy(key: String): Boolean = key in busy
}

class TraderViewModel(application: Application) : AndroidViewModel(application) {

    private val settings = AppSettings(application)
    private val api = ApiClient(settings)

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()

    private var messageCounter = 0L

    init {
        _state.update {
            it.copy(
                baseUrl = settings.baseUrl,
                apiToken = settings.apiToken,
                telegramToken = settings.telegramToken,
                telegramChatId = settings.telegramChatId,
                webhookUrl = settings.webhookUrl,
                symbol = settings.symbol,
                timeframe = settings.timeframe,
                capital = settings.capital,
                riskPercent = settings.riskPercent,
                newsStatus = settings.newsStatus
            )
        }
        if (settings.baseUrl.isNotBlank()) {
            checkHealth()
            if (settings.hasToken()) checkAuth(showToast = false)
            loadHistory(showToast = false)
        }
    }

    // ------------------------------------------------------------------ tabs

    fun selectTab(tab: Int) {
        _state.update { it.copy(tab = tab) }
        if (tab == TAB_JOURNAL) loadHistory(showToast = false)
    }

    // --------------------------------------------------------------- setters

    fun setBaseUrl(value: String) = _state.update { it.copy(baseUrl = value) }
    fun setApiToken(value: String) = _state.update { it.copy(apiToken = value) }
    fun setTelegramToken(value: String) = _state.update { it.copy(telegramToken = value) }
    fun setTelegramChatId(value: String) = _state.update { it.copy(telegramChatId = value) }
    fun setWebhookUrl(value: String) = _state.update { it.copy(webhookUrl = value) }
    fun setCapital(value: String) {
        _state.update { it.copy(capital = value) }
        settings.capital = value
    }

    fun setRiskPercent(value: String) {
        _state.update { it.copy(riskPercent = value) }
        settings.riskPercent = value
    }

    fun setNewsStatus(value: String) {
        _state.update { it.copy(newsStatus = value) }
        settings.newsStatus = value
    }

    fun setTimeframe(value: String) {
        _state.update { it.copy(timeframe = value) }
        settings.timeframe = value
    }

    fun setSymbol(value: String) {
        _state.update { it.copy(symbol = value.uppercase()) }
        settings.symbol = value
    }

    fun analyseFromScan(symbol: String) {
        setSymbol(symbol)
        _state.update { it.copy(tab = TAB_ANALYSIS) }
    }

    // -------------------------------------------------------------- settings

    fun saveSettings() {
        val snapshot = _state.value
        settings.baseUrl = snapshot.baseUrl
        settings.apiToken = snapshot.apiToken
        settings.telegramToken = snapshot.telegramToken
        settings.telegramChatId = snapshot.telegramChatId
        settings.webhookUrl = snapshot.webhookUrl
        post(R.string.msg_settings_saved)
        if (settings.baseUrl.isNotBlank()) {
            checkHealth()
            if (settings.hasToken()) checkAuth(showToast = false)
        }
    }

    fun checkAuth(showToast: Boolean = true) {
        if (settings.baseUrl.isBlank() || !settings.hasToken()) {
            _state.update { it.copy(authState = TriState.UNKNOWN) }
            if (showToast) post(R.string.msg_missing_config)
            return
        }
        launch {
            setBusy(BUSY_AUTH, true)
            try {
                val ok = SignalParser.authenticated(api.authCheck())
                _state.update { it.copy(authState = if (ok) TriState.OK else TriState.BAD) }
                if (showToast) post(if (ok) R.string.msg_token_valid else R.string.msg_token_invalid)
            } catch (e: Exception) {
                _state.update { it.copy(authState = TriState.BAD) }
                if (showToast) postError(e)
            } finally {
                setBusy(BUSY_AUTH, false)
            }
        }
    }

    fun checkHealth() {
        if (settings.baseUrl.isBlank()) {
            _state.update { it.copy(healthState = TriState.UNKNOWN, dbState = TriState.UNKNOWN) }
            return
        }
        launch {
            setBusy(BUSY_HEALTH, true)
            try {
                val health = SignalParser.health(api.health())
                val apiOk = health.status != null && health.status != "down"
                val dbOk = health.database != null && health.database != "down"
                _state.update {
                    it.copy(
                        health = health,
                        healthState = if (apiOk) TriState.OK else TriState.BAD,
                        dbState = if (dbOk) TriState.OK else TriState.BAD
                    )
                }
            } catch (e: Exception) {
                _state.update { it.copy(healthState = TriState.BAD, dbState = TriState.BAD) }
            } finally {
                setBusy(BUSY_HEALTH, false)
            }
        }
    }

    // -------------------------------------------------------------- analysis

    fun analyze() {
        val snapshot = _state.value
        val symbol = snapshot.symbol.trim().uppercase()
        if (!SYMBOL_PATTERN.matches(symbol)) {
            post(R.string.err_symbol_invalid)
            return
        }
        val risk = snapshot.riskPercent.trim().toDoubleOrNull()
        if (risk == null || risk <= 0.0 || risk > 1.0) {
            post(R.string.err_risk_range)
            return
        }
        val capitalText = snapshot.capital.trim()
        val capital = capitalText.toDoubleOrNull()
        if (capitalText.isNotEmpty() && (capital == null || capital <= 0.0)) {
            post(R.string.err_capital_invalid)
            return
        }
        if (!ready()) return
        launch {
            setBusy(BUSY_ANALYZE, true)
            try {
                val payload = api.analyze(symbol, snapshot.timeframe, capital, risk, snapshot.newsStatus)
                val signal = SignalParser.signal(payload)
                _state.update { it.copy(signal = signal, signalAt = now(), tab = TAB_DASHBOARD) }
                post(R.string.msg_analysis_done)
                loadHistory(showToast = false)
            } catch (e: Exception) {
                postError(e)
            } finally {
                setBusy(BUSY_ANALYZE, false)
            }
        }
    }

    fun loadHistory(showToast: Boolean = true) {
        if (!ready(showToast)) return
        launch {
            setBusy(BUSY_HISTORY, true)
            try {
                val items = SignalParser.historyItems(api.history(HISTORY_LIMIT))
                _state.update { it.copy(history = items, historyLoaded = true) }
                if (showToast) post(R.string.msg_history_done)
            } catch (e: Exception) {
                if (showToast) postError(e)
            } finally {
                setBusy(BUSY_HISTORY, false)
            }
        }
    }

    fun runScan() {
        if (!ready()) return
        launch {
            setBusy(BUSY_SCAN, true)
            try {
                val items = SignalParser.scanSignals(api.scan("USDT"))
                _state.update { it.copy(scan = items) }
                post(R.string.msg_scan_done)
            } catch (e: Exception) {
                postError(e)
            } finally {
                setBusy(BUSY_SCAN, false)
            }
        }
    }

    fun runBacktest() {
        val snapshot = _state.value
        val symbol = snapshot.symbol.trim().uppercase()
        if (!SYMBOL_PATTERN.matches(symbol)) {
            post(R.string.err_symbol_invalid)
            return
        }
        val risk = snapshot.riskPercent.trim().toDoubleOrNull()
        if (risk == null || risk <= 0.0 || risk > 1.0) {
            post(R.string.err_risk_range)
            return
        }
        val capitalText = snapshot.capital.trim()
        val capital = if (capitalText.isEmpty()) DEFAULT_BACKTEST_CAPITAL else capitalText.toDoubleOrNull()
        if (capital == null || capital < 10.0) {
            post(R.string.err_capital_invalid)
            return
        }
        if (!ready()) return
        launch {
            setBusy(BUSY_BACKTEST, true)
            try {
                val result = SignalParser.backtest(
                    api.backtest(symbol, snapshot.timeframe, capital, risk, snapshot.newsStatus)
                )
                _state.update { it.copy(backtest = result) }
                post(R.string.msg_backtest_done)
            } catch (e: Exception) {
                postError(e)
            } finally {
                setBusy(BUSY_BACKTEST, false)
            }
        }
    }

    // ----------------------------------------------------------- notifications

    fun sendCurrentSignal() {
        val signal = _state.value.signal
        if (signal == null) {
            post(R.string.msg_no_signal_to_send)
            return
        }
        sendNotification(if (_state.value.webhookUrl.isNotBlank()) CHANNEL_ALL else CHANNEL_TELEGRAM, SignalText.build(signal))
    }

    fun testNotifications() {
        sendNotification(CHANNEL_ALL, "<div dir=\"rtl\">تست اعلان MeeLano · Android</div>")
    }

    private fun sendNotification(channel: String, message: String) {
        if (!ready()) return
        launch {
            setBusy(BUSY_NOTIFY, true)
            try {
                api.notify(channel, message)
                post(R.string.msg_signal_sent)
            } catch (e: Exception) {
                postError(e)
            } finally {
                setBusy(BUSY_NOTIFY, false)
            }
        }
    }

    // ------------------------------------------------------------------ demo

    fun loadDemo() {
        val signal = SignalParser.signal(JSONObject(DemoData.ANALYZE_PAYLOAD))
        val history = SignalParser.historyItems(JSONObject(DemoData.HISTORY_PAYLOAD))
        _state.update {
            it.copy(
                signal = signal,
                signalAt = now(),
                history = history,
                historyLoaded = true,
                backtest = BacktestResult(
                    totalTrades = 46,
                    winRate = 58.7,
                    profitFactor = 1.94,
                    profitPct = 12.35,
                    maxDrawdown = 4.82,
                    finalEquity = 1123.5,
                    engine = "four-gate-pipeline"
                )
            )
        }
        post(R.string.msg_demo_loaded)
    }

    fun consumeMessage() = _state.update { it.copy(message = null) }

    // --------------------------------------------------------------- helpers

    private fun ready(showToast: Boolean = true): Boolean {
        if (settings.baseUrl.isBlank() || !settings.hasToken()) {
            if (showToast) post(R.string.msg_missing_config)
            return false
        }
        return true
    }

    private fun post(@StringRes res: Int, detail: String? = null) {
        _state.update { it.copy(message = UiMessage(++messageCounter, res, detail)) }
    }

    private fun postError(error: Throwable) {
        if (error is ApiException) {
            val res = when (error.kind) {
                ApiErrorKind.TIMEOUT -> R.string.err_timeout
                ApiErrorKind.UNAUTHORIZED -> R.string.err_unauthorized
                ApiErrorKind.RATE_LIMIT -> R.string.err_rate_limit
                ApiErrorKind.SERVER -> R.string.err_server
                ApiErrorKind.CONFIG -> R.string.msg_missing_config
                ApiErrorKind.BAD_RESPONSE -> R.string.err_server
                ApiErrorKind.HTTP -> R.string.err_server
                ApiErrorKind.NETWORK -> R.string.err_network
            }
            post(res, error.message)
        } else {
            post(R.string.err_network, error.message)
        }
    }

    private fun setBusy(key: String, busy: Boolean) {
        _state.update {
            it.copy(busy = if (busy) it.busy + key else it.busy - key)
        }
    }

    private fun launch(block: suspend () -> Unit) {
        viewModelScope.launch { block() }
    }

    private fun now(): String =
        SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(Date())

    companion object {
        const val TAB_DASHBOARD = 0
        const val TAB_ANALYSIS = 1
        const val TAB_MARKET = 2
        const val TAB_JOURNAL = 3
        const val TAB_SETTINGS = 4

        const val BUSY_ANALYZE = "analyze"
        const val BUSY_SCAN = "scan"
        const val BUSY_BACKTEST = "backtest"
        const val BUSY_HISTORY = "history"
        const val BUSY_AUTH = "auth"
        const val BUSY_HEALTH = "health"
        const val BUSY_NOTIFY = "notify"

        private const val HISTORY_LIMIT = 100
        private const val DEFAULT_BACKTEST_CAPITAL = 1000.0
        private const val CHANNEL_TELEGRAM = "telegram"
        private const val CHANNEL_ALL = "all"

        private val SYMBOL_PATTERN = Regex("^[A-Z0-9]{2,24}$")
    }
}
