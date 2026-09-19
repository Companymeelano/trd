package ir.meelano.trading.ui.components

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import ir.meelano.trading.data.TriState
import ir.meelano.trading.ui.theme.Amber
import ir.meelano.trading.ui.theme.Cyan
import ir.meelano.trading.ui.theme.Green
import ir.meelano.trading.ui.theme.Line
import ir.meelano.trading.ui.theme.Muted
import ir.meelano.trading.ui.theme.Panel
import ir.meelano.trading.ui.theme.Pink
import ir.meelano.trading.ui.theme.Purple
import ir.meelano.trading.ui.theme.Red
import ir.meelano.trading.ui.theme.TextPrimary

fun decisionColor(decision: String): Color = when (decision.uppercase()) {
    "ACCEPT" -> Green
    "REJECT" -> Red
    "WATCH" -> Amber
    else -> Cyan
}

fun triStateColor(state: TriState): Color = when (state) {
    TriState.OK -> Green
    TriState.BAD -> Red
    TriState.UNKNOWN -> Amber
}

@Composable
fun GlassCard(
    modifier: Modifier = Modifier,
    content: @Composable ColumnScope.() -> Unit
) {
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(18.dp),
        color = Panel.copy(alpha = 0.88f),
        border = androidx.compose.foundation.BorderStroke(1.dp, Line),
        content = {
            Column(modifier = Modifier.padding(16.dp), content = content)
        }
    )
}

@Composable
fun SectionCard(
    title: String,
    modifier: Modifier = Modifier,
    trailing: (@Composable RowScope.() -> Unit)? = null,
    content: @Composable ColumnScope.() -> Unit
) {
    GlassCard(modifier = modifier) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(
                text = title,
                style = MaterialTheme.typography.titleSmall,
                color = TextPrimary,
                modifier = Modifier.weight(1f)
            )
            trailing?.let { it() }
        }
        Spacer(modifier = Modifier.height(10.dp))
        content()
    }
}

@Composable
fun MetricRow(
    label: String,
    value: String,
    valueColor: Color = TextPrimary
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 4.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(
            text = label,
            style = MaterialTheme.typography.bodySmall,
            color = Muted,
            modifier = Modifier.weight(1f)
        )
        Spacer(modifier = Modifier.width(10.dp))
        Text(
            text = value,
            style = MaterialTheme.typography.bodySmall,
            color = valueColor,
            fontFamily = FontFamily.Monospace,
            textAlign = TextAlign.End,
            modifier = Modifier.weight(1.1f)
        )
    }
}

@Composable
fun StatusDot(state: TriState, modifier: Modifier = Modifier) {
    val color = triStateColor(state)
    Box(
        modifier = modifier
            .size(9.dp)
            .clip(CircleShape)
            .background(color)
            .border(1.dp, color.copy(alpha = 0.4f), CircleShape)
    )
}

@Composable
fun DecisionBadge(decision: String, modifier: Modifier = Modifier) {
    val color = decisionColor(decision)
    Surface(
        modifier = modifier,
        shape = RoundedCornerShape(999.dp),
        color = color.copy(alpha = 0.16f),
        border = androidx.compose.foundation.BorderStroke(1.dp, color.copy(alpha = 0.65f))
    ) {
        Text(
            text = decision.ifBlank { "WAIT" },
            style = MaterialTheme.typography.labelSmall,
            fontWeight = FontWeight.Bold,
            color = color,
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 5.dp)
        )
    }
}

@Composable
fun ScoreBar(value: Double, modifier: Modifier = Modifier) {
    val fraction = (value.coerceIn(0.0, 100.0) / 100.0).toFloat()
    Box(
        modifier = modifier
            .fillMaxWidth()
            .height(8.dp)
            .clip(RoundedCornerShape(99.dp))
            .background(Color.White.copy(alpha = 0.08f))
    ) {
        Box(
            modifier = Modifier
                .fillMaxWidth(fraction)
                .height(8.dp)
                .background(Brush.horizontalGradient(listOf(Purple, Pink, Cyan)))
        )
    }
}

@Composable
fun ScoreRing(
    percent: Double,
    centerTop: String,
    centerBottom: String,
    tint: Color,
    modifier: Modifier = Modifier,
    ringSize: Dp = 112.dp
) {
    val fraction = (percent.coerceIn(0.0, 100.0) / 100.0).toFloat()
    val strokeWidth = with(LocalDensity.current) { ringSize.toPx() } * 0.085f
    Box(modifier = modifier.size(ringSize), contentAlignment = Alignment.Center) {
        Canvas(modifier = Modifier.size(ringSize)) {
            drawArc(
                color = Color.White.copy(alpha = 0.08f),
                startAngle = 0f,
                sweepAngle = 360f,
                useCenter = false,
                style = Stroke(width = strokeWidth)
            )
            if (fraction > 0f) {
                drawArc(
                    color = tint,
                    startAngle = -90f,
                    sweepAngle = 360f * fraction,
                    useCenter = false,
                    style = Stroke(width = strokeWidth, cap = StrokeCap.Round)
                )
            }
        }
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Text(
                text = centerTop,
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.Bold,
                color = TextPrimary
            )
            Text(
                text = centerBottom,
                style = MaterialTheme.typography.labelSmall,
                color = Muted
            )
        }
    }
}

@Composable
fun ChoiceRow(
    options: List<String>,
    selected: String,
    onSelect: (String) -> Unit,
    modifier: Modifier = Modifier
) {
    Row(
        modifier = modifier.horizontalScroll(rememberScrollState()),
        horizontalArrangement = Arrangement.spacedBy(8.dp)
    ) {
        options.forEach { option ->
            val active = option == selected
            Surface(
                onClick = { onSelect(option) },
                shape = RoundedCornerShape(999.dp),
                color = if (active) Purple.copy(alpha = 0.35f) else Color.White.copy(alpha = 0.05f),
                border = androidx.compose.foundation.BorderStroke(
                    1.dp,
                    if (active) Pink.copy(alpha = 0.7f) else Line
                )
            ) {
                Text(
                    text = option,
                    style = MaterialTheme.typography.labelMedium,
                    color = if (active) TextPrimary else Muted,
                    modifier = Modifier.padding(horizontal = 14.dp, vertical = 8.dp)
                )
            }
        }
    }
}

@Composable
fun BusyButton(
    label: String,
    busy: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true
) {
    Button(
        onClick = onClick,
        enabled = enabled && !busy,
        modifier = modifier
    ) {
        if (busy) {
            CircularProgressIndicator(
                modifier = Modifier.size(18.dp),
                color = Color.White,
                strokeWidth = 2.dp
            )
        } else {
            Text(text = label)
        }
    }
}

@Composable
fun BulletList(items: List<String>) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        items.forEach { item ->
            Row(verticalAlignment = Alignment.Top) {
                Text(text = "• ", color = Amber, style = MaterialTheme.typography.bodySmall)
                Text(
                    text = item,
                    color = Muted,
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.weight(1f)
                )
            }
        }
    }
}

@Composable
fun VerticalScrollColumn(
    modifier: Modifier = Modifier,
    contentPadding: Dp = 16.dp,
    content: @Composable ColumnScope.() -> Unit
) {
    Column(
        modifier = modifier
            .verticalScroll(rememberScrollState())
            .padding(contentPadding),
        verticalArrangement = Arrangement.spacedBy(12.dp),
        content = content
    )
}
