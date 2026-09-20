package ir.meelano.hybrid

import android.app.Application
import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.SystemClock
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import ir.meelano.hybrid.core.HostProbe
import ir.meelano.hybrid.core.HybridConfig
import ir.meelano.hybrid.core.HybridRouter
import ir.meelano.hybrid.core.HybridTab
import ir.meelano.hybrid.core.ProbeDecision
import ir.meelano.hybrid.core.ProbeFailure
import ir.meelano.hybrid.core.ProbeSample
import ir.meelano.hybrid.core.ProbeVerdict
import ir.meelano.hybrid.core.ShellState
import ir.meelano.hybrid.core.Surface
import ir.meelano.hybrid.data.HybridSettings
import ir.meelano.hybrid.web.BridgeSnapshot
import ir.meelano.hybrid.web.CoreApi
import ir.meelano.hybrid.web.MeelanoBridge
import ir.meelano.hybrid.core.BridgeContract
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject
import java.io.IOException
import java.net.ConnectException
import java.net.SocketTimeoutException
import java.net.UnknownHostException
import java.util.concurrent.TimeUnit
import javax.net.ssl.SSLException

private val InitialProbe = ProbeDecision(
    verdict = ProbeVerdict.NOT_CONFIGURED,
    reachable = false,
    allowRemoteConsole = false,
    latencyMs = 0L,
    messageKey = HostProbe.KEY_NOT_CONFIGURED
)

/** Immutable snapshot the UI renders. `surface` is always router-derived. */
data class UiState(
    val tab: HybridTab = HybridTab.STATUS,
    val shell: ShellState = ShellState(),
    val probe: ProbeDecision = InitialProbe,
    val coreVersion: String = "",
    val dbConnected: Boolean = true,
    val probing: Boolean = false,
    val consoleReloads: Int = 0,
    val messageRes: Int = 0,
    val messageDetail: String = "",
    val hostInput: String = "",
    val tokenInput: String = "",
    val symbolInput: String = "",
    val timeframeInput: String = "",
    val preferNativeInput: Boolean = false
) {
    val surface: Surface get() = HybridRouter.applyPreference(tab, shell)
    val consoleUrl: String get() = surface.url
}

/**
 * Owns the hybrid decision loop: settings → probe → [ShellState] → router →
 * surface, plus the bridge that lets the embedded page reach the PHP core.
 *
 * Nothing here touches a `WebView` directly; [attachWebView] hands in a single
 * `evaluateJavascript` sink so the bridge can answer asynchronous calls.
 */
class HybridViewModel(application: Application) : AndroidViewModel(application) {

    val settings = HybridSettings(application)

    private val api = CoreApi(settings)

    private val probeClient: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .callTimeout(20, TimeUnit.SECONDS)
        .retryOnConnectionFailure(false)
        .build()

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()

    private var evaluateJs: ((String) -> Unit)? = null
    private var bridgeSource: String = ""
    /** Last payload the page pushed back through `postSignal`. */
    var lastSignalJson: String = ""
        private set

    /** Injected into pages as `window.MeelanoBridge`. */
    val bridge = MeelanoBridge(
        scope = viewModelScope,
        api = api,
        snapshot = ::snapshot,
        settingsReply = ::settingsJson,
        deliver = ::deliverJs,
        onSignal = ::onSignal,
        onLog = ::onLog
    )

    init {
        _state.update {
            it.copy(
                hostInput = settings.hostInput,
                tokenInput = settings.apiToken,
                symbolInput = settings.symbol,
                timeframeInput = settings.timeframe,
                preferNativeInput = settings.preferNative,
                shell = shellFrom(it.probe)
            )
        }
        if (settings.hasHost()) runProbe()
    }

    /* ── UI events ─────────────────────────────────────────────────────── */

    fun selectTab(tab: HybridTab) = _state.update { it.copy(tab = tab) }

    fun onHostChange(value: String) = _state.update { it.copy(hostInput = value) }

    fun onTokenChange(value: String) = _state.update { it.copy(tokenInput = value) }

    fun onSymbolChange(value: String) = _state.update { it.copy(symbolInput = value) }

    fun onTimeframeChange(value: String) = _state.update { it.copy(timeframeInput = value) }

    fun onPreferNativeChange(value: Boolean) = _state.update { it.copy(preferNativeInput = value) }

    fun consumeMessage() = _state.update { it.copy(messageRes = 0, messageDetail = "") }

    /** Bumps a counter the console surface keys its `LaunchedEffect` on. */
    fun reloadConsole() = _state.update { it.copy(consoleReloads = it.consoleReloads + 1) }

    fun saveSettings() {
        val current = _state.value
        settings.hostInput = current.hostInput
        settings.apiToken = current.tokenInput
        settings.symbol = current.symbolInput
        settings.timeframe = current.timeframeInput
        settings.preferNative = current.preferNativeInput
        _state.update { it.copy(shell = shellFrom(it.probe), messageRes = R.string.msg_settings_saved) }
        if (settings.hasHost()) runProbe()
    }

    fun runProbe() {
        val host = settings.hostRoot
        if (host.isEmpty()) {
            _state.update {
                it.copy(
                    probing = false,
                    probe = InitialProbe,
                    coreVersion = "",
                    shell = shellFrom(InitialProbe)
                )
            }
            return
        }
        _state.update { it.copy(probing = true) }
        viewModelScope.launch {
            val sample = withContext(Dispatchers.IO) { probeSample(HybridConfig.healthUrl(host)) }
            val decision = HostProbe.evaluate(true, sample)
            _state.update {
                it.copy(
                    probing = false,
                    probe = decision,
                    coreVersion = sample?.coreVersion.orEmpty(),
                    dbConnected = sample?.dbConnected ?: true,
                    shell = shellFrom(decision)
                )
            }
        }
    }

