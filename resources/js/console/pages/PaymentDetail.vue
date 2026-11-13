<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { get, post } from '../api.js';
import PageHead from '../components/PageHead.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { exact, METHODS, money, span, when } from '../format.js';
import { can } from '../session.js';

const route = useRoute();
const payment = ref(null);
const problem = ref('');
const note = ref('');
const busy = ref('');
const show = ref('all'); // all | problems | bank
const copied = ref(false);

onMounted(async () => {
    try {
        payment.value = (await get(`/payments/${route.params.id}`)).payment;
    } catch (e) {
        problem.value = e.status === 404 ? 'No such payment.' : e.message;
    }
});

async function act(name, path, done) {
    busy.value = name;
    note.value = '';
    try {
        const data = await post(`/payments/${payment.value.id}/${path}`);
        payment.value = data.payment;
        note.value = done(data);
    } catch (e) {
        note.value = e.message;
    } finally {
        busy.value = '';
    }
}

const resend = () => act('callback', 'callback', (d) => (d.delivered ? 'Callback delivered.' : "The company's system did not accept it. See the last step below."));
const recheck = () => act('refresh', 'refresh', (d) => (d.payment.status === 'pending' ? 'Still pending: the bank has no news yet.' : `Now ${d.payment.status}.`));

const pretty = (data) => JSON.stringify(data, null, 2);

// Whose step it was. Told apart by the word as well as the colour.
const ACTORS = {
    merchant: ["Company's system", 'bg-[#e3f0f8] text-[#0f4f7a]'],
    shopper: ['Shopper', 'bg-[#eaeaea] text-[#2b2b2b]'],
    system: ['BlessedPay', 'bg-gold text-black'],
    bank: ['Bank', 'bg-ink text-white'],
    staff: ['Staff', 'border border-ink bg-white text-ink'],
};
const WHERE = {
    none: ['ALL CLEAR', 'border-l-[#0f4f7a]'],
    bank: ['AT THE BANK', 'border-l-[#9b1c1c]'],
    system: ['IN BLESSEDPAY', 'border-l-[#9b1c1c]'],
    merchant: ["AT THE COMPANY'S SYSTEM", 'border-l-[#9b1c1c]'],
    shopper: ['WITH THE SHOPPER', 'border-l-gold'],
};

const tries = computed(() => Object.fromEntries(payment.value.attempts.map((a, i) => [a.id, { n: i + 1, ...a }])));

// Each step with how long after the one before it happened.
const steps = computed(() => {
    const events = payment.value.events;
    return events.map((e, i) => ({
        ...e,
        gap: i ? new Date(e.at) - new Date(events[i - 1].at) : null,
        try: e.attempt_id ? tries.value[e.attempt_id] : null,
        // What was sent and what came back get their own boxes; anything else is shown as it is.
        rest: e.data && Object.fromEntries(Object.entries(e.data).filter(([k]) => !['sent', 'received', 'their_answer', 'request', 'http_status', 'repeats', 'last_asked', 'connection_error'].includes(k))),
    }));
});
const shown = computed(() =>
    steps.value.filter((e) => (show.value === 'problems' ? ['error', 'warn'].includes(e.level) : show.value === 'bank' ? e.actor === 'bank' : true)),
);
const counts = computed(() => ({
    all: steps.value.length,
    problems: steps.value.filter((e) => ['error', 'warn'].includes(e.level)).length,
    bank: steps.value.filter((e) => e.actor === 'bank').length,
}));
const took = computed(() => {
    const p = payment.value;
    const end = p.finished_at || p.events.at(-1)?.at;
    return end ? span(new Date(end) - new Date(p.created_at)) : '';
});

