package ir.meelano.trading.data

import java.math.RoundingMode
import java.text.DecimalFormat

/** Number formatting shared by the UI and the Telegram signal text builder. */
object Fmt {

    const val DASH = "—"

    fun number(value: Double?, digits: Int = 3): String {
        if (value == null || !value.isFinite()) return DASH
        val format = DecimalFormat("#,##0.###")
        format.maximumFractionDigits = digits
        format.minimumFractionDigits = 0
        format.roundingMode = RoundingMode.HALF_UP
        return format.format(value)
    }

    fun percent(value: Double?, digits: Int = 1): String {
        if (value == null || !value.isFinite()) return DASH
        return "${number(value, digits)}%"
    }

    fun integer(value: Double?): String = if (value == null || !value.isFinite()) DASH else number(value, 0)

    fun integer(value: Int?): String = value?.toString() ?: DASH

    fun text(value: String?): String = value?.takeIf { it.isNotBlank() } ?: DASH
}
