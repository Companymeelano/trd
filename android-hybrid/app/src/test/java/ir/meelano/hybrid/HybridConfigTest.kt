package ir.meelano.hybrid

import ir.meelano.hybrid.core.HybridConfig
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Address handling for the hybrid shell: one user-entered host feeds the native
 * API client *and* the WebView, so every accepted spelling has to normalize to
 * the same root.
 */
class HybridConfigTest {

    @Test
    fun `every spelling normalizes to the host root`() {
        val expected = "https://panel.example.com/trader/"
        listOf(
            "panel.example.com/trader",
            "panel.example.com/trader/",
            "https://panel.example.com/trader",
            "https://panel.example.com/trader/",
            "https://panel.example.com/trader/api",
            "https://panel.example.com/trader/api/",
            "https://panel.example.com/trader/index.php",
            "  https://panel.example.com/trader/api/  "
        ).forEach { raw ->
            assertEquals("raw=$raw", expected, HybridConfig.normalizeHost(raw))
        }
    }

    @Test
    fun `http is preserved but https is the default`() {
        assertEquals("http://host.test/trader/", HybridConfig.normalizeHost("http://host.test/trader"))
        assertFalse(HybridConfig.isSecure(HybridConfig.normalizeHost("http://host.test/trader")))
        assertTrue(HybridConfig.isSecure(HybridConfig.normalizeHost("host.test/trader")))
    }

    @Test
    fun `empty input stays empty`() {
        assertEquals("", HybridConfig.normalizeHost("   "))
        assertFalse(HybridConfig.isConfigured(HybridConfig.normalizeHost("")))
        assertEquals("", HybridConfig.consoleUrl(""))
        assertEquals("", HybridConfig.apiBase(""))
        assertEquals("", HybridConfig.healthUrl(""))
    }

    @Test
    fun `derived urls hang off the same root`() {
        val root = HybridConfig.normalizeHost("panel.example.com/trader")
        assertEquals("https://panel.example.com/trader/index.php", HybridConfig.consoleUrl(root))
        assertEquals("https://panel.example.com/trader/api/", HybridConfig.apiBase(root))
        assertEquals("https://panel.example.com/trader/api/health.php", HybridConfig.healthUrl(root))
    }

    @Test
    fun `bundled assets live on the synthetic https origin`() {
        assertEquals(
            "https://${HybridConfig.ASSET_DOMAIN}/assets/www/offline.html",
            HybridConfig.offlineUrl()
        )
        assertEquals(
            "https://${HybridConfig.ASSET_DOMAIN}/assets/www/hybrid-bridge.js",
            HybridConfig.bridgeUrl()
        )
    }

    @Test
    fun `origin parsing ignores path, query and case`() {
        assertEquals("https://a.test", HybridConfig.originOf("HTTPS://A.Test/x/y?z=1#f"))
        assertEquals("http://a.test:8080", HybridConfig.originOf("http://a.test:8080/x"))
        assertEquals("", HybridConfig.originOf("not a url"))
    }

    @Test
    fun `navigation is internal only for the host or the bundled assets`() {
        val root = "https://panel.example.com/trader/"
        assertTrue(HybridConfig.isInternalTarget(root, "https://panel.example.com/trader/index.php"))
        assertTrue(HybridConfig.isInternalTarget(root, HybridConfig.offlineUrl()))
        assertFalse(HybridConfig.isInternalTarget(root, "https://evil.test/phish"))
        assertFalse(HybridConfig.isInternalTarget(root, "https://panel.example.com.evil.test/"))
        assertFalse(HybridConfig.sameOrigin(root, "http://panel.example.com/trader/"))
    }
}
