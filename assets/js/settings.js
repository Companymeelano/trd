/**
 * پنل هوشمند میلانو — منطق صفحه تنظیمات
 * تست اتصال دیتابیس، نصب جدول‌ها با SSE، تست کلیدهای AI و مسیریابی.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
(function (window, document) {
    'use strict';

    const M = window.Meelano;
    if (!M) { return; }

    const el = (id) => M.$('#' + id);

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

    function installLog(text, kind) {
        const list = el('install_log');
        if (!list) { return; }
        const li = document.createElement('li');
        li.className = kind || '';
        li.textContent = text;
        list.appendChild(li);
        list.scrollTop = list.scrollHeight;
    }

    /* ── تب‌ها ───────────────────────────────────────────────────────── */

    function initTabs() {
        const buttons = M.$$('.tabs button');
        buttons.forEach((btn) => {
            btn.addEventListener('click', () => {
                buttons.forEach((b) => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                M.$$('.tab-panel').forEach((p) => { p.hidden = true; });
                const panel = el('tab-' + btn.dataset.tab);
                if (panel) { panel.hidden = false; }
            });
        });
    }

    /* ── دیتابیس: تست اتصال ──────────────────────────────────────────── */

    function dbPayload() {
        return {
            host: el('db_host').value.trim(),
            port: Number(el('db_port').value) || 3306,
            name: el('db_name').value.trim(),
            user: el('db_user').value.trim(),
            pass: el('db_pass').value,
            prefix: el('db_prefix').value.trim() || 'mln_',
        };
    }

    async function testDb() {
        const btn = el('btn_db_test');
        setBusy(btn, true, 'در حال تست…');
        const box = el('db_test_result');
        box.innerHTML = '<p class="help">در حال برقراری اتصال…</p>';
        try {
            const data = await M.api('api/db_test.php', dbPayload());
            const r = data.result;
            let html = '<div class="card-3d" style="padding:16px;border-color:' +
                (r.ok ? 'rgba(52,211,153,.4)' : 'rgba(251,113,133,.4)') + '">';
            html += '<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px">' +
                '<i class="fa-solid ' + (r.ok ? 'fa-circle-check' : 'fa-circle-xmark') + '" style="font-size:22px;color:' +
                (r.ok ? '#34d399' : '#fb7185') + '"></i>' +
                '<div><b style="font-size:14px">' + M.escapeHtml(r.message) + '</b>' +
                '<p class="help" style="margin:2px 0 0">تأخیر: ' + M.toFa(r.latency_ms) + 'ms' +
                (r.server ? ' · سرور: ' + M.escapeHtml(r.server) : '') + '</p></div></div>';
            html += '<div class="stack">';
            (r.checks || []).forEach((c) => {
                html += '<div class="audit audit--' + (c.ok ? 'ok' : 'critical') + '">' +
                    '<i class="fa-solid ' + (c.ok ? 'fa-circle-check' : 'fa-circle-xmark') + '"></i>' +
                    '<div><p class="audit__title">' + M.escapeHtml(c.label) + '</p>' +
                    '<p class="audit__detail mono" style="font-size:11px">' + M.escapeHtml(c.detail) + '</p></div></div>';
            });
            html += '</div>';
            if (r.dsn_hint) { html += '<p class="help help--bad" style="margin-top:10px">' + M.escapeHtml(r.dsn_hint) + '</p>'; }
            html += '</div>';
            box.innerHTML = html;

            const chip = el('db_status_chip');
            chip.className = 'badge ' + (r.ok ? 'badge--ok' : 'badge--bad');
            chip.textContent = r.ok ? 'متصل' : 'قطع';
            M.toast(r.message, r.ok ? 'ok' : 'bad', 5000);
        } catch (err) {
            box.innerHTML = '<div class="audit audit--critical"><i class="fa-solid fa-circle-xmark"></i>' +
                '<div><p class="audit__title">تست ناموفق</p><p class="audit__detail">' + M.escapeHtml(err.message) + '</p></div></div>';
            M.toast(err.message, 'bad', 6000);
        } finally {
            setBusy(btn, false);
        }
    }

    async function saveDb() {
        const btn = el('btn_db_save');
        setBusy(btn, true, 'در حال ذخیره…');
        try {
            const data = await M.api('api/settings_save.php', { db: dbPayload() });
            M.toast(data.message, 'ok');
            el('db_pass').value = '';
        } catch (err) {
            M.toast(err.message, 'bad', 6000);
        } finally {
            setBusy(btn, false);
        }
    }

    /* ── دیتابیس: ساخت جداول با SSE ──────────────────────────────────── */

    async function installTables() {
        const btn = el('btn_db_install');
        setBusy(btn, true, 'در حال ساخت…');
        el('install_log').innerHTML = '';
        M.modal.open('ساخت جدول‌های پایگاه‌داده', 'آماده‌سازی ساختار…');

        let response;
        try {
            response = await fetch(M.apiUrl('api/db_install.php'), {
                method: 'POST',
                headers: { 'X-CSRF-Token': M.csrf, 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
                credentials: 'same-origin',
                body: JSON.stringify({}),
            });
        } catch (err) {
            M.modal.close(0);
            setBusy(btn, false);
            M.toast('خطای شبکه در شروع نصب: ' + err.message, 'bad');
            return;
        }

        if (!response.ok || !response.body) {
            M.modal.close(0);
            setBusy(btn, false);
            M.toast('شروع نصب ناموفق بود (HTTP ' + response.status + ')', 'bad');
            return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        try {
            while (true) {
                const chunk = await reader.read();
                if (chunk.done) { break; }
                buffer += decoder.decode(chunk.value, { stream: true });

                const parts = buffer.split('\n\n');
                buffer = parts.pop();

                parts.forEach((part) => {
                    const line = part.trim();
                    if (!line.startsWith('data:')) { return; }
                    let event;
                    try {
                        event = JSON.parse(line.slice(5).trim());
                    } catch (err) { return; }
                    handleInstallEvent(event);
                });
            }
        } catch (err) {
            installLog('قطع ارتباط جریان نصب: ' + err.message, 'bad');
        } finally {
            setBusy(btn, false);
            M.refreshSystemStatus();
        }
    }

    function handleInstallEvent(event) {
        const bar = el('install_bar');
        const label = el('install_percent_label');
        const pct = Math.max(0, Math.min(100, Number(event.percent) || 0));
        if (bar) { bar.style.width = pct + '%'; }
        if (label) { label.textContent = M.toFa(pct) + '٪'; }

        M.modal.set(pct, event.message || '', event.table ? ('جدول ' + M.toFa(event.step) + ' از ' + M.toFa(event.total)) : '');

        if (event.phase === 'table:done') {
            installLog((event.existed ? '↺ ' : '✓ ') + event.message + (event.columns ? ' · ' + M.toFa(event.columns) + ' ستون' : ''), 'ok');
            M.modal.log((event.existed ? '↺ ' : '✓ ') + (event.title || event.table), 'ok');
        } else if (event.phase === 'table:error' || event.phase === 'fatal') {
            installLog(event.message, 'bad');
            M.modal.log(event.message, 'bad');
        } else if (event.phase === 'complete') {
            const r = event.result || {};
            M.modal.set(100, r.summary || 'پایان');
            M.modal.log(r.ok ? '✓ نصب کامل شد' : '✗ نصب با خطا پایان یافت', r.ok ? 'ok' : 'bad');
            installLog('— پایان: ' + (r.summary || '') + ' در ' + M.toFa(r.duration_ms || 0) + 'ms', r.ok ? 'ok' : 'bad');
            M.toast(r.ok ? 'جدول‌ها با موفقیت ساخته شدند' : 'نصب با خطا پایان یافت — لاگ را ببینید', r.ok ? 'ok' : 'bad', 6000);
            setTimeout(() => M.modal.close(), 900);
        } else if (event.message) {
            installLog(event.message, event.ok === false ? 'bad' : '');
        }
    }

    /* ── هوش مصنوعی ──────────────────────────────────────────────────── */

    function providerPayload(card) {
        const id = card.dataset.provider;
        const out = { enabled: false, providers: {} };
        const enabled = card.querySelector('[data-field="enabled"]');
        const clear = card.querySelector('[data-field="clear"]');
        out.providers[id] = { enabled: !!(enabled && enabled.checked) };
        card.querySelectorAll('[data-field]').forEach((input) => {
            const field = input.dataset.field;
            if (field === 'enabled' || field === 'clear') { return; }
            if (input.type === 'checkbox') { return; }
            if (input.value.trim() !== '') {
                out.providers[id][field] = input.value.trim();
            }
        });
        if (clear && clear.checked) {
            const keyInput = card.querySelector('[data-field="api_key"], [data-field="api_token"]');
            const keyField = keyInput ? keyInput.dataset.field : 'api_key';
            out.providers[id][keyField + '_clear'] = 1;
        }
        return out;
    }

    function setStatus(card, ok, text, extra) {
        const box = card.querySelector('[data-role="status"]');
        if (!box) { return; }
        box.className = 'provider-card__status ' + (ok === true ? 'ok' : (ok === false ? 'bad' : ''));
        box.textContent = text + (extra ? ' · ' + extra : '');
    }

    async function testProvider(card) {
        const id = card.dataset.provider;
        const btn = card.querySelector('[data-action="test"]');
        setBusy(btn, true, 'تست…');
        setStatus(card, null, 'در حال تست…');
        try {
            const payload = providerPayload(card);
            const values = payload.providers[id];
            const body = { provider: id };
            Object.keys(values).forEach((k) => {
                if (k !== 'enabled') { body[k] = values[k]; }
            });
            const data = await M.api('api/ai_test.php', body);
            const r = data.result || data;
            setStatus(card, !!r.ok, r.message, r.latency_ms ? (M.toFa(r.latency_ms) + 'ms · HTTP ' + M.toFa(r.http_code)) : null);
            M.toast(r.label + ': ' + r.message, r.ok ? 'ok' : 'bad', 5000);
        } catch (err) {
            setStatus(card, false, err.message);
            M.toast(err.message, 'bad', 5500);
        } finally {
            setBusy(btn, false);
        }
    }

    async function testAllProviders() {
        const btn = el('btn_ai_test_all');
        setBusy(btn, true, 'در حال تست…');
        M.modal.open('اعتبارسنجی کلیدهای هوش مصنوعی', 'بررسی همه ارائه‌دهندگان…');
        try {
            await M.api('api/settings_save.php', collectAllProviders());
            const data = await M.api('api/ai_test.php', { all: true });
            const results = data.results || {};
            let index = 0;
            const ids = Object.keys(results);
            M.$$('.provider-card').forEach((card) => {
                const r = results[card.dataset.provider];
                if (r) {
                    setStatus(card, !!r.ok, r.message, r.latency_ms ? (M.toFa(r.latency_ms) + 'ms') : null);
                    M.modal.log((r.ok ? '✓ ' : '✗ ') + r.label + (r.latency_ms ? ' — ' + M.toFa(r.latency_ms) + 'ms' : ''), r.ok ? 'ok' : 'bad');
                }
            });
            M.modal.set(100, data.message);
            setTimeout(() => M.modal.close(), 700);
            M.toast(data.message, data.summary && data.summary.ok > 0 ? 'ok' : 'warn', 5500);
        } catch (err) {
            M.modal.close(0);
            M.toast(err.message, 'bad', 6000);
        } finally {
            setBusy(btn, false);
        }
    }

    function collectAllProviders() {
        const out = { ai: { providers: {} } };
        M.$$('.provider-card').forEach((card) => {
            const p = providerPayload(card);
            out.ai.providers = Object.assign(out.ai.providers, p.providers);
        });
        return out;
    }

    async function saveProviders() {
        const btn = el('btn_ai_save');
        setBusy(btn, true, 'در حال ذخیره…');
        try {
            const data = await M.api('api/settings_save.php', collectAllProviders());
            M.toast(data.message + ' (' + M.toFa(data.changed.length) + ' تغییر)', 'ok');
            M.$$('.provider-card').forEach((card) => {
                const clear = card.querySelector('[data-field="clear"]');
                if (clear) { clear.checked = false; }
                const keyInput = card.querySelector('[data-field="api_key"], [data-field="api_token"]');
                if (keyInput && keyInput.value) {
                    keyInput.placeholder = '•••••• ذخیره شد';
                    keyInput.value = '';
                }
            });
        } catch (err) {
            M.toast(err.message, 'bad', 6000);
        } finally {
            setBusy(btn, false);
        }
    }

    /* ── مسیریابی ────────────────────────────────────────────────────── */

    async function autoRoute() {
        const btn = el('btn_autoroute');
        setBusy(btn, true, 'در حال تحلیل…');
        M.modal.open('مسیریابی هوشمند وظایف', 'سنجش توانمندی، تأخیر و تخصص هر موتور…');
        try {
            await M.api('api/settings_save.php', collectAllProviders());
            const data = await M.api('api/ai_autoroute.php', { apply: true });
            const report = (data.plan && data.plan.report) || [];
            const map = (data.plan && data.plan.map) || {};

            Object.keys(map).forEach((task) => {
                const row = M.$('[data-task="' + task + '"]');
                if (!row) { return; }
                const select = row.querySelector('[data-role="provider"]');
                if (select) { select.value = map[task]; }
            });

            let html = '<h3 class="section__title" style="font-size:14px;margin-bottom:10px"><i class="fa-solid fa-list-check"></i> گزارش تصمیم‌گیری مسیریاب</h3>';
            report.forEach((r) => {
                if (!r.provider) {
                    html += '<div class="audit audit--warning"><i class="fa-solid fa-triangle-exclamation"></i><div>' +
                        '<p class="audit__title">' + M.escapeHtml(r.label) + '</p>' +
                        '<p class="audit__detail">' + M.escapeHtml(r.note) + '</p></div></div>';
                    return;
                }
                const reasons = (r.reasons || []).map((x) => '· ' + M.escapeHtml(x)).join('<br>');
                html += '<div class="audit audit--info"><i class="fa-solid fa-circle-info"></i><div>' +
                    '<p class="audit__title">' + M.escapeHtml(r.label) + ' → <b style="color:var(--gold-1)">' + M.escapeHtml(r.provider_label) + '</b>' +
                    ' <span class="mono dim">(امتیاز ' + M.toFa(r.score) + ')</span></p>' +
                    '<p class="audit__detail">' + reasons + '<br><em>' + M.escapeHtml(r.note || '') + '</em></p></div></div>';

                const row = M.$('[data-task="' + r.task + '"]');
                if (row) {
                    const cell = row.querySelector('[data-role="reason"]');
                    if (cell) { cell.innerHTML = 'امتیاز ' + M.toFa(r.score) + (r.runner_up ? ' · جایگزین: ' + M.escapeHtml(r.runner_up) : ''); }
                }
            });
            el('route_report').innerHTML = html;

            M.modal.set(100, data.message);
            M.modal.log(data.message, 'ok');
            setTimeout(() => M.modal.close(), 700);
            M.toast(data.message, 'ok', 5500);
        } catch (err) {
            M.modal.close(0);
            M.toast(err.message, 'bad', 6000);
        } finally {
            setBusy(btn, false);
        }
    }

    async function saveRouting() {
        const btn = el('btn_route_save');
        setBusy(btn, true, 'در حال ذخیره…');
        const map = {};
        M.$$('#routing_rows tr').forEach((row) => {
            const task = row.dataset.task;
            const select = row.querySelector('[data-role="provider"]');
            if (select && select.value) { map[task] = select.value; }
        });
        const mode = (M.$('input[name="route_mode"]:checked') || {}).value || 'auto';
        try {
            await M.api('api/settings_save.php', { routing: { map: map, mode: mode } });
            M.toast('مسیریابی دستی ذخیره شد — ' + M.toFa(Object.keys(map).length) + ' وظیفه', 'ok');
        } catch (err) {
            M.toast(err.message, 'bad', 6000);
        } finally {
            setBusy(btn, false);
        }
    }

    /* ── قیمت‌گذاری و امنیت ──────────────────────────────────────────── */

    async function savePricing() {
        const btn = el('btn_pricing_save');
        setBusy(btn, true, 'در حال ذخیره…');
        const trading = {};
        M.$$('[data-pricing]').forEach((input) => { trading[input.dataset.pricing] = Number(input.value); });
        const tf = el('pr_tf');
        const reqAi = el('pr_require_ai');
        const reqMtf = el('pr_require_mtf');
        const btcFilter = el('pr_btc_filter');
        if (tf) { trading.timeframe = tf.value; }
        const tfs = M.$$('.pr_tf_multi').filter((c) => c.checked).map((c) => c.value);
        if (tfs.length) { trading.timeframes = tfs; }
        if (reqAi) { trading.require_ai_agreement = reqAi.checked; }
        if (reqMtf) { trading.require_mtf = reqMtf.checked; }
        if (btcFilter) { trading.btc_filter = btcFilter.checked; }
        try {
            await M.api('api/settings_save.php', { trading: trading });
            M.toast('پارامترهای موتور سیگنال و ریسک ذخیره شد', 'ok');
        } catch (err) {
            M.toast(err.message, 'bad', 6000);
        } finally {
            setBusy(btn, false);
        }
    }

    async function savePassword() {
        const value = el('new_pass').value;
        if (value.length < 8) {
            M.toast('رمز باید حداقل ۸ نویسه باشد.', 'warn');
            return;
        }
        const btn = el('btn_pass_save');
        setBusy(btn, true, 'در حال تغییر…');
        try {
            await M.api('api/settings_save.php', { security: { password: value } });
            el('new_pass').value = '';
            M.toast('رمز تغییر کرد. برای اطمینان دوباره وارد شوید.', 'ok', 6000);
        } catch (err) {
            M.toast(err.message, 'bad', 6000);
        } finally {
            setBusy(btn, false);
        }
    }

    async function runFullHealth() {
        const btn = el('btn_health_all');
        setBusy(btn, true, 'در حال بررسی…');
        try {
            const data = await M.api('api/health.php', null, { method: 'GET' });
            let html = '<div class="stack">';
            Object.keys(data.checks).forEach((key) => {
                const c = data.checks[key];
                html += '<div class="audit audit--' + (c.ok ? 'ok' : 'critical') + '">' +
                    '<i class="fa-solid ' + (c.ok ? 'fa-circle-check' : 'fa-circle-xmark') + '"></i>' +
                    '<div><p class="audit__title">' + M.escapeHtml(c.label) + '</p>' +
                    '<p class="audit__detail mono">' + M.escapeHtml(String(c.value)) + '</p></div></div>';
            });
            html += '</div>';
            el('security_notes').innerHTML = html;
            M.$$('[data-tab]').forEach((b) => { if (b.dataset.tab === 'security') { b.click(); } });
            M.toast(data.healthy ? 'همه بررسی‌ها موفق بود' : 'برخی بررسی‌ها ناموفق بود — جزئیات در تب امنیت', data.healthy ? 'ok' : 'warn', 5500);
        } catch (err) {
            M.toast(err.message, 'bad', 6000);
        } finally {
            setBusy(btn, false);
        }
    }

    /* ── راه‌اندازی ─────────────────────────────────────────────────── */

    document.addEventListener('DOMContentLoaded', () => {
        initTabs();

        el('btn_db_test').addEventListener('click', testDb);
        el('btn_db_save').addEventListener('click', saveDb);
        el('btn_db_install').addEventListener('click', installTables);
        el('btn_ai_test_all').addEventListener('click', testAllProviders);
        el('btn_ai_save').addEventListener('click', saveProviders);
        el('btn_autoroute').addEventListener('click', autoRoute);
        el('btn_route_save').addEventListener('click', saveRouting);
        el('btn_pricing_save').addEventListener('click', savePricing);
        el('btn_pass_save').addEventListener('click', savePassword);
        el('btn_health_all').addEventListener('click', runFullHealth);

        M.$$('.provider-card [data-action="test"]').forEach((btn) => {
            btn.addEventListener('click', () => testProvider(btn.closest('.provider-card')));
        });

        // چک خودکار سلامت هنگام ورود
        M.refreshSystemStatus();
    });
}(window, document));
