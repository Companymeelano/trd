package ir.meelano.trading.ui

import androidx.annotation.StringRes
import ir.meelano.trading.R
import ir.meelano.trading.data.GateResult
import ir.meelano.trading.data.Signal

data class GateSpec(
    val key: String,
    @StringRes val titleRes: Int,
    @StringRes val weightRes: Int
)

val GATE_SPECS = listOf(
    GateSpec("quantitative", R.string.gate_quantitative, R.string.weight_quantitative),
    GateSpec("technical", R.string.gate_technical, R.string.weight_technical),
    GateSpec("sentiment_macro", R.string.gate_sentiment, R.string.weight_sentiment),
    GateSpec("risk_management", R.string.gate_risk, R.string.weight_risk)
)

fun findGate(signal: Signal, key: String): GateResult? {
    val aliases = when (key) {
        "quantitative" -> listOf("quantitative", "q")
        "technical" -> listOf("technical", "t")
        "sentiment_macro" -> listOf("sentiment_macro", "sentiment", "macro", "s")
        else -> listOf("risk_management", "risk", "r")
    }
    return signal.gates.firstOrNull { gate -> aliases.any { it.equals(gate.gate, ignoreCase = true) } }
}
