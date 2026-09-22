package ir.meelano.hybrid.web

import android.annotation.SuppressLint
import android.content.Context
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.webkit.WebViewAssetLoader
import ir.meelano.hybrid.core.HybridConfig

/**
 * WebView construction shared by every console surface.
 *
 * Bundled assets are served through [WebViewAssetLoader] on the synthetic
 * https origin [HybridConfig.ASSET_DOMAIN] instead of `file://`, so the offline
 * console and the remote console behave the same way (same-origin storage, no
 * file-access permission, no mixed-content downgrade).
 */
object ConsoleWeb {

    /** Suffix appended to the WebView UA so the PHP core can tell us apart. */
    const val UA_SUFFIX = "MeelanoHybrid/6.0"

    private const val BACKGROUND_COLOR = 0xFF05070F.toInt()

    fun assetLoader(context: Context): WebViewAssetLoader = WebViewAssetLoader.Builder()
        .setDomain(HybridConfig.ASSET_DOMAIN)
        .addPathHandler("/assets/", WebViewAssetLoader.AssetsPathHandler(context))
        .build()

    @SuppressLint("SetJavaScriptEnabled")
    fun configure(webView: WebView) {
        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            cacheMode = WebSettings.LOAD_DEFAULT
            mixedContentMode = WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE
            userAgentString = "$userAgentString $UA_SUFFIX"
            useWideViewPort = true
            loadWithOverviewMode = true
            setSupportZoom(false)
            builtInZoomControls = false
            displayZoomControls = false
        }
        webView.setBackgroundColor(BACKGROUND_COLOR)
    }

    /** Source of the bundled bridge, injected after every page load. */
    fun bridgeSource(context: Context): String =
        context.assets.open(HybridConfig.BRIDGE_ASSET_PATH).bufferedReader().use { it.readText() }

    /**
     * Serves bundled assets and keeps navigation inside the shell: anything that
     * is neither the configured host nor a bundled asset is reported instead of
     * loaded, so a stray link cannot replace the console.
     *
     * [onLoaded] is where the caller injects `hybrid-bridge.js` and the
     * `meelano-ready` event, so the remote console and the bundled one are wired
     * up the exact same way.
     */
    fun client(
        loader: WebViewAssetLoader,
        hostRoot: () -> String,
        onBlocked: (String) -> Unit,
        onLoaded: (String) -> Unit
    ): WebViewClient = object : WebViewClient() {

        override fun shouldInterceptRequest(
            view: WebView,
            request: WebResourceRequest
        ): WebResourceResponse? = loader.shouldInterceptRequest(request.url)

        override fun shouldOverrideUrlLoading(
            view: WebView,
            request: WebResourceRequest
        ): Boolean {
            val url = request.url?.toString().orEmpty()
            if (url.isEmpty()) return false
            if (HybridConfig.isInternalTarget(hostRoot(), url)) return false
            onBlocked(url)
            return true
        }

        override fun onPageFinished(view: WebView, url: String) {
            onLoaded(url)
        }
    }
}
