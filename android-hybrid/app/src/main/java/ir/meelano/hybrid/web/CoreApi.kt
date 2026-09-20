package ir.meelano.hybrid.web

import ir.meelano.hybrid.core.BridgeContract
import ir.meelano.hybrid.data.HybridSettings
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.IOException
import java.net.URLEncoder
import java.util.concurrent.TimeUnit

/**
 * Forwards bridge actions to the PHP core under `<host>/api/`.
 *
 * This is the only place that touches the network for the web surface, so the
 * API token is added here and never crosses into JavaScript. The response body
 * is handed to the page verbatim: the console already knows how to read it.
 */
class CoreApi(private val settings: HybridSettings) : BridgeApi {

    private val client: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(45, TimeUnit.SECONDS)
        .writeTimeout(20, TimeUnit.SECONDS)
        .callTimeout(180, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    override suspend fun call(action: String, paramsJson: String): BridgeResult =
        withContext(Dispatchers.IO) {
            val endpoint = EndpointMap.forAction(action)
                ?: return@withContext BridgeResult.Err(BridgeContract.ERR_UNKNOWN_ACTION)

            val base = settings.apiBase()
            if (base.isEmpty()) return@withContext BridgeResult.Err(ERR_NO_HOST)
            if (endpoint.auth && !settings.hasToken()) return@withContext BridgeResult.Err(ERR_NO_TOKEN)

            val params = parseParams(paramsJson)
                ?: return@withContext BridgeResult.Err(BridgeContract.ERR_BAD_PARAMS)

            val request = build(endpoint, base + endpoint.path, params)
            try {
                client.newCall(request).execute().use { response ->
                    val text = try {
                        response.body?.string().orEmpty()
                    } catch (e: IOException) {
                        return@use BridgeResult.Err(BridgeContract.ERR_NATIVE)
                    }
                    if (response.isSuccessful) {
                        BridgeResult.Ok(text.ifBlank { "{}" })
                    } else {
                        BridgeResult.Err(httpErrorKey(response.code))
                    }
                }
            } catch (e: IOException) {
                BridgeResult.Err(BridgeContract.ERR_NATIVE)
            }
        }

    private fun parseParams(paramsJson: String): JSONObject? {
        val trimmed = paramsJson.trim()
        if (trimmed.isEmpty()) return JSONObject()
        return try {
            JSONObject(trimmed)
        } catch (e: Exception) {
            null
        }
    }

    private fun build(endpoint: Endpoint, url: String, params: JSONObject): Request {
        val target = if (endpoint.method == EndpointMap.METHOD_POST) url else withQuery(url, params)
        val builder = Request.Builder()
            .url(target)
            .header("Accept", "application/json")
            .header("X-Requested-With", REQUESTED_WITH)
        if (endpoint.auth) {
            builder.header("X-API-Token", settings.apiToken)
        }
        return if (endpoint.method == EndpointMap.METHOD_POST) {
            builder.post(params.toString().toRequestBody(JSON_MEDIA_TYPE)).build()
        } else {
            builder.get().build()
        }
    }

    /** GET parameters travel in the query string, URL-encoded. */
    private fun withQuery(url: String, params: JSONObject): String {
        if (params.length() == 0) return url
        val query = params.keys().asSequence().joinToString("&") { key ->
            "$key=${URLEncoder.encode(params.optString(key), "UTF-8")}"
        }
        val separator = if (url.contains("?")) "&" else "?"
        return url + separator + query
    }

    private fun httpErrorKey(code: Int): String = when (code) {
        401, 403 -> ERR_UNAUTHORIZED
        404 -> ERR_NOT_FOUND
        429 -> ERR_RATE_LIMIT
        in 500..599 -> ERR_SERVER
        else -> BridgeContract.ERR_NATIVE
    }

    companion object {
        /** Lets the PHP core tell "opened from the hybrid app" apart from a browser. */
        const val REQUESTED_WITH = "ir.meelano.hybrid"

        const val ERR_NO_HOST = "NO_HOST"
        const val ERR_NO_TOKEN = "NO_TOKEN"
        const val ERR_UNAUTHORIZED = "UNAUTHORIZED"
        const val ERR_NOT_FOUND = "NOT_FOUND"
        const val ERR_RATE_LIMIT = "RATE_LIMITED"
        const val ERR_SERVER = "SERVER_ERROR"

        private val JSON_MEDIA_TYPE = "application/json; charset=utf-8".toMediaType()
    }
}
