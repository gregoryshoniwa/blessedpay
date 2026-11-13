/*! BlessedPay pop-up checkout · v1.0
 *
 *  <script src="https://pay.blessluxe.com/js/blessedpay.js"></script>
 *  BlessedPay.open({ url: payment.redirect_url, onSuccess: p => …, onCancel: p => … })
 *
 *  The checkout opens in a secure frame over your page, so the shopper never
 *  leaves. It closes itself when the payment is paid or cancelled and your
 *  callbacks run; then, unless redirect: false, the browser goes to the
 *  payment's return_url exactly as the full-page checkout would.
 *
 *  Treat onSuccess as a HINT. Before you hand anything over, confirm the
 *  payment from your server: the signed callback, or GET /api/v1/payments/{id}.
 *
 *  Options: url (required), redirect (default true), history (default false:
 *  true makes the browser's Back button close the pop-up), onSuccess, onCancel,
 *  onClose, onError.
 *  Declarative: <button data-blessedpay-url="…redirect_url…" data-blessedpay-redirect="false">
 */
(function (w, d) {
    'use strict';
    if (w.BlessedPay && w.BlessedPay.version) { return; }

    var VERSION = '1.0.0';
    var CSS = '.bp-overlay{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.72);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);opacity:0;transition:opacity .18s ease}'
        + '.bp-overlay.bp-on{opacity:1}'
        + '.bp-dialog{position:relative;width:100%;max-width:460px;height:var(--bp-h,620px);max-height:92vh;max-height:92dvh;overflow:hidden;background:#fff;box-shadow:0 40px 100px -30px rgba(0,0,0,.9),0 0 0 1px rgba(255,255,255,.14);transform:translateY(14px) scale(.985);transition:transform .22s cubic-bezier(.2,.8,.2,1),height .2s ease}'
        + '.bp-overlay.bp-on .bp-dialog{transform:none}'
        + '.bp-frame{display:block;width:100%;height:100%;border:0;background:transparent;opacity:0;transition:opacity .2s ease}.bp-frame.bp-ready{opacity:1}'
        + '.bp-close{position:absolute;top:0;right:0;z-index:2;width:44px;height:44px;border:0;background:#141414;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;transition:background .15s,color .15s}'
        + '.bp-close:hover,.bp-close:focus-visible{background:#e0b03a;color:#000;outline:none}.bp-close svg{width:18px;height:18px}'
        + '.bp-load{position:absolute;inset:0;z-index:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:24px;text-align:center;color:#5c5c5c;font:500 14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#fff}'
        + '.bp-spin{width:36px;height:36px;border-radius:50%;border:3px solid rgba(128,128,128,.3);border-top-color:#e0b03a;animation:bp-spin .8s linear infinite}@keyframes bp-spin{to{transform:rotate(360deg)}}'
        + '.bp-load a{color:#e9bf4a;font-weight:600}'
        + '@media (max-width:639px){.bp-overlay{padding:0;align-items:stretch}.bp-dialog{max-width:none;height:100%!important;max-height:100%;max-height:100dvh;box-shadow:none}}'
        + '@media (prefers-reduced-motion:reduce){.bp-overlay,.bp-dialog,.bp-frame{transition:none}.bp-spin{animation-duration:2s}}'
        + 'body.bp-lock{overflow:hidden!important;touch-action:none}';

    var active = null;

    function el(tag, cls, html) { var e = d.createElement(tag); if (cls) { e.className = cls; } if (html != null) { e.innerHTML = html; } return e; }
    function call(fn, a) { if (typeof fn === 'function') { try { fn(a); } catch (e) { setTimeout(function () { throw e; }); } } }
    function originOf(url) { var a = d.createElement('a'); a.href = url; return a.protocol + '//' + a.host; }
    function withQuery(url, params) {
        var q = [];
        for (var k in params) { if (params[k] != null && params[k] !== '') { q.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k])); } }
        if (!q.length) { return url; }
        var hash = '', i = url.indexOf('#');
        if (i >= 0) { hash = url.slice(i); url = url.slice(0, i); }
        return url + (url.indexOf('?') >= 0 ? '&' : '?') + q.join('&') + hash;
    }
    function escapeAttr(s) { return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

    function open(opts) {
        opts = opts || {};
        if (!opts.url) { throw new Error('BlessedPay.open: url (the payment\'s redirect_url) is required'); }
        if (active) { return active.handle; }
        if (w.location.origin === 'null' || !w.location.origin) {
            // No origin to give the gateway (a file:// page): use the full-page checkout.
            w.location.assign(opts.url);
            return null;
        }
        if (!d.getElementById('blessedpay-style')) { var st = el('style'); st.id = 'blessedpay-style'; st.textContent = CSS; d.head.appendChild(st); }

        var gateway = originOf(opts.url);
        var src = withQuery(opts.url, { embed: w.location.origin });

        var overlay = el('div', 'bp-overlay');
        var dialog = el('div', 'bp-dialog');
        dialog.setAttribute('role', 'dialog'); dialog.setAttribute('aria-modal', 'true'); dialog.setAttribute('aria-label', 'Secure payment');

        var load = el('div', 'bp-load', '<div class="bp-spin" aria-hidden="true"></div><div>Opening secure checkout…</div>');
        var close = el('button', 'bp-close', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="square" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>');
        close.type = 'button'; close.setAttribute('aria-label', 'Close payment');
        var frame = el('iframe', 'bp-frame');
        frame.src = src; frame.title = 'Secure checkout'; frame.setAttribute('allow', 'payment *; clipboard-write');

        dialog.appendChild(close); dialog.appendChild(load); dialog.appendChild(frame); overlay.appendChild(dialog);
        d.body.appendChild(overlay);
        var scrollY = w.scrollY || 0;
        d.body.classList.add('bp-lock');
        var opener = d.activeElement;
        var useHistory = opts.history === true;
        if (useHistory) { try { w.history.pushState(Object.assign({}, w.history.state || {}, { blessedpay: true }), ''); } catch (e) { useHistory = false; } }
        requestAnimationFrame(function () { overlay.classList.add('bp-on'); frame.focus(); });

        var done = false, resolveP, promise = new Promise(function (r) { resolveP = r; });
        var ready = false, readyTimer = setTimeout(function () {
            if (ready) { return; }
            load.innerHTML = '<div>This is taking longer than it should.</div><a href="' + escapeAttr(opts.url) + '" target="_blank" rel="noopener">Open the secure checkout in a new tab</a>';
            call(opts.onError, { code: 'load_timeout', message: 'The checkout did not load in time' });
        }, 15000);

        function post(type, data) { try { frame.contentWindow.postMessage({ source: 'blessedpay-host', type: type, data: data || {} }, gateway); } catch (e) { /* frame gone */ } }
        function finish(result) {
            if (done) { return; }
            done = true;
            clearTimeout(readyTimer);
            w.removeEventListener('message', onMessage); d.removeEventListener('keydown', onKey); w.removeEventListener('popstate', onPop);
            overlay.classList.remove('bp-on');
            setTimeout(function () { if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); } }, 180);
            d.body.classList.remove('bp-lock'); w.scrollTo(0, scrollY);
            if (opener && opener.focus) { try { opener.focus(); } catch (e) { /* gone */ } }
            if (useHistory && !result.fromHistory && w.history.state && w.history.state.blessedpay) { try { w.history.back(); } catch (e) { /* ignore */ } }
            active = null;
            resolveP(result);
        }
        function requestClose(fromHistory) {
            // Let the checkout tidy up first; close anyway if it doesn't answer.
            post('close-request');
            setTimeout(function () { if (!done) { finish({ status: 'closed', fromHistory: !!fromHistory }); call(opts.onClose); } }, 900);
        }
        function onMessage(e) {
            // Only our own frame, on the gateway's own origin, may talk to this page.
            if (e.origin !== gateway || e.source !== frame.contentWindow) { return; }
            var m = e.data || {};
            if (m.source !== 'blessedpay-checkout') { return; }
            var data = m.data || {};
            switch (m.type) {
                case 'ready': ready = true; clearTimeout(readyTimer); frame.classList.add('bp-ready'); load.style.display = 'none'; break;
                case 'resize': if (w.innerWidth >= 640 && data.height) { dialog.style.setProperty('--bp-h', Math.max(360, Math.ceil(data.height)) + 'px'); } break;
                case 'success':
                    call(opts.onSuccess, data.payment);
                    finish({ status: 'success', payment: data.payment });
                    if (opts.redirect !== false && data.return_url) { w.location.assign(data.return_url); }
                    break;
                case 'cancel':
                    finish({ status: 'cancelled', payment: data.payment });
                    call(opts.onCancel, data.payment);
                    if (opts.redirect !== false && data.return_url) { w.location.assign(data.return_url); }
                    break;
                case 'failed': call(opts.onError, { code: 'payment_failed', payment: data.payment, message: data.message }); break;
                // The bank's own page refuses to be framed, so the whole tab goes there. https only.
                case 'redirect': if (/^https:\/\//i.test(data.url || '') || originOf(data.url || '') === gateway) { w.location.assign(data.url); } break;
                case 'close': finish({ status: 'closed' }); call(opts.onClose); break;
            }
        }
        function onKey(e) { if (e.key === 'Escape') { requestClose(false); } }
        function onPop() { if (!done) { requestClose(true); } }
        w.addEventListener('message', onMessage); d.addEventListener('keydown', onKey);
        if (useHistory) { w.addEventListener('popstate', onPop); }
        close.addEventListener('click', function () { requestClose(false); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) { requestClose(false); } });

        var handle = { close: function () { requestClose(false); }, promise: promise, frame: frame };
        active = { handle: handle };
        return handle;
    }

    d.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-blessedpay-url]') : null;
        if (!t) { return; }
        e.preventDefault();
        open({ url: t.dataset.blessedpayUrl, redirect: t.dataset.blessedpayRedirect !== 'false', history: t.dataset.blessedpayHistory === 'true' });
    });

    w.BlessedPay = { version: VERSION, open: open, close: function () { if (active) { active.handle.close(); } }, isOpen: function () { return !!active; } };
})(window, document);
