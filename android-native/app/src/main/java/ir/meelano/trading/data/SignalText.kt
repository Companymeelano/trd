package ir.meelano.trading.data

/**
 * Builds the Telegram/HTML signal message with the same layout the web
 * console uses (signalText in index.html).
 */
object SignalText {

    fun build(signal: Signal): String {
        val plan = signal.plan
        val entry = if (plan.entryMin != null || plan.entryMax != null) {
            "${Fmt.number(plan.entryMin, 8)} — ${Fmt.number(plan.entryMax, 8)}"
        } else {
            Fmt.DASH
        }
        val targets = if (plan.takeProfits.isEmpty()) {
            "<li>TP: ${Fmt.DASH}</li>"
        } else {
            plan.takeProfits.joinToString("") { tp ->
                "<li>TP: ${esc(Fmt.number(tp.price, 8))}</li>"
            }
        }
        return buildString {
            append("<div dir=\"rtl\">")
            append("<strong>").append(esc(signal.symbol.ifBlank { Fmt.DASH })).append("</strong>")
            append(" · ").append(esc(signal.timeframe.ifBlank { Fmt.DASH })).append("<br>")
            append("Decision: <strong>").append(esc(signal.decision)).append("</strong><br>")
            append("Score: ").append(esc(Fmt.number(signal.finalScore, 2)))
            append(" · Confidence: ").append(esc(Fmt.percent(signal.confidence, 1))).append("<br>")
            append("RR: ").append(esc(Fmt.number(plan.riskReward, 2)))
            append(" · Position: ").append(esc(Fmt.number(plan.positionSize, 2))).append("<br>")
            append("Rejected By: ").append(esc(signal.rejectedBy ?: Fmt.DASH)).append("<br>")
            append("Entry: ").append(esc(entry)).append("<br>")
            append("SL: ").append(esc(Fmt.number(plan.stopLoss, 8))).append("<br>")
            append("<ul style=\"margin:8px 0 0;padding-right:18px\">").append(targets).append("</ul>")
            append("</div>")
        }
    }

    private fun esc(value: String): String = value
        .replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
}
