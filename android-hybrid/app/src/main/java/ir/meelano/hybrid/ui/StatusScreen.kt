package ir.meelano.hybrid.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.meelano.hybrid.HybridViewModel
import ir.meelano.hybrid.R
import ir.meelano.hybrid.UiState
import ir.meelano.hybrid.core.HybridTab
import ir.meelano.hybrid.ui.components.GlassCard
import ir.meelano.hybrid.ui.components.HintNote
import ir.meelano.hybrid.ui.components.InfoRow
import ir.meelano.hybrid.ui.components.probeColor
import ir.meelano.hybrid.ui.components.probeMessage
import ir.meelano.hybrid.ui.components.reasonMessage
import ir.meelano.hybrid.ui.components.surfaceColor
import ir.meelano.hybrid.ui.components.surfaceLabel
import ir.meelano.hybrid.ui.theme.Muted
import ir.meelano.hybrid.ui.theme.TextPrimary

/**
 * Native status surface. It answers the only question that matters for a hybrid
 * app: where is the next screen coming from, and why?
 */
@Composable
fun StatusScreen(state: UiState, vm: HybridViewModel) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp)
    ) {
        Text(
            text = stringResource(R.string.status_title),
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Black,
            color = TextPrimary
        )
        Text(
            text = stringResource(R.string.status_subtitle),
            style = MaterialTheme.typography.bodySmall,
            color = Muted
        )

        GlassCard(title = stringResource(R.string.card_shell)) {
            InfoRow(
                label = stringResource(R.string.label_host),
                value = state.shell.hostRoot.ifEmpty { stringResource(R.string.state_unknown) }
            )
            InfoRow(
                label = stringResource(R.string.label_api),
                value = vm.settings.apiBase().ifEmpty { stringResource(R.string.state_unknown) }
            )
            InfoRow(
                label = stringResource(R.string.label_probe_state),
                value = stringResource(probeMessage(state.probe.messageKey)),
                valueColor = probeColor(state.probe.verdict)
            )
            InfoRow(
                label = stringResource(R.string.label_latency),
                value = if (state.probe.latencyMs > 0L) "${state.probe.latencyMs} ms" else "—"
            )
            InfoRow(
                label = stringResource(R.string.label_core_version),
                value = state.coreVersion.ifEmpty { "—" }
            )
            InfoRow(
                label = stringResource(R.string.label_db),
                value = stringResource(if (state.dbConnected) R.string.yes else R.string.no)
            )
            InfoRow(
                label = stringResource(R.string.label_surface),
                value = stringResource(surfaceLabel(state.surface.kind)),
                valueColor = surfaceColor(state.surface.kind)
            )
            HintNote(stringResource(reasonMessage(state.surface.reasonKey)))
        }

        GlassCard(title = stringResource(R.string.card_actions)) {
            Button(
                onClick = { vm.runProbe() },
                enabled = !state.probing,
                modifier = Modifier.fillMaxWidth().height(48.dp)
            ) {
                Text(stringResource(if (state.probing) R.string.action_probing else R.string.action_probe))
            }
            Spacer(modifier = Modifier.height(10.dp))
            OutlinedButton(
                onClick = { vm.selectTab(HybridTab.CONSOLE) },
                modifier = Modifier.fillMaxWidth().height(48.dp)
            ) {
                Text(stringResource(R.string.action_open_console))
            }
            Spacer(modifier = Modifier.height(10.dp))
            OutlinedButton(
                onClick = { vm.selectTab(HybridTab.SETUP) },
                modifier = Modifier.fillMaxWidth().height(48.dp)
            ) {
                Text(stringResource(R.string.action_open_setup))
            }
        }

        GlassCard(title = stringResource(R.string.card_hybrid)) {
            HintNote(stringResource(R.string.hybrid_note))
        }
    }
}
