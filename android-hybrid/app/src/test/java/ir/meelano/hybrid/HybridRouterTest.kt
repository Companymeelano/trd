package ir.meelano.hybrid

import ir.meelano.hybrid.core.HybridConfig
import ir.meelano.hybrid.core.HybridRouter
import ir.meelano.hybrid.core.HybridTab
import ir.meelano.hybrid.core.ShellState
import ir.meelano.hybrid.core.SurfaceKind
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The router is the whole point of the hybrid app: it decides which surface a tab
 * renders. Every branch is pinned here so a future change has to argue with a
 * failing test.
 */
class HybridRouterTest {

    private val readyHost = "https://panel.example.com/trader/"

    private fun online() = ShellState(
        hostRoot = readyHost,
        hostReachable = true,
        remoteConsoleAllowed = true,
        authenticated = true,
        networkOnline = true
    )

    @Test
    fun `setup tab is always native`() {
        val surface = HybridRouter.decide(HybridTab.SETUP, online())
        assertEquals(SurfaceKind.NATIVE, surface.kind)
        assertEquals(HybridRouter.REASON_NATIVE_SETUP, surface.reasonKey)

        val offline = HybridRouter.decide(HybridTab.SETUP, online().copy(networkOnline = false))
        assertEquals(SurfaceKind.NATIVE, offline.kind)
    }

    @Test
    fun `status tab is native even when the host is healthy`() {
        val surface = HybridRouter.decide(HybridTab.STATUS, online())
        assertEquals(SurfaceKind.NATIVE, surface.kind)
        assertEquals(HybridRouter.REASON_NATIVE_STATUS, surface.reasonKey)
    }

    @Test
    fun `console loads the remote page when the probe allows it`() {
        val surface = HybridRouter.decide(HybridTab.CONSOLE, online())
        assertEquals(SurfaceKind.WEB, surface.kind)
        assertEquals(HybridRouter.REASON_REMOTE_CONSOLE, surface.reasonKey)
        assertEquals("https://panel.example.com/trader/index.php", surface.url)
        assertTrue(HybridRouter.canOpenRemoteConsole(online()))
    }

    @Test
    fun `console falls back to the bundled asset when the host is unreachable`() {
        val state = online().copy(hostReachable = false, remoteConsoleAllowed = false)
        val surface = HybridRouter.decide(HybridTab.CONSOLE, state)
        assertEquals(SurfaceKind.OFFLINE, surface.kind)
        assertEquals(HybridRouter.REASON_HOST_UNREACHABLE, surface.reasonKey)
        assertEquals(HybridConfig.offlineUrl(), surface.url)
    }

    @Test
    fun `a healthy host without console permission still falls back`() {
        // e.g. UNAUTHORIZED or RATE_LIMITED: reachable, but the remote console
        // must not be loaded.
        val state = online().copy(remoteConsoleAllowed = false)
        val surface = HybridRouter.decide(HybridTab.CONSOLE, state)
        assertEquals(SurfaceKind.OFFLINE, surface.kind)
        assertEquals(HybridRouter.REASON_HOST_UNREACHABLE, surface.reasonKey)
    }

    @Test
    fun `no network at all shows the bundled console, never a spinner`() {
        val surface = HybridRouter.decide(HybridTab.CONSOLE, online().copy(networkOnline = false))
        assertEquals(SurfaceKind.OFFLINE, surface.kind)
        assertEquals(HybridRouter.REASON_NO_NETWORK, surface.reasonKey)
    }

    @Test
    fun `an unconfigured host sends the console tab to setup`() {
        val surface = HybridRouter.decide(HybridTab.CONSOLE, ShellState())
        assertEquals(SurfaceKind.NATIVE, surface.kind)
        assertEquals(HybridRouter.REASON_SETUP_REQUIRED, surface.reasonKey)
        assertEquals("", surface.url)
    }

    @Test
    fun `preferNative keeps the status tab native and leaves the console alone`() {
        val state = online().copy(preferNative = true)
        assertEquals(
            SurfaceKind.NATIVE,
            HybridRouter.applyPreference(HybridTab.STATUS, state).kind
        )
        assertEquals(
            SurfaceKind.WEB,
            HybridRouter.applyPreference(HybridTab.CONSOLE, state).kind
        )
        assertEquals(
            SurfaceKind.NATIVE,
            HybridRouter.applyPreference(HybridTab.SETUP, state).kind
        )
    }

    @Test
    fun `every tab stays available so the app is never a dead end`() {
        assertEquals(
            listOf(HybridTab.STATUS, HybridTab.CONSOLE, HybridTab.SETUP),
            HybridRouter.availableTabs(ShellState())
        )
    }

    @Test
    fun `banner is only shown for fallback surfaces`() {
        assertNull(HybridRouter.bannerReason(HybridRouter.decide(HybridTab.CONSOLE, online())))
        val fallback = HybridRouter.decide(HybridTab.CONSOLE, online().copy(networkOnline = false))
        assertEquals(HybridRouter.REASON_NO_NETWORK, HybridRouter.bannerReason(fallback))
    }

    @Test
    fun `unknown tab ids fall back to the status tab`() {
        assertEquals(HybridTab.STATUS, HybridTab.fromId("nonsense"))
        assertEquals(HybridTab.CONSOLE, HybridTab.fromId("console"))
    }

    @Test
    fun `host normalization feeds the router`() {
        val state = ShellState(hostRoot = HybridConfig.normalizeHost("panel.example.com/trader/api/"))
        assertTrue(state.hostConfigured)
        assertTrue(state.secureHost)
        assertEquals(
            "https://panel.example.com/trader/index.php",
            HybridConfig.consoleUrl(state.hostRoot)
        )
    }
}
