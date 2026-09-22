package ir.meelano.hybrid.ui

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import ir.meelano.hybrid.HybridViewModel
import ir.meelano.hybrid.R
import ir.meelano.hybrid.UiState
import ir.meelano.hybrid.core.HybridConfig
import ir.meelano.hybrid.data.HybridSettings
import ir.meelano.hybrid.ui.components.GlassCard
import ir.meelano.hybrid.ui.components.HintNote
import ir.meelano.hybrid.ui.theme.Bad
import ir.meelano.hybrid.ui.theme.Muted
import ir.meelano.hybrid.ui.theme.TextPrimary

/**
 * Native setup surface. Deliberately native-only: a page served by the host
 * cannot be the place where that host is configured, because a broken host would
 * leave no way back in.
 */
@Composable
fun SetupScreen(state: UiState, vm: HybridViewModel) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp)
    ) {
        Text(
            text = stringResource(R.string.setup_title),
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Black,
            color = TextPrimary
        )
        Text(
            text = stringResource(R.string.setup_subtitle),
            style = MaterialTheme.typography.bodySmall,
            color = Muted
        )

        GlassCard(title = stringResource(R.string.section_connection)) {
            OutlinedTextField(
                value = state.hostInput,
                onValueChange = vm::onHostChange,
                label = { Text(stringResource(R.string.field_host)) },
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                modifier = Modifier.fillMaxWidth()
            )
            Spacer(modifier = Modifier.height(10.dp))
            OutlinedTextField(
                value = state.tokenInput,
                onValueChange = vm::onTokenChange,
                label = { Text(stringResource(R.string.field_token)) },
                singleLine = true,
                visualTransformation = PasswordVisualTransformation(),
                modifier = Modifier.fillMaxWidth()
            )
            HintNote(stringResource(R.string.warn_token_local))
            if (state.hostInput.isNotBlank() && !HybridConfig.isSecure(HybridConfig.normalizeHost(state.hostInput))) {
                HintNote(stringResource(R.string.warn_http))
            }
        }

        GlassCard(title = stringResource(R.string.section_analysis)) {
            OutlinedTextField(
                value = state.symbolInput,
                onValueChange = vm::onSymbolChange,
                label = { Text(stringResource(R.string.field_symbol)) },
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii),
                modifier = Modifier.fillMaxWidth()
            )
            Spacer(modifier = Modifier.height(10.dp))
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .horizontalScroll(rememberScrollState()),
                horizontalArrangement = Arrangement.spacedBy(6.dp)
            ) {
                HybridSettings.SYMBOL_PRESETS.forEach { preset ->
                    FilterChip(
                        selected = state.symbolInput.equals(preset, ignoreCase = true),
                        onClick = { vm.onSymbolChange(preset) },
                        label = { Text(preset) }
                    )
                }
            }
            Spacer(modifier = Modifier.height(12.dp))
            Text(
                text = stringResource(R.string.field_timeframe),
                style = MaterialTheme.typography.labelMedium,
                color = Muted
            )
            Spacer(modifier = Modifier.height(6.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                HybridSettings.TIMEFRAMES.forEach { timeframe ->
                    FilterChip(
                        selected = state.timeframeInput == timeframe,
                        onClick = { vm.onTimeframeChange(timeframe) },
                        label = { Text(timeframe) }
                    )
                }
            }
        }

        GlassCard(title = stringResource(R.string.section_surface)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text(
                    text = stringResource(R.string.switch_prefer_native),
                    modifier = Modifier.weight(1f),
                    style = MaterialTheme.typography.bodySmall,
                    color = TextPrimary
                )
                Switch(
                    checked = state.preferNativeInput,
                    onCheckedChange = vm::onPreferNativeChange
                )
            }
            HintNote(stringResource(R.string.prefer_native_note))
        }

        Button(
            onClick = { vm.saveSettings() },
            modifier = Modifier
                .fillMaxWidth()
                .height(50.dp)
        ) {
            Text(stringResource(R.string.action_save))
        }
        Spacer(modifier = Modifier.height(4.dp))
        Text(
            text = stringResource(R.string.footer_note),
            style = MaterialTheme.typography.labelSmall,
            color = Bad
        )
    }
}
