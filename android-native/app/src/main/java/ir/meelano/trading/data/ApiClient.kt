package ir.meelano.trading.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.IOException
import java.net.SocketTimeoutException
import java.util.concurrent.TimeUnit

enum class ApiErrorKind { CONFIG, NETWORK, TIMEOUT, UNAUTHORIZED, RATE_LIMIT, SERVER, HTTP, BAD_RESPONSE }

class ApiException(
    val kind: ApiErrorKind,
    detail: String? = null,
    val httpCode: Int = 0
) : Exception(detail)

/**
 * Thin OkHttp client for the PHP core (api5). All endpoints live under
 * `<server>/api/*.php` and authenticate with the `X-API-Token` header.
 */
class ApiClient(private val settings: AppSettings) {

    private val client: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(35, TimeUnit.SECONDS)
        .writeTimeout(20, TimeUnit.SECONDS)
        .callTimeout(120, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    suspend fun health(): JSONObject = get("health.php", auth = false)

    suspend fun authCheck(): JSONObject = get("auth-check.php", auth = true)

    suspend fun history(limit: Int = 50): JSONObject = get("history.php?limit=$limit", auth = true)

    suspend fun scan(quote: String): JSONObject = get("scan.php?quote=$quote", auth = true, slow = true)

    suspend fun analyze(
        symbol: String,
        timeframe: String,
        capital: Double?,
        riskPercent: Double,
        newsStatus: String
    ): JSONObject = post(
        "analyze.php",
        JSONObject().apply {
            put("symbol", symbol)
            put("timeframe", timeframe)
            if (capital != null) put("capital", capital) else put("capital", JSONObject.NULL)
            put("risk_pct", riskPercent / 100.0)
            put("news_status", newsStatus)
        },
        auth = true,
        slow = true
    )

    suspend fun backtest(
        symbol: String,
        timeframe: String,
        capital: Double,
        riskPercent: Double,
        newsStatus: String
    ): JSONObject = post(
        "backtest.php",
        JSONObject().apply {
            put("symbol", symbol)
            put("timeframe", timeframe)
            put("capital", capital)
            put("risk_pct", riskPercent / 100.0)
            put("news_status", newsStatus)
        },
        auth = true,
        slow = true
    )

    suspend fun notify(channel: String, message: String): JSONObject = post(
        "notify.php",
        JSONObject().apply {
            put("channel", channel)
            put("message", message)
            put("tg_token", settings.telegramToken)
            put("tg_chat_id", settings.telegramChatId)
            put("webhook_url", settings.webhookUrl)
        },
        auth = true
    )

    private fun endpoint(path: String): String {
        val base = settings.apiBase()
        if (base.isEmpty()) throw ApiException(ApiErrorKind.CONFIG)
        return base + path
    }

    private suspend fun get(path: String, auth: Boolean, slow: Boolean = false): JSONObject =
        withContext(Dispatchers.IO) { execute(buildRequest(endpoint(path), null, auth), slow) }

    private suspend fun post(path: String, body: JSONObject, auth: Boolean, slow: Boolean = false): JSONObject =
        withContext(Dispatchers.IO) { execute(buildRequest(endpoint(path), body, auth), slow) }

    private fun buildRequest(url: String, body: JSONObject?, auth: Boolean): Request {
        val builder = Request.Builder()
            .url(url)
            .header("Accept", "application/json")
        if (auth) {
            val token = settings.apiToken
            if (token.isBlank()) throw ApiException(ApiErrorKind.CONFIG)
            builder.header("X-API-Token", token)
        }
        if (body != null) {
            builder.post(body.toString().toRequestBody(JSON_MEDIA_TYPE))
        } else {
            builder.get()
        }
        return builder.build()
    }

    private fun execute(request: Request, slow: Boolean): JSONObject {
        val http = if (slow) {
            client.newBuilder()
                .readTimeout(150, TimeUnit.SECONDS)
                .callTimeout(300, TimeUnit.SECONDS)
                .build()
        } else {
            client
        }
        val response = try {
            http.newCall(request).execute()
        } catch (e: SocketTimeoutException) {
            throw ApiException(ApiErrorKind.TIMEOUT, e.message)
        } catch (e: IOException) {
            throw ApiException(ApiErrorKind.NETWORK, e.message)
        }
        return response.use { res ->
            val text = try {
                res.body?.string().orEmpty()
            } catch (e: IOException) {
                throw ApiException(ApiErrorKind.NETWORK, e.message)
            }
            val parsed: JSONObject = if (text.isBlank()) {
                JSONObject()
            } else {
                try {
                    JSONObject(text)
                } catch (e: Exception) {
                    throw ApiException(ApiErrorKind.BAD_RESPONSE, text.take(180))
                }
            }
            if (!res.isSuccessful) {
                val serverMessage = parsed.strOrNull("message") ?: parsed.strOrNull("error")
                val kind = when (res.code) {
                    401 -> ApiErrorKind.UNAUTHORIZED
                    429 -> ApiErrorKind.RATE_LIMIT
                    in 500..599 -> ApiErrorKind.SERVER
                    else -> ApiErrorKind.HTTP
                }
                throw ApiException(kind, serverMessage ?: text.take(180), res.code)
            }
            parsed
        }
    }

    private companion object {
        val JSON_MEDIA_TYPE = "application/json; charset=utf-8".toMediaType()
    }
}
