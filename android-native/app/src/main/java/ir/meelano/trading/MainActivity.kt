package ir.meelano.trading

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.BorderStroke
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
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.Star
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
import androidx.compose.runtime.getValue
import androidx.compose.runtime.collectAsState
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
import ir.meelano.trading.data.TriState
import ir.meelano.trading.ui.components.StatusDot
import ir.meelano.trading.ui.screens.AnalysisScreen
import ir.meelano.trading.ui.screens.DashboardScreen
import ir.meelano.trading.ui.screens.JournalScreen
import ir.meelano.trading.ui.screens.MarketScreen
import ir.meelano.trading.ui.screens.SettingsScreen
import ir.meelano.trading.ui.theme.Bg
import ir.meelano.trading.ui.theme.Line
import ir.meelano.trading.ui.theme.MeeLanoTheme
import ir.meelano.trading.ui.theme.Muted
import ir.meelano.trading.ui.theme.Panel
import ir.meelano.trading.ui.theme.Pink
import ir.meelano.trading.ui.theme.Purple
import ir.meelano.trading.ui.theme.TextPrimary

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        setContent {
            MeeLanoTheme {
                TraderApp()
            }
        }
    }
}

@Composable
fun TraderApp(viewModel: TraderViewModel = viewModel()) {
    val state by viewModel.state.collectAsState()
    val snackbarHostState = remember { SnackbarHostState() }
    val message = state.message
    val messageText = message?.let { current ->
        buildString {
            append(stringResource(current.textRes))
            current.detail?.takeIf { it.isNotBlank() }?.let { append('\n').append(it) }
        }
    }
    LaunchedEffect(message?.id) {
        if (messageText != null) {
            snackbarHostState.showSnackbar(messageText)
            viewModel.consumeMessage()
        }
    }

    Scaffold(
        containerColor = Bg,
        topBar = { AppHeader(state) },
        bottomBar = { AppBottomBar(state.tab) { viewModel.selectTab(it) } },
        snackbarHost = { SnackbarHost(snackbarHostState) }
    ) { padding ->
        Box(
            modifier = Modifier
                .padding(padding)
                .fillMaxSize()
        ) {
            when (state.tab) {
                TraderViewModel.TAB_DASHBOARD -> DashboardScreen(
                    state = state,
                    onGoAnalysis = { viewModel.selectTab(TraderViewModel.TAB_ANALYSIS) }
                )

                TraderViewModel.TAB_ANALYSIS -> AnalysisScreen(state = state, vm = viewModel)
                TraderViewModel.TAB_MARKET -> MarketScreen(state = state, vm = viewModel)
                TraderViewModel.TAB_JOURNAL -> JournalScreen(state = state, vm = viewModel)
                else -> SettingsScreen(state = state, vm = viewModel)
            }
        }
    }
}

@Composable
private fun AppHeader(state: UiState) {
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
                        .background(Brush.linearGradient(listOf(Pink, Purple))),
                    contentAlignment = Alignment.Center
                ) {
                    Text(
                        text = "ML",
                        color = Color.White,
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
                    stringResource(R.string.chip_core),
                    if (state.apiToken.isNotBlank()) TriState.OK else TriState.UNKNOWN
                )
                StatusChip(stringResource(R.string.chip_auth), state.authState)
                StatusChip(stringResource(R.string.chip_market_api), state.healthState)
                StatusChip(stringResource(R.string.chip_sqlite), state.dbState)
            }
        }
    }
}

@Composable
private fun StatusChip(label: String, state: TriState) {
    Surface(
        shape = RoundedCornerShape(999.dp),
        color = Color.White.copy(alpha = 0.05f),
        border = BorderStroke(1.dp, Line)
    ) {
        Row(
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(6.dp)
        ) {
            StatusDot(state)
            Text(
                text = label,
                style = MaterialTheme.typography.labelSmall,
                color = Muted
            )
        }
    }
}

@Composable
private fun AppBottomBar(current: Int, onSelect: (Int) -> Unit) {
    NavigationBar(containerColor = Panel) {
        NavigationBarItem(
            selected = current == TraderViewModel.TAB_DASHBOARD,
            onClick = { onSelect(TraderViewModel.TAB_DASHBOARD) },
            icon = { Icon(Icons.Filled.Home, contentDescription = null) },
            label = { Text(stringResource(R.string.tab_dashboard), maxLines = 1, overflow = TextOverflow.Ellipsis) }
        )
        NavigationBarItem(
            selected = current == TraderViewModel.TAB_ANALYSIS,
            onClick = { onSelect(TraderViewModel.TAB_ANALYSIS) },
            icon = { Icon(Icons.Filled.Star, contentDescription = null) },
            label = { Text(stringResource(R.string.tab_analysis), maxLines = 1, overflow = TextOverflow.Ellipsis) }
        )
        NavigationBarItem(
            selected = current == TraderViewModel.TAB_MARKET,
            onClick = { onSelect(TraderViewModel.TAB_MARKET) },
            icon = { Icon(Icons.Filled.Search, contentDescription = null) },
            label = { Text(stringResource(R.string.tab_market), maxLines = 1, overflow = TextOverflow.Ellipsis) }
        )
        NavigationBarItem(
            selected = current == TraderViewModel.TAB_JOURNAL,
            onClick = { onSelect(TraderViewModel.TAB_JOURNAL) },
            icon = { Icon(Icons.AutoMirrored.Filled.List, contentDescription = null) },
            label = { Text(stringResource(R.string.tab_journal), maxLines = 1, overflow = TextOverflow.Ellipsis) }
        )
        NavigationBarItem(
            selected = current == TraderViewModel.TAB_SETTINGS,
            onClick = { onSelect(TraderViewModel.TAB_SETTINGS) },
            icon = { Icon(Icons.Filled.Settings, contentDescription = null) },
            label = { Text(stringResource(R.string.tab_settings), maxLines = 1, overflow = TextOverflow.Ellipsis) }
        )
    }
}
