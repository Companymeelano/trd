/**
 * میلانو تریدینگ اینتلیجنس — منطق پنل معامله‌گر خودکار (نسخهٔ ۵٫۲).
 * کیف پول تست · اجرای خودکار · پوزیشن‌های زنده · کارنامه ·
 * نمودار منحنی سرمایه (SVG گرادیانی) · دونات برد/باخت · میله‌های نماد.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
(function (window, document) {
    'use strict';

    const M = window.Meelano;
    if (!M) { return; }

    const el = (id) => M.$('#' + id);
    const price = (v) => {
        const n = Number(v) || 0;
        return M.toFa(n >= 1000 ? n.toLocaleString('en-US', { maximumFractionDigits: 0 })
            : n >= 1 ? n.toLocaleString('en-US', { maximumFractionDigits: 2 })
            : n.toPrecision(4));
    };
    const usd = (v) => M.toFa((Number(v) || 0).toLocaleString('en-US', { maximumFractionDigits: 2 }));
    const pnlCls = (v) => (Number(v) > 0 ? 'var(--emerald)' : (Number(v) < 0 ? 'var(--rose)' : 'var(--text-dim)'));
    const sign = (v) => (Number(v) > 0 ? '+' : '');
    const TIER_LABEL = { stop: 'استاپ', be_stop: 'استاپ سربه‌سر', tp1: 'TP1', tp2: 'TP2', tp3: 'TP3', signal: 'سیگنال مخالف', manual: 'دستی', timeout: 'افق زمانی' };

    function setBusy(btn, busy, label) {
        if (!btn) { return; }
        if (busy) { btn.dataset.orig = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + (label || '…'); }
        else { btn.disabled = false; if (btn.dataset.orig) { btn.innerHTML = btn.dataset.orig; } }
    }

    /* ═══ نمودارها (SVG — بدون وابستگی خارجی) ═════════════════════════ */

    function equityChart(points) {
        const box = el('equity_chart');
        if (!box) { return; }
        if (!points || points.length < 2) {
            box.innerHTML = '<p class="help" style="padding:30px 0;text-align:center">هنوز معامله‌ای بسته نشده — منحنی پس از اولین خروج رسم می‌شود.</p>';
            return;
        }
        const w = 640, h = 220, padB = 24, padT = 16;
        const vals = points.map(function (p) { return p.equity; });
        const min = Math.min.apply(null, vals), max = Math.max.apply(null, vals);
        const range = (max - min) || 1;
        const stepX = w / (points.length - 1);
        const y = function (v) { return padT + (1 - (v - min) / range) * (h - padB - padT); };
        let line = '', area = '';
        points.forEach(function (p, i) {
            const x = (i * stepX).toFixed(1), yy = y(p.equity).toFixed(1);
            line += (i === 0 ? 'M' : 'L') + x + ',' + yy + ' ';
            area += (i === 0 ? 'M' + x + ',' + (h - padB) + ' L' + x + ',' + yy + ' ' : 'L' + x + ',' + yy + ' ');
        });
        area += 'L' + w + ',' + (h - padB) + ' Z';
        const last = vals[vals.length - 1];
        const up = last >= vals[0];
        const stroke = up ? '#34d399' : '#fb7185';
        const gid = 'eqg' + Math.random().toString(36).slice(2, 7);
        const grid = [0, 0.25, 0.5, 0.75, 1].map(function (t) {
            const gy = (padT + t * (h - padB - padT)).toFixed(1);
            const gv = (max - t * range);
            return '<line x1="0" y1="' + gy + '" x2="' + w + '" y2="' + gy + '" stroke="rgba(148,163,184,.12)"/>'
                + '<text x="' + (w - 4) + '" y="' + (gy - 4) + '" fill="rgba(148,163,184,.55)" font-size="10" text-anchor="end">' + Math.round(gv).toLocaleString('en-US') + '</text>';
        }).join('');
        const lastY = y(last).toFixed(1);
        const dot = '<circle cx="' + ((points.length - 1) * stepX).toFixed(1) + '" cy="' + lastY + '" r="4" fill="' + stroke + '">'
            + '<animate attributeName="r" values="4;7;4" dur="1.6s" repeatCount="indefinite"/></circle>';
        box.innerHTML = '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" style="width:100%;height:' + h + 'px">'
            + '<defs><linearGradient id="' + gid + '" x1="0" y1="0" x2="0" y2="1">'
            + '<stop offset="0%" stop-color="' + stroke + '" stop-opacity=".45"/>'
            + '<stop offset="100%" stop-color="' + stroke + '" stop-opacity="0"/></linearGradient></defs>'
            + grid
            + '<path d="' + area + '" fill="url(#' + gid + ')"/>'
            + '<path d="' + line + '" fill="none" stroke="' + stroke + '" stroke-width="2.4" stroke-linejoin="round" style="filter:drop-shadow(0 0 6px ' + stroke + '66)"/>'
            + dot + '</svg>';
    }

    function donutChart(stats) {
        const box = el('donut_chart');
        if (!box) { return; }
        const n = stats.trades || 0;
        if (!n) {
            box.innerHTML = '<p class="help" style="padding:24px 0;text-align:center">بدون معامله — آمار پس از اولین خروج نمایش داده می‌شود.</p>';
            const sm = el('dn_summary'); if (sm) { sm.textContent = '—'; }
            return;
        }
        const wins = Math.round(n * (stats.winrate || 0) / 100);
        const losses = n - wins;
        const wr = Math.max(0, Math.min(100, stats.winrate || 0));
        const r = 46, c = 2 * Math.PI * r;
        const seg = (wr / 100) * c;
        const sm = el('dn_summary');
        if (sm) { sm.textContent = M.toFa(wins) + ' برد / ' + M.toFa(losses) + ' باخت'; }
        box.innerHTML = '<svg viewBox="0 0 140 90" style="width:100%;height:120px" preserveAspectRatio="xMidYMid meet">'
            + '<ellipse cx="70" cy="50" rx="' + r + '" ry="30" fill="none" stroke="rgba(148,163,184,.15)" stroke-width="14"/>'
            + '<ellipse cx="70" cy="46" rx="' + r + '" ry="30" fill="none" stroke="#fb7185" stroke-width="14" stroke-dasharray="' + c * 0.94 + ' ' + c + '" transform="rotate(-90 70 46)" stroke-linecap="round" opacity=".85"/>'
            + '<ellipse cx="70" cy="46" rx="' + r + '" ry="30" fill="none" stroke="#34d399" stroke-width="14" stroke-dasharray="' + seg * 0.94 + ' ' + c + '" transform="rotate(-90 70 46)" stroke-linecap="round" style="filter:drop-shadow(0 0 5px #34d39955)"/>'
            + '<text x="70" y="44" text-anchor="middle" fill="#eef2ff" font-size="17" font-weight="700">' + M.toFa(Math.round(wr)) + '٪</text>'
            + '<text x="70" y="58" text-anchor="middle" fill="rgba(148,163,184,.8)" font-size="9">وین‌ریت واقعی</text>'
            + '</svg>';
    }

    function symbolBars(bySymbol) {
        const box = el('symbol_bars');
        if (!box) { return; }
        if (!bySymbol || !bySymbol.length) {
            box.innerHTML = '<p class="help">سود/ضرر به تفکیک نماد پس از معاملات نمایش داده می‌شود.</p>';
            return;
        }
        const maxAbs = Math.max.apply(null, bySymbol.map(function (s) { return Math.abs(s.pnl_usdt); })) || 1;
        box.innerHTML = bySymbol.map(function (s) {
            const pos = s.pnl_usdt >= 0;
            const wPct = Math.max(4, Math.round(Math.abs(s.pnl_usdt) / maxAbs * 100));
            return '<div class="sym-bar">'
                + '<span class="mono sym-bar__name">' + M.escapeHtml(s.symbol.replace('USDT', '')) + '</span>'
                + '<div class="sym-bar__track"><div class="sym-bar__fill ' + (pos ? 'up' : 'down') + '" style="width:' + wPct + '%"></div></div>'
                + '<span class="mono sym-bar__val" style="color:' + pnlCls(s.pnl_usdt) + '">' + sign(s.pnl_usdt) + usd(s.pnl_usdt) + '</span>'
                + '<span class="help">' + M.toFa(s.n) + ' معامله</span>'
                + '</div>';
        }).join('');
    }

    /* ═══ رندر وضعیت ═══════════════════════════════════════════════════ */

    function renderState(data) {
        const a = data.account || {};
        el('w_balance').textContent = usd(a.balance_usdt);
        el('w_equity').textContent = usd(a.equity_usdt);
        el('w_pnl').innerHTML = '<span style="color:' + pnlCls(a.total_pnl_usdt) + '">' + sign(a.total_pnl_usdt) + usd(a.total_pnl_usdt) + '</span>';
        el('w_pnl').style.color = '';
        el('w_pnl_sub').textContent = sign(a.total_pnl_pct) + M.toFa(a.total_pnl_pct) + '٪ نسبت به ' + usd(a.initial_usdt) + ' دلار اولیه';
        el('w_pnl_card').style.borderColor = (a.total_pnl_usdt >= 0 ? 'rgba(52,211,153,.35)' : 'rgba(251,113,133,.35)');
        el('w_open').textContent = M.toFa(a.open_count);
        el('w_open_sub').textContent = a.open_count ? 'در پایش استاپ/تارگت (کندل بسته)' : 'بدون پوزیشن باز';
        el('w_trades').textContent = M.toFa(data.stats.trades);
        el('w_winrate').textContent = M.toFa(data.stats.winrate) + '٪';

        const chip = el('auto_status_chip');
        const auto = data.auto || {};
        if (auto.auto_trade_enabled) {
            chip.className = 'chip chip--ok';
            chip.innerHTML = '<i class="fa-solid fa-robot"></i> خودکار ' + (auto.auto_dry_run ? 'فعال (شبیه‌سازی)' : 'فعال (اجرای واقعی کیف)');
        } else {
            chip.className = 'chip chip--bad';
            chip.innerHTML = '<i class="fa-solid fa-power-off"></i> خودکار خاموش';
        }
        const stamp = el('paper_refresh_stamp');
        if (stamp) {
            stamp.textContent = 'به‌روزرسانی: ' + new Date().toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            stamp.className = 'badge badge--ok';
        }

        equityChart(data.equity_curve);
        donutChart(data.stats);
        symbolBars(data.by_symbol);

        const eqRoi = el('eq_roi');
        if (eqRoi) {
            const roi = a.total_pnl_pct || 0;
            eqRoi.textContent = 'بازده: ' + sign(roi) + M.toFa(roi) + '٪';
            eqRoi.className = 'badge ' + (roi >= 0 ? 'badge--ok' : 'badge--bad');
        }
        const tm = el('trades_meta');
        if (tm) {
            const st = data.stats;
            tm.textContent = M.toFa(st.trades) + ' معامله · PF ' + M.toFa(st.profit_factor) + ' · میانگین ' + M.toFa(st.avg_r) + 'R · میانگین ' + M.toFa(st.avg_duration_min) + ' دقیقه';
        }

        renderPositions(data.positions || []);
        renderTrades(data.trades || []);
        syncAutoForm(data.auto || {});
    }

    function renderPositions(list) {
        const box = el('open_positions');
        if (!list.length) {
            box.innerHTML = '<p class="help" style="text-align:center;padding:18px 0"><i class="fa-solid fa-mug-hot"></i> پوزیشن بازی باز نیست — با «معاملهٔ دستی» یا اسکن بازار (با خودکار روشن) شروع کنید.</p>';
            return;
        }
        box.innerHTML = '<div class="table-wrap"><table class="data"><thead><tr>'
            + '<th>نماد</th><th>درجه</th><th>ورود</th><th>قیمت جاری</th><th>مقدار</th><th>مانده</th><th>استاپ</th><th>TP1</th><th>TP2</th>'
            + '<th>سود/ضرر</th><th>٪</th><th>پیشرفت به TP2</th><th></th></tr></thead><tbody>'
            + list.map(function (p) {
                return '<tr>'
                    + '<td class="mono" style="font-weight:700;color:var(--gold-1)">' + M.escapeHtml(p.symbol) + '</td>'
                    + '<td><span class="tier-badge tier-' + String(p.tier).replace('+', 'ap') + '">' + M.escapeHtml(p.tier) + '</span></td>'
                    + '<td class="mono">' + price(p.entry_price) + '</td>'
                    + '<td class="mono">' + price(p.mark_price) + '</td>'
                    + '<td class="mono">' + price(p.quantity) + '</td>'
                    + '<td class="mono">' + M.toFa(p.remaining_pct) + '٪' + (p.hit_tp1 ? ' <i class="fa-solid fa-shield-halved" style="color:var(--emerald)" title="استاپ سربه‌ر شده"></i>' : '') + '</td>'
                    + '<td class="mono" style="color:var(--rose)">' + price(p.stop_loss || '—') + '<span class="help"> (' + (p.stop_type === 'breakeven' ? 'سربه‌سر' : (p.stop_type === 'signal' ? 'سیگنال' : '—')) + ')</span></td>'
                    + '<td class="mono">' + price(p.take_profit_1 || '—') + (p.hit_tp1 ? ' ✓' : '') + '</td>'
                    + '<td class="mono" style="color:var(--emerald)">' + price(p.take_profit_2 || '—') + '</td>'
                    + '<td class="mono" style="color:' + pnlCls(p.total_pnl_usdt) + ';font-weight:700">' + sign(p.total_pnl_usdt) + usd(p.total_pnl_usdt) + '</td>'
                    + '<td class="mono" style="color:' + pnlCls(p.total_pnl_usdt) + '">' + sign(p.pnl_pct) + M.toFa(p.pnl_pct) + '٪</td>'
                    + '<td style="min-width:110px"><div class="pos-progress"><div class="pos-progress__fill" style="width:' + p.progress_pct + '%"></div><i class="fa-solid fa-location-crosshairs pos-progress__pin" style="inset-inline-start:' + p.progress_pct + '%"></i></div></td>'
                    + '<td><button class="btn btn--sm btn--bad" data-close="' + p.id + '"><i class="fa-solid fa-xmark"></i> بستن</button></td>'
                    + '</tr>';
            }).join('') + '</tbody></table></div>';

        box.querySelectorAll('[data-close]').forEach(function (b) {
            b.addEventListener('click', function () { closePosition(Number(b.dataset.close)); });
        });
    }

    function renderTrades(list) {
        const box = el('trades_box');
        if (!list.length) {
            box.innerHTML = '<p class="help" style="text-align:center;padding:18px 0">هنوز معاملهٔ بسته‌شده‌ای نیست — کارنامه پس از اولین خروج (استاپ/تارگت/سیگنال/دستی) کامل می‌شود.</p>';
            return;
        }
        box.innerHTML = '<div class="table-wrap"><table class="data"><thead><tr>'
            + '<th>زمان بستن</th><th>نماد</th><th>درجه</th><th>رژیم</th><th>ورود</th><th>خروج</th><th>مبلغ</th>'
            + '<th>دلیل خروج</th><th>R</th><th>سود/ضرر (USDT)</th><th>٪</th><th>کارمزد</th><th>مدت</th></tr></thead><tbody>'
            + list.map(function (t) {
                return '<tr>'
                    + '<td class="mono" style="font-size:11px">' + M.escapeHtml(String(t.closed_at).replace('T', ' ').slice(5, 16)) + '</td>'
                    + '<td class="mono" style="font-weight:700;color:var(--gold-1)">' + M.escapeHtml(t.symbol) + '</td>'
                    + '<td><span class="tier-badge tier-' + String(t.tier).replace('+', 'ap') + '">' + M.escapeHtml(t.tier) + '</span></td>'
                    + '<td style="font-size:11px">' + M.escapeHtml(t.regime || '—') + '</td>'
                    + '<td class="mono">' + price(t.entry_price) + '</td>'
                    + '<td class="mono">' + price(t.exit_price) + '</td>'
                    + '<td class="mono">' + usd(t.entry_usdt) + '</td>'
                    + '<td>' + (TIER_LABEL[t.exit_reason] ? '<span class="badge ' + (t.exit_reason.startsWith('tp') ? 'badge--ok' : (t.exit_reason === 'manual' || t.exit_reason === 'signal' ? 'badge--gold' : 'badge--bad')) + '">' + TIER_LABEL[t.exit_reason] + '</span>' : M.escapeHtml(t.exit_reason)) + '</td>'
                    + '<td class="mono" style="color:' + pnlCls(t.r_multiple) + '">' + sign(t.r_multiple) + M.toFa(t.r_multiple) + '</td>'
                    + '<td class="mono" style="color:' + pnlCls(t.pnl_usdt) + ';font-weight:700">' + sign(t.pnl_usdt) + usd(t.pnl_usdt) + '</td>'
                    + '<td class="mono" style="color:' + pnlCls(t.pnl_pct) + '">' + sign(t.pnl_pct) + M.toFa(t.pnl_pct) + '٪</td>'
                    + '<td class="mono help">' + usd(t.fees_usdt) + '</td>'
                    + '<td class="mono">' + M.toFa(Math.round(t.duration_min)) + '′</td>'
                    + '</tr>';
            }).join('') + '</tbody></table></div>';
    }

    function syncAutoForm(auto) {
        const map = {
            at_max_open: 'auto_max_open_positions', at_min_combined: 'auto_min_combined',
            at_min_tier: 'auto_min_tier', at_tp_mode: 'auto_tp_mode',
            at_honor_stop: 'auto_honor_stop', at_close_opposite: 'auto_close_on_opposite', at_dry_run: 'auto_dry_run',
        };
        Object.keys(map).forEach(function (id) {
            const node = el(id);
            if (!node) { return; }
            const v = auto[map[id]];
            if (node.type === 'checkbox') { node.checked = !!v; }
            else if (v !== undefined && v !== null) { node.value = v; }
        });
        const en = el('at_enabled');
        if (en) { en.checked = !!auto.auto_trade_enabled; }
        const lbl = el('at_enabled_label');
        if (lbl) { lbl.textContent = en && en.checked ? 'روشن' : 'خاموش'; }
        document.querySelectorAll('.mode-card').forEach(function (card) {
            card.classList.toggle('is-active', card.dataset.mode === auto.auto_mode);
        });
        const am = el('at_amount_mode');
        if (am) {
            am.value = auto.auto_amount_mode || 'percent';
            const amount = el('at_amount');
            if (amount) { amount.value = auto.auto_amount_mode === 'fixed' ? auto.auto_amount_fixed : auto.auto_amount_percent; }
            syncAmountUnit();
        }
    }

    function syncAmountUnit() {
        const am = el('at_amount_mode');
        const unit = el('at_amount_unit');
        if (am && unit) { unit.textContent = am.value === 'percent' ? '٪' : 'USDT'; }
    }

    /* ═══ فراخوانی‌ها ═══════════════════════════════════════════════════ */

    async function loadState() {
        try {
            const data = await M.api('api/paper.php', { action: 'state' });
            if (data.ok) { renderState(data); }
        } catch (err) { /* بی‌دیتابیس/بی‌سرور: بعداً دوباره تلاش می‌شود */ }
    }

    function collectAuto() {
        const am = el('at_amount_mode');
        return {
            auto_mode: (document.querySelector('input[name="at_mode"]:checked') || {}).value || 'buy_sell',
            auto_amount_mode: am ? am.value : 'percent',
            auto_amount_percent: am && am.value === 'percent' ? Number(el('at_amount').value) : undefined,
            auto_amount_fixed: am && am.value === 'fixed' ? Number(el('at_amount').value) : undefined,
            auto_max_open_positions: Number(el('at_max_open').value),
            auto_min_tier: el('at_min_tier').value,
            auto_min_combined: Number(el('at_min_combined').value),
            auto_tp_mode: el('at_tp_mode').value,
            auto_honor_stop: el('at_honor_stop').checked,
            auto_close_on_opposite: el('at_close_opposite').checked,
            auto_dry_run: el('at_dry_run').checked,
        };
    }

    async function saveAuto(withToggle) {
        const btn = el('btn_auto_save');
        setBusy(btn, true, 'ذخیره…');
        try {
            const payload = collectAuto();
            if (withToggle !== undefined) { payload.auto_trade_enabled = withToggle; }
            const data = await M.api('api/paper.php', { action: 'config', config: payload });
            if (data.ok) {
                M.toast('تنظیمات معامله‌گر ذخیره شد' + (withToggle !== undefined ? (withToggle ? ' — خودکار روشن شد' : ' — خودکار خاموش شد') : ''), 'ok', 5000);
                loadState();
            } else {
                M.toast(data.error || 'ذخیره ناموفق بود.', 'bad', 6000);
            }
        } catch (err) {
            M.toast(err.message, 'bad', 6000);
        } finally {
            setBusy(btn, false);
        }
    }

    async function toggleAuto() {
        const on = el('at_enabled').checked;
        if (on && el('at_dry_run').checked) {
            M.toast('خودکار در حالت شبیه‌سازی روشن می‌شود — برای اجرای واقعی روی کیف، Dry-Run را خاموش کنید.', 'info', 7000);
        }
        if (on) {
            const ok = window.confirm('فعال‌سازی معاملهٔ خودکار؟\nسیگنال‌های عبورکرده از فیلترها روی کیف پول تست اجرا می‌شوند.');
            if (!ok) { el('at_enabled').checked = false; return; }
        }
        el('at_enabled_label').textContent = on ? 'روشن' : 'خاموش';
        saveAuto(on);
    }

    async function resetWallet() {
        const initial = Number(el('wallet_initial').value) || 10000;
        const ok = window.confirm('بازنشانی کیف پول تست به ' + M.toFa(initial) + ' USDT؟\nتمام پوزیشن‌های باز و کارنامهٔ معاملات پاک می‌شود.');
        if (!ok) { return; }
        const btn = el('btn_wallet_reset');
        setBusy(btn, true, 'در حال بازنشانی…');
        try {
            const data = await M.api('api/paper.php', { action: 'reset', initial_usdt: initial });
            if (data.ok) {
                M.toast('کیف پول با ' + M.toFa(initial) + ' USDT بازنشانی شد.', 'ok');
                renderState(data.state);
            } else { M.toast(data.error || 'ناموفق', 'bad', 6000); }
        } catch (err) {
            M.toast(err.message, 'bad', 6000);
        } finally { setBusy(btn, false); }
    }

    async function openManual() {
        const sym = (window.prompt('نماد برای معاملهٔ دستی (مثل BTCUSDT):') || '').trim().toUpperCase();
        if (!sym) { return; }
        const usdt = Number(window.prompt('مبلغ معامله (USDT):', '100'));
        if (!(usdt >= 10)) { M.toast('حداقل مبلغ ۱۰ USDT است.', 'warn'); return; }
        M.toast('در حال تحلیل ' + sym + ' و ساخت پلن ریسک…', 'info');
        try {
            const data = await M.api('api/paper.php', { action: 'open', symbol: sym, usdt: usdt });
            if (data.ok) {
                M.toast('پوزیشن ' + sym + ' با ' + usd(usdt) + ' USDT باز شد (استاپ/تارگت از موتور).', 'ok', 6000);
                renderState(data.state);
            } else {
                M.toast(data.reason || data.error || 'بازکردن پوزیشن ناموفق بود.', 'bad', 6500);
            }
        } catch (err) {
            M.toast(err.message, 'bad', 6000);
        }
    }

    async function closePosition(pid) {
        if (!window.confirm('بستن کامل این پوزیشن در قیمت جاری؟')) { return; }
        try {
            const data = await M.api('api/paper.php', { action: 'close', position_id: pid });
            if (data.ok && data.trade) {
                M.toast('بسته شد: ' + sign(data.trade.pnl_usdt) + usd(data.trade.pnl_usdt) + ' USDT', data.trade.pnl_usdt >= 0 ? 'ok' : 'warn', 6000);
            }
            if (data.state) { renderState(data.state); }
        } catch (err) {
            M.toast(err.message, 'bad', 6000);
        }
    }

    async function manualUpdate() {
        const btn = el('btn_paper_update');
        setBusy(btn, true, 'پایش…');
        try {
            const data = await M.api('api/paper.php', { action: 'update' });
            if (data.state) { renderState(data.state); }
            const closed = (data.closed || []).length;
            M.toast('پایش کامل شد' + (closed ? ' — ' + M.toFa(closed) + ' پوزیشن بسته شد' : ''), closed ? 'ok' : 'info', 5000);
        } catch (err) {
            M.toast(err.message, 'bad', 6000);
        } finally { setBusy(btn, false); }
    }

    /* ═══ صرافی ═════════════════════════════════════════════════════════ */

    /* فرادادهٔ صرافی‌ها از سرور (در body تا JS سبک بماند) */
    const EX_META = window.MEELANO_EX_META || {};

    function currentProvider() {
        const p = el('ex_provider');
        return p ? p.value : 'binance';
    }

    function applyProviderUI() {
        const pid = currentProvider();
        const meta = EX_META[pid] || {};
        document.querySelectorAll('[data-ex-card]').forEach(function (c) {
            c.classList.toggle('is-active', c.getAttribute('data-ex-card') === pid);
        });
        const mb = el('ex_mode_box'); if (mb) { mb.hidden = pid !== 'binance'; }
        const sb = el('ex_secret_box'); if (sb) { sb.hidden = pid === 'wallex'; }
        const kh = el('ex_key');
        if (kh) {
            kh.type = meta.key_is_secret ? 'password' : 'text';
            kh.placeholder = meta.key_ph || 'کلید عمومی صرافی';
            const st = window.__exStatus;
            if (st && st.providers && st.providers[pid] && st.providers[pid].api_key_masked) {
                kh.placeholder = st.providers[pid].api_key_masked + ' (ذخیره شده — خالی بماند تا تغییر نکند)';
            }
        }
        const sh = el('ex_secret');
        if (sh) { sh.placeholder = meta.secret_ph || 'فقط اگر می‌خواهید عوض شود'; }
        const help = el('ex_provider_help');
        if (help) { help.innerHTML = meta.help ? '<i class="fa-solid fa-circle-info"></i> ' + meta.help : ''; }
    }

    async function exchangeStatus() {
        try {
            const st = await M.api('api/exchange.php', { action: 'status' });
            window.__exStatus = st;
            const chip = el('ex_status_chip');
            if (st.ok) {
                if (st.live_enabled) {
                    chip.className = 'chip chip--bad';
                    chip.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> معاملهٔ زنده فعال (' + M.escapeHtml((st.providers && st.providers[st.provider] && st.providers[st.provider].label) || st.provider) + ')';
                    const off = el('btn_ex_live_off'); if (off) { off.hidden = false; }
                } else if (st.keys_set) {
                    chip.className = 'chip chip--ok';
                    chip.innerHTML = '<i class="fa-solid fa-plug-circle-check"></i> کلیدها ذخیره شده' + (st.provider === 'binance' ? ' (' + (st.mode === 'testnet' ? 'Testnet' : 'Live') + ')' : '');
                } else {
                    chip.className = 'chip chip--pending';
                    chip.innerHTML = '<i class="fa-solid fa-plug-circle-xmark"></i> بدون کلید';
                }
                const sel = el('ex_provider');
                if (sel && st.provider) { sel.value = st.provider; }
                Object.keys(st.providers || {}).forEach(function (pid) {
                    const p = st.providers[pid];
                    const dot = document.querySelector('[data-ex-dot="' + pid + '"]');
                    if (dot) {
                        dot.className = 'nt-dot ' + (p.configured ? 'nt-dot--ok' : 'nt-dot--off');
                        dot.title = p.configured ? 'کلیدها ذخیره شده' : 'بدون کلید';
                    }
                });
                applyProviderUI();
            }
        } catch (err) { /* بی‌صدا */ }
    }

    async function selectProvider(pid) {
        try {
            const d = await M.api('api/exchange.php', { action: 'select', provider: pid });
            if (d.ok) {
                M.toast('صرافی فعال: ' + ((EX_META[pid] || {}).label_fa || pid), 'ok', 5000);
                el('ex_key').value = '';
                el('ex_secret').value = '';
                exchangeStatus();
            } else {
                M.toast(d.error || 'تغییر صرافی ناموفق بود', 'bad', 6000);
                exchangeStatus();
            }
        } catch (err) { M.toast(err.message, 'bad', 6000); }
    }

    async function saveExchangeKeys() {
        const btn = el('btn_ex_save');
        setBusy(btn, true, 'ذخیره…');
        try {
            const payload = {
                action: 'save_keys',
                provider: currentProvider(),
                api_key: el('ex_key').value.trim(),
                api_secret: el('ex_secret').value.trim(),
            };
            if (currentProvider() === 'binance') { payload.mode = el('ex_mode').value; }
            const data = await M.api('api/exchange.php', payload);
            M.toast(data.message || data.error, data.ok ? 'ok' : 'bad', 6500);
            if (data.ok) { el('ex_secret').value = ''; exchangeStatus(); }
        } catch (err) { M.toast(err.message, 'bad', 6000); }
        finally { setBusy(btn, false); }
    }

    async function testExchange() {
        const btn = el('btn_ex_test');
        setBusy(btn, true, 'تست…');
        const box = el('ex_result');
        try {
            const d = await M.api('api/exchange.php', { action: 'test', provider: currentProvider() });
            let html = '';
            if (d.ok) {
                html += '<div class="ex-ok"><i class="fa-solid fa-circle-check"></i> دسترسی به ' + M.escapeHtml(d.endpoint || '') + ' برقرار است — پینگ ' + M.toFa(d.ping_ms) + 'ms</div>';
                if (d.balance && !d.balance.error) {
                    html += '<div class="ex-ok"><i class="fa-solid fa-wallet"></i> موجودی USDT: <b>' + usd(d.balance.USDT) + '</b> · ' + M.toFa(d.balance.assets || 0) + ' دارایی</div>';
                } else if (d.balance_error) {
                    html += '<div class="ex-warn"><i class="fa-solid fa-triangle-exclamation"></i> کلیدها ذخیره‌اند اما حساب خوانده نشد: ' + M.escapeHtml(d.balance_error) + '</div>';
                } else if (!d.keys_set) {
                    html += '<div class="ex-warn"><i class="fa-solid fa-key"></i> کلید ذخیره نشده — فقط دسترسی عمومی تست شد.</div>';
                }
            } else {
                html += '<div class="ex-warn"><i class="fa-solid fa-circle-xmark"></i> اتصال ناموفق: ' + M.escapeHtml(d.error || 'خطای نامشخص') + '</div>';
            }
            box.innerHTML = html;
        } catch (err) {
            box.innerHTML = '<div class="ex-warn">خطا: ' + M.escapeHtml(err.message) + '</div>';
        } finally { setBusy(btn, false); }
    }

    async function enableLive() {
        const phrase = window.prompt('⚠️ فعال‌سازی معاملهٔ واقعی روی صرافی:\n\n• سفارش‌ها با پول واقعی اجرا می‌شوند\n• با مبلغ کم شروع کنید و اول Testnet را کامل تست کنید\n\nبرای تأیید دقیقاً تایپ کنید: ENABLE-LIVE');
        if (phrase === null) { return; }
        if (phrase.trim().toUpperCase() !== 'ENABLE-LIVE') {
            M.toast('عبارت تأیید صحیح نیست — معاملهٔ زنده قفل ماند.', 'warn', 6000);
            return;
        }
        try {
            const d = await M.api('api/exchange.php', { action: 'enable_live', confirm: 'ENABLE-LIVE' });
            M.toast(d.message || d.error, d.ok ? 'ok' : 'bad', 8000);
            exchangeStatus();
        } catch (err) { M.toast(err.message, 'bad', 6000); }
    }

    async function disableLive() {
        try {
            const d = await M.api('api/exchange.php', { action: 'disable_live' });
            M.toast(d.message || 'قفل شد.', 'ok', 5000);
            exchangeStatus();
        } catch (err) { M.toast(err.message, 'bad', 6000); }
    }

    /* ═══ راه‌اندازی ════════════════════════════════════════════════════ */

    document.addEventListener('DOMContentLoaded', function () {
        if (!el('wallet_cards')) { return; } // صفحه بدون دیتابیس

        el('at_enabled').addEventListener('change', toggleAuto);
        el('btn_auto_save').addEventListener('click', function () { saveAuto(); });
        el('at_amount_mode').addEventListener('change', syncAmountUnit);
        document.querySelectorAll('.mode-card').forEach(function (card) {
            card.addEventListener('click', function () {
                document.querySelectorAll('.mode-card').forEach(function (c) { c.classList.remove('is-active'); });
                card.classList.add('is-active');
                card.querySelector('input').checked = true;
            });
        });

        el('btn_wallet_reset').addEventListener('click', resetWallet);
        el('btn_paper_open').addEventListener('click', openManual);
        el('btn_paper_update').addEventListener('click', manualUpdate);

        el('btn_ex_save').addEventListener('click', saveExchangeKeys);
        el('btn_ex_test').addEventListener('click', testExchange);
        el('btn_ex_live').addEventListener('click', enableLive);
        const exSel = el('ex_provider');
        if (exSel) {
            exSel.addEventListener('change', function () { selectProvider(exSel.value); });
        }
        document.querySelectorAll('[data-ex-card]').forEach(function (card) {
            card.addEventListener('click', function () {
                const pid = card.getAttribute('data-ex-card');
                if (el('ex_provider') && el('ex_provider').value !== pid) {
                    el('ex_provider').value = pid;
                    selectProvider(pid);
                }
            });
        });
        applyProviderUI();
        el('btn_ex_live_off').addEventListener('click', disableLive);

        loadState();
        exchangeStatus();
        setInterval(loadState, 60000); // وضعیت کیف هر دقیقه
        setInterval(manualUpdateQuiet, 5 * 60000); // پایش خروج هر ۵ دقیقه
        M.refreshSystemStatus();
    });

    let quietBusy = false;
    async function manualUpdateQuiet() {
        if (quietBusy) { return; }
        quietBusy = true;
        try {
            const data = await M.api('api/paper.php', { action: 'update' });
            if (data.state) { renderState(data.state); }
        } catch (err) { /* بی‌صدا */ }
        finally { quietBusy = false; }
    }
}(window, document));
