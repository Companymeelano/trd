/**
 * میلانو تریدینگ اینتلیجنس — منطق پنل اطلاع‌رسانی چندکاناله (نسخهٔ ۵٫۳).
 * تلگرام · بله · روبیکا · واتساپ · پیامک — وضعیت/ذخیره/تست سلامت/
 * ارسال تست/پیش‌نمایش پیام/گزارش کیف/تاریخچهٔ ارسال.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
(function (window, document) {
    'use strict';

    const M = window.Meelano;
    if (!M) { return; }

    const el = (id) => M.$('#' + id);
    const CHANNEL_LABELS = {
        telegram: 'تلگرام', bale: 'بله', rubika: 'روبیکا', whatsapp: 'واتساپ', sms: 'پیامک'
    };
    const EVENT_LABELS = {
        signal: 'سیگنال', trade_opened: 'بازشدن پوزیشن', trade_closed: 'بستن پوزیشن',
        wallet_report: 'گزارش کیف', test: 'پیام تست'
    };

    function setBusy(btn, busy, label) {
        if (!btn) { return; }
        if (busy) { btn.dataset.orig = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + (label || '…'); }
        else { btn.disabled = false; if (btn.dataset.orig) { btn.innerHTML = btn.dataset.orig; } }
    }

    function esc(s) {
        return M.escapeHtml(String(s ?? ''));
    }

    /* ═══ وضعیت → UI ═══════════════════════════════════════════════════ */

    function renderStatus(data) {
        if (!data || !data.ok) { return; }
        el('nt_enabled').checked = !!data.enabled;
        el('nt_min_tier').value = data.min_tier || 'B';
        el('nt_throttle').value = data.throttle_sec ?? 45;
        el('nt_report_on_close').checked = !!data.report_on_close;

        const chip = el('nt_master_chip');
        chip.textContent = data.enabled ? 'اطلاع‌رسانی: روشن' : 'اطلاع‌رسانی: خاموش';
        chip.className = 'chip ' + (data.enabled ? 'chip--ok' : 'chip--warn');

        Object.keys(data.channels || {}).forEach(function (cid) {
            const ch = data.channels[cid];

            const en = document.querySelector('[data-nt-enable="' + cid + '"]');
            if (en) { en.checked = !!ch.enabled; }

            Object.keys(ch.fields || {}).forEach(function (fid) {
                const input = document.querySelector('[data-nt-field="' + cid + '|' + fid + '"]');
                if (!input) { return; }
                const f = ch.fields[fid];
                input.value = f.value || '';
                if (f.set && f.masked) {
                    input.placeholder = 'ذخیره شده: ' + f.masked + ' — خالی بماند تا تغییر نکند';
                }
                const badge = document.querySelector('[data-secret-badge="' + cid + '_' + fid + '"]');
                if (badge) { badge.hidden = !f.set; }
            });

            Object.keys(ch.events || {}).forEach(function (ev) {
                const box = document.querySelector('[data-nt-event="' + cid + '|' + ev + '"]');
                if (box) { box.checked = !!ch.events[ev]; }
            });

            const dot = document.querySelector('[data-dot="' + cid + '"]');
            if (dot) {
                dot.className = 'nt-dot ' + (ch.enabled ? (ch.configured ? 'nt-dot--ok' : 'nt-dot--warn') : 'nt-dot--off');
                dot.title = ch.enabled ? (ch.configured ? 'فعال و پیکربندی‌شده' : 'فعال ولی پیکربندی ناقص') : 'غیرفعال';
            }

            const last = document.querySelector('[data-last="' + cid + '"]');
            if (last) {
                last.textContent = ch.last
                    ? 'آخرین ارسال: ' + (ch.last.ok ? '✅ ' : '❌ ') + (EVENT_LABELS[ch.last.event] || ch.last.event) + ' — ' + ch.last.at
                    : 'آخرین ارسال: —';
                if (ch.last && !ch.last.ok && ch.last.error) { last.title = ch.last.error; }
            }
        });

        renderLog(data.log || []);
        el('nt_refresh_stamp').textContent = 'به‌روزرسانی: ' + new Date().toLocaleTimeString('fa-IR');
    }

    function renderLog(rows) {
        const body = el('nt_log_body');
        if (!body) { return; }
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-dim)">هنوز چیزی ارسال نشده است.</td></tr>';
            return;
        }
        body.innerHTML = rows.map(function (r) {
            return '<tr>'
                + '<td style="white-space:nowrap;color:var(--text-dim)">' + esc(r.at) + '</td>'
                + '<td>' + esc(CHANNEL_LABELS[r.channel] || r.channel) + '</td>'
                + '<td>' + esc(EVENT_LABELS[r.event] || r.event) + '</td>'
                + '<td>' + (r.ok ? '<span class="chip chip--ok">موفق</span>' : '<span class="chip chip--bad">ناموفق</span>') + '</td>'
                + '<td style="max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(r.error || r.preview || '') + '">'
                    + esc(r.error ? ('خطا: ' + r.error) : (r.preview || '—')) + '</td>'
                + '</tr>';
        }).join('');
    }

    async function loadStatus() {
        try {
            const data = await M.api('api/notify.php', { action: 'status' });
            renderStatus(data);
        } catch (err) { /* بی‌دیتابیس: بعداً دوباره */ }
    }

    /* ═══ جمع‌آوری و ذخیره ═════════════════════════════════════════════ */

    function collectConfig() {
        const channels = {};
        document.querySelectorAll('[data-nt-enable]').forEach(function (box) {
            const cid = box.getAttribute('data-nt-enable');
            if (!channels[cid]) { channels[cid] = { enabled: false, events: {} }; }
            channels[cid].enabled = box.checked;
        });
        document.querySelectorAll('[data-nt-field]').forEach(function (input) {
            const parts = input.getAttribute('data-nt-field').split('|');
            if (!channels[parts[0]]) { channels[parts[0]] = { enabled: false, events: {} }; }
            channels[parts[0]][parts[1]] = input.value.trim();
        });
        document.querySelectorAll('[data-nt-event]').forEach(function (box) {
            const parts = box.getAttribute('data-nt-event').split('|');
            if (!channels[parts[0]]) { channels[parts[0]] = { enabled: false, events: {} }; }
            channels[parts[0]].events[parts[1]] = box.checked;
        });
        return {
            enabled: el('nt_enabled').checked,
            min_tier: el('nt_min_tier').value,
            throttle_sec: Number(el('nt_throttle').value) || 0,
            report_on_close: el('nt_report_on_close').checked,
            channels: channels
        };
    }

    async function saveConfig() {
        const btn = el('btn_nt_save');
        setBusy(btn, true, 'ذخیره…');
        try {
            const data = await M.api('api/notify.php', { action: 'save', config: collectConfig() });
            if (data.ok) {
                M.toast('تنظیمات اطلاع‌رسانی ذخیره شد', 'ok');
                await loadStatus();
            } else {
                M.toast(data.error || 'خطا در ذخیره', 'bad');
            }
        } catch (err) {
            M.toast('ارتباط برقرار نشد', 'bad');
        } finally { setBusy(btn, false); }
    }

    /* ═══ تست سلامت / ارسال تست / پیش‌نمایش / گزارش ═══════════════════ */

    function resultBox(cid) {
        return document.querySelector('[data-result="' + cid + '"]');
    }

    async function checkChannel(cid, btn) {
        setBusy(btn, true, 'بررسی…');
        const box = resultBox(cid);
        if (box) { box.innerHTML = '<span class="help">در حال بررسی اتصال…</span>'; }
        try {
            const data = await M.api('api/notify.php', { action: 'check', channel: cid });
            const info = data.info && Object.keys(data.info).length
                ? ' (' + Object.keys(data.info).map(function (k) { return data.info[k]; }).join(' · ') + ')' : '';
            if (box) {
                box.innerHTML = data.ok
                    ? '<div class="nt-ok"><i class="fa-solid fa-circle-check"></i> اتصال سالم است' + esc(info) + '</div>'
                    : '<div class="nt-bad"><i class="fa-solid fa-circle-xmark"></i> ' + esc(data.error || 'اتصال برقرار نشد') + '</div>';
            }
        } catch (err) {
            if (box) { box.innerHTML = '<div class="nt-bad">ارتباط با سرور برقرار نشد.</div>'; }
        } finally { setBusy(btn, false); }
    }

    async function testChannel(cid, btn) {
        setBusy(btn, true, 'ارسال…');
        const box = resultBox(cid);
        if (box) { box.innerHTML = '<span class="help">در حال ارسال پیام تست…</span>'; }
        try {
            const data = await M.api('api/notify.php', { action: 'test', channel: cid });
            if (box) {
                box.innerHTML = data.ok
                    ? '<div class="nt-ok"><i class="fa-solid fa-circle-check"></i> پیام تست ارسال شد — کانال/گوشی خود را بررسی کنید.</div>'
                    : '<div class="nt-bad"><i class="fa-solid fa-circle-xmark"></i> ' + esc(data.error || 'ارسال ناموفق') + '</div>';
            }
            await loadStatus();
        } catch (err) {
            if (box) { box.innerHTML = '<div class="nt-bad">ارتباط با سرور برقرار نشد.</div>'; }
        } finally { setBusy(btn, false); }
    }

    async function previewEvent(ev, btn) {
        setBusy(btn, true);
        try {
            const data = await M.api('api/notify.php', { action: 'preview', event: ev });
            if (data.ok) {
                el('nt_preview_box').textContent = data.text;
            }
        } catch (err) { /* بی‌اثر */ }
        finally { setBusy(btn, false); }
    }

    async function sendReport(btn) {
        setBusy(btn, true, 'ارسال…');
        try {
            const data = await M.api('api/notify.php', { action: 'report' });
            const sent = (data.sent || []).length;
            const failed = (data.failed || []).length;
            if (sent > 0) {
                M.toast('گزارش کیف به ' + sent + ' کانال ارسال شد', 'ok');
            } else {
                M.toast(failed > 0 ? 'ارسال ناموفق: ' + data.failed.join(' | ') : 'هیچ کانال فعالی برای گزارش کیف مشترک نیست', 'bad');
            }
            await loadStatus();
        } catch (err) {
            M.toast('ارتباط برقرار نشد', 'bad');
        } finally { setBusy(btn, false); }
    }

    /* ═══ رویدادها ═════════════════════════════════════════════════════ */

    document.addEventListener('DOMContentLoaded', function () {
        if (!el('nt_channels')) { return; } // صفحهٔ بی‌دیتابیس

        loadStatus();

        el('btn_nt_save').addEventListener('click', saveConfig);
        el('btn_nt_report').addEventListener('click', function () { sendReport(this); });
        el('btn_nt_refresh').addEventListener('click', loadStatus);

        document.querySelectorAll('[data-nt-check]').forEach(function (btn) {
            btn.addEventListener('click', function () { checkChannel(btn.getAttribute('data-nt-check'), btn); });
        });
        document.querySelectorAll('[data-nt-test]').forEach(function (btn) {
            btn.addEventListener('click', function () { testChannel(btn.getAttribute('data-nt-test'), btn); });
        });
        document.querySelectorAll('[data-nt-preview]').forEach(function (btn) {
            btn.addEventListener('click', function () { previewEvent(btn.getAttribute('data-nt-preview'), btn); });
        });

        setInterval(loadStatus, 60000);
    });

})(window, document);
