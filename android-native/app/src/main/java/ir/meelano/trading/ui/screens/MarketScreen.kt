package ir.meelano.trading.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.meelano.trading.R
import ir.meelano.trading.TraderViewModel
import ir.meelano.trading.data.Fmt
import ir.meelano.trading.data.Signal
import ir.meelano.trading.ui.components.BusyButton
import ir.meelano.trading.ui.components.DecisionBadge
import ir.meelano.trading.ui.components.GlassCard
import ir.meelano.trading.ui.components.MetricRow
import ir.meelano.trading.ui.components.ScoreBar
import ir.meelano.trading.ui.components.SectionCard
import ir.meelano.trading.ui.components.VerticalScrollColumn
import ir.meelano.trading.ui.components.decisionColor
import ir.meelano.trading.ui.theme.Line
import ir.meelano.trading.ui.theme.Muted
import ir.meelano.trading.ui.theme.Panel2
import ir.meelano.trading.ui.theme.Red
import ir.meelano.trading.UiState

@Composable
fun MarketScreen(state: UiState, vm: TraderViewModel) {
    VerticalScrollColumn {
        GlassCard {
            Text(
                text = stringResource(R.string.scan_note),
                style = MaterialTheme.typography.bodySmall,
                color = Muted
            )
            Spacer(modifier = Modifier.height(10.dp))
            BusyButton(
                label = stringResource(R.string.action_scan),
                busy = state.isBusy(TraderViewModel.BUSY_SCAN),
                onClick = vm::runScan,
                modifier = Modifier.fillMaxWidth()
            )
        }

        SectionCard(title = stringResource(R.string.label_btc_guard)) {
            MetricRow(
                stringResource(R.string.label_btc_status),
                Fmt.text(state.signal?.btcStatus),
                decisionColor(if (state.signal?.btcStatus == "CRITICAL") "REJECT" else "ACCEPT")
            )
            MetricRow(stringResource(R.string.label_btc_reason), Fmt.text(state.signal?.btcReason), Muted)
        }

        SectionCard(title = stringResource(R.string.card_ranking)) {
            if (state.scan.isEmpty()) {
                Text(
                    text = stringResource(R.string.empty_scan),
                    style = MaterialTheme.typography.bodySmall,
                    color = Muted
                )
            } else {
                Text(
                    text = stringResource(R.string.scan_tap_hint),
                    style = MaterialTheme.typography.labelSmall,
                    color = Muted
                )
                Spacer(modifier = Modifier.height(8.dp))
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    state.scan.forEach { signal ->
                        ScanRow(signal = signal, onClick = { vm.analyseFromScan(signal.symbol) })
                    }
                }
            }
        }
    }
}

@Composable
private fun ScanRow(signal: Signal, onClick: () -> Unit) {
    Surface(
        onClick = onClick,
        shape = RoundedCornerShape(14.dp),
        color = Panel2.copy(alpha = 0.72f),
        border = BorderStroke(1.dp, Line)
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    text = signal.symbol.ifBlank { Fmt.DASH },
                    style = MaterialTheme.typography.bodyMedium,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.weight(1f)
                )
                DecisionBadge(signal.decision)
            }
            Spacer(modifier = Modifier.height(6.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    text = Fmt.percent(signal.confidence, 1),
                    style = MaterialTheme.typography.titleMedium,
                    color = decisionColor(signal.decision),
                    modifier = Modifier.weight(1f)
                )
                Text(
                    text = "Score · ${Fmt.number(signal.finalScore, 1)}",
                    style = MaterialTheme.typography.labelSmall,
                    color = Muted
                )
            }
            Spacer(modifier = Modifier.height(6.dp))
            ScoreBar(signal.finalScore)
            if (!signal.error.isNullOrBlank()) {
                Spacer(modifier = Modifier.height(6.dp))
                Text(
                    text = signal.error,
                    style = MaterialTheme.typography.labelSmall,
                    color = Red
                )
            }
        }
    }
}
