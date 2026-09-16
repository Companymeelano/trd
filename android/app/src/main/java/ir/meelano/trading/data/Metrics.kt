package ir.meelano.trading.data

import org.json.JSONObject

/**
 * Flattens a gate `metrics` object into display rows so the analysis screen
 * can render any metric the PHP engine adds in the future without code edits.
 */
object Metrics {

    private const val MAX_ROWS = 14

    fun flatten(metrics: JSONObject?): List<Pair<String, String>> {
        if (metrics == null) return emptyList()
        val rows = mutableListOf<Pair<String, String>>()
        collect(metrics, "", rows)
        return rows.take(MAX_ROWS)
    }

    private fun collect(obj: JSONObject, prefix: String, out: MutableList<Pair<String, String>>) {
        val keys = obj.keys()
        while (keys.hasNext()) {
            val key = keys.next()
            val value = obj.opt(key)
            val label = prefix + key
            when (value) {
                null, JSONObject.NULL -> out += label to Fmt.DASH
                is JSONObject -> collect(value, "$key.", out)
                is Boolean -> out += label to if (key == "above_cloud") {
                    if (value) "Above Cloud" else "Below Cloud"
                } else {
                    if (value) "true" else "false"
                }
                is Number -> out += label to numeric(label, value.toDouble())
                is String -> out += label to value.trim().ifEmpty { Fmt.DASH }
                else -> out += label to value.toString()
            }
        }
    }

    private fun numeric(label: String, value: Double): String = when {
        !value.isFinite() -> Fmt.DASH
        label.endsWith("pct") || label.contains("percent") -> Fmt.percent(value, 2)
        label == "price" || label.endsWith(".price") -> Fmt.number(value, 8)
        label == "histogram" -> Fmt.number(value, 5)
        else -> Fmt.number(value, 3)
    }
}
