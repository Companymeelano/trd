package ir.meelano.hybrid.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import ir.meelano.hybrid.R
import ir.meelano.hybrid.core.HostProbe
import ir.meelano.hybrid.core.HybridRouter
import ir.meelano.hybrid.core.ProbeVerdict
import ir.meelano.hybrid.core.SurfaceKind
import ir.meelano.hybrid.ui.theme.Bad
import ir.meelano.hybrid.ui.theme.Gold
import ir.meelano.hybrid.ui.theme.IndigoLight
import ir.meelano.hybrid.ui.theme.Line
import ir.meelano.hybrid.ui.theme.Muted
import ir.meelano.hybrid.ui.theme.Ok
import ir.meelano.hybrid.ui.theme.Panel
import ir.meelano.hybrid.ui.theme.TextPrimary

/** Panel with the same glass treatment as the web console cards. */
@Composable
fun GlassCard(
    modifier: Modifier = Modifier,
    title: String? = null,
    content: @Composable ColumnScope.() -> Unit
) {
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(18.dp),
        color = Panel,
        border = androidx.compose.foundation.BorderStroke(1.dp, Line)
    ) {
        Column(modifier = Modifier.padding(16.dp)) {
            if (title != null) {
                Text(
                    text = title,
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.Bold,
                    color = Gold
                )
                Box(modifier = Modifier.padding(top = 10.dp))
            }
            content()
        }
    }
}

@Composable
fun StatusDot(color: Color) {
    Box(
        modifier = Modifier
            .size(8.dp)
            .clip(CircleShape)
            .background(color)
            .border(1.dp, color.copy(alpha = 0.4f), CircleShape)
    )
}

@Composable
fun StatusChip(label: String, color: Color) {
    Surface(
        shape = RoundedCornerShape(999.dp),
        color = Color.White.copy(alpha = 0.05f),
        border = androidx.compose.foundation.BorderStroke(1.dp, Line)
    ) {
        Row(
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(6.dp)
        ) {
            StatusDot(color)
            Text(
                text = label,
                style = MaterialTheme.typography.labelSmall,
                color = Muted
            )
        }
    }
}

/** Label on the inline-start side, value on the inline-end side. */
@Composable
fun InfoRow(label: String, value: String, valueColor: Color = TextPrimary) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(
            text = label,
            modifier = Modifier.weight(1f),
            style = MaterialTheme.typography.bodySmall,
            color = Muted
        )
        Text(
            text = value,
            modifier = Modifier.weight(1.2f),
            style = MaterialTheme.typography.bodySmall,
            fontWeight = FontWeight.SemiBold,
            color = valueColor,
            textAlign = TextAlign.End
        )
    }
}

@Composable
fun HintNote(text: String) {
    Text(
        text = text,
        modifier = Modifier.padding(top = 8.dp),
        style = MaterialTheme.typography.bodySmall,
        color = Muted
    )
}

/** Maps a `HostProbe.KEY_*` onto a localized string. */
fun probeMessage(key: String): Int = when (key) {
    HostProbe.KEY_ONLINE -> R.string.probe_online
    HostProbe.KEY_SLOW -> R.string.probe_slow
    HostProbe.KEY_DEGRADED -> R.string.probe_degraded
    HostProbe.KEY_NO_RESPONSE -> R.string.probe_no_response
    HostProbe.KEY_TIMEOUT -> R.string.probe_timeout
    HostProbe.KEY_DNS -> R.string.probe_dns
    HostProbe.KEY_TLS -> R.string.probe_tls
    HostProbe.KEY_REFUSED -> R.string.probe_refused
    HostProbe.KEY_IO -> R.string.probe_io
    HostProbe.KEY_NOT_INSTALLED -> R.string.probe_not_installed
    HostProbe.KEY_UNAUTHORIZED -> R.string.probe_unauthorized
    HostProbe.KEY_RATE_LIMITED -> R.string.probe_rate_limited
    HostProbe.KEY_SERVER -> R.string.probe_server
    HostProbe.KEY_BAD_PAYLOAD -> R.string.probe_bad_payload
    else -> R.string.probe_not_configured
}

/** Maps a `HybridRouter.REASON_*` onto a localized string. */
fun reasonMessage(key: String): Int = when (key) {
    HybridRouter.REASON_REMOTE_CONSOLE -> R.string.reason_remote_console
    HybridRouter.REASON_OFFLINE_ASSET -> R.string.reason_offline_asset
    HybridRouter.REASON_SETUP_REQUIRED -> R.string.reason_setup_required
    HybridRouter.REASON_HOST_UNREACHABLE -> R.string.reason_host_unreachable
    HybridRouter.REASON_AUTH_REQUIRED -> R.string.reason_auth_required
    HybridRouter.REASON_NO_NETWORK -> R.string.reason_no_network
    HybridRouter.REASON_USER_FORCED_NATIVE -> R.string.reason_user_forced_native
    HybridRouter.REASON_NATIVE_SETUP -> R.string.reason_native_setup
    else -> R.string.reason_native_status
}

fun surfaceLabel(kind: SurfaceKind): Int = when (kind) {
    SurfaceKind.WEB -> R.string.surface_web
    SurfaceKind.OFFLINE -> R.string.surface_offline
    SurfaceKind.NATIVE -> R.string.surface_native
}

fun surfaceColor(kind: SurfaceKind): Color = when (kind) {
    SurfaceKind.WEB -> IndigoLight
    SurfaceKind.OFFLINE -> Gold
    SurfaceKind.NATIVE -> Muted
}

fun probeColor(verdict: ProbeVerdict): Color = when (verdict) {
    ProbeVerdict.ONLINE -> Ok
    ProbeVerdict.DEGRADED -> Gold
    ProbeVerdict.NOT_CONFIGURED, ProbeVerdict.NO_RESPONSE -> Muted
    ProbeVerdict.UNAUTHORIZED, ProbeVerdict.RATE_LIMITED -> IndigoLight
    else -> Bad
}
