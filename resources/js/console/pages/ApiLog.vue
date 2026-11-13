<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get } from '../api.js';
import PageHead from '../components/PageHead.vue';
import { exact, span } from '../format.js';
import { session } from '../session.js';

// Every call a company's system made to the API, with the request and our
// answer in full: the place to look when an integration "does not work".
const route = useRoute();
const router = useRouter();

const FIELDS = ['q', 'result', 'method', 'company_id', 'from', 'to'];
const filters = reactive(Object.fromEntries(FIELDS.map((f) => [f, route.query[f] || ''])));
const page = ref(Number(route.query.page) || 1);
const result = ref(null);
const companies = ref([]);
const problem = ref('');
const open = ref(new Set());

const clean = (extra = {}) => Object.fromEntries(Object.entries({ ...filters, ...extra }).filter(([, v]) => v !== '' && v != null));
const anyFilter = computed(() => FIELDS.some((f) => filters[f] !== ''));

async function load() {
    try {
        result.value = await get('/api-log', clean({ mode: session.mode, page: page.value }));
    } catch (e) {
        problem.value = Object.values(e.errors || {})[0]?.[0] || e.message;
    }
}
const apply = (toPage = 1) => router.push({ path: '/api-log', query: clean({ page: toPage > 1 ? toPage : '' }) });
function setResult(value) {
    filters.result = value;
    apply();
}
function toggle(id) {
    const next = new Set(open.value);
    next.has(id) ? next.delete(id) : next.add(id);
    open.value = next;
}

const pretty = (value) => (typeof value === 'string' ? value : JSON.stringify(value, null, 2));
const headerLines = (headers) => Object.entries(headers || {}).map(([k, v]) => `${k}: ${v}`).join('\n');
// What went wrong, in the API's own words, for the collapsed line.
const reason = (r) => r.response?.error?.message || r.response?.message || '';

onMounted(async () => {
    load();
    if (session.user.is_staff) {
        companies.value = (await get('/companies').catch(() => ({ companies: [] }))).companies;
    }
});
</script>

