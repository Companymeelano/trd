/**
 * پنل هوشمند میلانو — هسته جاوااسکریپت مشترک
 * Meelano Smart Panel — shared JS core
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
(function (window, document) {
    'use strict';

    const CFG = window.MEELANO || { base: '', csrf: '' };

    /* ── ابزارها ─────────────────────────────────────────────────────── */

    const $ = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

    const FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    function toFa(value) {
        return String(value).replace(/[0-9]/g, (d) => FA_DIGITS[Number(d)]);
    }

    function money(value) {
        const n = Math.round(Number(value) || 0);
        return n.toLocaleString('en-US');
    }

    function moneyFa(value) {
        return toFa(money(value));
    }

    function escapeHtml(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function debounce(fn, wait) {
        let timer = null;
        return function () {
            const args = arguments;
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(null, args), wait || 350);
        };
    }

    /** خواندن عدد از ورودی قیمتی (حذف کاما). */
    function readPrice(el) {
        if (!el) return 0;
        const raw = String(el.value || '').replace(/[^\d.\-]/g, '');
        const n = Number(raw);
        return isFinite(n) ? n : 0;
    }

    /** نوشتن قیمت با جداکننده هزارگان. */
    function writePrice(el, value) {
        if (!el) return;
        el.value = money(value);
    }

    function formatPriceInput(input) {
        const digits = String(input.value).replace(/\D/g, '');
        input.value = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /* ── نوتیفیکیشن ──────────────────────────────────────────────────── */

    const ICONS = { ok: 'fa-circle-check', bad: 'fa-circle-exclamation', warn: 'fa-triangle-exclamation', info: 'fa-circle-info' };

    function toast(message, type, timeout) {
        const kind = type || 'info';
        const stack = $('#toast_stack');
        if (!stack) { return; }
        const el = document.createElement('div');
        el.className = 'toast toast--' + kind;
        el.innerHTML = '<i class="fa-solid ' + (ICONS[kind] || ICONS.info) + '"></i><div>' + escapeHtml(message) + '</div>';
        stack.appendChild(el);
        setTimeout(() => {
            el.style.transition = 'opacity .3s, transform .3s';
            el.style.opacity = '0';
            el.style.transform = 'translateX(-18px)';
            setTimeout(() => el.remove(), 320);
        }, timeout || 4200);
    }

    /* ── HTTP ────────────────────────────────────────────────────────── */

    function apiUrl(path) {
        const base = CFG.base ? CFG.base.replace(/\/$/, '') : '';
        return base + '/' + String(path).replace(/^\//, '');
    }

    async function api(path, payload, options) {
        const opts = options || {};
        const init = {
            method: opts.method || 'POST',
            headers: { 'X-CSRF-Token': CFG.csrf, 'Accept': 'application/json' },
            credentials: 'same-origin',
        };
        if (payload !== undefined && payload !== null) {
            if (payload instanceof FormData) {
                init.body = payload;
            } else {
                init.headers['Content-Type'] = 'application/json';
                init.body = JSON.stringify(payload);
            }
        }
        let res;
        try {
            res = await fetch(apiUrl(path), init);
        } catch (err) {
            throw new Error('خطای شبکه — سرور پاسخ نداد. (' + err.message + ')');
        }
        const text = await res.text();
        let data;
        try {
            data = text ? JSON.parse(text) : {};
        } catch (err) {
            throw new Error('پاسخ سرور JSON معتبر نبود (HTTP ' + res.status + ').');
        }
        if (!res.ok && data && !data.ok) {
            const err = new Error(data.error || ('خطای HTTP ' + res.status));
            err.status = res.status;
            err.data = data;
            throw err;
        }
        return data;
    }

    /* ── مودال پیشرفت ────────────────────────────────────────────────── */

    const modal = {
        open(title, status) {
            const box = $('#ai_modal');
            if (!box) return;
            box.hidden = false;
            $('#ai_modal_title').textContent = title || 'در حال پردازش…';
            $('#ai_modal_status').textContent = status || '';
            const log = $('#ai_modal_log');
            if (log) log.innerHTML = '';
            this.set(0);
        },
        set(percent, status, provider) {
            const pct = Math.max(0, Math.min(100, Math.round(Number(percent) || 0)));
            const bar = $('#ai_modal_bar');
            if (bar) bar.style.width = pct + '%';
            const label = $('#ai_modal_percent');
            if (label) label.textContent = toFa(pct) + '٪';
            if (status) { const s = $('#ai_modal_status'); if (s) s.textContent = status; }
            if (provider) { const p = $('#ai_modal_provider'); if (p) p.textContent = provider; }
        },
        log(text, kind) {
            const list = $('#ai_modal_log');
            if (!list) return;
            const li = document.createElement('li');
            li.className = kind || '';
            li.textContent = text;
            list.appendChild(li);
            list.scrollTop = list.scrollHeight;
        },
        close(delay) {
            const box = $('#ai_modal');
            if (!box) return;
            setTimeout(() => { box.hidden = true; }, delay || 220);
        },
    };

    /* ── وضعیت سامانه (چک خودکار هنگام ورود) ────────────────────────── */

    async function refreshSystemStatus() {
        const dbChip = $('#nav_db_chip');
        const aiChip = $('#nav_ai_chip');
        try {
            const data = await api('api/health.php', null, { method: 'GET' });
            if (dbChip) {
                const ok = !!(data.checks && data.checks.db && data.checks.db.ok);
                const complete = !!(data.install && data.install.complete);
                dbChip.className = 'chip ' + (ok && complete ? 'chip--ok' : (ok ? 'chip--warn' : 'chip--bad'));
                dbChip.innerHTML = '<i class="fa-solid fa-database"></i><b>' +
                    escapeHtml(ok && complete ? 'دیتابیس متصل' : (ok ? 'جدول‌ها ناقص' : 'دیتابیس قطع')) + '</b>';
                dbChip.title = (data.checks && data.checks.db ? data.checks.db.value : '') +
                    (data.install ? ' — ' + data.install.percent + '% جدول‌ها' : '');
            }
            if (aiChip) {
                const providers = data.providers || {};
                const ids = Object.keys(providers);
                const healthy = ids.filter((id) => providers[id].ok).length;
                const configured = ids.filter((id) => providers[id].configured).length;
                aiChip.className = 'chip ' + (healthy > 0 ? 'chip--ok' : (configured > 0 ? 'chip--warn' : 'chip--bad'));
                aiChip.innerHTML = '<i class="fa-solid fa-microchip"></i><b>AI ' + toFa(healthy) + '/' + toFa(configured) + '</b>';
            }
            document.dispatchEvent(new CustomEvent('meelano:health', { detail: data }));
            return data;
        } catch (err) {
            if (dbChip) {
                dbChip.className = 'chip chip--bad';
                dbChip.innerHTML = '<i class="fa-solid fa-database"></i><b>بررسی ناموفق</b>';
            }
            return null;
        }
    }

    /* ── ساعت فوتر ──────────────────────────────────────────────────── */

    function startFooterClock() {
        const el = $('#footer_clock');
        if (!el) return;
        const tick = () => {
            const now = new Date();
            const t = now.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            el.textContent = t + ' — ' + now.toLocaleDateString('fa-IR');
        };
        tick();
        setInterval(tick, 1000);
    }

    /* ── راه‌اندازی ─────────────────────────────────────────────────── */

    document.addEventListener('DOMContentLoaded', () => {
        startFooterClock();
        $$('input[data-price]').forEach((el) => {
            el.addEventListener('input', () => formatPriceInput(el));
            formatPriceInput(el);
        });
        refreshSystemStatus();
    });

    // بستن مودال با کلیک پس‌زمینه یا Escape
    document.addEventListener('click', (e) => {
        const target = e.target.closest('[data-close]');
        if (target && target.closest('#ai_modal')) {
            const box = $('#ai_modal');
            if (box) box.hidden = true;
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const box = $('#ai_modal');
            if (box) box.hidden = true;
        }
    });

    /* ── خروجی عمومی ────────────────────────────────────────────────── */

    window.Meelano = {
        $: $, $$: $$,
        api: api, apiUrl: apiUrl,
        toast: toast, modal: modal,
        money: money, moneyFa: moneyFa, toFa: toFa,
        escapeHtml: escapeHtml, debounce: debounce,
        readPrice: readPrice, writePrice: writePrice,
        formatPriceInput: formatPriceInput,
        refreshSystemStatus: refreshSystemStatus,
        csrf: CFG.csrf, base: CFG.base,
    };
}(window, document));
