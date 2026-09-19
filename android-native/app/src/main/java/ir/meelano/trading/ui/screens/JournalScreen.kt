package ir.meelano.trading.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.meelano.trading.R
import ir.meelano.trading.TraderViewModel
import ir.meelano.trading.data.Fmt
import ir.meelano.trading.data.HistoryItem
import ir.meelano.trading.ui.components.BusyButton
import ir.meelano.trading.ui.components.DecisionBadge
import ir.meelano.trading.ui.components.MetricRow
import ir.meelano.trading.ui.components.SectionCard
import ir.meelano.trading.ui.components.VerticalScrollColumn
import ir.meelano.trading.ui.components.decisionColor
import ir.meelano.trading.ui.theme.Muted
import ir.meelano.trading.UiState

@Composable
fun JournalScreen(state: UiState, vm: TraderViewModel) {
    VerticalScrollColumn {
        SectionCard(
            title = stringResource(R.string.section_backtest),
            trailing = {
                BusyButton(
                    label = stringResource(R.string.action_run_backtest),
                    busy = state.isBusy(TraderViewModel.BUSY_BACKTEST),
                    onClick = vm::runBacktest
                )
            }
        ) {
            Text(
                text = stringResource(R.string.backtest_note),
                style = MaterialTheme.typography.bodySmall,
                color = Muted
            )
            Spacer(modifier = Modifier.height(8.dp))
            MetricRow(stringResource(R.string.label_symbol), state.symbol)
            MetricRow(stringResource(R.string.label_timeframe), state.timeframe)
            Spacer(modifier = Modifier.height(6.dp))
            val backtest = state.backtest
            if (backtest == null) {
                Text(
                    text = stringResource(R.string.empty_backtest),
                    style = MaterialTheme.typography.bodySmall,
                    color = Muted
                )
            } else {
                MetricRow(
                    stringResource(R.string.label_win_rate),
                    Fmt.percent(backtest.winRate, 2),
                    decisionColor(if (backtest.winRate >= 50) "ACCEPT" else "WATCH")
                )
                MetricRow(stringResource(R.string.label_profit_factor), Fmt.number(backtest.profitFactor, 2))
                MetricRow(
                    stringResource(R.string.label_profit_pct),
                    Fmt.percent(backtest.profitPct, 2),
                    decisionColor(if (backtest.profitPct >= 0) "ACCEPT" else "REJECT")
                )
                MetricRow(stringResource(R.string.label_max_drawdown), Fmt.percent(backtest.maxDrawdown, 2), decisionColor("REJECT"))
                MetricRow(stringResource(R.string.label_total_trades), Fmt.integer(backtest.totalTrades))
                MetricRow(stringResource(R.string.label_final_equity), Fmt.number(backtest.finalEquity, 2))
                MetricRow(stringResource(R.string.label_engine), Fmt.text(backtest.engine))
            }
        }

        SectionCard(
            title = stringResource(R.string.section_history),
            trailing = {
                OutlinedButton(
                    onClick = { vm.loadHistory(showToast = true) },
                    enabled = !state.isBusy(TraderViewModel.BUSY_HISTORY)
                ) {
                    Text(stringResource(R.string.action_load_history))
                }
            }
        ) {
            if (state.history.isEmpty()) {
                Text(
                    text = stringResource(R.string.empty_history),
                    style = MaterialTheme.typography.bodySmall,
                    color = Muted
                )
            } else {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    state.history.forEach { item -> HistoryRow(item) }
                }
            }
        }
    }
}

@Composable
private fun HistoryRow(item: HistoryItem) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = "${item.symbol} · ${item.timeframe}",
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = FontWeight.SemiBold
            )
            Text(
                text = item.createdAt ?: Fmt.DASH,
                style = MaterialTheme.typography.labelSmall,
                color = Muted
            )
        }
        Spacer(modifier = Modifier.width(8.dp))
        DecisionBadge(item.decision.ifBlank { "WAIT" })
        Spacer(modifier = Modifier.width(10.dp))
        Text(
            text = Fmt.number(item.score, 1),
            style = MaterialTheme.typography.bodyMedium,
            color = decisionColor(item.decision),
            fontFamily = FontFamily.Monospace
        )
    }
}
