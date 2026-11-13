<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { get, post } from '../api.js';
import PageHead from '../components/PageHead.vue';
import { can, session } from '../session.js';

// A demo shop for trying the integration. Everything it does goes through the
// real API with a real key, and every request and response is shown. It follows
// the console's Test / Live switch: with "Live keys" it takes REAL payments
// through the bank, so it asks for a tick first and never remembers the key.
const route = useRoute();
const origin = window.location.origin;

const saved = (name, fallback) => {
    try {
        return sessionStorage.getItem(name) || fallback;
    } catch {
        return fallback;
    }
};
const keep = (name, value) => {
    try {
        sessionStorage.setItem(name, value);
    } catch {
        /* private window: just not remembered */
    }
};
const randomHex = (bytes) => Array.from(crypto.getRandomValues(new Uint8Array(bytes)), (b) => b.toString(16).padStart(2, '0')).join('');

const mode = session.mode; // the page is rebuilt when the switch changes
const live = mode === 'live';
const KEY_NAME = `YOUR_${mode.toUpperCase()}_KEY`;
const understood = ref(false);

const apiKey = ref(live ? '' : saved('bp-try-key', ''));
const hookToken = saved('bp-try-hook', randomHex(16));
keep('bp-try-hook', hookToken);
const hookUrl = `${origin}/try/hook/${hookToken}`;

const order = reactive({ item: live ? 'Live test payment' : 'Silk wrap dress', amount: live ? '1.00' : '48.00', currency: 'USD', phone: '' });
const reference = ref('');
const newReference = () => (reference.value = 'TRY-' + Date.now().toString(36).toUpperCase());
newReference();

const busy = ref('');
const problem = ref('');
const payment = ref(null);
const log = ref([]);
const includeKey = ref(false);
const copied = ref('');
const seenCallbacks = new Set();

const keyOk = computed(() => new RegExp(`^sk_${mode}_[a-f0-9]{20,}$`).test(apiKey.value.trim()));
// A key for the other mode: say so, rather than quietly making the wrong kind of payment.
const keyWrongMode = computed(() => apiKey.value.trim().startsWith(live ? 'sk_test_' : 'sk_live_'));
const ready = computed(() => keyOk.value && (!live || understood.value));
const maskedKey = computed(() => (apiKey.value ? apiKey.value.trim().slice(0, 14) + '…' : KEY_NAME));
const rememberKey = () => !live && keep('bp-try-key', apiKey.value.trim()); // a live key stays in memory only

function add(entry) {
    log.value.push({ id: log.value.length + 1, at: new Date(), open: true, ...entry });
}
const pretty = (value) => (typeof value === 'string' ? value : JSON.stringify(value, null, 2));

// ── cURL: the same commands for a terminal or for Postman's Import ▸ Raw text ──
const keyFor = (real) => (real ? apiKey.value.trim() : KEY_NAME);
const createBody = () => ({
    amount: order.amount,
    currency: order.currency,
    reference: reference.value,
    description: order.item,
    return_url: `${origin}/console/try`,
    callback_url: hookUrl,
    ...(order.phone ? { customer: { phone: order.phone } } : {}),
});
function curlCreate(real, body = createBody(), idempotency = reference.value) {
    return [
        `curl --location --request POST '${origin}/api/v1/payments' \\`,
        `--header 'Authorization: Bearer ${keyFor(real)}' \\`,
        `--header 'Idempotency-Key: ${idempotency}' \\`,
        `--header 'Content-Type: application/json' \\`,
        `--header 'Accept: application/json' \\`,
        `--data-raw '${JSON.stringify(body, null, 2).replace(/'/g, "'\\''")}'`,
    ].join('\n');
}
function curlStatus(real, id = payment.value?.id || 'pay_PASTE_THE_ID_HERE') {
    return [
        `curl --location --request GET '${origin}/api/v1/payments/${id}' \\`,
        `--header 'Authorization: Bearer ${keyFor(real)}' \\`,
        `--header 'Accept: application/json'`,
    ].join('\n');
}
const verifySnippet = `// PHP: check a callback really came from BlessedPay before trusting it.
$raw = file_get_contents('php://input');                       // the body exactly as received
preg_match('/^t=(\\d+),v1=([0-9a-f]{64})$/', $_SERVER['HTTP_X_BLESSEDPAY_SIGNATURE'] ?? '', $m);
$expected = hash_hmac('sha256', ($m[1] ?? '') . '.' . $raw, $signingSecret);

if (! $m || ! hash_equals($expected, $m[2]) || abs(time() - (int) $m[1]) > 300) {
    http_response_code(400); exit;                             // not ours, or too old
}
$event = json_decode($raw, true);                              // $event['event'] === 'payment.paid'
http_response_code(200);                                       // the same X-BlessedPay-Event may arrive again: ignore repeats`;