<template>
    <PageHead title="API log" :sub="`Every call made to the API with ${session.mode} keys: what was sent and what BlessedPay answered, in full.`" />

    <div class="mb-4 flex flex-wrap gap-1" role="group" aria-label="Result">
        <button
            v-for="[value, label] in [['', 'All calls'], ['failed', 'Refused or failed'], ['ok', 'Accepted']]"
            :key="value"
            type="button"
            :aria-pressed="filters.result === value"
            class="min-h-11 border px-4 text-[15px] font-semibold"
            :class="filters.result === value ? 'border-ink bg-ink text-white' : 'border-line bg-white text-ink hover:border-ink'"
            @click="setResult(value)"
        >
            {{ label }}
        </button>
    </div>

    <form class="mb-5 flex flex-wrap items-end gap-3 border border-line bg-white p-4" role="search" @submit.prevent="apply()">
        <div class="min-w-0 flex-[2_1_240px]">
            <label class="label" for="q">Search</label>
            <input id="q" v-model.trim="filters.q" class="field" type="search" placeholder="Order reference, payment id, idempotency key or IP address" />
        </div>
        <div v-if="session.user.is_staff" class="min-w-0 flex-[1_1_170px]">
            <label class="label" for="company">Company</label>
            <select id="company" v-model="filters.company_id" class="field">
                <option value="">All companies</option>
                <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
        </div>
        <div class="min-w-0 flex-[1_1_130px]">
            <label class="label" for="method">Call</label>
            <select id="method" v-model="filters.method" class="field">
                <option value="">Any</option>
                <option value="POST">Start a payment</option>
                <option value="GET">Read a payment</option>
            </select>
        </div>
        <div class="min-w-0 flex-[1_1_140px]">
            <label class="label" for="from">From</label>
            <input id="from" v-model="filters.from" class="field" type="date" />
        </div>
        <div class="min-w-0 flex-[1_1_140px]">
            <label class="label" for="to">To</label>
            <input id="to" v-model="filters.to" class="field" type="date" />
        </div>
        <button type="submit" class="btn btn-dark">Search</button>
        <button v-if="anyFilter" type="button" class="btn btn-line" @click="router.push('/api-log')">Clear all</button>
    </form>

    <p v-if="problem" role="alert" class="mb-4 border-l-4 border-ink bg-white px-4 py-3 font-medium">{{ problem }}</p>
    <p v-if="!result && !problem" class="text-mute">Loading…</p>

    <template v-if="result">
        <p class="mb-2 text-[15px]" aria-live="polite">
            <strong class="font-semibold">{{ result.total.toLocaleString() }} {{ result.total === 1 ? 'call' : 'calls' }}</strong>
        </p>

        <p v-if="!result.requests.length" class="border border-dashed border-[#c9c6bd] bg-white px-5 py-10 text-center text-mute">
            No calls match. A call appears here the moment a company's system (or the Try it page) uses a {{ session.mode }} key.
        </p>

        <ol class="grid grid-cols-[minmax(0,1fr)] gap-2">
            <li v-for="r in result.requests" :key="r.id" class="min-w-0 border bg-white" :class="r.status >= 400 ? 'border-[#9b1c1c]' : 'border-line'">
                <button type="button" class="flex min-h-14 w-full flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-left" :aria-expanded="open.has(r.id)" @click="toggle(r.id)">
                    <span class="flex-none px-2 py-0.5 font-mono text-xs font-semibold" :class="r.status >= 400 ? 'bg-[#9b1c1c] text-white' : 'bg-[#e3f0f8] text-[#0f4f7a]'">{{ r.status }}</span>
                    <span class="min-w-0 flex-[1_1_260px]">
                        <span class="block break-all font-mono text-sm font-medium">{{ r.method }} {{ r.path }}</span>
                        <span v-if="r.status >= 400 && reason(r)" class="block text-sm text-[#9b1c1c]">{{ reason(r) }}</span>
                        <span v-else-if="r.request?.body?.reference" class="block text-sm text-mute">Order {{ r.request.body.reference }}</span>
                    </span>
                    <span v-if="session.user.is_staff" class="flex-none text-sm">{{ r.company?.name || 'Unknown key' }}</span>
                    <span class="flex-none font-mono text-xs text-mute">{{ exact(r.at) }} · {{ span(r.ms) }}<template v-if="r.repeats > 1"> · ×{{ r.repeats }}</template></span>
                </button>

                <div v-if="open.has(r.id)" class="grid grid-cols-[minmax(0,1fr)] gap-4 border-t border-line px-4 py-4 text-sm lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                    <div class="min-w-0">
                        <h3 class="mb-1 font-semibold">What was sent</h3>
                        <pre class="max-h-[28rem] overflow-auto bg-paper p-3 font-mono text-xs leading-relaxed" tabindex="0">{{ r.method }} {{ r.path }}
{{ headerLines(r.request?.headers) }}<template v-if="r.request?.body">

{{ pretty(r.request.body) }}</template></pre>
                        <p class="mt-2 text-mute">
                            From {{ r.ip || 'an unknown address' }}<template v-if="r.key_hint"> with key {{ r.key_hint }}</template>. The key is never kept in full.
                        </p>
                    </div>
                    <div class="min-w-0">
                        <h3 class="mb-1 font-semibold">What BlessedPay answered</h3>
                        <pre class="max-h-[28rem] overflow-auto bg-paper p-3 font-mono text-xs leading-relaxed" tabindex="0">HTTP {{ r.status }}

{{ pretty(r.response?.text ?? r.response) }}</pre>
                        <p v-if="r.repeats > 1" class="mt-2 text-mute">Asked {{ r.repeats }} times with this same answer, last at {{ exact(r.last_at) }}.</p>
                        <p v-if="r.payment_id" class="mt-2"><router-link :to="`/payments/${r.payment_id}`" class="link">See every step of this transaction</router-link></p>
                    </div>
                </div>
            </li>
        </ol>

        <nav v-if="result.pages > 1" aria-label="Pages" class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-mute">Showing {{ result.from }}–{{ result.to }} of {{ result.total.toLocaleString() }}</p>
            <div class="flex items-center gap-2">
                <button type="button" class="btn btn-line" :disabled="result.page <= 1" @click="apply(result.page - 1)">Newer</button>
                <span class="text-sm text-mute">Page {{ result.page }} of {{ result.pages }}</span>
                <button type="button" class="btn btn-line" :disabled="result.page >= result.pages" @click="apply(result.page + 1)">Older</button>
            </div>
        </nav>
    </template>
</template>
