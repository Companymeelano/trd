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
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import ir.meelano.trading.BuildConfig
import ir.meelano.trading.R
import ir.meelano.trading.TraderViewModel
import ir.meelano.trading.data.Fmt
import ir.meelano.trading.data.TriState
import ir.meelano.trading.ui.components.BusyButton
import ir.meelano.trading.ui.components.MetricRow
import ir.meelano.trading.ui.components.SectionCard
import ir.meelano.trading.ui.components.StatusDot
import ir.meelano.trading.ui.components.VerticalScrollColumn
import ir.meelano.trading.ui.components.triStateColor
import ir.meelano.trading.ui.theme.Amber
import ir.meelano.trading.ui.theme.Muted
import ir.meelano.trading.UiState

@Composable
fun SettingsScreen(state: UiState, vm: TraderViewModel) {
    VerticalScrollColumn {
        SectionCard(title = stringResource(R.string.section_connection)) {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                OutlinedTextField(
                    value = state.baseUrl,
                    onValueChange = vm::setBaseUrl,
                    label = { Text(stringResource(R.string.field_base_url)) },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                    modifier = Modifier.fillMaxWidth()
                )
                OutlinedTextField(
                    value = state.apiToken,
                    onValueChange = vm::setApiToken,
                    label = { Text(stringResource(R.string.field_api_token)) },
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                    modifier = Modifier.fillMaxWidth()
                )
                Text(
                    text = stringResource(R.string.warn_token_local),
                    style = MaterialTheme.typography.labelSmall,
                    color = Muted
                )
                if (state.baseUrl.trim().lowercase().startsWith("http://")) {
                    Text(
                        text = stringResource(R.string.warn_http),
                        style = MaterialTheme.typography.labelSmall,
                        color = Amber
                    )
                }
                Row(verticalAlignment = Alignment.CenterVertically) {
                    BusyButton(
                        label = stringResource(R.string.action_save),
                        busy = false,
                        onClick = vm::saveSettings,
                        modifier = Modifier.weight(1f)
                    )
                    Spacer(modifier = Modifier.width(8.dp))
                    OutlinedButton(
                        onClick = { vm.checkHealth() },
                        enabled = !state.isBusy(TraderViewModel.BUSY_HEALTH)
                    ) {
                        Text(stringResource(R.string.action_health))
                    }
                    Spacer(modifier = Modifier.width(8.dp))
                    OutlinedButton(
                        onClick = { vm.checkAuth(showToast = true) },
                        enabled = !state.isBusy(TraderViewModel.BUSY_AUTH)
                    ) {
                        Text(stringResource(R.string.action_check_token))
                    }
                }
                StatusLine(stringResource(R.string.label_api_status), state.healthState)
                StatusLine(stringResource(R.string.label_db_status), state.dbState)
                StatusLine(stringResource(R.string.chip_auth), state.authState)
                MetricRow(stringResource(R.string.label_version), Fmt.text(state.health?.version))
                MetricRow(stringResource(R.string.label_php), Fmt.text(state.health?.php))
                MetricRow(stringResource(R.string.label_config), Fmt.text(state.health?.config))
                MetricRow(
                    stringResource(R.string.label_pdo_sqlite),
                    state.health?.pdoSqlite?.let { if (it) stringResource(R.string.yes) else stringResource(R.string.no) }
                        ?: Fmt.DASH
                )
                MetricRow(stringResource(R.string.label_server_time), Fmt.text(state.health?.time))
            }
        }

        SectionCard(title = stringResource(R.string.section_notifications)) {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                OutlinedTextField(
                    value = state.telegramToken,
                    onValueChange = vm::setTelegramToken,
                    label = { Text(stringResource(R.string.field_tg_token)) },
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                    modifier = Modifier.fillMaxWidth()
                )
                OutlinedTextField(
                    value = state.telegramChatId,
                    onValueChange = vm::setTelegramChatId,
                    label = { Text(stringResource(R.string.field_tg_chat)) },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth()
                )
                OutlinedTextField(
                    value = state.webhookUrl,
                    onValueChange = vm::setWebhookUrl,
                    label = { Text(stringResource(R.string.field_webhook)) },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                    modifier = Modifier.fillMaxWidth()
                )
                if (state.telegramToken.isBlank() || state.telegramChatId.isBlank()) {
                    if (state.webhookUrl.isBlank()) {
                        Text(
                            text = stringResource(R.string.warn_disabled_notify),
                            style = MaterialTheme.typography.labelSmall,
                            color = Amber
                        )
                    }
                }
                Row(verticalAlignment = Alignment.CenterVertically) {
                    BusyButton(
                        label = stringResource(R.string.action_test_notifications),
                        busy = state.isBusy(TraderViewModel.BUSY_NOTIFY),
                        onClick = vm::testNotifications,
                        modifier = Modifier.weight(1f)
                    )
                    Spacer(modifier = Modifier.width(8.dp))
                    BusyButton(
                        label = stringResource(R.string.action_send_signal),
                        busy = state.isBusy(TraderViewModel.BUSY_NOTIFY),
                        onClick = vm::sendCurrentSignal,
                        modifier = Modifier.weight(1f)
                    )
                }
            }
        }

        SectionCard(title = stringResource(R.string.section_about)) {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                BusyButton(
                    label = stringResource(R.string.action_demo),
                    busy = false,
                    onClick = vm::loadDemo,
                    modifier = Modifier.fillMaxWidth()
                )
                Text(
                    text = "Android ${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})",
                    style = MaterialTheme.typography.labelSmall,
                    color = Muted
                )
                Text(
                    text = stringResource(R.string.footer_note),
                    style = MaterialTheme.typography.labelSmall,
                    color = Muted
                )
            }
        }
    }
}

@Composable
private fun StatusLine(label: String, state: TriState) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(
            text = label,
            style = MaterialTheme.typography.bodySmall,
            color = Muted,
            modifier = Modifier.weight(1f)
        )
        StatusDot(state)
        Spacer(modifier = Modifier.width(6.dp))
        Text(
            text = when (state) {
                TriState.OK -> stringResource(R.string.state_ok)
                TriState.BAD -> stringResource(R.string.state_bad)
                TriState.UNKNOWN -> stringResource(R.string.state_unknown)
            },
            style = MaterialTheme.typography.bodySmall,
            color = triStateColor(state)
        )
    }
}
