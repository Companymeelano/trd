package ir.meelano.hybrid.web

/** Outcome of one bridge call, as raw JSON text or an error code for the page. */
sealed class BridgeResult {
    data class Ok(val body: String) : BridgeResult()
    data class Err(val code: String) : BridgeResult()
}

/**
 * Server-backed half of the bridge. Implemented by [CoreApi] with OkHttp; the
 * interface exists so `MeelanoBridge` can be exercised without a network.
 */
interface BridgeApi {
    suspend fun call(action: String, paramsJson: String): BridgeResult
}
