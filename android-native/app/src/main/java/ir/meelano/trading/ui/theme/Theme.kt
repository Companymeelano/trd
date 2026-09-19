package ir.meelano.trading.ui.theme

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
    primary = Pink,
    onPrimary = Color.White,
    primaryContainer = Purple,
    onPrimaryContainer = Color.White,
    secondary = Cyan,
    onSecondary = Color.Black,
    secondaryContainer = Panel2,
    onSecondaryContainer = TextPrimary,
    tertiary = Green,
    onTertiary = Color.Black,
    background = Bg,
    onBackground = TextPrimary,
    surface = Panel,
    onSurface = TextPrimary,
    surfaceVariant = Panel2,
    onSurfaceVariant = Muted,
    outline = Line,
    outlineVariant = Line,
    error = Red,
    onError = Color.White,
    errorContainer = Red.copy(alpha = 0.2f),
    onErrorContainer = TextPrimary
)

/**
 * The product is Persian-first (the web console ships `dir="rtl"`), so the
 * layout direction follows the resolved locale: RTL for Persian devices and
 * for every non-English locale, LTR only when the English resources are used.
 */
@Composable
fun MeeLanoTheme(content: @Composable () -> Unit) {
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
