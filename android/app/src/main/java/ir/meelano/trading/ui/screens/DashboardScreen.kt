package ir.meelano.trading.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import ir.meelano.trading.R
import ir.meelano.trading.data.Fmt
import ir.meelano.trading.data.Signal
import ir.meelano.trading.data.TradePlan
import ir.meelano.trading.ui.GATE_SPECS
import ir.meelano.trading.ui.components.BusyButton
import ir.meelano.trading.ui.components.BulletList
import ir.meelano.trading.ui.components.DecisionBadge
import ir.meelano.trading.ui.components.GlassCard
import ir.meelano.trading.ui.components.MetricRow
import ir.meelano.trading.ui.components.ScoreBar
import ir.meelano.trading.ui.components.ScoreRing
import ir.meelano.trading.ui.components.SectionCard
import ir.meelano.trading.ui.components.VerticalScrollColumn
import ir.meelano.trading.ui.components.decisionColor
import ir.meelano.trading.ui.theme.Cyan
import ir.meelano.trading.ui.theme.Muted
import ir.meelano.trading.ui.theme.Pink
import ir.meelano.trading.ui.theme.TextPrimary
import ir.meelano.trading.UiState
import ir.meelano.trading.ui.findGate

@Composable
fun DashboardScreen(state: UiState, onGoAnalysis: () -> Unit) {
    val signal = state.signal
    VerticalScrollColumn {
        if (signal == null) {
            GlassCard {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Text(
                        text = stringResource(R.string.empty_no_signal),
                        style = MaterialTheme.typography.bodySmall,
                        color = Muted
                    )
                    Spacer(modifier = Modifier.height(12.dp))
                    BusyButton(
                        label = stringResource(R.string.tab_analysis),
                        busy = false,
                        onClick = onGoAnalysis
                    )
                }
            }
        } else {
            DecisionCard(signal, state.signalAt)
            GatesCard(signal)
            TradePlanCard(signal.plan)
            if (signal.warnings.isNotEmpty()) {
                SectionCard(title = stringResource(R.string.card_warnings)) {
                    BulletList(signal.warnings)
                }
            }
            SectionCard(title = stringResource(R.string.card_data_quality)) {
                MetricRow(stringResource(R.string.label_provider), Fmt.text(signal.provider))
                MetricRow(
                    stringResource(R.string.label_stale),
                    stringResource(if (signal.stale) R.string.yes else R.string.no),
                    if (signal.stale) decisionColor("WATCH") else TextPrimary
                )
                MetricRow(stringResource(R.string.label_candles), Fmt.integer(signal.candles))
                MetricRow(stringResource(R.string.label_request_time), Fmt.text(state.signalAt))
            }
        }
    }
}

@Composable
private fun DecisionCard(signal: Signal, signalAt: String?) {
    SectionCard(title = stringResource(R.string.card_decision)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            ScoreRing(
                percent = signal.confidence,
                centerTop = Fmt.percent(signal.confidence, 1),
                centerBottom = stringResource(R.string.label_confidence),
                tint = Pink
            )
            Spacer(modifier = Modifier.width(14.dp))
            Column(modifier = Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    DecisionBadge(signal.decision)
                    Spacer(modifier = Modifier.width(8.dp))
                    Text(
                        text = Fmt.number(signal.finalScore, 2),
                        style = MaterialTheme.typography.titleMedium,
                        color = decisionColor(signal.decision)
                    )
                }
                Spacer(modifier = Modifier.height(6.dp))
                MetricRow(stringResource(R.string.label_symbol), Fmt.text(signal.symbol))
                MetricRow(stringResource(R.string.label_timeframe), Fmt.text(signal.timeframe))
                MetricRow(stringResource(R.string.label_price), Fmt.number(signal.price, 8))
                MetricRow(stringResource(R.string.label_btc_guard), Fmt.text(signal.btcStatus))
                MetricRow(
                    stringResource(R.string.label_order_book),
                    Fmt.number(signal.orderBookImbalance, 3)
                )
                MetricRow(stringResource(R.string.label_rejected_by), Fmt.text(signal.rejectedBy), Muted)
            }
        }
        Spacer(modifier = Modifier.height(8.dp))
        ScoreBar(signal.confidence)
    }
}

@Composable
private fun GatesCard(signal: Signal) {
    SectionCard(title = stringResource(R.string.card_gates)) {
        Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            GATE_SPECS.forEach { spec ->
                val gate = findGate(signal, spec.key)
                val decision = gate?.decision ?: "WAIT"
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(modifier = Modifier.weight(1f)) {
                        Text(
                            text = "${stringResource(spec.titleRes)} · ${stringResource(spec.weightRes)}",
                            style = MaterialTheme.typography.bodySmall,
                            color = Muted
                        )
                        Spacer(modifier = Modifier.height(4.dp))
                        ScoreBar(gate?.score ?: 0.0)
                    }
                    Spacer(modifier = Modifier.width(12.dp))
                    DecisionBadge(decision)
                    Spacer(modifier = Modifier.width(8.dp))
                    Text(
                        text = Fmt.number(gate?.score, 0),
                        style = MaterialTheme.typography.bodyMedium,
                        color = decisionColor(decision)
                    )
                }
            }
        }
    }
}

@Composable
private fun TradePlanCard(plan: TradePlan) {
    SectionCard(title = stringResource(R.string.card_risk_monitor)) {
        val rr = plan.riskReward ?: 0.0
        val riskHealth = if (rr > 0) (rr / 2.5) * 100 else 0.0
        Row(verticalAlignment = Alignment.CenterVertically) {
            ScoreRing(
                percent = riskHealth,
                centerTop = Fmt.percent(riskHealth, 0),
                centerBottom = stringResource(R.string.label_risk_health),
                tint = Pink
            )
            Spacer(modifier = Modifier.width(14.dp))
            Column(modifier = Modifier.weight(1f)) {
                MetricRow(
                    stringResource(R.string.label_entry_zone),
                    "${Fmt.number(plan.entryMin, 8)} — ${Fmt.number(plan.entryMax, 8)}"
                )
                MetricRow(stringResource(R.string.label_stop_loss), Fmt.number(plan.stopLoss, 8), decisionColor("REJECT"))
                MetricRow(stringResource(R.string.label_tp1), Fmt.number(plan.takeProfits.getOrNull(0)?.price, 8), decisionColor("ACCEPT"))
                MetricRow(stringResource(R.string.label_tp2), Fmt.number(plan.takeProfits.getOrNull(1)?.price, 8), decisionColor("ACCEPT"))
            }
        }
        Spacer(modifier = Modifier.height(6.dp))
        MetricRow(stringResource(R.string.label_rr), Fmt.number(plan.riskReward, 2), Cyan)
        MetricRow(stringResource(R.string.label_max_risk_percent), Fmt.percent(plan.maxRiskPercent, 2))
        MetricRow(stringResource(R.string.label_position_size), Fmt.number(plan.positionSize, 6))
        MetricRow(stringResource(R.string.label_position_usd), Fmt.number(plan.positionUsd, 2))
        MetricRow(stringResource(R.string.label_risk_amount), Fmt.number(plan.riskAmount, 2))
    }
}
