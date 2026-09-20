package ir.meelano.hybrid

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import ir.meelano.hybrid.ui.HybridApp
import ir.meelano.hybrid.ui.theme.HybridTheme

/**
 * Entry point of the unified hybrid app: one activity, one Compose shell, three
 * tabs — two native, one that hosts the PHP web console in a WebView.
 */
class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        setContent {
            HybridTheme {
                HybridApp()
            }
        }
    }
}
