/*!
 * ZapPay.js — embed ZapPay checkout on your own website
 * Full docs: Developer Portal -> API Integration (api.html) in your ZapPay dashboard.
 *
 * Usage:
 *   <script src="https://YOUR-ZAPPAY-DOMAIN/zappay-pay.js"></script>
 *   <script>
 *     ZapPay.setCallbacks({
 *       onSuccess: (order) => alert('Paid! Order ' + order.order_id),
 *       onFailed:  (order) => alert('Payment failed'),
 *       onTimeout: (order) => alert('Payment timed out'),
 *     });
 *     ZapPay.createOrder({
 *       zap_api: 'YOUR_ZAPAPI_KEY',
 *       amount: 100,
 *       title: 'Order #123',
 *     });
 *   </script>
 *
 * BUGFIX (this version): earlier builds derived the backend URL from this
 * script's OWN <script src> ("getApiBase" scanned document.scripts for a
 * tag containing 'zappay-pay.js' and used everything before that as the
 * API base). That's wrong for this project's setup — this file is hosted
 * on the FRONTEND domain, but the API lives on a SEPARATE backend domain
 * — so it silently called the wrong origin (or, if the tag didn't match
 * for any reason — inlined code, a bundler, a proxy, a sandboxed test
 * page — silently fell back to a relative URL against whatever page
 * embedded it). Either way: "TypeError: Failed to fetch", 100% of the
 * time, regardless of who wrote the integration. Fixed by hardcoding the
 * real backend origin below — no detection, no guessing, no silent
 * failure mode. If ZapPay's backend ever moves to a new domain, update
 * the single constant below (or use the data-api override).
 */
(function (global) {
  // ZapPay's backend. This is intentionally NOT derived from where this
  // widget file itself is hosted (that's your frontend) — the API is a
  // separate server. Update this one line if the backend domain changes.
  const DEFAULT_API_BASE = 'https://paymentbackend-ruby.vercel.app';

  function getApiBase() {
    // Optional explicit override, e.g.:
    // <script src=".../zappay-pay.js" data-api="https://api.yourdomain.com"></script>
    const scripts = document.getElementsByTagName('script');
    for (let i = 0; i < scripts.length; i++) {
      const s = scripts[i];
      if (s.src && s.src.indexOf('zappay-pay.js') !== -1 && s.dataset && s.dataset.api) {
        return s.dataset.api.replace(/\/+$/, '');
      }
    }
    return DEFAULT_API_BASE;
  }

  const API_BASE = getApiBase();
  const POLL_INTERVAL_MS = 2500;
  const MAX_POLLS = 48; // ~2 minutes

  let callbacks = { onSuccess: null, onFailed: null, onTimeout: null };
  let popupRef = null;
  let pollTimer = null;

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  function closePopup() {
    try { if (popupRef && !popupRef.closed) popupRef.close(); } catch (e) {}
    popupRef = null;
  }

  function poll(zapApiKey, orderId, amount) {
    let attempts = 0;
    pollTimer = setInterval(async function () {
      attempts++;
      // If the customer closed the payment window themselves, give the
      // backend a couple more checks (the webhook may still land a
      // moment later) before giving up.
      const popupClosed = !popupRef || popupRef.closed;

      try {
        const res = await fetch(`${API_BASE}/api/developer/order-status/${orderId}`, {
          method: 'GET',
          headers: { 'X-ZapAPI-Key': zapApiKey },
        });
        const data = await res.json();
        const status = data && data.data && data.data.status;

        if (status === 'success') {
          stopPolling(); closePopup();
          callbacks.onSuccess && callbacks.onSuccess({ order_id: orderId, amount });
          return;
        }
        if (status === 'failed') {
          stopPolling(); closePopup();
          callbacks.onFailed && callbacks.onFailed({ order_id: orderId, amount });
          return;
        }
      } catch (e) { /* network hiccup — keep polling */ }

      if (popupClosed && attempts > 2) {
        stopPolling();
        callbacks.onTimeout && callbacks.onTimeout({ order_id: orderId, amount });
        return;
      }
      if (attempts >= MAX_POLLS) {
        stopPolling(); closePopup();
        callbacks.onTimeout && callbacks.onTimeout({ order_id: orderId, amount });
      }
    }, POLL_INTERVAL_MS);
  }

  const ZapPay = {
    /**
     * Register what happens after a payment resolves. Call this once,
     * anywhere on the page, before createOrder().
     */
    setCallbacks(cb) {
      callbacks = Object.assign({}, callbacks, cb || {});
    },

    /**
     * Create an order and open the ZapPay checkout as a popup.
     * opts: { zap_api, amount, title?, customer_mobile?, redirect_url? }
     * amount must be between ₹1 and ₹5,000.
     *
     * BUGFIX: this widget always polls order-status itself (see poll()
     * above) instead of relying on Zap's embedded-overlay redirect
     * handling, but it never told the backend where the customer should
     * land after paying. Without a redirect_url, the backend/gateway
     * fell back to ZapPay's OWN site as the redirect target — so a paid
     * customer could end up bounced to zappay.shop instead of back to
     * this merchant's page. Pass redirect_url (defaults to the current
     * page) so the customer always comes back here.
     */
    async createOrder(opts) {
      opts = opts || {};
      if (!opts.zap_api) { console.error('ZapPay.js: zap_api key is required'); return; }
      if (!opts.amount) { console.error('ZapPay.js: amount is required'); return; }

      try {
        const res = await fetch(`${API_BASE}/api/developer/create-order`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            zap_api: opts.zap_api,
            amount: opts.amount,
            title: opts.title || '',
            customer_mobile: opts.customer_mobile || '',
            redirect_url: opts.redirect_url || window.location.href,
            useEmbedded: true,
          }),
        });
        const data = await res.json();

        if (!data.success) {
          console.error('ZapPay.js:', data.message);
          callbacks.onFailed && callbacks.onFailed({ error: data.message });
          return;
        }

        const { order_id, payment_url, amount } = data.data;
        popupRef = window.open(payment_url, 'ZapPayCheckout', 'width=430,height=700,menubar=no,toolbar=no');
        if (!popupRef) {
          // Popup blocked by the browser — fall back to a same-tab redirect.
          window.location.href = payment_url;
          return;
        }
        poll(opts.zap_api, order_id, amount);
      } catch (e) {
        console.error('ZapPay.js: network error creating order', e);
        callbacks.onFailed && callbacks.onFailed({ error: 'network_error' });
      }
    },
  };

  global.ZapPay = ZapPay;
})(window);
