package ir.meelano.hybrid.core

/**
 * Verdict of the `<host>/api/health.php` probe. The verdict drives both the
 * status chips and whether the remote web console may be loaded.
 */
enum class ProbeVerdict {
    NOT_CONFIGURED,
    NO_RESPONSE,
    TIMEOUT,
    DNS_FAILURE,
    TLS_FAILURE,
    REFUSED,
    NETWORK_IO,
    NOT_INSTALLED,
    UNAUTHORIZED,
    RATE_LIMITED,
    SERVER_ERROR,
    BAD_PAYLOAD,
    DEGRADED,
    ONLINE
}

/** Transport-level failure observed while probing, if any. */
enum class ProbeFailure { NONE, TIMEOUT, DNS, TLS, REFUSED, IO }

/**
 * One probe attempt. `httpCode` is 0 when the request never produced a
 * response. `coreVersion`/`dbConnected` are read from the health payload.
 */
data class ProbeSample(
    val httpCode: Int,
    val failure: ProbeFailure = ProbeFailure.NONE,
    val latencyMs: Long = 0L,
    val coreVersion: String = "",
    val dbConnected: Boolean = true,
    /** False when a 2xx answered with something that is not the health JSON. */
    val payloadValid: Boolean = true
)

/**
 * What the shell should do with a probe: whether the host counts as reachable,
 * whether the WebView may load the remote console, and which localized message
 * key explains the state to the user.
 */
data class ProbeDecision(
    val verdict: ProbeVerdict,
    val reachable: Boolean,
    val allowRemoteConsole: Boolean,
    val latencyMs: Long,
    val messageKey: String
)

/**
 * Turns a raw health probe into a routing decision. Pure function: the same
 * sample always yields the same decision.
 */
object HostProbe {

    /** Slowest a healthy host may answer before it counts as a timeout. */
    const val TIMEOUT_BUDGET_MS = 9_000L

    /** Latency above which the console is still loaded but flagged as slow. */
    const val SLOW_MS = 4_000L

    /**
     * Oldest PHP core the hybrid shell understands. `<host>/api/health.php`
     * reports `4.0.0` for the hardened core in `server/` and `5.x` for the web
     * console backend in `api/`; both are accepted.
     */
    const val MIN_CORE_VERSION = "4.0"

    const val KEY_ONLINE = "probe_online"
    const val KEY_SLOW = "probe_slow"
    const val KEY_DEGRADED = "probe_degraded"
    const val KEY_NOT_CONFIGURED = "probe_not_configured"
    const val KEY_NO_RESPONSE = "probe_no_response"
    const val KEY_TIMEOUT = "probe_timeout"
    const val KEY_DNS = "probe_dns"
    const val KEY_TLS = "probe_tls"
    const val KEY_REFUSED = "probe_refused"
    const val KEY_IO = "probe_io"
    const val KEY_NOT_INSTALLED = "probe_not_installed"
    const val KEY_UNAUTHORIZED = "probe_unauthorized"
    const val KEY_RATE_LIMITED = "probe_rate_limited"
    const val KEY_SERVER = "probe_server"
    const val KEY_BAD_PAYLOAD = "probe_bad_payload"

    fun evaluate(hostConfigured: Boolean, sample: ProbeSample?): ProbeDecision {
        if (!hostConfigured) {
            return ProbeDecision(ProbeVerdict.NOT_CONFIGURED, false, false, 0L, KEY_NOT_CONFIGURED)
        }
        if (sample == null) {
            return ProbeDecision(ProbeVerdict.NO_RESPONSE, false, false, 0L, KEY_NO_RESPONSE)
        }
        when (sample.failure) {
            ProbeFailure.TIMEOUT ->
                return ProbeDecision(ProbeVerdict.TIMEOUT, false, false, sample.latencyMs, KEY_TIMEOUT)
            ProbeFailure.DNS ->
                return ProbeDecision(ProbeVerdict.DNS_FAILURE, false, false, sample.latencyMs, KEY_DNS)
            ProbeFailure.TLS ->
                return ProbeDecision(ProbeVerdict.TLS_FAILURE, false, false, sample.latencyMs, KEY_TLS)
            ProbeFailure.REFUSED ->
                return ProbeDecision(ProbeVerdict.REFUSED, false, false, sample.latencyMs, KEY_REFUSED)
            ProbeFailure.IO ->
                return ProbeDecision(ProbeVerdict.NETWORK_IO, false, false, sample.latencyMs, KEY_IO)
            ProbeFailure.NONE -> Unit
        }
        return when (sample.httpCode) {
            in 200..299 -> success(sample)
            401, 403 -> ProbeDecision(ProbeVerdict.UNAUTHORIZED, true, false, sample.latencyMs, KEY_UNAUTHORIZED)
            404 -> ProbeDecision(ProbeVerdict.NOT_INSTALLED, true, false, sample.latencyMs, KEY_NOT_INSTALLED)
            429 -> ProbeDecision(ProbeVerdict.RATE_LIMITED, true, false, sample.latencyMs, KEY_RATE_LIMITED)
            // health.php answers 503 when the core is up but degraded (database
            // down, config incomplete). The console still loads, so the WebView
            // is allowed and the shell just flags the state.
            503 -> ProbeDecision(ProbeVerdict.DEGRADED, true, true, sample.latencyMs, KEY_DEGRADED)
            in 500..599 -> ProbeDecision(ProbeVerdict.SERVER_ERROR, true, false, sample.latencyMs, KEY_SERVER)
            else -> ProbeDecision(ProbeVerdict.BAD_PAYLOAD, true, false, sample.latencyMs, KEY_BAD_PAYLOAD)
        }
    }

    private fun success(sample: ProbeSample): ProbeDecision {
        if (!sample.payloadValid) {
            return ProbeDecision(ProbeVerdict.BAD_PAYLOAD, true, false, sample.latencyMs, KEY_BAD_PAYLOAD)
        }
        if (!sample.dbConnected) {
            return ProbeDecision(ProbeVerdict.DEGRADED, true, true, sample.latencyMs, KEY_DEGRADED)
        }
        if (sample.coreVersion.isNotBlank() && compareVersions(sample.coreVersion, MIN_CORE_VERSION) < 0) {
            return ProbeDecision(ProbeVerdict.DEGRADED, true, true, sample.latencyMs, KEY_DEGRADED)
        }
        val key = if (sample.latencyMs > SLOW_MS) KEY_SLOW else KEY_ONLINE
        return ProbeDecision(ProbeVerdict.ONLINE, true, true, sample.latencyMs, key)
    }

    /**
     * Numeric dotted comparison that tolerates suffixes: `5.8.1` > `5.8`,
     * `v5.9` > `5.8`, and a non-numeric tail (`5.8-beta`) is ignored.
     * Missing segments count as zero, so `5.8` == `5.8.0`.
     */
    fun compareVersions(left: String, right: String): Int {
        val a = segmentsOf(left)
        val b = segmentsOf(right)
        val size = maxOf(a.size, b.size)
        for (index in 0 until size) {
            val x = a.getOrElse(index) { 0 }
            val y = b.getOrElse(index) { 0 }
            if (x != y) return if (x > y) 1 else -1
        }
        return 0
    }

    private fun segmentsOf(version: String): List<Int> {
        val cleaned = version.trim().trimStart('v', 'V')
        val numeric = cleaned.takeWhile { it.isDigit() || it == '.' }
        if (numeric.isBlank()) return emptyList()
        return numeric.split('.').mapNotNull { part -> part.toIntOrNull() }
    }
}
