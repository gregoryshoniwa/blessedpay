<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

// initial: the state the server rendered with the page. embed: the origin of
// the site showing us in a pop-up (already checked by the server), or null.
const props = defineProps({ initial: { type: Object, required: true }, embed: { type: String, default: null } });

const state = reactive({ ...props.initial });
const embedded = !!props.embed && window.parent !== window;
const base = `/p/${state.payment.id}`;

// What is on screen: choose | waiting | card | bank | simulate | paid | ended
const view = ref('choose');
const method = ref(state.methods[0]?.id || '');
const phone = ref('');
const busy = ref(false);
const problem = ref('');
const card = reactive({ loading: false, failed: false, url: '' });
const bankUrl = ref('');
const now = ref(Date.now());
const returning = ref(0);
const root = ref(null);

const pay = computed(() => state.payment);
const money = (amount) => `${pay.value.currency} ${Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const chosen = computed(() => state.methods.find((m) => m.id === method.value));
const needsPhone = computed(() => chosen.value?.needs.includes('phone'));
// Any fee on top depends on the way of paying, so while choosing, the total is the chosen method's.
const choosing = computed(() => view.value === 'choose' && !!chosen.value);
const due = computed(() => (choosing.value ? chosen.value.total : pay.value.charged));
const dueFee = computed(() => (choosing.value ? chosen.value.fee : pay.value.fee));
const hasFee = computed(() => Number(dueFee.value) > 0);

// The marks of what we accept. The checkout is white so they sit on it as their owners draw them (never recoloured).
const MARKS = {
    ecocash: [{ src: '/pay/ecocash.svg', alt: 'EcoCash', w: 68 }],
    card: [
        { src: '/pay/visa.svg', alt: 'Visa', w: 46 },
        { src: '/pay/mastercard.svg', alt: 'Mastercard', w: 34 },
    ],
    bank: [{ src: '/pay/tncb.png', alt: 'TN CyberTech Bank', w: 78 }],
};

const secondsLeft = computed(() => (pay.value.expires_at ? Math.max(0, Math.round((new Date(pay.value.expires_at).getTime() - now.value) / 1000)) : null));
const clock = computed(() => (secondsLeft.value === null ? '' : `${Math.floor(secondsLeft.value / 60)}:${String(secondsLeft.value % 60).padStart(2, '0')}`));

// ── Talking to the page that opened the pop-up ──────────────────────────────
function tell(type, data = {}) {
    if (embedded) window.parent.postMessage({ source: 'blessedpay-checkout', type, data }, props.embed);
}
const publicPayment = () => ({
    id: pay.value.id, status: pay.value.status, amount: pay.value.amount, charged: pay.value.charged,
    currency: pay.value.currency, reference: pay.value.reference, mode: pay.value.mode,
});

function onMessage(event) {
    const message = event.data || {};
    // The card form finished and came back (it may be nested inside the bank's frame): look now.
    if (message.source === 'blessedpay-card') return void check();
    if (event.origin !== props.embed || message.source !== 'blessedpay-host') return;
    if (message.type === 'close-request') tell('close'); // the payment stays open; the shopper can come back to it
}

// ── Server calls ────────────────────────────────────────────────────────────
async function call(path, body) {
    const response = await fetch(base + path, {
        method: body ? 'POST' : 'GET',
        headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
        body: body ? JSON.stringify(body) : undefined,
        credentials: 'omit',
    });
    const data = await response.json().catch(() => ({}));
    if (data.payment) Object.assign(state, data);
    if (!response.ok) throw new Error(data.message || 'Something went wrong. Please try again.');
    return data;
}

let timer = null;
function watchStatus(every) {
    clearInterval(timer);
    timer = every ? setInterval(check, every) : null;
}
async function check() {
    try {
        await call('/status');
    } catch {
        return; // a dropped connection: the next tick tries again
    }
    settle();
}

// Move the screen to wherever the payment now is.
function settle() {
    if (pay.value.status === 'paid') return finish('paid');
    if (pay.value.status !== 'pending') return finish('ended');

    const attempt = state.attempt;
    if (attempt?.status === 'failed' && ['waiting', 'card', 'bank', 'simulate'].includes(view.value)) {
        problem.value = `${attempt.error || 'That did not go through.'} Try again or choose another way to pay.`;
        tell('failed', { payment: publicPayment(), message: attempt.error });
        closeCard();
        view.value = 'choose';
        watchStatus(0);
    }
}

function finish(kind) {
    watchStatus(0);
    closeCard();
    view.value = kind;
    if (kind === 'paid') {
        // Let the tick be seen before the pop-up closes.
        if (embedded) setTimeout(() => tell('success', { payment: publicPayment(), return_url: state.return_url }), 1400);
        else startReturn();
    } else if (embedded && pay.value.status === 'cancelled') {
        tell('cancel', { payment: publicPayment(), return_url: state.return_url });
    }
}

let returnTimer = null;
function startReturn() {
    returning.value = 5;
    returnTimer = setInterval(() => {
        if (--returning.value <= 0) {
            clearInterval(returnTimer);
            window.location.assign(state.return_url);
        }
    }, 1000);
}
function stay() {
    clearInterval(returnTimer);
    returning.value = 0;
}

// ── Paying ──────────────────────────────────────────────────────────────────
async function submit() {
    if (busy.value || !chosen.value) return;
    problem.value = '';

    if (needsPhone.value && !state.has_phone && !/^(\+?263|0)?7\d{8}$/.test(phone.value.replace(/[\s-]/g, ''))) {
        problem.value = 'Enter a Zimbabwe mobile number, for example 077 123 4567.';
        nextTick(() => document.getElementById(`phone-${method.value}`)?.focus());
        return;
    }

    // The bank's page opens in a new tab. Browsers only allow that directly
    // from the click, so open it now and point it at the bank once we know where.
    const tab = method.value === 'bank' && pay.value.mode === 'live' ? window.open('', '_blank') : null;

    busy.value = true;
    try {
        const { next } = await call('/pay', { method: method.value, phone: phone.value || null });
        begin(next, tab);
    } catch (e) {
        tab?.close();
        problem.value = e.message;
    } finally {
        busy.value = false;
    }
}

function begin(next, tab) {
    if (next.kind === 'card') {
        view.value = 'card';
        watchStatus(4000);
        return openCard(next);
    }
    if (next.kind === 'open') {
        bankUrl.value = next.url;
        if (tab) tab.location.replace(next.url);
        view.value = 'bank';
        return watchStatus(4000);
    }
    tab?.close();
    if (next.kind === 'simulate') {
        view.value = 'simulate';
        return;
    }
    view.value = 'waiting';
    watchStatus(3000);
}

// The card form is Mastercard's own page, drawn inside ours by their script.
// We never see what is typed into it.
function openCard(next) {
    card.loading = true;
    card.failed = false;
    card.url = next.url;

    window.bpCardDone = () => check();
    window.bpCardError = () => {
        card.failed = true;
        card.loading = false;
    };
    try {
        Object.keys(sessionStorage).filter((k) => k.startsWith('HostedCheckout')).forEach((k) => sessionStorage.removeItem(k));
    } catch {
        /* storage blocked: nothing to clear */
    }

    document.getElementById('bp-card-script')?.remove();
    const script = document.createElement('script');
    script.id = 'bp-card-script';
    script.src = next.script;
    script.dataset.complete = 'bpCardDone';
    script.dataset.cancel = 'bpCardDone';
    script.dataset.timeout = 'bpCardDone';
    script.dataset.error = 'bpCardError';
    script.onerror = window.bpCardError;
    script.onload = () => {
        try {
            window.Checkout.configure({ session: { id: next.session_id } });
            window.Checkout.showEmbeddedPage('#bp-card');
            card.loading = false;
        } catch {
            window.bpCardError();
        }
    };
    document.head.appendChild(script);
}
function closeCard() {
    document.getElementById('bp-card-script')?.remove();
    const box = document.getElementById('bp-card');
    if (box) box.innerHTML = '';
}

async function simulate(outcome) {
    busy.value = true;
    try {
        await call('/simulate', { outcome });
        settle();
    } catch (e) {
        problem.value = e.message;
    } finally {
        busy.value = false;
    }
}

function another() {
    closeCard();
    watchStatus(0);
    problem.value = '';
    view.value = 'choose';
}

async function cancel() {
    busy.value = true;
    try {
        await call('/cancel', {});
    } catch {
        /* closing anyway */
    }
    busy.value = false;
    if (embedded) return finish(pay.value.status === 'paid' ? 'paid' : 'ended');
    window.location.assign(state.return_url);
}

// ── Life cycle ──────────────────────────────────────────────────────────────
let tick = null;
let sizer = null;
onMounted(() => {
    window.addEventListener('message', onMessage);
    tick = setInterval(() => (now.value = Date.now()), 1000);

    if (pay.value.status === 'paid') view.value = 'paid';
    else if (pay.value.status !== 'pending') view.value = 'ended';
    else if (state.attempt?.status === 'pending' && state.attempt.method !== 'card') {
        // The page was reloaded part-way through: carry on waiting.
        method.value = state.attempt.method;
        view.value = pay.value.mode === 'test' ? 'simulate' : state.attempt.method === 'bank' ? 'bank' : 'waiting';
        if (view.value !== 'simulate') watchStatus(3000);
    } else if (new URLSearchParams(window.location.search).has('card')) {
        view.value = 'waiting';
        method.value = 'card';
        watchStatus(3000);
    }

    // Inside the pop-up the keyboard is ours, not the shop page's: pass Escape up so it still closes.
    if (embedded) document.addEventListener('keydown', (e) => e.key === 'Escape' && tell('close'));

    tell('ready');
    if (embedded && 'ResizeObserver' in window) {
        sizer = new ResizeObserver(() => tell('resize', { height: root.value?.scrollHeight }));
        sizer.observe(root.value);
    }
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && timer && check());
});
onBeforeUnmount(() => {
    window.removeEventListener('message', onMessage);
    clearInterval(tick);
    clearInterval(timer);
    clearInterval(returnTimer);
    sizer?.disconnect();
});

watch(secondsLeft, (left) => left === 0 && view.value === 'choose' && check());
watch(method, () => (problem.value = ''));

const waitingCopy = computed(() =>
    method.value === 'card'
        ? { title: 'Confirming your card payment', steps: ['Card details sent to the bank', 'Waiting for the bank to confirm', 'Payment confirmed'] }
        : { title: 'Approve the payment on your phone', steps: [`Request sent to ${state.attempt?.phone || 'your phone'}`, 'Enter your EcoCash PIN on your phone to approve', 'Payment confirmed'] },
);
</script>

<template>
    <!-- White, so the payment marks sit on it as their owners draw them. Kept short: it must fit a laptop screen without scrolling. -->
    <div ref="root" class="bp-checkout mx-auto w-full bg-white text-ink" :class="embedded ? 'max-w-none' : 'border border-line shadow-[0_20px_60px_rgba(0,0,0,0.12)] lg:border-0 lg:shadow-none'">
        <p v-if="pay.mode === 'test'" class="bg-gold px-4 py-1 text-center font-mono text-xs font-medium text-black" :class="embedded ? '' : 'lg:mb-6'">TEST MODE · NO MONEY MOVES</p>

        <!-- Who is being paid, and how much. On a wide full page this sits in the picture beside the form instead (see pay/show.blade.php). -->
        <header class="bg-black px-5 py-4 text-white sm:px-6" :class="embedded ? 'pr-14' : 'lg:hidden'">
            <div class="flex items-end justify-between gap-4">
                <div class="min-w-0">
                    <p class="font-mono text-[11px] text-[#a8a8a8]">PAYING · REF {{ pay.reference }}</p>
                    <h1 class="truncate font-display text-lg font-bold leading-tight">{{ state.merchant.name }}</h1>
                    <p v-if="pay.description" class="truncate text-sm text-haze">{{ pay.description }}</p>
                </div>
                <div class="flex-none text-right">
                    <p class="font-display text-2xl font-bold leading-none tabular-nums">{{ money(due) }}</p>
                    <p v-if="hasFee" class="mt-1 text-xs text-[#a8a8a8]">{{ money(pay.amount) }} + {{ money(dueFee) }} fee</p>
                </div>
            </div>
        </header>

        <div class="px-5 py-5 sm:px-6" :class="embedded ? '' : 'lg:px-0 lg:py-0'" aria-live="polite">
            <!-- Choose how to pay -->
            <form v-if="view === 'choose'" novalidate @submit.prevent="submit">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-display text-lg font-bold sm:text-xl">How would you like to pay?</h2>
                    <span v-if="clock" class="flex-none font-mono text-sm tabular-nums" :class="secondsLeft < 120 ? 'bg-ink px-1.5 text-white' : 'text-mute'" title="Time left to pay">
                        <span class="sr-only">Time left to pay: </span>{{ clock }}
                    </span>
                </div>

                <p v-if="problem" role="alert" class="mt-3 border-l-4 border-[#9b1c1c] bg-[#fdf3f1] px-3 py-2.5 text-sm font-medium">{{ problem }}</p>

                <fieldset class="mt-3 grid gap-2">
                    <legend class="sr-only">Ways to pay</legend>
                    <div v-for="m in state.methods" :key="m.id" class="border-2 transition-colors" :class="method === m.id ? 'border-ink bg-[#fffaec]' : 'border-line bg-white hover:border-[#8a8a8a]'">
                        <label class="flex min-h-14 cursor-pointer items-center gap-3 px-3.5 py-2">
                            <input v-model="method" type="radio" name="method" :value="m.id" class="size-5 flex-none accent-[#141414]" />
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold leading-tight">{{ m.label }}</span>
                                <span class="block text-[13px] leading-snug text-mute">{{ m.hint }}</span>
                            </span>
                            <span v-if="MARKS[m.id]" class="flex flex-none items-center gap-2.5">
                                <img v-for="mark in MARKS[m.id]" :key="mark.src" :src="mark.src" :alt="mark.alt" :width="mark.w" :style="{ width: mark.w + 'px' }" class="max-h-7 object-contain mix-blend-multiply" />
                            </span>
                        </label>

                        <div v-if="method === m.id && m.needs.includes('phone') && !state.has_phone" class="px-3.5 pb-3">
                            <label class="mb-1 block text-[13px] font-medium" :for="`phone-${m.id}`">
                                {{ m.id === 'ecocash' ? 'EcoCash number' : 'The mobile number on your bank profile' }}
                            </label>
                            <div class="flex">
                                <span class="flex items-center border border-r-0 border-[#8a8a8a] bg-paper px-3 font-mono text-sm" aria-hidden="true">+263</span>
                                <input
                                    :id="`phone-${m.id}`"
                                    v-model.trim="phone"
                                    type="tel"
                                    inputmode="tel"
                                    autocomplete="tel-national"
                                    placeholder="77 123 4567"
                                    class="min-h-11 w-full min-w-0 border border-[#8a8a8a] bg-white px-3 py-2 text-base text-ink placeholder:text-[#8a8a8a]"
                                />
                            </div>
                        </div>
                        <p v-else-if="method === m.id && m.needs.includes('phone')" class="px-3.5 pb-3 text-sm text-mute">Using {{ state.phone }}</p>
                    </div>
                </fieldset>

                <button type="submit" class="btn btn-gold mt-4 w-full py-3.5 text-base" :disabled="busy || !chosen">
                    <template v-if="busy">{{ method === 'ecocash' ? 'Sending…' : 'One moment…' }}</template>
                    <template v-else-if="method === 'bank'">Continue with TN CyberTech Bank</template>
                    <template v-else-if="method === 'card'">Enter card details</template>
                    <template v-else>Pay {{ money(due) }}</template>
                </button>
                <p v-if="hasFee" class="mt-2 text-center text-[13px] text-mute">
                    You pay <strong class="font-semibold text-ink">{{ money(due) }}</strong>: {{ money(pay.amount) }} + {{ money(dueFee) }} processing fee<template v-if="chosen?.fee_rate"> ({{ chosen.fee_rate }})</template>
                </p>
            </form>

            <!-- EcoCash prompt sent / card being confirmed -->
            <div v-else-if="view === 'waiting'">
                <h2 class="font-display text-xl font-bold">{{ waitingCopy.title }}</h2>
                <ol class="mt-5 grid gap-4">
                    <li class="flex items-start gap-3">
                        <span class="mt-0.5 flex size-5 flex-none items-center justify-center bg-gold text-xs font-bold text-black" aria-hidden="true">✓</span>
                        {{ waitingCopy.steps[0] }}
                    </li>
                    <li class="flex items-start gap-3 font-semibold">
                        <span class="relative mt-1 flex size-4 flex-none" aria-hidden="true"><span class="absolute inline-flex size-full bg-gold opacity-60 motion-safe:animate-ping"></span><span class="relative inline-flex size-4 bg-gold"></span></span>
                        {{ waitingCopy.steps[1] }}
                    </li>
                    <li class="flex items-start gap-3 text-mute">
                        <span class="mt-1 inline-flex size-4 flex-none border border-[#8a8a8a]" aria-hidden="true"></span>
                        {{ waitingCopy.steps[2] }}
                    </li>
                </ol>
                <!-- EcoCash names the BANK's merchant on the prompt, not the shop: say so, or a careful shopper cancels it. -->
                <p v-if="method === 'ecocash'" class="mt-5 border-l-4 border-gold bg-paper px-3 py-2.5 text-sm">
                    The prompt on your phone will say <strong class="font-semibold">{{ money(pay.charged) }} to STEWARDPAY</strong>. That is TN CyberTech Bank's payment service, taking this payment for {{ state.merchant.name }}.
                </p>
                <p class="mt-4 text-sm text-mute">Keep this page open. It updates by itself.</p>
                <button type="button" class="link mt-3 min-h-11 text-sm" @click="another">Choose another way to pay</button>
            </div>

            <!-- Mastercard's card form -->
            <div v-else-if="view === 'card'">
                <h2 class="font-display text-xl font-bold">Enter your card details</h2>
                <p class="mt-1 text-sm text-mute">This form belongs to the bank. Your card number is never seen by {{ state.merchant.name }} or BlessedPay.</p>
                <p v-if="card.loading" class="mt-4 text-mute">Opening the bank's card form…</p>
                <div v-if="card.failed" role="alert" class="mt-4 border-l-4 border-[#9b1c1c] bg-[#fdf3f1] px-3 py-2.5 text-sm">
                    The card form could not be shown here.
                    <a :href="card.url" target="_blank" rel="noopener" class="link font-semibold">Open the bank's card page in a new tab</a>, then come back: this page updates by itself.
                </div>
                <div class="mt-3 border border-line">
                    <div class="flex items-center justify-between gap-3 border-b border-line bg-paper px-3.5 py-2.5">
                        <span class="flex items-center gap-2 text-sm font-medium">
                            <svg aria-hidden="true" class="size-4 flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10" /><path d="M8 11V7a4 4 0 018 0v4" /></svg>
                            Paying {{ money(pay.charged) }} · secure bank form
                        </span>
                        <span class="flex flex-none items-center gap-2">
                            <img src="/pay/visa.svg" alt="Visa" width="38" class="w-[38px] mix-blend-multiply" />
                            <img src="/pay/mastercard.svg" alt="Mastercard" width="28" class="w-7" />
                        </span>
                    </div>
                    <!-- Mastercard's form starts with an empty band; it is tucked up under the bar above. -->
                    <div class="overflow-hidden bg-white px-3.5 pb-2"><div id="bp-card" class="-mt-12 min-h-[420px]"></div></div>
                </div>
                <button type="button" class="link mt-3 min-h-11 text-sm" @click="another">Choose another way to pay</button>
            </div>

            <!-- The bank's own sign-in page, in another tab -->
            <div v-else-if="view === 'bank'">
                <h2 class="font-display text-xl font-bold">Finish on the bank's page</h2>
                <p class="mt-2 text-[#333]">We opened TN CyberTech Bank in a new tab. Sign in there and approve the payment, then come back here.</p>
                <a v-if="bankUrl" :href="bankUrl" target="_blank" rel="noopener" class="btn btn-gold mt-5 w-full py-3.5 text-base">Open the bank page</a>
                <p class="mt-5 flex items-center gap-3 text-sm text-mute">
                    <span class="relative flex size-3 flex-none" aria-hidden="true"><span class="absolute inline-flex size-full bg-gold opacity-60 motion-safe:animate-ping"></span><span class="relative inline-flex size-3 bg-gold"></span></span>
                    Waiting for the bank. This page updates by itself.
                </p>
                <button type="button" class="link mt-3 min-h-11 text-sm" @click="another">Choose another way to pay</button>
            </div>

            <!-- Test mode: play the bank -->
            <div v-else-if="view === 'simulate'">
                <h2 class="font-display text-xl font-bold">Test mode: you are the bank</h2>
                <p class="mt-2 text-[#333]">No real {{ chosen?.label || 'payment' }} is made. Choose what the bank should say happened.</p>
                <p v-if="problem" role="alert" class="mt-3 border-l-4 border-[#9b1c1c] bg-[#fdf3f1] px-3 py-2.5 text-sm font-medium">{{ problem }}</p>
                <div class="mt-5 grid gap-3">
                    <button type="button" class="btn btn-gold py-3.5" :disabled="busy" @click="simulate('paid')">Approve: payment succeeds</button>
                    <button type="button" class="btn btn-line py-3.5" :disabled="busy" @click="simulate('failed')">Decline: payment fails</button>
                </div>
                <button type="button" class="link mt-3 min-h-11 text-sm" @click="another">Choose another way to pay</button>
            </div>

            <!-- Paid -->
            <div v-else-if="view === 'paid'" class="py-4 text-center">
                <svg class="mx-auto size-20" viewBox="0 0 80 80" fill="none" role="img" aria-label="Paid">
                    <rect width="80" height="80" fill="#e0b03a" />
                    <path class="bp-tick" d="M22 41l12 12 24-27" stroke="#000" stroke-width="6" />
                </svg>
                <h2 class="mt-5 font-display text-2xl font-bold">Payment received</h2>
                <p class="mt-1 text-[#333]">{{ money(pay.charged) }} paid to {{ state.merchant.name }}</p>
                <p class="mt-3 font-mono text-xs text-mute">{{ pay.id }}</p>
                <template v-if="!embedded">
                    <a :href="state.return_url" class="btn btn-gold mt-6 w-full py-3.5 text-base">Return to {{ state.merchant.name }}</a>
                    <p v-if="returning" class="mt-3 text-sm text-mute">
                        Returning in {{ returning }}s ·
                        <button type="button" class="link min-h-11" @click="stay">stay here</button>
                    </p>
                </template>
            </div>

            <!-- Failed, cancelled or out of time -->
            <div v-else class="py-4 text-center">
                <svg class="mx-auto size-20" viewBox="0 0 80 80" fill="none" stroke="#141414" stroke-width="5" aria-hidden="true">
                    <rect x="2.5" y="2.5" width="75" height="75" stroke-width="3" />
                    <path d="M27 27l26 26M53 27L27 53" />
                </svg>
                <h2 class="mt-5 font-display text-2xl font-bold">{{ pay.status === 'cancelled' ? 'Payment cancelled' : 'Payment not completed' }}</h2>
                <p class="mt-1 text-[#333]">No money was taken.</p>
                <a v-if="!embedded" :href="state.return_url" class="btn btn-gold mt-6 w-full py-3.5 text-base">Return to {{ state.merchant.name }}</a>
                <button v-else type="button" class="btn btn-gold mt-6 w-full py-3.5 text-base" @click="tell('close')">Close</button>
            </div>
        </div>

        <footer v-if="!['paid', 'ended'].includes(view)" class="flex items-center justify-between gap-x-3 border-t border-line px-5 py-1 text-[13px] text-mute sm:px-6" :class="embedded ? '' : 'lg:mt-5 lg:px-0'">
            <span class="flex min-w-0 items-center gap-1.5 truncate">
                <svg aria-hidden="true" class="size-3.5 flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10" /><path d="M8 11V7a4 4 0 018 0v4" /></svg>
                Secured by TN CyberTech Bank
            </span>
            <button type="button" class="min-h-11 flex-none underline underline-offset-2 hover:text-ink" :disabled="busy" @click="cancel">Cancel and return</button>
        </footer>
    </div>
</template>

<style>
.bp-tick {
    stroke-dasharray: 60;
    stroke-dashoffset: 60;
    animation: bp-draw 0.5s 0.15s ease-out forwards;
}
@keyframes bp-draw {
    to {
        stroke-dashoffset: 0;
    }
}
@media (prefers-reduced-motion: reduce) {
    .bp-tick {
        animation: none;
        stroke-dashoffset: 0;
    }
}
/* Keep the bank's 3-D Secure step inside the checkout, not over the shop's page. */
#bp-card iframe {
    width: 100% !important;
    min-height: 420px;
    border: 0;
}
</style>