async function copy(text, what) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = what;
        setTimeout(() => (copied.value = ''), 2200);
    } catch {
        /* clipboard blocked: the text is selectable on screen */
    }
}

function downloadPostman() {
    const header = (key, value) => ({ key, value });
    const collection = {
        info: { name: 'BlessedPay API', schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json', description: 'Start a payment, then read it back. Set api_key to a key from the console.' },
        variable: [
            { key: 'base_url', value: origin },
            { key: 'api_key', value: includeKey.value && keyOk.value ? apiKey.value.trim() : KEY_NAME },
            { key: 'payment_id', value: payment.value?.id || '' },
        ],
        item: [
            {
                name: '1. Start a payment',
                event: [{ listen: 'test', script: { type: 'text/javascript', exec: ['const body = pm.response.json();', "if (body.id) { pm.collectionVariables.set('payment_id', body.id); }", "pm.test('payment created', () => pm.expect(pm.response.code).to.be.oneOf([200, 201]));"] } }],
                request: {
                    method: 'POST',
                    header: [header('Authorization', 'Bearer {{api_key}}'), header('Idempotency-Key', '{{$guid}}'), header('Content-Type', 'application/json'), header('Accept', 'application/json')],
                    body: { mode: 'raw', raw: JSON.stringify({ ...createBody(), reference: 'ORDER-{{$timestamp}}' }, null, 2), options: { raw: { language: 'json' } } },
                    url: '{{base_url}}/api/v1/payments',
                },
            },
            {
                name: '2. Read the payment',
                request: { method: 'GET', header: [header('Authorization', 'Bearer {{api_key}}'), header('Accept', 'application/json')], url: '{{base_url}}/api/v1/payments/{{payment_id}}' },
            },
        ],
    };
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([JSON.stringify(collection, null, 2)], { type: 'application/json' }));
    link.download = 'BlessedPay.postman_collection.json';
    link.click();
    URL.revokeObjectURL(link.href);
}

// ── The real API calls, logged ──────────────────────────────────────────────
async function callApi(title, method, path, body, extraHeaders = {}) {
    const headers = { Authorization: `Bearer ${apiKey.value.trim()}`, Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}), ...extraHeaders };
    const started = performance.now();
    let status = 0;
    let data = null;
    try {
        const response = await fetch(origin + path, { method, headers, body: body ? JSON.stringify(body) : undefined, credentials: 'omit' });
        status = response.status;
        data = await response.json().catch(() => null);
    } catch {
        data = { error: 'Could not reach the server.' };
    }
    add({
        kind: 'api',
        title,
        ok: status >= 200 && status < 300,
        request: { line: `${method} ${path}`, headers: { ...headers, Authorization: `Bearer ${maskedKey.value}` }, body },
        response: { status, body: data, ms: Math.round(performance.now() - started) },
        curl: (real) => (method === 'POST' ? curlCreate(real, body, extraHeaders['Idempotency-Key']) : curlStatus(real, path.split('/').pop())),
    });
    return { status, data };
}

