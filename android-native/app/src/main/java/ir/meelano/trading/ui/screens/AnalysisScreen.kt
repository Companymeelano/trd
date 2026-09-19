package ir.meelano.trading.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import ir.meelano.trading.R
import ir.meelano.trading.TraderViewModel
import ir.meelano.trading.data.AppSettings
import ir.meelano.trading.data.Fmt
import ir.meelano.trading.data.GateResult
import ir.meelano.trading.data.Metrics
import ir.meelano.trading.ui.GateSpec
import ir.meelano.trading.ui.GATE_SPECS
import ir.meelano.trading.ui.components.BusyButton
import ir.meelano.trading.ui.components.BulletList
import ir.meelano.trading.ui.components.ChoiceRow
import ir.meelano.trading.ui.components.DecisionBadge
import ir.meelano.trading.ui.components.GlassCard
import ir.meelano.trading.ui.components.MetricRow
import ir.meelano.trading.ui.components.ScoreBar
import ir.meelano.trading.ui.components.SectionCard
import ir.meelano.trading.ui.components.VerticalScrollColumn
import ir.meelano.trading.ui.components.decisionColor
import ir.meelano.trading.ui.findGate
import ir.meelano.trading.ui.theme.Muted
import ir.meelano.trading.UiState

@Composable
fun AnalysisScreen(state: UiState, vm: TraderViewModel) {
    VerticalScrollColumn {
        GlassCard {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text(
                    text = stringResource(R.string.field_symbol),
                    style = MaterialTheme.typography.labelMedium,
                    color = Muted
                )
                OutlinedTextField(
                    value = state.symbol,
                    onValueChange = vm::setSymbol,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth()
                )
                ChoiceRow(
                    options = AppSettings.SYMBOL_PRESETS,
                    selected = state.symbol,
                    onSelect = vm::setSymbol
                )
                Text(
                    text = stringResource(R.string.field_timeframe),
                    style = MaterialTheme.typography.labelMedium,
                    color = Muted
                )
                ChoiceRow(
                    options = AppSettings.TIMEFRAMES,
                    selected = state.timeframe,
                    onSelect = vm::setTimeframe
                )
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    OutlinedTextField(
                        value = state.capital,
                        onValueChange = vm::setCapital,
                        label = { Text(stringResource(R.string.field_capital)) },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        modifier = Modifier.weight(1f)
                    )
                    OutlinedTextField(
                        value = state.riskPercent,
                        onValueChange = vm::setRiskPercent,
                        label = { Text(stringResource(R.string.field_risk)) },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        modifier = Modifier.weight(1f)
                    )
                }
                Text(
                    text = stringResource(R.string.field_news),
                    style = MaterialTheme.typography.labelMedium,
                    color = Muted
                )
                ChoiceRow(
                    options = AppSettings.NEWS_STATUSES,
                    selected = state.newsStatus,
                    onSelect = vm::setNewsStatus
                )
                Row(verticalAlignment = Alignment.CenterVertically) {
                    BusyButton(
                        label = stringResource(R.string.action_analyze),
                        busy = state.isBusy(TraderViewModel.BUSY_ANALYZE),
                        onClick = vm::analyze,
                        modifier = Modifier.weight(1f)
                    )
                    Spacer(modifier = Modifier.width(8.dp))
                    OutlinedButton(
                        onClick = { vm.checkAuth(showToast = true) },
                        enabled = !state.isBusy(TraderViewModel.BUSY_AUTH)
                    ) {
                        Text(stringResource(R.string.action_check_token))
                    }
                }
            }
        }

        val signal = state.signal
        if (signal != null) {
            GATE_SPECS.forEach { spec ->
                GateDetailCard(spec, findGate(signal, spec.key))
            }
        }
    }
}

@Composable
private fun GateDetailCard(spec: GateSpec, gate: GateResult?) {
    val decision = gate?.decision ?: "WAIT"
    SectionCard(
        title = "${stringResource(spec.titleRes)} · ${stringResource(spec.weightRes)}",
        trailing = { DecisionBadge(decision) }
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            ScoreBar(gate?.score ?: 0.0, modifier = Modifier.weight(1f))
            Spacer(modifier = Modifier.width(10.dp))
            Text(
                text = Fmt.number(gate?.score, 1),
                style = MaterialTheme.typography.bodyMedium,
                color = decisionColor(decision)
            )
        }
        Spacer(modifier = Modifier.height(10.dp))
        Text(
            text = stringResource(R.string.label_metrics),
            style = MaterialTheme.typography.labelMedium,
            color = Muted
        )
        Spacer(modifier = Modifier.height(4.dp))
        val rows = Metrics.flatten(gate?.metrics)
        if (rows.isEmpty()) {
            Text(
                text = Fmt.DASH,
                style = MaterialTheme.typography.bodySmall,
                color = Muted
            )
        } else {
            rows.forEach { (label, value) -> MetricRow(label, value) }
        }
        Spacer(modifier = Modifier.height(8.dp))
        Text(
            text = stringResource(R.string.label_reasons),
            style = MaterialTheme.typography.labelMedium,
            color = Muted
        )
        Spacer(modifier = Modifier.height(4.dp))
        val reasons = gate?.reasons.orEmpty()
        if (reasons.isEmpty()) {
            Text(
                text = stringResource(R.string.no_reasons),
                style = MaterialTheme.typography.bodySmall,
                color = Muted
            )
        } else {
            BulletList(reasons)
        }
    }
}