    /* ── WebView plumbing ──────────────────────────────────────────────── */

    /** Called by the console surface when its WebView is ready. */
    fun attachWebView(evaluate: (String) -> Unit, source: String) {
        evaluateJs = evaluate
        bridgeSource = source
    }

    fun detachWebView() {
        evaluateJs = null
    }

    /** A link pointed outside the host root and the bundled assets. */
    fun onBlockedNavigation(url: String) {
        onLog("blocked", url.take(180))
    }

    fun snapshot(): BridgeSnapshot = BridgeSnapshot(
        appVersion = BuildConfig.VERSION_NAME,
        hostRoot = settings.hostRoot,
        mode = _state.value.surface.kind.name.lowercase(),
        hasToken = settings.hasToken(),
        symbol = settings.symbol,
        timeframe = settings.timeframe
    )

    /** Bridge source injected into a page once it finishes loading. */
    fun bridgeScript(): String = bridgeSource

    /* ── internals ─────────────────────────────────────────────────────── */

    private fun shellFrom(decision: ProbeDecision): ShellState = ShellState(
        hostRoot = settings.hostRoot,
        hostReachable = decision.reachable,
        remoteConsoleAllowed = decision.allowRemoteConsole,
        authenticated = settings.hasToken(),
        networkOnline = networkOnline(),
        preferNative = settings.preferNative
    )

    private fun settingsJson(): String = buildString {
        append('{')
        append("\"host\":").append(BridgeContract.jsonString(settings.hostRoot)).append(',')
        append("\"symbol\":").append(BridgeContract.jsonString(settings.symbol)).append(',')
        append("\"timeframe\":").append(BridgeContract.jsonString(settings.timeframe)).append(',')
        append("\"preferNative\":").append(if (settings.preferNative) "true" else "false").append(',')
        append("\"token\":").append(if (settings.hasToken()) "true" else "false")
        append('}')
    }

    private fun deliverJs(script: String) {
        evaluateJs?.invoke(script)
    }

    private fun onSignal(payloadJson: String) {
        lastSignalJson = payloadJson
        _state.update {
            it.copy(
                messageRes = R.string.msg_signal_from_page,
                messageDetail = payloadJson.take(120)
            )
        }
    }

    private fun onLog(level: String, message: String) {
        _state.update {
            it.copy(
                messageRes = R.string.msg_page_log,
                messageDetail = "$level: ${message.take(160)}"
            )
        }
    }

    private fun networkOnline(): Boolean {
        val manager = getApplication<Application>()
            .getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
            ?: return true
        val network = manager.activeNetwork ?: return false
        val caps = manager.getNetworkCapabilities(network) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    /** One `GET api/health.php` attempt, mapped onto the pure [ProbeSample]. */
    private fun probeSample(url: String): ProbeSample {
        val started = SystemClock.elapsedRealtime()
        val request = Request.Builder()
            .url(url)
            .header("Accept", "application/json")
            .header("X-Requested-With", CoreApi.REQUESTED_WITH)
            .get()
            .build()
        return try {
            probeClient.newCall(request).execute().use { response ->
                val latency = SystemClock.elapsedRealtime() - started
                val text = try {
                    response.body?.string().orEmpty()
                } catch (e: IOException) {
                    return@use ProbeSample(0, ProbeFailure.IO, latency)
                }
                val json = if (text.isBlank()) null else try {
                    JSONObject(text)
                } catch (e: Exception) {
                    null
                }
                ProbeSample(
                    httpCode = response.code,
                    failure = ProbeFailure.NONE,
                    latencyMs = latency,
                    coreVersion = json?.optString("version", "").orEmpty(),
                    dbConnected = if (json == null) true else readDbConnected(json),
                    payloadValid = json != null
                )
            }
        } catch (e: SocketTimeoutException) {
            ProbeSample(0, ProbeFailure.TIMEOUT, SystemClock.elapsedRealtime() - started)
        } catch (e: SSLException) {
            ProbeSample(0, ProbeFailure.TLS, SystemClock.elapsedRealtime() - started)
        } catch (e: UnknownHostException) {
            ProbeSample(0, ProbeFailure.DNS, SystemClock.elapsedRealtime() - started)
        } catch (e: ConnectException) {
            ProbeSample(0, ProbeFailure.REFUSED, SystemClock.elapsedRealtime() - started)
        } catch (e: IOException) {
            ProbeSample(0, ProbeFailure.IO, SystemClock.elapsedRealtime() - started)
        }
    }

    /**
     * Two health shapes exist in this repo: `server/api/health.php` answers
     * `{"database":"connected"}` while `api/health.php` answers
     * `{"checks":{"db":{"ok":true}}}`. Both are understood; an unknown shape is
     * not punished.
     */
    private fun readDbConnected(json: JSONObject): Boolean {
        if (json.has("database")) {
            return json.optString("database").equals("connected", ignoreCase = true)
        }
        val db = json.optJSONObject("checks")?.optJSONObject("db")
        return db?.optBoolean("ok", true) ?: true
    }
}
