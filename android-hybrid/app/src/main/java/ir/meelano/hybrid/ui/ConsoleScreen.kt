package ir.meelano.hybrid.ui

import android.webkit.WebView
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import ir.meelano.hybrid.HybridViewModel
import ir.meelano.hybrid.R
import ir.meelano.hybrid.UiState
import ir.meelano.hybrid.core.BridgeContract
import ir.meelano.hybrid.core.HybridTab
import ir.meelano.hybrid.core.SurfaceKind
import ir.meelano.hybrid.ui.components.GlassCard
import ir.meelano.hybrid.ui.components.HintNote
import ir.meelano.hybrid.ui.components.reasonMessage
import ir.meelano.hybrid.ui.theme.Bad
import ir.meelano.hybrid.ui.theme.Muted
import ir.meelano.hybrid.web.ConsoleWeb
import ir.meelano.hybrid.web.Injector

/**
 * The web surface of the hybrid app.
 *
 * Loads either the remote PHP console (`<host>/index.php`) or, when the host is
 * unreachable, the bundled offline console. Both get the same treatment: the
 * bridge script is injected on `onPageFinished` and a `meelano-ready` event is
 * dispatched, so the page never has to know which of the two it is.
 */
@Composable
fun ConsoleScreen(state: UiState, vm: HybridViewModel) {
    val surface = state.surface

    if (surface.kind == SurfaceKind.NATIVE) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            GlassCard(title = stringResource(R.string.console_title)) {
                HintNote(stringResource(reasonMessage(surface.reasonKey)))
            }
            OutlinedButton(
                onClick = { vm.selectTab(HybridTab.SETUP) },
                modifier = Modifier.fillMaxWidth()
            ) {
                Text(stringResource(R.string.action_open_setup))
            }
        }
        return
    }

    val context = LocalContext.current
    val webView = remember { WebView(context) }
    val loader = remember { ConsoleWeb.assetLoader(context) }
    val bridgeSource = remember { ConsoleWeb.bridgeSource(context) }

    DisposableEffect(webView) {
        ConsoleWeb.configure(webView)
        webView.addJavascriptInterface(vm.bridge, BridgeContract.JS_NAMESPACE)
        webView.webViewClient = ConsoleWeb.client(
            loader = loader,
            hostRoot = { vm.settings.hostRoot },
            onBlocked = { url -> vm.onBlockedNavigation(url) },
            onLoaded = {
                webView.post {
                    webView.evaluateJavascript(bridgeSource, null)
                    webView.evaluateJavascript(Injector.readySnippet(bootstrapPayload(vm)), null)
                }
            }
        )
        vm.attachWebView(
            evaluate = { script -> webView.post { webView.evaluateJavascript(script, null) } },
            source = bridgeSource
        )
        onDispose {
            vm.detachWebView()
            webView.removeJavascriptInterface(BridgeContract.JS_NAMESPACE)
            webView.stopLoading()
            webView.destroy()
        }
    }

    LaunchedEffect(surface.url, state.consoleReloads) {
        if (surface.url.isNotEmpty()) webView.loadUrl(surface.url)
    }

    Column(modifier = Modifier.fillMaxSize()) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 16.dp, vertical = 8.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(
                text = stringResource(reasonMessage(surface.reasonKey)),
                modifier = Modifier.weight(1f),
                style = MaterialTheme.typography.labelSmall,
                color = if (surface.kind == SurfaceKind.OFFLINE) Bad else Muted
            )
            OutlinedButton(onClick = { vm.reloadConsole() }) {
                Text(stringResource(R.string.console_reload))
            }
        }
        AndroidView(
            factory = { webView },
            modifier = Modifier.fillMaxSize()
        )
    }
}

/** Same payload `MeelanoBridge.bootstrap()` returns, pushed instead of pulled. */
private fun bootstrapPayload(vm: HybridViewModel): String {
    val snapshot = vm.snapshot()
    return BridgeContract.bootstrap(
        appVersion = snapshot.appVersion,
        hostRoot = snapshot.hostRoot,
        mode = snapshot.mode,
        hasToken = snapshot.hasToken,
        symbol = snapshot.symbol,
        timeframe = snapshot.timeframe
    )
}
