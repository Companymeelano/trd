package ir.meelano.hybrid.ui.theme

import android.os.Build
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.unit.LayoutDirection

private val DarkScheme = darkColorScheme(
    primary = Gold,
    onPrimary = Color(0xFF231803),
    primaryContainer = GoldLight,
    onPrimaryContainer = Color(0xFF231803),
    secondary = Indigo,
    onSecondary = Color.White,
    secondaryContainer = Panel2,
    onSecondaryContainer = TextPrimary,
    tertiary = IndigoLight,
    onTertiary = Color.Black,
    background = Bg,
    onBackground = TextPrimary,
    surface = Panel,
    onSurface = TextPrimary,
    surfaceVariant = Panel2,
    onSurfaceVariant = Muted,
    outline = Line,
    outlineVariant = Line,
    error = Bad,
    onError = Color.White,
    errorContainer = Bad.copy(alpha = 0.2f),
    onErrorContainer = TextPrimary
)

/**
 * The product is Persian-first (both web surfaces ship `dir="rtl"`), so the
 * layout direction follows the resolved locale: RTL for Persian devices and for
 * every non-English locale, LTR only when the English resources are used.
 */
@Composable
fun HybridTheme(content: @Composable () -> Unit) {
    val language = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
        LocalConfiguration.current.locales[0].language
    } else {
        @Suppress("DEPRECATION")
        LocalConfiguration.current.locale.language
    }
    val direction = if (language == "en") LayoutDirection.Ltr else LayoutDirection.Rtl
    CompositionLocalProvider(LocalLayoutDirection provides direction) {
        MaterialTheme(
            colorScheme = DarkScheme,
            content = content
        )
    }
}
