package ir.meelano.hybrid.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.List
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import ir.meelano.hybrid.HybridViewModel
import ir.meelano.hybrid.R
import ir.meelano.hybrid.UiState
import ir.meelano.hybrid.core.HybridTab
import ir.meelano.hybrid.ui.components.StatusChip
import ir.meelano.hybrid.ui.components.probeColor
import ir.meelano.hybrid.ui.components.probeMessage
import ir.meelano.hybrid.ui.components.surfaceColor
import ir.meelano.hybrid.ui.components.surfaceLabel
import ir.meelano.hybrid.ui.theme.Bg
import ir.meelano.hybrid.ui.theme.Gold
import ir.meelano.hybrid.ui.theme.IndigoLight
import ir.meelano.hybrid.ui.theme.Muted
import ir.meelano.hybrid.ui.theme.Panel
import ir.meelano.hybrid.ui.theme.TextPrimary

/**
 * The shell: brand header, three tabs, one snackbar. Which surface renders
 * inside a tab is decided by `HybridRouter`, never here.
 */
@Composable
fun HybridApp(viewModel: HybridViewModel = viewModel()) {
    val state by viewModel.state.collectAsState()
    val snackbarHostState = remember { SnackbarHostState() }

    val messageRes = state.messageRes
    val detail = state.messageDetail
    val messageText = if (messageRes != 0) {
        val base = stringResource(messageRes)
        if (detail.isBlank()) base else base + "\n" + detail
    } else {
        null
    }

    LaunchedEffect(messageRes) {
        if (messageText != null) {
            snackbarHostState.showSnackbar(messageText)
            viewModel.consumeMessage()
        }
    }

    Scaffold(
        containerColor = Bg,
        topBar = { ShellHeader(state) },
        bottomBar = { ShellBottomBar(state.tab) { viewModel.selectTab(it) } },
        snackbarHost = { SnackbarHost(snackbarHostState) }
    ) { padding ->
        Box(
            modifier = Modifier
                .padding(padding)
                .fillMaxSize()
        ) {
            when (state.tab) {
                HybridTab.STATUS -> StatusScreen(state = state, vm = viewModel)
                HybridTab.CONSOLE -> ConsoleScreen(state = state, vm = viewModel)
                HybridTab.SETUP -> SetupScreen(state = state, vm = viewModel)
            }
        }
    }
}

@Composable
private fun ShellHeader(state: UiState) {
    Surface(
        modifier = Modifier.fillMaxWidth(),
        color = Bg.copy(alpha = 0.94f)
    ) {
        Column(modifier = Modifier.padding(horizontal = 16.dp, vertical = 10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(
                    modifier = Modifier
                        .size(40.dp)
                        .clip(RoundedCornerShape(12.dp))
                        .background(Brush.linearGradient(listOf(Gold, IndigoLight))),
                    contentAlignment = Alignment.Center
                ) {
                    Text(
                        text = "ML",
                        color = Color(0xFF231803),
                        fontWeight = FontWeight.Black,
                        style = MaterialTheme.typography.titleSmall
                    )
                }
                Spacer(modifier = Modifier.width(10.dp))
                Column(modifier = Modifier.weight(1f)) {
                    Text(
                        text = stringResource(R.string.brand_title),
                        style = MaterialTheme.typography.titleSmall,
                        color = TextPrimary,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis
                    )
                    Text(
                        text = stringResource(R.string.brand_subtitle),
                        style = MaterialTheme.typography.labelSmall,
                        color = Muted,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis
                    )
                }
            }
            Spacer(modifier = Modifier.height(8.dp))
            Row(
                modifier = Modifier.horizontalScroll(rememberScrollState()),
                horizontalArrangement = Arrangement.spacedBy(6.dp)
            ) {
                StatusChip(
                    label = stringResource(R.string.chip_host),
                    color = if (state.shell.hostConfigured) Gold else Muted
                )
                StatusChip(
                    label = stringResource(probeMessage(state.probe.messageKey)),
                    color = probeColor(state.probe.verdict)
                )
                StatusChip(
                    label = stringResource(surfaceLabel(state.surface.kind)),
                    color = surfaceColor(state.surface.kind)
                )
                StatusChip(
                    label = stringResource(R.string.chip_token),
                    color = if (state.shell.authenticated) IndigoLight else Muted
                )
            }
        }
    }
}

@Composable
private fun ShellBottomBar(current: HybridTab, onSelect: (HybridTab) -> Unit) {
    NavigationBar(containerColor = Panel) {
        NavigationBarItem(
            selected = current == HybridTab.STATUS,
            onClick = { onSelect(HybridTab.STATUS) },
            icon = { Icon(Icons.Filled.Home, contentDescription = null) },
            label = {
                Text(
                    text = stringResource(R.string.tab_status),
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )
            }
        )
        NavigationBarItem(
            selected = current == HybridTab.CONSOLE,
            onClick = { onSelect(HybridTab.CONSOLE) },
            icon = { Icon(Icons.AutoMirrored.Filled.List, contentDescription = null) },
            label = {
                Text(
                    text = stringResource(R.string.tab_console),
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )
            }
        )
        NavigationBarItem(
            selected = current == HybridTab.SETUP,
            onClick = { onSelect(HybridTab.SETUP) },
            icon = { Icon(Icons.Filled.Settings, contentDescription = null) },
            label = {
                Text(
                    text = stringResource(R.string.tab_setup),
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )
            }
        )
    }
}
