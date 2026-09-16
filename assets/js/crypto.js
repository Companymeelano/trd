/**
 * میلانو تریدینگ اینتلیجنس — منطق داشبورد سیگنال کریپتو (نسخهٔ ۵)
 * پالس بازار، اسپارک‌لاین، کارت سیگنال درجه‌دار با حلقهٔ اعتماد،
 * نوار هم‌راستایی MTF، رندر بک‌تست با منحنی سرمایه.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
(function (window, document) {
    'use strict';

    const M = window.Meelano;
    if (!M) { return; }

    const el = (id) => M.$('#' + id);
    const REGIME_LABELS = {
        trend_up: 'روند صعودی', trend_down: 'روند نزولی', range: 'رِنج/فشرده', volatile: 'نوسان بالا'
    };
    const MOOD_LABELS = { greedy: 'طمع', fearful: 'ترس', neutral: 'متعادل' };

    /* ── ابزارهای نمایش ─────────────────────────────────────────────── */

    function setBusy(button, busy, label) {
        if (!button) { return; }
        if (busy) {
            button.dataset.label = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-circle-notch btn__spin"></i> ' + (label || 'در حال پردازش…');
        } else {
            button.disabled = false;
            button.innerHTML = button.dataset.label || button.innerHTML;
        }
    }

    const price = (v) => {
        const n = Number(v) || 0;
        if (n === 0) { return '—'; }
        if (n >= 1000) { return M.money(n); }
        if (n >= 1) { return n.toFixed(3); }
        return n.toFixed(6);
    };

    const compact = (v) => {
        const n = Number(v) || 0;
        if (n >= 1e9) { return M.toFa((n / 1e9).toFixed(1)) + 'B'; }
        if (n >= 1e6) { return M.toFa((n / 1e6).toFixed(1)) + 'M'; }
        if (n >= 1e3) { return M.toFa((n / 1e3).toFixed(0)) + 'K'; }
        return M.toFa(n);
    };

    /** حلقهٔ اعتماد SVG (0..100). */
    function confidenceRing(score) {
        const pct = Math.max(0, Math.min(100, Math.round(Number(score) || 0)));
        const r = 26;
        const c = 2 * Math.PI * r;
        const filled = (pct / 100) * c;
        const color = pct >= 80 ? '#34d399' : (pct >= 68 ? '#fbbf24' : '#fb7185');
        return '<svg class="conf-ring" viewBox="0 0 64 64" role="img" aria-label="اعتماد ' + pct + '٪">' +
            '<circle cx="32" cy="32" r="' + r + '" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="6"/>' +
            '<circle cx="32" cy="32" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="6" ' +
            'stroke-linecap="round" stroke-dasharray="' + filled.toFixed(1) + ' ' + c.toFixed(1) + '" ' +
            'transform="rotate(-90 32 32)"/>' +
            '<text x="32" y="30" text-anchor="middle" fill="#fff" font-size="14" font-weight="700">' + M.toFa(pct) + '</text>' +
            '<text x="32" y="43" text-anchor="middle" fill="rgba(255,255,255,.55)" font-size="8">اعتماد</text></svg>';
    }

    /** نوار هم‌راستایی چند تایم‌فریم. */
    function mtfBars(mtf) {
        if (!mtf || !mtf.ok || !Array.isArray(mtf.tf)) { return '<span class="dim">—</span>'; }
        return '<div class="mtf-bars">' + mtf.tf.map(function (row) {
            if (!row.ok) { return '<span class="mtf-bar mtf-bar--na" title="' + M.escapeHtml(row.tf + ': بدون داده') + '">' + M.escapeHtml(row.tf) + '</span>'; }
            const cls = row.agree ? 'mtf-bar--buy' : (row.side === 'SELL' ? 'mtf-bar--sell' : 'mtf-bar--na');
            const title = row.tf + ': ' + row.side + ' · امتیاز ' + Math.round(row.score) + ' · ' + (row.regime_label || '');
            return '<span class="mtf-bar ' + cls + '" title="' + M.escapeHtml(title) + '">' + M.escapeHtml(row.tf) + '</span>';
        }).join('') + '<span class="mtf-align">' + M.toFa(Math.round((mtf.alignment_ratio || 0) * 100)) + '٪ هم‌راستا</span></div>';
    }

    /** بوم اسپارک‌لاین. */
    function sparkline(values, width, height, stroke) {
        if (!Array.isArray(values) || values.length < 2) { return ''; }
        const w = width || 120, h = height || 34;
        const min = Math.min.apply(null, values);
        const max = Math.max.apply(null, values);
        const range = (max - min) || 1;
        const step = w / (values.length - 1);
        const pts = values.map(function (v, i) {
            const x = (i * step).toFixed(1);
            const y = (h - 3 - ((v - min) / range) * (h - 6)).toFixed(1);
            return x + ',' + y;
        });
        const up = values[values.length - 1] >= values[0];
        const color = stroke || (up ? '#34d399' : '#fb7185');
        const fillId = 'sg' + Math.random().toString(36).slice(2, 8);
        return '<svg class="spark" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none">' +
            '<defs><linearGradient id="' + fillId + '" x1="0" y1="0" x2="0" y2="1">' +
            '<stop offset="0" stop-color="' + color + '" stop-opacity=".35"/><stop offset="1" stop-color="' + color + '" stop-opacity="0"/>' +
            '</linearGradient></defs>' +
            '<polygon points="0,' + h + ' ' + pts.join(' ') + ' ' + w + ',' + h + '" fill="url(#' + fillId + ')"/>' +
            '<polyline points="' + pts.join(' ') + '" fill="none" stroke="' + color + '" stroke-width="1.6" stroke-linejoin="round"/>' +
            '</svg>';
    }

    function kv(label, value, color) {
        return '<div class="stat"><div class="stat__label">' + M.escapeHtml(label) + '</div>' +
            '<div class="stat__value" style="font-size:15px;' + (color ? 'color:' + color : '') + '">' + M.escapeHtml(String(value)) + '</div></div>';
    }

    function tierBadge(tier) {
        const cls = String(tier || 'C').replace('+', 'ap');
        return '<span class="tier-badge tier-' + M.escapeHtml(cls) + '">درجه ' + M.escapeHtml(String(tier || 'C')) + '</span>';
    }

    function filterRows(filters) {
        return (filters || []).map(function (f) {
            const icon = f.pass ? 'fa-circle-check' : 'fa-circle-xmark';
            const cls = f.pass ? 'ok' : 'bad';
            const weight = f.weight && f.weight !== 1 ? ' · وزن ' + M.toFa(f.weight) : '';
            return '<li class="' + cls + '"><i class="fa-solid ' + icon + '"></i> ' +
                M.escapeHtml(f.label) + ' <span class="dim">— ' + M.escapeHtml(f.detail) + weight + '</span></li>';
        }).join('');
    }

    function aiRows(ai) {
        if (!ai || !ai.ok) { return '<li class="dim">اجماع AI در دسترس نبود (کلیدها را در تنظیمات فعال کنید) — سیگنال صرفاً تکنیکالی صادر شده است.</li>'; }
        let out = (ai.opinions || []).map(function (o) {
            const icon = o.signal === 'BUY' ? 'fa-arrow-trend-up' : (o.signal === 'SELL' ? 'fa-arrow-trend-down' : 'fa-minus');
            return '<li class="' + (o.signal === 'NEUTRAL' ? 'dim' : 'ok') + '"><i class="fa-solid ' + icon + '"></i> ' +
                '<b>' + M.escapeHtml(o.provider_label || o.provider) + '</b>: ' + M.escapeHtml(o.signal) +
                ' (' + M.toFa(Math.round(o.confidence)) + '٪)' +
                (o.reasoning ? ' <span class="dim">— ' + M.escapeHtml(String(o.reasoning).slice(0, 160)) + '</span>' : '') + '</li>';
        }).join('');
        out += '<li class="' + (ai.agreement ? 'ok' : 'bad') + '"><i class="fa-solid ' + (ai.agreement ? 'fa-handshake' : 'fa-triangle-exclamation') + '"></i> ' +
            (ai.agreement ? 'اجماع AI با سمت تکنیکال هم‌راستاست' : 'هشدار: اجماع AI با تکنیکال هم‌راستا نیست') +
            ' · امتیاز AI: ' + M.toFa(ai.ai_score) + '</li>';
        if (ai.red_team && ai.red_team.ok) {
            const rt = ai.red_team;
            const rtIcon = rt.verdict === 'VALID' ? 'fa-shield-halved' : (rt.verdict === 'WEAK' ? 'fa-triangle-exclamation' : 'fa-skull-crossbones');
            const rtCls = rt.verdict === 'VALID' ? 'ok' : (rt.verdict === 'WEAK' ? 'warn' : 'bad');
            out += '<li class="' + rtCls + '"><i class="fa-solid ' + rtIcon + '"></i> <b>وکیل مدافع:</b> ' +
                M.escapeHtml(rt.verdict) + ' (' + M.toFa(Math.round(rt.confidence)) + '٪)' +
                ((rt.flaws || []).length ? ' — حفره‌ها: ' + rt.flaws.map(function (f) { return M.escapeHtml(String(f).slice(0, 90)); }).join(' · ') : '') + '</li>';
        }
        if (ai.invalidation) {
            out += '<li class="dim"><i class="fa-solid fa-ban"></i> شرط ابطال: ' + M.escapeHtml(ai.invalidation) + '</li>';
        }
        return out;
    }

    /* ── کارت سیگنال ═════════════════════════════════════════════════ */

    function signalCard(s) {
        const isBuy = s.side === 'BUY';
        const sideCls = isBuy ? 'badge--ok' : 'badge--bad';
        const r = s.risk || {};
        const ind = s.indicators || {};
        let html = '<div class="card-3d section signal-card" style="border-color:' + (isBuy ? 'rgba(52,211,153,.4)' : 'rgba(251,113,133,.4)') + '">';

        html += '<div class="signal-card__head">' +
            '<div class="signal-card__title">' +
            '<h3 class="section__title" style="font-size:17px"><span class="mono" style="color:var(--gold-1)">' + M.escapeHtml(s.symbol) + '</span>' +
            ' <span class="badge ' + sideCls + '">' + (isBuy ? 'خرید BUY' : 'فروش SELL') + '</span> ' + tierBadge(s.tier) + '</h3>' +
            '<div class="signal-card__meta mono">' +
            'قیمت: ' + price(s.price) + ' · ۲۴س: ' + M.toFa(Math.round(s.change24 * 10) / 10) + '٪ · حجم: ' + compact(s.quote_volume) +
            ' · <span class="regime-chip regime-' + M.escapeHtml(s.regime || 'range') + '">' + M.escapeHtml(s.regime_label || REGIME_LABELS[s.regime] || s.regime) + '</span>' +
            '</div></div>' +
            confidenceRing(s.confidence || s.combined_score) +
            '</div>';
        if (s.calibrated && s.calibrated.calibrated) {
            const cs = s.calibrated.stats;
            html += '<div class="calib-chip"><i class="fa-solid fa-history"></i> اعتماد کالیبره با گذشته: <b>' + M.toFa(Math.round(s.calibrated.confidence)) + '</b>' +
                ' (از ' + M.toFa(cs.n) + ' سیگنال مشابه: TP1 ' + M.toFa(cs.tp1_rate) + '٪ · میانگین ' + M.toFa(cs.avg_r) + 'R)</div>';
        }

        // اعداد عملیاتی
        html += '<div class="grid grid--6" style="margin-top:14px">';
        html += kv('ورود', price(r.entry), 'var(--gold-1)');
        html += kv('استاپ (' + (r.stop_type === 'structure' ? 'ساختاری' : 'ATR') + ')', price(r.stop_loss), 'var(--rose)');
        html += kv('TP1', price(r.take_profit_1), 'var(--emerald)');
        html += kv('TP2', price(r.take_profit_2), 'var(--emerald)');
        html += kv('TP3', price(r.take_profit_3), 'var(--emerald)');
        html += kv('R:R تارگت۲', M.toFa(r.risk_reward_2), 'var(--indigo-1)');
        html += '</div>';
        html += '<div class="grid grid--6" style="margin-top:8px">';
        html += kv('سایز پوزیشن', M.toFa(r.position_percent) + '٪', 'var(--indigo-1)');
        html += kv('فاصلهٔ استاپ', M.toFa(r.stop_distance_pct) + '٪');
        html += kv('اهرم پیشنهادی', M.toFa(r.leverage_suggested || 1) + '×');
        html += kv('امتیاز تکنیکال', M.toFa(Math.round(s.tech_score)));
        html += kv('امتیاز AI', M.toFa(Math.round((s.ai && s.ai.ai_score) || 0)));
        html += kv('هم‌گرایی', M.toFa(s.confluence || 0) + '/' + M.toFa(s.confluence_total || 0));
        html += '</div>';

        // تأیید چند تایم‌فریمی + اندیکاتورهای کلیدی
        html += '<div class="grid grid--2" style="margin-top:12px">';
        html += '<div><p class="field-label">تأیید چند تایم‌فریمی (MTF)</p>' + mtfBars(s.mtf) + '</div>';
        html += '<div><p class="field-label">اندیکاتورهای کلیدی</p><div class="ind-row mono">' +
            'RSI ' + M.toFa(ind.rsi ?? '—') +
            ' · ADX ' + M.toFa(ind.adx ?? '—') +
            ' · ATR٪ ' + M.toFa(ind.atr_pct ?? '—') +
            ' · حجم ' + M.toFa(ind.vol_ratio ?? '—') + '×' +
            ' · VWAP ' + (ind.supertrend_dir === 'up' ? '<i class="fa-solid fa-caret-up" style="color:var(--emerald)"></i>' : '<i class="fa-solid fa-caret-down" style="color:var(--rose)"></i>') +
            '</div><div class="rr-track" title="نسبت ریسک به بازده"><div class="rr-track__risk"></div><div class="rr-track__reward" style="flex:' + Math.max(0.5, (r.risk_reward_2 || 2)) + '"></div></div></div>';
        html += '</div>';

        // جزئیات فیلترها + AI
        html += '<div class="grid grid--2" style="margin-top:14px">';
        html += '<div><p class="field-label">فیلترهای هم‌گرایی (' + M.toFa(s.passed) + ' از ' + M.toFa(s.total) + ')</p><ul class="modal__log" style="max-height:220px">' + filterRows(s.filters) + '</ul></div>';
        html += '<div><p class="field-label">اجماع هوش مصنوعی</p><ul class="modal__log" style="max-height:220px">' + aiRows(s.ai) + '</ul></div>';
        html += '</div>';

        // یادداشت اجرایی
        html += '<div class="exec-note"><i class="fa-solid fa-clipboard-check"></i> ' +
            M.escapeHtml(r.breakeven_note || '') + ' ' + M.escapeHtml(r.invalidation || '') + '</div>';

        html += '</div>';
        return html;
    }

    function rejectCard(s) {
        let extra = '';
        if (s.mtf && s.mtf.ok) {
            extra += '<div style="margin-top:10px"><p class="field-label">تأیید چند تایم‌فریمی</p>' + mtfBars(s.mtf) + '</div>';
        }
        return '<div class="card-3d section" style="border-color:rgba(251,191,36,.3)">' +
            '<div class="section__head" style="border:none;margin:0;padding:0"><h3 class="section__title">' +
            '<span class="mono" style="color:var(--gold-1)">' + M.escapeHtml(s.symbol) + '</span> ' +
            '<span class="badge badge--gold">سیگنال صادر نشد</span> ' +
            (s.regime_label ? '<span class="regime-chip regime-' + M.escapeHtml(s.regime || 'range') + '">' + M.escapeHtml(s.regime_label) + '</span>' : '') +
            '</h3>' +
            '<span class="mono dim">امتیاز تکنیکال: ' + M.toFa(Math.round(s.tech_score || 0)) + ' · فیلترها: ' + M.toFa(s.passed || 0) + '/' + M.toFa(s.total || 0) + '</span></div>' +
            '<p class="help" style="margin-top:10px"><b>دلیل:</b> ' + M.escapeHtml(s.reason || 'شرایط ورود برقرار نیست.') + '</p>' +
            extra +
            '<ul class="modal__log" style="max-height:200px;margin-top:10px">' + filterRows(s.filters) + '</ul>' +
            '</div>';
    }

    function renderSignals(list) {
        const box = el('live_signals');
        const none = el('no_signal');
        if (!list || !list.length) {
            box.innerHTML = '';
            if (none) { none.style.display = ''; }
            return;
        }
        if (none) { none.style.display = 'none'; }
        box.innerHTML = list.map(signalCard).join('');
    }

    /* ── پالس بازار ═══════════════════════════════════════════════════ */

    async function loadPulse() {
        try {
            const data = await M.api('api/market.php?limit=60', null, { method: 'GET' });
            if (!data.ok) { return; }

            // بیت‌کوین
            const btc = data.btc;
            if (btc) {
                el('btc_price').textContent = price(btc.price);
                const mood = btc.strongly === 'bearish' ? '🔴 نزولی قوی' : (btc.strongly === 'bullish' ? '🟢 صعودی قوی' : '⚪ خنثی');
                el('btc_regime').innerHTML = mood + ' · ' + M.escapeHtml(btc.label || '') +
                    ' · تغییر ۲۴س: ' + M.toFa(Math.round((btc.change24 || 0) * 10) / 10) + '٪';
            }

            // ترس و طمع (لایهٔ سنتیمنت)
            const fg = data.fear_greed;
            if (fg && typeof fg.value === 'number') {
                const fgEl = el('fg_value'); const fgFill = el('fg_fill'); const fgLabel = el('fg_label');
                if (fgEl) { fgEl.textContent = M.toFa(fg.value) + '/۱۰۰'; }
                if (fgFill) {
                    fgFill.style.width = Math.max(2, Math.min(100, fg.value)) + '%';
                    fgFill.style.background = fg.value <= 25 ? '#34d399' : (fg.value >= 75 ? '#fb7185' : '#fbbf24');
                }
                if (fgLabel) {
                    const fa = fg.value <= 25 ? 'ترس شدید — فرصت خلاف‌گردش' : (fg.value >= 75 ? 'طمع شدید — احتیاط' : 'محدودهٔ نرمال');
                    fgLabel.textContent = fa;
                }
            }

            // عرض بازار
            const b = data.breadth || {};
            el('breadth_value').textContent = M.toFa(b.up || 0) + ' ↑ / ' + M.toFa(b.down || 0) + ' ↓';
            const fill = el('breadth_fill');
            if (fill) { fill.style.width = Math.round((b.ratio || 0) * 100) + '%'; }
            el('market_mood').textContent = MOOD_LABELS[b.mood] || '—';
            el('market_mood_sub').textContent = 'نسبت صعودی‌ها: ' + M.toFa(Math.round((b.ratio || 0) * 100)) + '٪';

            // برترین صعودی
            const g = (data.gainers || [])[0];
            if (g) {
                el('top_gainer').innerHTML = '<span class="mono">' + M.escapeHtml(g.base) + '</span> ' +
                    '<span style="color:var(--emerald);font-size:13px">+' + M.toFa(Math.round(g.change_pct * 10) / 10) + '٪</span>';
                el('top_gainer_sub').textContent = 'قیمت: ' + price(g.price) + ' · حجم: ' + compact(g.quote_volume);
            }

            // نوار متحرک
            const inner = el('ticker_inner');
            if (inner && Array.isArray(data.top) && data.top.length) {
                const items = data.top.map(function (t) {
                    const up = t.change_pct >= 0;
                    return '<span class="tick"><b class="mono">' + M.escapeHtml(t.base) + '</b> ' + price(t.price) +
                        ' <span class="' + (up ? 'up' : 'down') + '">' + (up ? '▲' : '▼') + ' ' + M.toFa(Math.abs(Math.round(t.change_pct * 10) / 10)) + '٪</span>' +
                        sparkline(t.spark, 64, 20) + '</span>';
                }).join('');
                inner.innerHTML = items + items; // برای اسکرول بی‌درز
            }
            const stamp = el('pulse_updated');
            if (stamp) {
                stamp.textContent = 'به‌روزرسانی: ' + new Date().toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                stamp.className = 'badge badge--ok';
            }
        } catch (err) {
            const stamp = el('pulse_updated');
            if (stamp) { stamp.textContent = 'خطا در دریافت پالس'; stamp.className = 'badge badge--bad'; }
        }
    }

    /* ── اسکن بازار ═══════════════════════════════════════════════════ */

    async function scanMarket() {
        const btn = el('btn_scan');
        setBusy(btn, true, 'در حال رصد بازار…');
        M.modal.open('رصد کل بازار کریپتو', 'دریافت دادهٔ زندهٔ بازار و اجرای قیف ۹ مرحله‌ای…');
        const stages = [
            { at: 8, text: 'دریافت فهرست ارزهای پرحجم + فاندینگ و ترس و طمع…' },
            { at: 22, text: 'محاسبهٔ رژیم بازار و ۲۵+ اندیکاتور روی کندل بسته…' },
            { at: 42, text: 'اجرای ۲۵ فیلتر هم‌گرایی در ۵ لایهٔ اطلاعاتی…' },
            { at: 58, text: 'تأیید چند تایم‌فریمی و اوپن اینترست…' },
            { at: 74, text: 'اجماع چندمدلی AI + وکیل مدافع (Red-Team)…' },
            { at: 88, text: 'پلن ریسک ساختاری، درجه‌بندی و کالیبراسیون تاریخی…' },
            { at: 96, text: 'دروازهٔ همبستگی پرتفوی و خنک‌کردن تکرار…' },
        ];
        let i = 0;
        const tick = setInterval(() => {
            if (i < stages.length) { const s = stages[i++]; M.modal.set(s.at, s.text); }
        }, 1400);
        try {
            const data = await M.api('api/scan.php', {});
            clearInterval(tick);
            M.modal.set(100, 'اسکن ' + M.toFa(data.scanned) + ' ارز کامل شد');
            const supp = data.suppressed ? ' · ' + M.toFa(data.suppressed) + ' خنک‌شده' : '';
            const pf = data.portfolio;
            const pfTxt = pf ? ' · پرتفوی: ' + M.toFa(pf.longs) + 'L/' + M.toFa(pf.shorts) + 'S (' + M.toFa(pf.total_position_pct) + '٪)' : '';
            el('scan_meta').textContent = M.toFa(data.scanned) + ' ارز · ' + M.toFa(data.signal_count) + ' سیگنال' + supp + pfTxt + ' · ' + M.toFa(Math.round(data.duration_ms)) + 'ms';
            renderSignals(data.signals || []);
            if (!data.signals || !data.signals.length) {
                M.toast('هیچ ارزی از قیف سخت‌گیرانه عبور نکرد — این یعنی فیلترها درست کار می‌کنند.', 'info', 6000);
            } else {
                M.toast(M.toFa(data.signal_count) + ' سیگنال صادر شد' + (data.ai_used ? ' (تأییدشده با اجماع AI)' : ''), 'ok', 5500);
            }
            setTimeout(() => M.modal.close(), 600);
            loadPulse();
            M.refreshSystemStatus();
        } catch (err) {
            clearInterval(tick);
            M.modal.close(0);
            M.toast(err.message, 'bad', 7000);
        } finally {
            setBusy(btn, false);
        }
    }

    async function analyzeSingle() {
        const sym = (el('single_symbol').value || '').trim().toUpperCase();
        if (!sym) {
            M.toast('نماد را وارد کنید (مثل BTCUSDT).', 'warn');
            return;
        }
        const btn = el('btn_single');
        setBusy(btn, true, 'در حال تحلیل…');
        M.modal.open('تحلیل ' + sym, 'اجرای قیف کامل: فیلترها، MTF و اجماع AI…');
        try {
            const data = await M.api('api/scan.php', { symbol: sym });
            const s = data.signal;
            if (s && s.is_signal && !s.suppressed) {
                renderSignals([s]);
                el('no_signal').style.display = 'none';
                M.toast(sym + ' سیگنال ' + s.side + ' درجهٔ ' + s.tier + ' با اعتماد ' + M.toFa(Math.round(s.combined_score)) + ' دارد', 'ok', 6000);
            } else if (s && s.is_signal && s.suppressed) {
                el('live_signals').innerHTML = rejectCard(Object.assign({}, s, { reason: s.suppressed_reason || 'تکرار اخیر' }));
                el('no_signal').style.display = 'none';
                M.toast(sym + ' سیگنال دارد اما در بازهٔ خنک‌کردن است: ' + (s.suppressed_reason || ''), 'warn', 7000);
            } else {
                el('live_signals').innerHTML = rejectCard(s || { symbol: sym });
                el('no_signal').style.display = 'none';
                M.toast(sym + ' از قیف سخت‌گیرانه عبور نکرد (امتیاز ' + M.toFa(Math.round((s && s.tech_score) || 0)) + ')', 'warn', 6000);
            }
            M.modal.set(100, 'تحلیل کامل شد');
            setTimeout(() => M.modal.close(), 500);
        } catch (err) {
            M.modal.close(0);
            M.toast(err.message, 'bad', 7000);
        } finally {
            setBusy(btn, false);
        }
    }

    /* ── بک‌تست ═══════════════════════════════════════════════════════ */

    async function runBacktest() {
        const sym = (el('bt_symbol').value || '').trim().toUpperCase();
        if (!sym) { M.toast('نماد بک‌تست را وارد کنید.', 'warn'); return; }
        const btn = el('btn_backtest');
        setBusy(btn, true, 'در حال شبیه‌سازی…');
        try {
            const mode = (el('bt_mode') && el('bt_mode').value) || 'standard';
            const data = await M.api('api/backtest.php', {
                symbol: sym,
                interval: el('bt_tf').value,
                bars: Number(el('bt_bars').value) || 500,
                mode: mode,
            });
            const box = el('backtest_result');
            if (!data.ok) {
                box.innerHTML = '<p class="help" style="color:var(--rose)"><i class="fa-solid fa-triangle-exclamation"></i> ' + M.escapeHtml(data.error || 'بک‌تست ناموفق بود.') + '</p>';
                return;
            }

            // ── حالت واک‌فوروارد: پایداری در پنجره‌های زمانی ──
            if (mode === 'walkforward') {
                const verdict = { robust: ['مستحکم', 'ok'], mixed: ['متوسط', 'warn'], fragile: ['شکننده', 'bad'] }[data.verdict] || ['نامشخص', ''];
                let w = '<div class="grid grid--4" style="margin-bottom:12px">';
                w += kv('پنجره‌های سودده', M.toFa(data.positive_folds) + ' از ' + M.toFa((data.folds || []).length), data.stability >= 0.75 ? 'var(--emerald)' : 'var(--rose)');
                w += kv('پایداری', M.toFa(Math.round(data.stability * 100)) + '٪', data.stability >= 0.75 ? 'var(--emerald)' : 'var(--rose)');
                w += kv('حکم', verdict[0], data.verdict === 'robust' ? 'var(--emerald)' : 'var(--rose)');
                w += kv('کندل‌ها', M.toFa(data.bars));
                w += '</div>';
                w += '<div class="table-wrap"><table class="data"><thead><tr><th>پنجره</th><th>از</th><th>تا</th><th>معامله</th><th>وین‌ریت</th><th>امید R</th><th>PF</th><th>حداکثر افت</th></tr></thead><tbody>';
                (data.folds || []).forEach(function (f) {
                    w += '<tr><td>' + M.toFa(f.fold) + '</td><td class="mono" style="font-size:11px">' + M.escapeHtml(f.from || '') + '</td>' +
                        '<td class="mono" style="font-size:11px">' + M.escapeHtml(f.to || '') + '</td>' +
                        '<td class="mono">' + M.toFa(f.trades || 0) + '</td>' +
                        '<td class="mono">' + M.toFa(f.winrate || 0) + '٪</td>' +
                        '<td class="mono" style="color:' + (f.expectancy_r > 0 ? 'var(--emerald)' : 'var(--rose)') + '">' + M.toFa(f.expectancy_r || 0) + 'R</td>' +
                        '<td class="mono">' + M.toFa(f.profit_factor || 0) + '</td>' +
                        '<td class="mono">' + M.toFa(f.max_drawdown_r || 0) + 'R</td></tr>';
                });
                w += '</tbody></table></div>';
                w += '<p class="help" style="margin-top:10px"><i class="fa-solid fa-circle-info"></i> استراتژی فقط وقتی «مستحکم» است که در ≥۷۵٪ پنجره‌های زمانی سودده باشد؛ شکستن یک پنجرهٔ خوش‌شانس، حکم «شکننده» می‌دهد.</p>';
                box.innerHTML = w;
                M.toast('واک‌فوروارد ' + sym + ': ' + verdict[0] + ' (' + M.toFa(Math.round(data.stability * 100)) + '٪ پنجره‌های سودده)', data.verdict === 'robust' ? 'ok' : 'warn', 6500);
                return;
            }

            const good = data.expectancy_r > 0;
            let html = '<div class="grid grid--6" style="margin-bottom:12px">';
            html += kv('معامله‌ها', M.toFa(data.trades));
            html += kv('وین‌ریت', M.toFa(data.winrate) + '٪');
            html += kv('امید ریاضی', M.toFa(data.expectancy_r) + 'R', good ? 'var(--emerald)' : 'var(--rose)');
            html += kv('پروفایت فاکتور', M.toFa(data.profit_factor), data.profit_factor >= 1.5 ? 'var(--emerald)' : 'var(--rose)');
            html += kv('حداکثر افت', M.toFa(data.max_drawdown_r) + 'R', 'var(--rose)');
            html += kv('بازه', M.escapeHtml(data.from || '') + ' → ' + M.escapeHtml(data.to || ''));
            html += '</div>';

            // تفکیک رژیمی — استراتژی در کدام رژیم سود می‌دهد؟
            if (data.by_regime && Object.keys(data.by_regime).length) {
                html += '<div class="table-wrap" style="margin-bottom:12px"><table class="data"><thead><tr><th>رژیم</th><th>معامله</th><th>وین‌ریت</th><th>امید R</th></tr></thead><tbody>';
                Object.keys(data.by_regime).forEach(function (r) {
                    const row = data.by_regime[r];
                    html += '<tr><td><span class="regime-chip regime-' + M.escapeHtml(r) + '">' + M.escapeHtml(REGIME_LABELS[r] || r) + '</span></td>' +
                        '<td class="mono">' + M.toFa(row.trades) + '</td>' +
                        '<td class="mono">' + M.toFa(row.winrate) + '٪</td>' +
                        '<td class="mono" style="color:' + (row.expectancy_r > 0 ? 'var(--emerald)' : 'var(--rose)') + '">' + M.toFa(row.expectancy_r) + 'R</td></tr>';
                });
                html += '</tbody></table></div>';
            }

            // مونت‌کارلو — توزیع واقعی ریسک
            if (data.monte_carlo && data.monte_carlo.ok) {
                const mc = data.monte_carlo;
                html += '<div class="card-3d" style="padding:12px;border-color:rgba(99,102,241,.3);margin-bottom:12px">';
                html += '<p class="field-label" style="margin:0 0 8px"><i class="fa-solid fa-dice"></i> مونت‌کارلو — ' + M.toFa(mc.runs) + ' بازچینی تصادفی ترتیب معاملات</p>';
                html += '<div class="grid grid--6">';
                html += kv('R نهایی (میانه)', M.toFa(mc.final_r_p50) + 'R', mc.final_r_p50 > 0 ? 'var(--emerald)' : 'var(--rose)');
                html += kv('R بدشانس‌ترین ۵٪', M.toFa(mc.final_r_p5) + 'R', mc.final_r_p5 > 0 ? 'var(--emerald)' : 'var(--rose)');
                html += kv('افت میانه', M.toFa(mc.max_dd_p50) + 'R');
                html += kv('افت بدشانس‌ترین ۵٪', M.toFa(mc.max_dd_p95) + 'R', 'var(--rose)');
                html += kv('احتمال ضرر', M.toFa(Math.round(mc.loss_prob * 100)) + '٪', mc.loss_prob < 0.3 ? 'var(--emerald)' : 'var(--rose)');
                html += kv('معامله پایه', M.toFa(mc.trades));
                html += '</div></div>';
            }

            html += '<div class="bt-equity"><p class="field-label">منحنی سرمایه (R تجمعی)</p>' + sparkline(data.equity || [], 600, 70) + '</div>';
            if (Array.isArray(data.trades_list) && data.trades_list.length) {
                html += '<div class="table-wrap" style="margin-top:12px;max-height:260px;overflow:auto"><table class="data"><thead><tr>' +
                    '<th>زمان ورود</th><th>جهت</th><th>رژیم</th><th>ورود</th><th>خروج</th><th>نتیجه</th><th>کندل</th><th>R</th></tr></thead><tbody>';
                data.trades_list.slice().reverse().forEach(function (t) {
                    const out = t.outcome === 'tp2' ? '<span class="badge badge--ok">TP2</span>' : (t.outcome === 'stop' ? '<span class="badge badge--bad">استاپ</span>' : '<span class="badge">تایم‌اوت</span>');
                    html += '<tr><td class="mono" style="font-size:11px">' + M.escapeHtml(t.entry_time) + '</td>' +
                        '<td><span class="badge ' + (t.side === 'BUY' ? 'badge--ok' : 'badge--bad') + '">' + M.escapeHtml(t.side) + '</span></td>' +
                        '<td style="font-size:11px">' + M.escapeHtml(REGIME_LABELS[t.regime] || t.regime) + '</td>' +
                        '<td class="mono">' + price(t.entry) + '</td><td class="mono">' + price(t.exit) + '</td>' +
                        '<td>' + out + '</td><td class="mono">' + M.toFa(t.bars) + '</td>' +
                        '<td class="mono" style="color:' + (t.r > 0 ? 'var(--emerald)' : 'var(--rose)') + '">' + M.toFa(t.r) + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            html += '<p class="help" style="margin-top:10px"><i class="fa-solid fa-circle-info"></i> این بک‌تست فقط لایهٔ تکنیکال را می‌سنجد (بدون اجماع AI)؛ ' +
                'نتیجه «تُب» است نه تضمین — قبل از سرمایهٔ واقعی، walk-forward و paper trading انجام دهید.</p>';
            box.innerHTML = html;
            M.toast('بک‌تست ' + sym + ' کامل شد: ' + M.toFa(data.trades) + ' معامله، وین‌ریت ' + M.toFa(data.winrate) + '٪', good ? 'ok' : 'warn', 6000);
        } catch (err) {
            M.toast(err.message, 'bad', 7000);
        } finally {
            setBusy(btn, false);
        }
    }

    /* ── ردیاب سیگنال (داوری گذشته) ═════════════════════════════════ */

    function renderTracker(data) {
        const box = el('tracker_result');
        if (!box) { return; }
        const o = (data && data.overall) || { n: 0, tp1_rate: 0, win_rate: 0, avg_r: 0 };
        const rows = (data && data.rows) || [];
        if (!o.n) {
            box.innerHTML = '<p class="help"><i class="fa-solid fa-circle-info"></i> هنوز سیگنال داوری‌شده‌ای وجود ندارد. پس از چند اسکن و گذشت زمان، دکمهٔ «داوری سیگنال‌های باز» را بزنید تا عملکرد واقعی درجه‌ها اینجا ظاهر شود.</p>';
            return;
        }
        let html = '<div class="grid grid--4" style="margin-bottom:12px">';
        html += kv('سیگنال‌های داوری‌شده', M.toFa(o.n));
        html += kv('نرخ TP1', M.toFa(o.tp1_rate) + '٪', o.tp1_rate >= 55 ? 'var(--emerald)' : 'var(--rose)');
        html += kv('وین‌ریت واقعی', M.toFa(o.win_rate) + '٪', o.win_rate >= 50 ? 'var(--emerald)' : 'var(--rose)');
        html += kv('میانگین R واقعی', M.toFa(o.avg_r) + 'R', o.avg_r > 0 ? 'var(--emerald)' : 'var(--rose)');
        html += '</div>';
        html += '<div class="table-wrap"><table class="data"><thead><tr><th>درجه</th><th>رژیم</th><th>تعداد</th><th>نرخ TP1</th><th>نرخ TP2</th><th>استاپ خام</th><th>وین‌ریت</th><th>میانگین R</th></tr></thead><tbody>';
        rows.forEach(function (r) {
            html += '<tr>' +
                '<td>' + tierBadge(r.tier) + '</td>' +
                '<td><span class="regime-chip regime-' + M.escapeHtml(r.regime || 'range') + '">' + M.escapeHtml(REGIME_LABELS[r.regime] || r.regime) + '</span></td>' +
                '<td class="mono">' + M.toFa(r.n) + '</td>' +
                '<td class="mono" style="color:' + (r.tp1_rate >= 55 ? 'var(--emerald)' : 'var(--rose)') + '">' + M.toFa(r.tp1_rate) + '٪</td>' +
                '<td class="mono">' + M.toFa(r.tp2_rate) + '٪</td>' +
                '<td class="mono" style="color:var(--rose)">' + M.toFa(r.raw_stop_rate) + '٪</td>' +
                '<td class="mono">' + M.toFa(r.win_rate) + '٪</td>' +
                '<td class="mono" style="color:' + (r.avg_r > 0 ? 'var(--emerald)' : 'var(--rose)') + '">' + M.toFa(r.avg_r) + 'R</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        html += '<p class="help" style="margin-top:8px"><i class="fa-solid fa-circle-info"></i> «استاپ خام» = خوردن استاپ قبل از رسیدن به TP1. این آمار به‌صورت خودکار در قضاوت AI (پرامپت) و «اعتماد کالیبره» کارت‌های سیگنال تزریق می‌شود.</p>';
        box.innerHTML = html;
    }

    async function loadTracker() {
        try {
            const data = await M.api('api/tracker.php?action=stats', null, { method: 'GET' });
            if (data.ok) { renderTracker(data); }
            const meta = el('tracker_meta');
            if (meta && data.ok) {
                meta.textContent = data.total ? M.toFa(data.total) + ' داوری‌شده' : 'بدون داوری';
                meta.className = 'badge ' + (data.total ? 'badge--ok' : '');
            }
        } catch (err) { /* بی‌دیتابیس بی‌صدا رد می‌شود */ }
    }

    async function runTracker() {
        const btn = el('btn_tracker_run');
        setBusy(btn, true, 'در حال داوری…');
        try {
            const data = await M.api('api/tracker.php', { action: 'run' });
            if (data.ok) {
                renderTracker(data.stats || data);
                loadLearning(); // نسخهٔ ۵٫۶: داوری تازه = یادگیری تازه
                M.toast('داوری کامل شد: ' + M.toFa(data.checked) + ' بررسی · ' + M.toFa(data.closed) + ' بسته‌شده', 'ok', 6000);
                const meta = el('tracker_meta');
                if (meta && data.stats) {
                    meta.textContent = M.toFa(data.stats.total || 0) + ' داوری‌شده';
                    meta.className = 'badge badge--ok';
                }
            } else {
                M.toast(data.error || 'داوری ناموفق بود.', 'bad', 6000);
            }
        } catch (err) {
            M.toast(err.message, 'bad', 6000);
        } finally {
            setBusy(btn, false);
        }
    }

    /* ── راه‌اندازی ═══════════════════════════════════════════════════ */

    /* ═══ موتور یادگیری تطبیقی (نسخهٔ ۵٫۶) ═══════════════════════════ */

    function learnMultBadge(f) {
        if (f.disabled) { return '<span class="chip chip--pending" style="font-size:10px">غیرفعال</span>'; }
        if (f.quarantined) { return '<span class="chip chip--bad" style="font-size:10px">قرنطینه ×' + M.toFa(f.mult.toFixed(2)) + '</span>'; }
        const drift = f.drift_pct || 0;
        const arrow = drift > 1 ? '↑' : (drift < -1 ? '↓' : '');
        const color = drift > 1 ? '#34d399' : (drift < -1 ? '#fb7185' : '#94a3b8');
        return '<span class="mono" style="color:' + color + '">×' + M.toFa(f.mult.toFixed(2)) + ' ' + arrow + '</span>';
    }

    function renderLearning(st) {
        const meta = el('learn_meta');
        if (!meta) { return; }
        window.__learnEnabled = !!st.enabled;
        if (!st.ok) {
            meta.textContent = 'آماده نیست';
            meta.className = 'badge badge--bad';
            return;
        }
        meta.textContent = (st.enabled ? 'فعال' : 'خاموش') + ' · نسل ' + M.toFa(st.generation || 0)
            + (st.total_learned ? ' · ' + M.toFa(st.total_learned) + ' داوری' : '');
        meta.className = 'badge ' + (st.enabled ? 'badge--ok' : 'badge--bad');

        const tgl = el('btn_learn_toggle');
        if (tgl) {
            tgl.hidden = false;
            tgl.innerHTML = st.enabled
                ? '<i class="fa-solid fa-power-off"></i> خاموش‌کردن'
                : '<i class="fa-solid fa-power-off"></i> روشن‌کردن';
        }

        const sum = el('learn_summary');
        if (sum) {
            const active = (st.filters || []).filter(function (f) { return !f.disabled && !f.quarantined; }).length;
            const quarantined = (st.filters || []).filter(function (f) { return f.quarantined; }).length;
            const learned = (st.filters || []).reduce(function (a, f) { return a + (f.n || 0); }, 0);
            const best = (st.filters || []).filter(function (f) { return (f.n || 0) >= 10; })
                .sort(function (a, b) { return (b.correctness || 0) - (a.correctness || 0); })[0];
            sum.innerHTML =
                '<div><div class="stat__value">' + M.toFa(active) + '</div><div class="stat__sub">شاهد فعال</div></div>' +
                '<div><div class="stat__value" style="color:' + (quarantined ? '#fb7185' : 'inherit') + '">' + M.toFa(quarantined) + '</div><div class="stat__sub">قرنطینه</div></div>' +
                '<div><div class="stat__value">' + M.toFa(learned) + '</div><div class="stat__sub">رأی سنجیده‌شده</div></div>' +
                '<div><div class="stat__value" style="font-size:14px">' + (best ? M.escapeHtml(best.label.slice(0, 22)) : '—') + '</div><div class="stat__sub">' + (best ? 'دقیق‌ترین شاهد: ' + M.toFa(Math.round((best.correctness || 0) * 100)) + '٪' : 'دقیق‌ترین شاهد') + '</div></div>';
        }

        const box = el('learn_filters');
        if (box) {
            const rows = (st.filters || []).map(function (f) {
                const corr = Math.round((f.correctness || 0) * 100);
                const corrColor = corr >= 58 ? '#34d399' : (corr >= 48 ? '#fbbf24' : '#fb7185');
                const rTxt = f.avg_r !== null && f.avg_r !== undefined ? M.toFa(f.avg_r.toFixed(2)) : '—';
                const dir = f.directional
                    ? '<span class="mono" style="color:' + corrColor + '">' + M.toFa(corr) + '٪</span>'
                    : '<span class="dim" style="font-size:11px">غیرجهتی</span>';
                const manual = f.manual ? ' <i class="fa-solid fa-hand" style="font-size:10px;color:#fbbf24" title="ضریب دستی"></i>' : '';
                return '<tr>'
                    + '<td style="padding:6px 8px">' + M.escapeHtml(f.label) + manual + '</td>'
                    + '<td style="padding:6px 8px;text-align:center">' + (f.directional ? M.toFa(f.n) : '—') + '</td>'
                    + '<td style="padding:6px 8px;text-align:center">' + dir + '</td>'
                    + '<td style="padding:6px 8px;text-align:center">' + (f.directional ? rTxt : '—') + '</td>'
                    + '<td style="padding:6px 8px;text-align:center">' + learnMultBadge(f) + '</td>'
                    + '</tr>';
            }).join('');
            box.innerHTML = (st.filters || []).length
                ? '<div class="table-wrap"><table class="data" style="width:100%;font-size:12px"><thead><tr>'
                    + '<th style="text-align:right">فیلتر</th><th>نمونه</th><th>درست‌بودن</th><th>میانگین R</th><th>ضریب فعلی</th>'
                    + '</tr></thead><tbody>' + rows + '</tbody></table></div>'
                : '<p class="help">هنوز داده‌ای نیست — بعد از اولین داوری ردیاب، کارنامهٔ فیلترها اینجا ساخته می‌شود.</p>';
        }

        const ml = el('learn_manual_list');
        if (ml) {
            ml.innerHTML = (st.filters || []).map(function (f) {
                return '<div style="display:flex;align-items:center;gap:10px;padding:4px 0;flex-wrap:wrap">'
                    + '<label style="min-width:220px;font-size:12px"><input type="checkbox" data-lm-dis="' + M.escapeHtml(f.key) + '"' + (f.disabled ? ' checked' : '') + '> ' + M.escapeHtml(f.label) + '</label>'
                    + '<span class="dim" style="font-size:11px">ضریب دستی:</span>'
                    + '<input type="number" step="0.05" min="0.05" max="3" style="width:90px" data-lm-mult="' + M.escapeHtml(f.key) + '"'
                    + ' value="' + (f.manual ? f.mult : '') + '" placeholder="خودکار">'
                    + '</div>';
            }).join('') || '<p class="help">فیلتری شناسایی نشده — ابتدا یک اسکن اجرا کنید.</p>';
        }

        const ev = el('learn_events');
        if (ev) {
            const typeMap = { adapt: 'تطبیق وزن', quarantine: 'قرنطینه', recover: 'بازسازی', reset: 'بازنشانی' };
            ev.innerHTML = (st.events || []).map(function (e) {
                const color = e.type === 'quarantine' ? '#fb7185' : (e.type === 'recover' ? '#34d399' : '#a78bfa');
                return '<li><span style="color:' + color + '">[' + (typeMap[e.type] || e.type) + ']</span> '
                    + M.escapeHtml(e.filter) + ' ×' + M.toFa(e.old_mult.toFixed(2)) + ' → ×' + M.toFa(e.new_mult.toFixed(2))
                    + ' <span class="dim">(' + M.escapeHtml(e.detail || '') + ')</span></li>';
            }).join('') || '<li class="dim">رویدادی ثبت نشده است.</li>';
        }
    }

    async function loadLearning() {
        try {
            renderLearning(await M.api('api/learning.php', { action: 'status' }));
        } catch (err) { /* بی‌صدا */ }
    }

    async function runLearning() {
        const btn = el('btn_learn_run');
        setBusy(btn, true, 'در حال یادگیری…');
        try {
            const d = await M.api('api/learning.php', { action: 'run' });
            if (d.ok) {
                renderLearning(d.status || {});
                M.toast('یادگیری کامل شد: نسل ' + M.toFa(d.generation || 0) + ' · ' + M.toFa(d.changed ? d.changed.length : 0) + ' وزن تغییر کرد'
                    + (d.quarantined && d.quarantined.length ? ' · ' + M.toFa(d.quarantined.length) + ' قرنطینه!' : ''), 'ok', 7000);
                loadTracker();
            } else {
                M.toast((d.errors && d.errors[0]) || d.error || 'یادگیری ناموفق بود', 'bad', 7000);
            }
        } catch (err) { M.toast(err.message, 'bad', 6000); }
        finally { setBusy(btn, false); }
    }

    async function toggleLearning(current) {
        try {
            const d = await M.api('api/learning.php', { action: 'config', enabled: !current });
            M.toast(d.ok ? (!current ? 'موتور یادگیری روشن شد.' : 'موتور یادگیری خاموش شد — وزن‌ها فعلاً ایستا هستند.') : (d.error || 'ناموفق'), d.ok ? 'ok' : 'bad', 6000);
            if (d.ok) { renderLearning(d.status || {}); }
        } catch (err) { M.toast(err.message, 'bad', 6000); }
    }

    async function resetLearning() {
        if (!window.confirm('همهٔ ضرایب فیلترها به مقدار پایه (۱٫۰) بازگردند؟ کارنامه و رویدادها حفظ می‌شوند.')) { return; }
        const btn = el('btn_learn_reset');
        setBusy(btn, true, 'در حال بازنشانی…');
        try {
            const d = await M.api('api/learning.php', { action: 'reset' });
            M.toast(d.message || d.error, d.ok ? 'ok' : 'bad', 6000);
            if (d.ok) { renderLearning(d.status || {}); }
        } catch (err) { M.toast(err.message, 'bad', 6000); }
        finally { setBusy(btn, false); }
    }

    async function saveLearningManual() {
        const btn = el('btn_learn_manual_save');
        setBusy(btn, true, 'ذخیره…');
        try {
            const disabled = [];
            const overrides = {};
            document.querySelectorAll('[data-lm-dis]').forEach(function (c) {
                if (c.checked) { disabled.push(c.getAttribute('data-lm-dis')); }
            });
            document.querySelectorAll('[data-lm-mult]').forEach(function (i) {
                const v = parseFloat(i.value);
                if (!isNaN(v) && v > 0) { overrides[i.getAttribute('data-lm-mult')] = v; }
            });
            const d = await M.api('api/learning.php', { action: 'config', disabled: disabled, overrides: overrides });
            M.toast(d.message || d.error, d.ok ? 'ok' : 'bad', 6000);
            if (d.ok) { renderLearning(d.status || {}); }
        } catch (err) { M.toast(err.message, 'bad', 6000); }
        finally { setBusy(btn, false); }
    }

    document.addEventListener('DOMContentLoaded', () => {
        el('btn_scan').addEventListener('click', scanMarket);
        el('btn_single').addEventListener('click', analyzeSingle);
        el('single_symbol').addEventListener('keydown', (e) => { if (e.key === 'Enter') { analyzeSingle(); } });
        const btBtn = el('btn_backtest');
        if (btBtn) { btBtn.addEventListener('click', runBacktest); }
        const btSym = el('bt_symbol');
        if (btSym) { btSym.addEventListener('keydown', (e) => { if (e.key === 'Enter') { runBacktest(); } }); }
        const trBtn = el('btn_tracker_run');
        if (trBtn) { trBtn.addEventListener('click', runTracker); }
        const lrBtn = el('btn_learn_run');
        if (lrBtn) { lrBtn.addEventListener('click', runLearning); }
        const lrRst = el('btn_learn_reset');
        if (lrRst) { lrRst.addEventListener('click', resetLearning); }
        const lrTgl = el('btn_learn_toggle');
        if (lrTgl) { lrTgl.addEventListener('click', function () { toggleLearning(!window.__learnEnabled); }); }
        const lrMan = el('btn_learn_manual_save');
        if (lrMan) { lrMan.addEventListener('click', saveLearningManual); }
        loadPulse();
        loadTracker();
        loadLearning();
        setInterval(loadPulse, 60000); // پالس بازار هر دقیقه
        setInterval(loadTracker, 5 * 60000); // آمار ردیاب هر ۵ دقیقه
        M.refreshSystemStatus();
    });
}(window, document));
