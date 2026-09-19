package ir.meelano.trading.data

import org.json.JSONArray
import org.json.JSONObject

/* Small defensive accessors over org.json; the PHP core returns loosely typed
 * JSON (numbers may arrive as strings from SQLite) so every read is tolerant. */

internal fun JSONObject.objOrNull(key: String): JSONObject? =
    opt(key)?.let { it as? JSONObject }

internal fun JSONObject.strOrNull(key: String): String? =
    opt(key)?.let { if (it === JSONObject.NULL) null else it.toString() }
        ?.trim()?.takeIf { it.isNotEmpty() }

internal fun JSONObject.numOrNull(key: String): Double? = when (val value = opt(key)) {
    null -> null
    JSONObject.NULL -> null
    is Number -> value.toDouble().takeIf { it.isFinite() }
    is String -> value.trim().toDoubleOrNull()?.takeIf { it.isFinite() }
    else -> null
}

internal fun JSONObject.boolOrNull(key: String): Boolean? = when (val value = opt(key)) {
    null -> null
    JSONObject.NULL -> null
    is Boolean -> value
    is Number -> value.toInt() != 0
    is String -> when (value.trim().lowercase()) {
        "1", "true", "yes", "on" -> true
        "0", "false", "no", "off" -> false
        else -> null
    }
    else -> null
}

internal fun JSONObject.strListOrEmpty(key: String): List<String> = when (val value = opt(key)) {
    is JSONArray -> (0 until value.length()).mapNotNull { i ->
        value.opt(i)?.takeIf { it !== JSONObject.NULL }?.toString()?.trim()?.takeIf { it.isNotEmpty() }
    }
    is String -> listOf(value.trim()).filter { it.isNotEmpty() }
    else -> emptyList()
}

internal fun JSONArray.objects(): List<JSONObject> =
    (0 until length()).mapNotNull { i -> optJSONObject(i) }

internal fun JSONObject.numOrZero(key: String): Double = numOrNull(key) ?: 0.0