async function start() {
    problem.value = '';
    rememberKey();
    const { status, data } = await callApi('Your server starts a payment', 'POST', '/api/v1/payments', createBody(), { 'Idempotency-Key': reference.value });
    if (status !== 200 && status !== 201) {
        problem.value = data?.error?.message || data?.message || Object.values(data?.errors || {})[0]?.[0] || 'The payment could not be started. See the log.';
        return null;
    }
    payment.value = data;
    return data;
}

async function payInPopup() {
    busy.value = 'popup';
    const created = await start();
    busy.value = '';
    if (!created) return;

    add({ kind: 'note', title: 'Your page opens the pop-up', text: `BlessedPay.open({ url: "${created.redirect_url}", redirect: false, onSuccess, onCancel, onClose })` });
    window.BlessedPay.open({
        url: created.redirect_url,
        redirect: false,
        onSuccess: (p) => {
            add({ kind: 'event', title: 'onSuccess ran in your page (a hint only: now confirm from your server)', body: p });
            afterCheckout();
        },
        onCancel: (p) => {
            add({ kind: 'event', title: 'onCancel ran in your page', body: p });
            afterCheckout();
        },
        onClose: () => {
            add({ kind: 'event', title: 'onClose ran in your page: the shopper closed the pop-up without finishing' });
            afterCheckout();
        },
        onError: (e) => add({ kind: 'event', title: 'onError ran in your page', body: { code: e.code, message: e.message } }),
    });
}

async function payFullPage() {
    busy.value = 'page';
    const created = await start();
    busy.value = '';
    if (!created) return;
    add({ kind: 'note', title: 'Your page sends the shopper to the checkout', text: `window.location = "${created.redirect_url}"  (opened in a new tab here so this log stays on screen)` });
    window.open(created.redirect_url, '_blank', 'noopener');
}

async function checkStatus() {
    if (!payment.value) return;
    busy.value = 'status';
    const { data } = await callApi('Your server asks what happened', 'GET', `/api/v1/payments/${payment.value.id}`);
    if (data?.id) payment.value = data;
    busy.value = '';
    await showCallbacks();
    await showServerLog();
}

let after = null;
function afterCheckout() {
    clearTimeout(after);
    after = setTimeout(checkStatus, 600);
}

async function showCallbacks() {
    const { callbacks } = await get(`/try/hook/${hookToken}`).catch(() => ({ callbacks: [] }));
    for (const callback of callbacks) {
        const key = callback.at + callback.headers['X-BlessedPay-Event'];
        if (seenCallbacks.has(key)) continue;
        seenCallbacks.add(key);
        let body = callback.body;
        try {
            body = JSON.parse(callback.body);
        } catch {
            /* show as text */
        }
        add({ kind: 'callback', title: 'BlessedPay calls your server (the signed callback)', ok: callback.signature_valid, request: { line: `POST ${hookUrl.replace(origin, '')}`, headers: callback.headers, body }, signature: callback.signature_valid });
    }
}

async function showServerLog() {
    if (!payment.value) return;
    try {
        const { payment: detail } = await get(`/payments/${payment.value.id}`);
        const existing = log.value.find((e) => e.kind === 'server' && e.paymentId === detail.id);
        const entry = { kind: 'server', paymentId: detail.id, title: `BlessedPay's own log for ${detail.reference}`, events: detail.events };
        if (existing) Object.assign(existing, entry);
        else add(entry);
    } catch {
        /* this user may not view payments: skip */
    }
}

async function makeKey() {
    busy.value = 'key';
    problem.value = '';
    try {
        const data = await post(`/companies/${session.user.company.id}/keys`, { name: 'Try it page', mode });
        apiKey.value = data.plain;
        rememberKey();
    } catch (e) {
        problem.value = e.message;
    } finally {
        busy.value = '';
    }
}

function reset() {
    log.value = [];
    payment.value = null;
    problem.value = '';
    newReference();
}

