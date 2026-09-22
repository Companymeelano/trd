package ir.meelano.hybrid.core

/** Where the pixels of a tab come from. */
enum class SurfaceKind { NATIVE, WEB, OFFLINE }

/**
 * Tabs of the unified shell. `CONSOLE` is the PHP web console; `STATUS` and
 * `SETUP` are native Kotlin/Compose screens that keep working with no host at
 * all — that is what makes the app a hybrid instead of a thin wrapper.
 */
enum class HybridTab(val id: String, val labelKey: String) {
    STATUS("status", "tab_status"),
    CONSOLE("console", "tab_console"),
    SETUP("setup", "tab_setup");

    companion object {
        fun fromId(id: String): HybridTab = entries.firstOrNull { it.id == id } ?: STATUS
    }
}

/**
 * Everything the router needs to know about the device and the host. Filled in
 * by `HybridViewModel` from `HybridSettings` and the latest probe.
 */
data class ShellState(
    val hostRoot: String = "",
    val hostReachable: Boolean = false,
    val remoteConsoleAllowed: Boolean = false,
    val authenticated: Boolean = false,
    val networkOnline: Boolean = true,
    val preferNative: Boolean = false
) {
    val hostConfigured: Boolean get() = HybridConfig.isConfigured(hostRoot)
    val secureHost: Boolean get() = HybridConfig.isSecure(hostRoot)
}

/** Resolved rendering target of a tab, plus why it was chosen. */
data class Surface(
    val kind: SurfaceKind,
    val url: String,
    val reasonKey: String
)

/**
 * Decides, per tab, whether the shell shows a native screen, the remote web
 * console, or the bundled offline console. Pure and total: every combination
 * of `ShellState` maps to exactly one `Surface`.
 *
 * Precedence:
 *  1. `SETUP` is always native — the host cannot be configured from a page
 *     served by that same host.
 *  2. No network at all → the bundled console (never a spinner).
 *  3. `preferNative` keeps `STATUS` native but still lets `CONSOLE` go remote,
 *     because opening the console is an explicit user intent.
 *  4. Otherwise remote when the probe allows it, bundled fallback when not.
 */
object HybridRouter {

    const val REASON_NATIVE_STATUS = "reason_native_status"
    const val REASON_NATIVE_SETUP = "reason_native_setup"
    const val REASON_REMOTE_CONSOLE = "reason_remote_console"
    const val REASON_OFFLINE_ASSET = "reason_offline_asset"
    const val REASON_SETUP_REQUIRED = "reason_setup_required"
    const val REASON_HOST_UNREACHABLE = "reason_host_unreachable"
    const val REASON_AUTH_REQUIRED = "reason_auth_required"
    const val REASON_NO_NETWORK = "reason_no_network"
    const val REASON_USER_FORCED_NATIVE = "reason_user_forced_native"

    fun decide(tab: HybridTab, state: ShellState): Surface = when (tab) {
        HybridTab.SETUP -> Surface(SurfaceKind.NATIVE, "", REASON_NATIVE_SETUP)
        HybridTab.STATUS -> statusSurface(state)
        HybridTab.CONSOLE -> consoleSurface(state)
    }

    private fun statusSurface(state: ShellState): Surface =
        Surface(SurfaceKind.NATIVE, "", REASON_NATIVE_STATUS)

    private fun consoleSurface(state: ShellState): Surface {
        if (!state.hostConfigured) {
            return Surface(SurfaceKind.NATIVE, "", REASON_SETUP_REQUIRED)
        }
        if (!state.networkOnline) {
            return Surface(SurfaceKind.OFFLINE, HybridConfig.offlineUrl(), REASON_NO_NETWORK)
        }
        if (!state.remoteConsoleAllowed || !state.hostReachable) {
            return Surface(SurfaceKind.OFFLINE, HybridConfig.offlineUrl(), REASON_HOST_UNREACHABLE)
        }
        return Surface(SurfaceKind.WEB, HybridConfig.consoleUrl(state.hostRoot), REASON_REMOTE_CONSOLE)
    }

    /**
     * Tabs that can actually render something useful right now. `SETUP` is
     * always available; the bundled console keeps `CONSOLE` available even with
     * no host configured, so the app is never a dead end.
     */
    fun availableTabs(state: ShellState): List<HybridTab> = HybridTab.entries.toList()

    /** True when the shell may show the "console is remote" affordance. */
    fun canOpenRemoteConsole(state: ShellState): Boolean =
        decide(HybridTab.CONSOLE, state).kind == SurfaceKind.WEB

    /** Human-readable reason for the banner shown above a console surface. */
    fun bannerReason(surface: Surface): String? = when (surface.reasonKey) {
        REASON_REMOTE_CONSOLE -> null
        REASON_NATIVE_STATUS, REASON_NATIVE_SETUP -> null
        else -> surface.reasonKey
    }

    /**
     * Explicit "stay native" toggle from settings: it only affects `STATUS`,
     * and it can never hide the setup screen.
     */
    fun applyPreference(tab: HybridTab, state: ShellState): Surface {
        val base = decide(tab, state)
        if (!state.preferNative) return base
        if (tab != HybridTab.STATUS) return base
        if (base.kind == SurfaceKind.NATIVE) return base
        return Surface(SurfaceKind.NATIVE, "", REASON_USER_FORCED_NATIVE)
    }
}
