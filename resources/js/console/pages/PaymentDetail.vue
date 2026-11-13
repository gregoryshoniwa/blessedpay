<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { get, post } from '../api.js';
import PageHead from '../components/PageHead.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { money, when } from '../format.js';
import { can } from '../session.js';

const route = useRoute();
const payment = ref(null);
const problem = ref('');
const note = ref('');
const busy = ref('');

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

const resend = () => act('callback', 'callback', (d) => (d.delivered ? 'Callback delivered.' : 'The company\'s system did not accept it. See the log below.'));
const recheck = () => act('refresh', 'refresh', (d) => (d.payment.status === 'pending' ? 'Still pending: the bank has no news yet.' : `Now ${d.payment.status}.`));

const pretty = (data) => JSON.stringify(data, null, 2);
</script>

<template>
    <p class="mb-3 text-sm"><router-link to="/payments" class="link">Back to payments</router-link></p>

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

        <div class="grid grid-cols-[minmax(0,1fr)] gap-6 xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
            <section aria-labelledby="facts" class="border border-line bg-white p-5">
                <h2 id="facts" class="mb-4 text-lg font-semibold">Details</h2>
                <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-6 gap-y-3 text-[15px]">
                    <dt class="text-mute">Status</dt>
                    <dd><StatusBadge :status="payment.status" /> <StatusBadge :status="payment.mode" class="ml-1" /></dd>
                    <dt class="text-mute">Company</dt>
                    <dd><router-link :to="`/companies/${payment.company.id}`" class="link">{{ payment.company.name }}</router-link></dd>
                    <dt class="text-mute">Payment id</dt>
                    <dd class="break-all font-mono text-sm">{{ payment.id }}</dd>
                    <dt class="text-mute">Bank</dt>
                    <dd>{{ payment.gateway === 'sandbox' ? 'Sandbox (pretend bank)' : 'StewardPay' }}</dd>
                    <dt class="text-mute">Bank reference</dt>
                    <dd class="break-all font-mono text-sm">{{ payment.gateway_reference || '—' }}</dd>
                    <dt class="text-mute">Paid with</dt>
                    <dd>{{ payment.method || '—' }}</dd>
                    <dt class="text-mute">Started</dt>
                    <dd>{{ when(payment.created_at) }}</dd>
                    <dt class="text-mute">Paid</dt>
                    <dd>{{ when(payment.paid_at) }}</dd>
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
                <template v-if="payment.metadata">
                    <h3 class="mb-2 mt-5 text-sm font-semibold">Extra details sent by the company</h3>
                    <pre class="overflow-x-auto bg-paper p-3 font-mono text-xs">{{ pretty(payment.metadata) }}</pre>
                </template>
            </section>

            <section aria-labelledby="log" class="border border-line bg-white p-5">
                <h2 id="log" class="mb-4 text-lg font-semibold">Payment log</h2>
                <ol class="grid gap-0">
                    <li v-for="event in payment.events" :key="event.id" class="grid grid-cols-[10px_minmax(0,1fr)] gap-x-4 border-l border-line pb-5 pl-0 last:pb-0">
                        <span class="-ml-[5.5px] mt-1.5 size-2.5" :class="event.type.endsWith('failed') ? 'bg-ink' : 'bg-gold'" aria-hidden="true"></span>
                        <div class="min-w-0">
                            <p class="font-medium">{{ event.summary }}</p>
                            <p class="text-sm text-mute">
                                <span class="font-mono text-xs">{{ event.type }}</span> · {{ when(event.at) }}
                            </p>
                            <details v-if="event.data" class="mt-1.5">
                                <summary class="text-sm text-gold-ink underline underline-offset-2">Show data</summary>
                                <pre class="mt-2 overflow-x-auto bg-paper p-3 font-mono text-xs">{{ pretty(event.data) }}</pre>
                            </details>
                        </div>
                    </li>
                </ol>
            </section>
        </div>
    </template>
</template>