onMounted(() => {
    if (!window.BlessedPay) {
        const script = document.createElement('script');
        script.src = '/js/blessedpay.js';
        document.head.appendChild(script);
    }
    // Back from the full-page checkout: this is all a shop's return page is given.
    if (route.query.payment_id) {
        payment.value = { id: String(route.query.payment_id), reference: String(route.query.reference || '') };
        add({ kind: 'event', title: 'The shopper came back to your return_url with these in the address (do not trust the status: ask)', body: { payment_id: route.query.payment_id, reference: route.query.reference, status: route.query.status } });
        if (keyOk.value) checkStatus();
    }
});
onBeforeUnmount(() => clearTimeout(after));
</script>

<template>
    <PageHead
        title="Try it"
        :sub="live
            ? 'A demo shop that uses the real API with a live key. Payments here are real, and every request and response is shown.'
            : 'A demo shop that uses the real API with a test key. No money moves, and every request and response is shown.'"
    />

    <div v-if="live" role="note" class="mb-5 border border-line border-l-[6px] border-l-[#9b1c1c] bg-white px-5 py-4">
        <p class="font-semibold">Live: this takes real money.</p>
        <p class="mt-1 max-w-[80ch] text-[#333]">
            A payment made here goes through TN CyberTech Bank and is taken from the card, EcoCash wallet or bank account that pays it, into the company's bank account.
            The bank's fee applies and there is no refund button yet. Use a small amount. To practise without money, switch to "Test keys" at the bottom of the menu.
        </p>
        <label class="mt-3 flex min-h-11 cursor-pointer items-center gap-3 font-medium">
            <input v-model="understood" type="checkbox" class="size-5 accent-ink" />
            I understand these are real payments
        </label>
    </div>

    <div class="grid grid-cols-[minmax(0,1fr)] gap-6 xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
        <!-- The demo shop -->
        <section aria-labelledby="shop-h" class="grid min-w-0 grid-cols-[minmax(0,1fr)] content-start gap-5">
            <div class="border border-line bg-white p-5">
                <h2 id="shop-h" class="text-lg font-semibold">1. Your {{ mode }} key</h2>
                <label class="label mt-3" for="try-key">{{ live ? 'Live' : 'Test' }} API key</label>
                <input id="try-key" v-model.trim="apiKey" class="field font-mono text-sm" type="password" autocomplete="off" :placeholder="`sk_${mode}_…`" aria-describedby="try-key-help" />
                <p id="try-key-help" class="mt-1.5 text-sm" :class="keyWrongMode ? 'font-medium' : 'text-mute'">
                    <template v-if="keyWrongMode">
                        That is a {{ live ? 'test' : 'live' }} key, and the console is showing {{ live ? 'Live' : 'Test' }}. Paste a {{ mode }} key, or change the switch at the bottom of the menu.
                    </template>
                    <template v-else-if="live">Used for this visit only and never remembered. Keys are shown once when created, on your company page.</template>
                    <template v-else>Kept in this browser tab only. Keys are shown once when created, on your company page.</template>
                </p>
                <button v-if="!keyOk && !session.user.is_staff && can('keys.manage')" type="button" class="btn btn-line mt-3" :disabled="busy === 'key'" @click="makeKey">
                    {{ busy === 'key' ? 'Creating…' : `Create a ${mode} key for this page` }}
                </button>
            </div>

            <form class="border border-line bg-white p-5" @submit.prevent="payInPopup">
                <h2 class="text-lg font-semibold">2. The demo shop</h2>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="label" for="try-item">Item</label>
                        <input id="try-item" v-model.trim="order.item" class="field" type="text" maxlength="120" />
                    </div>
                    <div>
                        <label class="label" for="try-amount">Amount</label>
                        <input id="try-amount" v-model.trim="order.amount" class="field" type="text" inputmode="decimal" />
                    </div>
                    <div>
                        <label class="label" for="try-currency">Currency</label>
                        <select id="try-currency" v-model="order.currency" class="field">
                            <option>USD</option>
                            <option>ZWG</option>
                        </select>
                    </div>
                    <div>
                        <label class="label" for="try-ref">Order reference</label>
                        <input id="try-ref" v-model.trim="reference" class="field font-mono text-sm" type="text" maxlength="100" />
                    </div>
                    <div>
                        <label class="label" for="try-phone">Shopper's phone <span class="font-normal text-mute">(optional)</span></label>
                        <input id="try-phone" v-model.trim="order.phone" class="field" type="tel" placeholder="0771234567" />
                    </div>
                </div>

                <p v-if="problem" role="alert" class="mt-4 border-l-4 border-ink bg-paper px-4 py-3 text-sm font-medium">{{ problem }}</p>

                <div class="mt-5 flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-gold" :disabled="!ready || !!busy">{{ busy === 'popup' ? 'Starting…' : 'Pay in the pop-up' }}</button>
                    <button type="button" class="btn btn-dark" :disabled="!ready || !!busy" @click="payFullPage">{{ busy === 'page' ? 'Starting…' : 'Pay on the full page' }}</button>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="btn btn-line" :disabled="!payment || !keyOk || !!busy" @click="checkStatus">{{ busy === 'status' ? 'Asking…' : 'Check the payment again' }}</button>
                    <button type="button" class="btn btn-line" :disabled="!!busy" @click="reset">Start a new order</button>
                </div>
                <p v-if="live" class="mt-4 text-sm text-mute">The checkout talks to the real bank: a real card form, a real EcoCash prompt, a real bank sign-in. Each bank call and its answer is kept in the transaction's steps.</p>
                <p v-else class="mt-4 text-sm text-mute">In test mode the checkout shows Approve and Decline buttons in place of the bank. Use the same order reference twice to see that no second payment is made.</p>
            </form>

            <!-- Copy and paste -->
            <div class="border border-line bg-white p-5">
                <h2 class="text-lg font-semibold">3. Copy into Postman or a terminal</h2>
                <p class="mt-1 text-sm text-mute">In Postman choose Import, then paste a command: it becomes a ready request. Or download the collection, which carries the payment id from the first request into the second.</p>
                <label class="mt-3 flex min-h-11 cursor-pointer items-center gap-3 text-sm">
                    <input v-model="includeKey" type="checkbox" class="size-5 accent-ink" :disabled="!keyOk" />
                    Put my {{ mode }} key in what I copy (otherwise it says {{ KEY_NAME }})
                </label>

                <div v-for="snippet in [
                    { id: 'create', label: 'Start a payment', text: curlCreate(includeKey && keyOk) },
                    { id: 'status', label: 'Read the payment', text: curlStatus(includeKey && keyOk) },
                    { id: 'verify', label: 'Check a callback\'s signature (PHP)', text: verifySnippet },
                ]" :key="snippet.id" class="mt-4">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold">{{ snippet.label }}</h3>
                        <button type="button" class="link min-h-11 text-sm" @click="copy(snippet.text, snippet.id)">{{ copied === snippet.id ? 'Copied' : 'Copy' }}</button>
                    </div>
                    <pre class="max-h-64 overflow-auto bg-coal p-3 font-mono text-xs leading-relaxed text-[#ededed]" tabindex="0">{{ snippet.text }}</pre>
                </div>

                <button type="button" class="btn btn-dark mt-5" @click="downloadPostman">Download the Postman collection</button>
            </div>
        </section>

        <!-- What happened -->
        <section aria-labelledby="log-h" class="min-w-0">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 id="log-h" class="text-lg font-semibold">What happened</h2>
                <span class="text-sm text-mute" aria-live="polite">{{ log.length }} {{ log.length === 1 ? 'step' : 'steps' }}</span>
            </div>

            <p v-if="!log.length" class="border border-dashed border-[#c9c6bd] bg-white px-5 py-10 text-center text-mute">
                Nothing yet. Enter a {{ mode }} key and press "Pay in the pop-up". Each request your server would make, and each answer, appears here in order.
            </p>

            <ol class="grid grid-cols-[minmax(0,1fr)] gap-3">
                <li v-for="entry in log" :key="entry.id" class="min-w-0 border border-line bg-white">
                    <button type="button" class="flex min-h-12 w-full items-center gap-3 px-4 py-2.5 text-left" :aria-expanded="entry.open" @click="entry.open = !entry.open">
                        <span class="flex size-6 flex-none items-center justify-center bg-ink font-mono text-xs text-white">{{ entry.id }}</span>
                        <span class="min-w-0 flex-1 font-medium">{{ entry.title }}</span>
                        <span v-if="entry.kind === 'api'" class="flex-none px-2 py-0.5 font-mono text-xs font-medium" :class="entry.ok ? 'bg-[#e3f0f8] text-[#0f4f7a]' : 'bg-ink text-white'">{{ entry.response.status || 'ERR' }} · {{ entry.response.ms }} ms</span>
                        <span v-if="entry.kind === 'callback'" class="flex-none px-2 py-0.5 text-xs font-semibold" :class="entry.signature ? 'bg-[#e3f0f8] text-[#0f4f7a]' : 'bg-ink text-white'">{{ entry.signature ? 'Signature valid' : 'Signature NOT valid' }}</span>
                    </button>

                    <div v-if="entry.open" class="grid grid-cols-[minmax(0,1fr)] gap-3 border-t border-line px-4 py-4 text-sm">
                        <template v-if="entry.kind === 'api' || entry.kind === 'callback'">
                            <div>
                                <div class="mb-1 flex items-center justify-between gap-3">
                                    <h3 class="font-semibold">Request</h3>
                                    <button v-if="entry.curl" type="button" class="link min-h-11" @click="copy(entry.curl(includeKey && keyOk), 'e' + entry.id)">{{ copied === 'e' + entry.id ? 'Copied' : 'Copy as cURL' }}</button>
                                </div>
                                <pre class="overflow-x-auto bg-paper p-3 font-mono text-xs leading-relaxed" tabindex="0">{{ entry.request.line }}
