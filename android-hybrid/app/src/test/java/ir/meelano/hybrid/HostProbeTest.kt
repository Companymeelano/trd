package ir.meelano.hybrid

import ir.meelano.hybrid.core.HostProbe
import ir.meelano.hybrid.core.ProbeFailure
import ir.meelano.hybrid.core.ProbeSample
import ir.meelano.hybrid.core.ProbeVerdict
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * `api/health.php` answers in two shapes in this repo (the hardened core in
 * `server/` reports `database`, the console backend in `api/` reports
 * `checks.db.ok`), and it uses 503 for "up but degraded". All of it is pinned
 * here because the verdict decides whether the WebView may load the remote
 * console.
 */
class HostProbeTest {

    @Test
    fun `no host configured`() {
        val decision = HostProbe.evaluate(false, null)
        assertEquals(ProbeVerdict.NOT_CONFIGURED, decision.verdict)
        assertFalse(decision.reachable)
        assertFalse(decision.allowRemoteConsole)
        assertEquals(HostProbe.KEY_NOT_CONFIGURED, decision.messageKey)
    }

    @Test
    fun `configured host with no sample at all`() {
        val decision = HostProbe.evaluate(true, null)
        assertEquals(ProbeVerdict.NO_RESPONSE, decision.verdict)
        assertFalse(decision.reachable)
    }

    @Test
    fun `transport failures map one to one`() {
        val cases = mapOf(
            ProbeFailure.TIMEOUT to ProbeVerdict.TIMEOUT,
            ProbeFailure.DNS to ProbeVerdict.DNS_FAILURE,
            ProbeFailure.TLS to ProbeVerdict.TLS_FAILURE,
            ProbeFailure.REFUSED to ProbeVerdict.REFUSED,
            ProbeFailure.IO to ProbeVerdict.NETWORK_IO
        )
        cases.forEach { (failure, verdict) ->
            val decision = HostProbe.evaluate(true, ProbeSample(0, failure, 120L))
            assertEquals("failure $failure", verdict, decision.verdict)
            assertFalse(decision.reachable)
            assertFalse(decision.allowRemoteConsole)
            assertEquals(120L, decision.latencyMs)
        }
    }

    @Test
    fun `healthy core goes online and unlocks the remote console`() {
        val decision = HostProbe.evaluate(
            true,
            ProbeSample(200, coreVersion = "4.0.0", dbConnected = true, latencyMs = 240L)
        )
        assertEquals(ProbeVerdict.ONLINE, decision.verdict)
        assertTrue(decision.reachable)
        assertTrue(decision.allowRemoteConsole)
        assertEquals(HostProbe.KEY_ONLINE, decision.messageKey)
    }

    @Test
    fun `a slow but healthy core is flagged, not blocked`() {
        val decision = HostProbe.evaluate(
            true,
            ProbeSample(200, coreVersion = "4.0.0", latencyMs = HostProbe.SLOW_MS + 1)
        )
        assertEquals(ProbeVerdict.ONLINE, decision.verdict)
        assertTrue(decision.allowRemoteConsole)
        assertEquals(HostProbe.KEY_SLOW, decision.messageKey)
    }

    @Test
    fun `database down degrades but still allows the console`() {
        val decision = HostProbe.evaluate(
            true,
            ProbeSample(200, coreVersion = "4.0.0", dbConnected = false)
        )
        assertEquals(ProbeVerdict.DEGRADED, decision.verdict)
        assertTrue(decision.reachable)
        assertTrue(decision.allowRemoteConsole)
        assertEquals(HostProbe.KEY_DEGRADED, decision.messageKey)
    }

    @Test
    fun `an old core degrades`() {
        val decision = HostProbe.evaluate(true, ProbeSample(200, coreVersion = "3.9.9"))
        assertEquals(ProbeVerdict.DEGRADED, decision.verdict)
    }

    @Test
    fun `a 5.x core version is accepted`() {
        val decision = HostProbe.evaluate(true, ProbeSample(200, coreVersion = "5.8.1"))
        assertEquals(ProbeVerdict.ONLINE, decision.verdict)
    }

    @Test
    fun `200 with an unreadable body is a bad payload`() {
        val decision = HostProbe.evaluate(true, ProbeSample(200, payloadValid = false))
        assertEquals(ProbeVerdict.BAD_PAYLOAD, decision.verdict)
        assertTrue(decision.reachable)
        assertFalse(decision.allowRemoteConsole)
    }

    @Test
    fun `503 means up but degraded, so the console stays allowed`() {
        val decision = HostProbe.evaluate(true, ProbeSample(503, coreVersion = "4.0.0"))
        assertEquals(ProbeVerdict.DEGRADED, decision.verdict)
        assertTrue(decision.reachable)
        assertTrue(decision.allowRemoteConsole)
    }

    @Test
    fun `auth and rate limit are reachable but block the console`() {
        val unauthorized = HostProbe.evaluate(true, ProbeSample(401))
        assertEquals(ProbeVerdict.UNAUTHORIZED, unauthorized.verdict)
        assertTrue(unauthorized.reachable)
        assertFalse(unauthorized.allowRemoteConsole)

        val limited = HostProbe.evaluate(true, ProbeSample(429))
        assertEquals(ProbeVerdict.RATE_LIMITED, limited.verdict)
        assertFalse(limited.allowRemoteConsole)
    }

    @Test
    fun `404 means the core is not installed at that address`() {
        val decision = HostProbe.evaluate(true, ProbeSample(404))
        assertEquals(ProbeVerdict.NOT_INSTALLED, decision.verdict)
        assertEquals(HostProbe.KEY_NOT_INSTALLED, decision.messageKey)
    }

    @Test
    fun `500 is a server error`() {
        val decision = HostProbe.evaluate(true, ProbeSample(500))
        assertEquals(ProbeVerdict.SERVER_ERROR, decision.verdict)
        assertTrue(decision.reachable)
        assertFalse(decision.allowRemoteConsole)
    }

    @Test
    fun `version comparison tolerates prefixes and suffixes`() {
        assertTrue(HostProbe.compareVersions("4.0.0", "4.0") == 0)
        assertTrue(HostProbe.compareVersions("5.8.1", "5.8") > 0)
        assertTrue(HostProbe.compareVersions("v5.9", "5.8") > 0)
        assertTrue(HostProbe.compareVersions("4.0-beta", "4.0") == 0)
        assertTrue(HostProbe.compareVersions("3.9", "4.0") < 0)
        assertTrue(HostProbe.compareVersions("", "4.0") < 0)
        assertEquals(0, HostProbe.compareVersions("4.0", "4.0.0"))
        assertEquals(1, HostProbe.compareVersions("4.1", "4.0.9"))
        assertEquals(-1, HostProbe.compareVersions("4.0", "4.1"))
    }
}