async function goTo(id) {
    show.value = 'all';
    await nextTick();
    document.getElementById(`step-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// Everything on this page as text, to paste into an email to the bank or a support ticket.
async function copyTrail() {
    const p = payment.value;
    const lines = [
        `BlessedPay transaction ${p.id}`,
        `Reference ${p.reference} · ${money(p.amount, p.currency)} · ${p.status.toUpperCase()} · ${p.mode} mode`,
        `Finding: ${p.diagnosis.title}. ${p.diagnosis.detail}`,
        '',
        ...steps.value.flatMap((e, i) => [
            `${i + 1}. [${new Date(e.at).toISOString()}] ${ACTORS[e.actor]?.[0] || e.actor} · ${e.type} · ${e.level.toUpperCase()}${e.ms != null ? ` · took ${span(e.ms)}` : ''}`,
            `   ${e.summary}`,
            ...(e.data ? pretty(e.data).split('\n').map((l) => '   ' + l) : []),
            '',
        ]),
    ];
    try {
        await navigator.clipboard.writeText(lines.join('\n'));
        copied.value = true;
        setTimeout(() => (copied.value = false), 2500);
    } catch {
        note.value = 'Copying is blocked in this browser. Select the steps and copy them by hand.';
    }
}
</script>

<template>
    <p class="mb-3 text-sm"><router-link to="/payments" class="link">Back to transactions</router-link></p>

    <p v-if="problem" role="alert" class="border-l-4 border-ink bg-white px-4 py-3 font-medium">{{ problem }}</p>
    <p v-else-if="!payment" class="text-mute">Loading…</p>

    <template v-else>
        <PageHead :title="money(payment.amount, payment.currency)" :sub="`Reference ${payment.reference}`">
            <button v-if="payment.status === 'pending'" type="button" class="btn btn-line" :disabled="!!busy" @click="recheck">
                {{ busy === 'refresh' ? 'Asking the bank…' : 'Check with the bank' }}
            </button>
            <button v-if="can('callbacks.resend') && payment.status !== 'pending' && payment.callback_url" type="button" class="btn btn-dark" :disabled="!!busy" @click="resend">
                {{ busy === 'callback' ? 'Sending…' : 'Send callback again' }}
            </button>
        </PageHead>

        <p v-if="note" role="status" class="mb-5 border border-line border-l-4 border-l-gold bg-white px-4 py-3">{{ note }}</p>

        <!-- The finding: where this transaction stands and, if it was stopped, on whose side. -->
        <section aria-labelledby="finding" class="mb-6 border border-line border-l-[6px] bg-white px-5 py-4" :class="WHERE[payment.diagnosis.where]?.[1]">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <StatusBadge :status="payment.status" />
                <span class="eyebrow font-semibold" :class="['bank', 'system', 'merchant'].includes(payment.diagnosis.where) ? 'text-[#9b1c1c]' : 'text-[#454545]'">
                    {{ payment.status === 'paid' && payment.diagnosis.where === 'none' ? 'ALL CLEAR' : `WHERE TO LOOK: ${WHERE[payment.diagnosis.where]?.[0]}` }}
                </span>
            </div>
            <h2 id="finding" class="mt-2 text-xl font-semibold">{{ payment.diagnosis.title }}</h2>
            <p class="mt-1 max-w-[75ch] text-[#333]">{{ payment.diagnosis.detail }}</p>
            <button v-if="payment.diagnosis.event_id" type="button" class="link mt-2 min-h-11 text-sm" @click="goTo(payment.diagnosis.event_id)">Go to that step</button>
        </section>

        <div class="grid grid-cols-[minmax(0,1fr)] gap-6 xl:grid-cols-[minmax(0,5fr)_minmax(0,8fr)]">
            <section aria-labelledby="facts" class="self-start border border-line bg-white p-5">
                <h2 id="facts" class="mb-4 text-lg font-semibold">Details</h2>
                <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-6 gap-y-3 text-[15px]">
                    <dt class="text-mute">Status</dt>
                    <dd><StatusBadge :status="payment.status" /> <StatusBadge :status="payment.mode" class="ml-1" /></dd>
                    <dt class="text-mute">Company</dt>
                    <dd><router-link :to="`/companies/${payment.company.id}`" class="link">{{ payment.company.name }}</router-link></dd>
                    <template v-if="Number(payment.fee) > 0">
                        <dt class="text-mute">Shopper paid</dt>
                        <dd>{{ money(payment.charged, payment.currency) }} <span class="text-sm text-mute">(includes {{ money(payment.fee, payment.currency) }} processing fee)</span></dd>
                    </template>
                    <dt class="text-mute">Payment id</dt>
                    <dd class="break-all font-mono text-sm">{{ payment.id }}</dd>
                    <dt class="text-mute">Bank</dt>
                    <dd>{{ payment.gateway === 'sandbox' ? 'Sandbox (pretend bank)' : 'TN CyberTech Bank (StewardPay)' }}</dd>
                    <dt class="text-mute">Bank reference</dt>
                    <dd class="break-all font-mono text-sm">{{ payment.gateway_reference || '—' }}</dd>
                    <dt class="text-mute">Paid with</dt>
                    <dd>{{ METHODS[payment.method] || payment.method || '—' }}</dd>
                    <dt class="text-mute">Started</dt>
                    <dd>{{ when(payment.created_at) }}</dd>
                    <dt class="text-mute">Paid</dt>
                    <dd>{{ when(payment.paid_at) }}</dd>
                    <template v-if="took">
                        <dt class="text-mute">{{ payment.finished_at ? 'Took' : 'Open for' }}</dt>
                        <dd>{{ took }}</dd>
                    </template>
                    <template v-if="payment.description">
                        <dt class="text-mute">Description</dt>
                        <dd>{{ payment.description }}</dd>
                    </template>
                    <dt class="text-mute">Callback</dt>
                    <dd>
                        <template v-if="payment.callback_url">
                            <StatusBadge v-if="payment.callback_status" :status="payment.callback_status" />
                            <span v-else class="text-mute">Not sent yet</span>
                            <span class="ml-2 text-sm text-mute">{{ payment.callback_attempts }} {{ payment.callback_attempts === 1 ? 'try' : 'tries' }}</span>
                            <div class="mt-1 break-all font-mono text-xs text-mute">{{ payment.callback_url }}</div>
                        </template>
                        <span v-else class="text-mute">None asked for</span>
                    </dd>
                    <dt class="text-mute">Return address</dt>
                    <dd class="break-all font-mono text-xs text-mute">{{ payment.return_url }}</dd>
                    <template v-if="payment.idempotency_key">
                        <dt class="text-mute">Idempotency key</dt>
                        <dd class="break-all font-mono text-sm">{{ payment.idempotency_key }}</dd>
                    </template>
                </dl>
                <template v-if="payment.attempts.length">
                    <h3 class="mb-2 mt-6 text-sm font-semibold">Tries at paying</h3>
                    <ul class="grid gap-px border border-line bg-line text-sm">
                        <li v-for="(a, i) in payment.attempts" :key="a.id" class="bg-white px-3 py-2.5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-medium">Try {{ i + 1 }} · {{ a.method }}<span v-if="a.phone" class="font-normal text-mute"> · {{ a.phone }}</span></span>
                                <StatusBadge :status="a.status === 'abandoned' ? 'cancelled' : a.status" />
                            </div>
                            <div class="mt-1 break-all font-mono text-xs text-mute">ours {{ a.reference }}<template v-if="a.bank_id"> · bank's {{ a.bank_id }}</template></div>
                            <div v-if="a.error" class="mt-1 text-[#454545]">{{ a.error }}</div>
                        </li>
                    </ul>
                </template>
                <template v-if="payment.metadata">
                    <h3 class="mb-2 mt-5 text-sm font-semibold">Extra details sent by the company</h3>
                    <pre class="overflow-x-auto bg-paper p-3 font-mono text-xs">{{ pretty(payment.metadata) }}</pre>
                </template>
            </section>

            <section aria-labelledby="trail" class="border border-line bg-white p-5">
                <div class="mb-1 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="trail" class="text-lg font-semibold">Every step, in order</h2>
                    <button type="button" class="btn btn-line !min-h-10 !py-1.5 text-sm" @click="copyTrail">{{ copied ? 'Copied' : 'Copy all steps' }}</button>
                </div>
                <p class="mb-4 text-sm text-mute">What was sent and what came back at each step. Passwords, keys and card details are never kept.</p>

                <div class="mb-5 flex flex-wrap gap-1" role="group" aria-label="Which steps to show">
                    <button
                        v-for="[key, label] in [['all', 'All steps'], ['problems', 'Problems only'], ['bank', 'Bank only']]"
                        :key="key"
                        type="button"
                        :aria-pressed="show === key"
                        class="min-h-10 border px-3 text-sm font-semibold"
                        :class="show === key ? 'border-ink bg-ink text-white' : 'border-line bg-white hover:border-ink'"
                        @click="show = key"
                    >
                        {{ label }} ({{ counts[key] }})
                    </button>
                </div>

                <p v-if="!shown.length" class="py-6 text-center text-mute">{{ show === 'problems' ? 'No step reported a problem.' : 'No steps to show.' }}</p>

                <ol class="grid gap-0">
                    <li
                        v-for="event in shown"
                        :id="`step-${event.id}`"
                        :key="event.id"
                        class="grid scroll-mt-4 grid-cols-[20px_minmax(0,1fr)] gap-x-3 border-l-2 pb-5 last:pb-0"
                        :class="event.level === 'error' ? 'border-[#9b1c1c]' : 'border-line'"
                    >
                        <!-- How the step went: a tick, a cross, a warning mark, or a plain dot. -->
                        <span
                            class="-ml-[11px] mt-0.5 flex size-5 items-center justify-center text-[12px] font-bold leading-none"
                            :class="{ ok: 'bg-gold text-black', error: 'bg-[#9b1c1c] text-white', warn: 'border-2 border-ink bg-white text-ink', info: 'border border-[#8a8a8a] bg-white' }[event.level]"
                            aria-hidden="true"
                            >{{ { ok: '✓', error: '✕', warn: '!', info: '' }[event.level] }}</span
                        >
                        <div class="min-w-0" :class="event.level === 'error' ? 'bg-[#fdf3f1] px-3 py-2.5' : ''">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                <span class="px-2 py-0.5 font-semibold" :class="ACTORS[event.actor]?.[1]">{{ ACTORS[event.actor]?.[0] || event.actor }}</span>
                                <span v-if="event.type.startsWith('payment.')" class="font-semibold">FINAL RESULT</span>
                                <span v-else-if="event.level === 'error'" class="font-semibold text-[#9b1c1c]">FAILED HERE</span>
                                <span v-else-if="event.level === 'warn'" class="font-semibold">CHECK THIS</span>
                                <span v-if="event.try" class="text-mute">Try {{ event.try.n }}</span>
                                <span class="font-mono text-mute">{{ exact(event.at) }}</span>
                                <span v-if="event.gap >= 1000" class="font-mono text-mute" title="Time since the step before">+{{ span(event.gap) }}</span>
                            </div>
                            <p class="mt-1.5 font-medium">{{ event.summary }}</p>
                            <p class="mt-0.5 text-sm text-mute">
                                <span class="font-mono text-xs">{{ event.type }}</span>
                                <template v-if="event.ms != null"> · answered in {{ span(event.ms) }}</template>
                                <template v-if="event.data?.repeats > 1"> · asked {{ event.data.repeats }} times with the same answer, last at {{ exact(event.data.last_asked) }}</template>
                            </p>

                            <details v-if="event.data" class="mt-2" :open="event.level === 'error'">
                                <summary class="text-sm font-medium text-gold-ink underline underline-offset-2">What was sent and received</summary>
                                <div class="mt-2 grid grid-cols-[minmax(0,1fr)] gap-3">
                                    <div v-if="event.data.request || event.data.sent">
                                        <h3 class="mb-1 text-xs font-semibold text-[#454545]">
                                            {{ event.actor === 'merchant' ? "We sent the company's system" : 'We sent' }}
                                            <span v-if="event.data.request" class="font-mono font-normal">{{ event.data.request }}</span>
                                        </h3>
                                        <pre v-if="event.data.sent" class="max-h-80 overflow-auto bg-paper p-3 font-mono text-xs">{{ pretty(event.data.sent) }}</pre>
                                    </div>
                                    <div v-if="event.data.received !== undefined || event.data.their_answer !== undefined || event.data.connection_error">
                                        <h3 class="mb-1 text-xs font-semibold text-[#454545]">
                                            {{ event.actor === 'merchant' ? 'It answered' : 'The bank answered' }}
                                            <span v-if="event.data.http_status" class="font-mono font-normal">HTTP {{ event.data.http_status }}</span>
                                        </h3>
                                        <pre v-if="event.data.connection_error" class="overflow-auto bg-ink p-3 font-mono text-xs text-white">No answer: {{ event.data.connection_error }}</pre>
                                        <pre v-else class="max-h-80 overflow-auto bg-paper p-3 font-mono text-xs">{{ typeof (event.data.received ?? event.data.their_answer) === 'string' ? (event.data.received ?? event.data.their_answer) || '(empty)' : pretty(event.data.received) }}</pre>
                                    </div>
                                    <div v-if="event.rest && Object.keys(event.rest).length">
                                        <h3 v-if="event.data.sent || event.data.received !== undefined" class="mb-1 text-xs font-semibold text-[#454545]">Also recorded</h3>
                                        <pre class="max-h-80 overflow-auto bg-paper p-3 font-mono text-xs">{{ pretty(event.rest) }}</pre>
                                    </div>
                                </div>
                            </details>
                        </div>
                    </li>
                </ol>
            </section>
        </div>
    </template>
</template>