<template v-for="(value, name) in entry.request.headers" :key="name">{{ name }}: {{ value }}
</template><template v-if="entry.request.body">
{{ pretty(entry.request.body) }}</template></pre>
                            </div>
                            <div v-if="entry.response">
                                <h3 class="mb-1 font-semibold">Response</h3>
                                <pre class="overflow-x-auto bg-paper p-3 font-mono text-xs leading-relaxed" tabindex="0">HTTP {{ entry.response.status }}

{{ pretty(entry.response.body) }}</pre>
                            </div>
                            <p v-if="entry.kind === 'callback'" class="text-[#454545]">
                                Your server must check the signature with your signing secret before trusting this, then answer 200. The Try it page stands in for your server here, so this is exactly what yours would receive.
                            </p>
                        </template>

                        <pre v-else-if="entry.text" class="overflow-x-auto bg-paper p-3 font-mono text-xs leading-relaxed" tabindex="0">{{ entry.text }}</pre>
                        <pre v-else-if="entry.body" class="overflow-x-auto bg-paper p-3 font-mono text-xs leading-relaxed" tabindex="0">{{ pretty(entry.body) }}</pre>

                        <ol v-else-if="entry.events" class="grid gap-2">
                            <li v-for="event in entry.events" :key="event.id" class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3">
                                <span class="mt-1.5 size-2" :class="event.level === 'error' ? 'bg-[#9b1c1c]' : 'bg-gold'" aria-hidden="true"></span>
                                <span>{{ event.summary }} <span class="font-mono text-xs text-mute">{{ event.type }}</span></span>
                            </li>
                            <li><router-link :to="`/payments/${entry.paymentId}`" class="link">See every step with what was sent and received</router-link></li>
                        </ol>
                    </div>
                </li>
            </ol>
        </section>
    </div>
</template>
