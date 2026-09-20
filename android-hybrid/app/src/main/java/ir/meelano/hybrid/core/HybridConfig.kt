package ir.meelano.hybrid.core

/**
 * Pure address/asset helpers for the unified hybrid shell.
 *
 * The hybrid app serves one APK that contains both surfaces of the product:
 * the PHP web console (`index.php` on the customer's cPanel host) and the
 * native Kotlin shell. Everything that decides *where* a screen comes from is
 * kept here so it can be unit-tested on the JVM without Android.
 *
 * No Android imports on purpose.
 */
object HybridConfig {

    /** Web console entry point relative to the host root. */
    const val CONSOLE_PATH = "index.php"

    /** Health endpoint used by the probe (public, no auth). */
    const val HEALTH_PATH = "api/health.php"

    /** Authenticated endpoints live under `<host>/api/`. */
    const val API_PATH = "api/"

    /** Bundled fallback console shown when the host is unreachable. */
    const val OFFLINE_ASSET_PATH = "www/offline.html"

    /** Bundled bridge injected into every loaded page. */
    const val BRIDGE_ASSET_PATH = "www/hybrid-bridge.js"

    /**
     * Assets are served through `androidx.webkit.WebViewAssetLoader` on this
     * synthetic https origin, so the offline console and the remote console
     * share the same scheme and `localStorage`/cookie behaviour.
     */
    const val ASSET_DOMAIN = "appassets.androidplatform.net"

    /**
     * Accepts `https://host/trader`, `https://host/trader/`,
     * `https://host/trader/api`, `https://host/trader/api/` or
     * `https://host/trader/index.php` and normalizes all of them to the host
     * root with exactly one trailing slash (`https://host/trader/`).
     *
     * A bare `host/trader` gains an `https://` scheme because cleartext is the
     * exception, not the default.
     */
    fun normalizeHost(raw: String): String {
        var value = raw.trim()
        if (value.isEmpty()) return ""
        if (value.endsWith("/index.php", ignoreCase = true)) {
            value = value.dropLast("/index.php".length)
        }
        if (value.endsWith("/api/", ignoreCase = true)) {
            value = value.dropLast("/api/".length)
        }
        if (value.endsWith("/api", ignoreCase = true)) {
            value = value.dropLast("/api".length)
        }
        while (value.endsWith("/")) value = value.dropLast(1)
        val lowered = value.lowercase()
        if (!lowered.startsWith("http://") && !lowered.startsWith("https://")) {
            value = "https://$value"
        }
        return "$value/"
    }

    fun isConfigured(hostRoot: String): Boolean = hostRoot.isNotBlank()

    fun isSecure(hostRoot: String): Boolean = hostRoot.lowercase().startsWith("https://")

    fun consoleUrl(hostRoot: String): String =
        if (hostRoot.isEmpty()) "" else hostRoot + CONSOLE_PATH

    fun apiBase(hostRoot: String): String =
        if (hostRoot.isEmpty()) "" else hostRoot + API_PATH

    fun healthUrl(hostRoot: String): String =
        if (hostRoot.isEmpty()) "" else hostRoot + HEALTH_PATH

    /** Synthetic origin used for bundled assets. */
    fun offlineUrl(): String = assetUrl(OFFLINE_ASSET_PATH)

    fun bridgeUrl(): String = assetUrl(BRIDGE_ASSET_PATH)

    fun assetUrl(assetPath: String): String = "https://$ASSET_DOMAIN/assets/$assetPath"

    /**
     * `scheme://authority` in lowercase, or an empty string when the URL has no
     * usable origin. Used to decide whether a redirect inside the WebView is
     * still "our" console or an external page.
     */
    fun originOf(url: String): String {
        val trimmed = url.trim()
        val schemeEnd = trimmed.indexOf("://")
        if (schemeEnd <= 0) return ""
        val scheme = trimmed.substring(0, schemeEnd).lowercase()
        val rest = trimmed.substring(schemeEnd + 3)
        val authorityEnd = rest.indexOfFirst { it == '/' || it == '?' || it == '#' }
        val authority = if (authorityEnd < 0) rest else rest.substring(0, authorityEnd)
        if (authority.isBlank()) return ""
        return "$scheme://${authority.lowercase()}"
    }

    fun sameOrigin(hostRoot: String, pageUrl: String): Boolean {
        val host = originOf(hostRoot)
        return host.isNotEmpty() && host == originOf(pageUrl)
    }

    /** Hosts the WebView may open without leaving the shell. */
    fun isInternalTarget(hostRoot: String, pageUrl: String): Boolean {
        if (originOf(pageUrl) == "https://$ASSET_DOMAIN") return true
        return sameOrigin(hostRoot, pageUrl)
    }
}
