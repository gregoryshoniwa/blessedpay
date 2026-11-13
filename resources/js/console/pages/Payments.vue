<script setup>
import SelectBox from '../components/SelectBox.vue';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get } from '../api.js';
import PageHead from '../components/PageHead.vue';
import PaymentTable from '../components/PaymentTable.vue';
import { money } from '../format.js';
import { can, session } from '../session.js';

const route = useRoute();
const router = useRouter();

// The address bar holds the filters, so a filtered list can be bookmarked or shared.
const FIELDS = ['q', 'status', 'method', 'currency', 'callback', 'company_id', 'from', 'to', 'min', 'max', 'sort', 'per_page'];
const filters = reactive(Object.fromEntries(FIELDS.map((f) => [f, route.query[f] || ''])));
const page = ref(Number(route.query.page) || 1);

const result = ref(null);
const companies = ref([]);
const problem = ref('');

const STATUSES = [
    ['', 'All'],
    ['paid', 'Paid'],
    ['pending', 'Pending'],
    ['failed', 'Failed'],
    ['cancelled', 'Cancelled'],
];

// How many of the less-used filters are set: they sit behind "More filters".
const extras = ['method', 'currency', 'callback', 'min', 'max'];
const extraCount = computed(() => extras.filter((f) => filters[f] !== '').length);
const showMore = ref(extraCount.value > 0);
const companyName = computed(() => companies.value.find((c) => String(c.id) === String(route.query.company_id || ''))?.name);
const anyFilter = computed(() => FIELDS.some((f) => !['sort', 'per_page'].includes(f) && filters[f] !== ''));

const clean = (extra = {}) => Object.fromEntries(Object.entries({ ...filters, ...extra }).filter(([, v]) => v !== '' && v != null));

async function load() {
    problem.value = '';
    try {
        result.value = await get('/payments', clean({ mode: session.mode, page: page.value }));
    } catch (e) {
        problem.value = Object.values(e.errors || {})[0]?.[0] || e.message;
    }
}

function apply(toPage = 1) {
    router.push({ path: '/payments', query: clean({ page: toPage > 1 ? toPage : '' }) });
}
function setStatus(status) {
    filters.status = status;
    apply();
}
function clear() {
    router.push('/payments');
}

const exportUrl = () => '/console-api/payments/export?' + new URLSearchParams(clean({ mode: session.mode })).toString();

// Page numbers to offer: the ends, and a few either side of where we are.
const pageList = computed(() => {
    if (!result.value) return [];
    const { page: at, pages } = result.value;
    const wanted = new Set([1, 2, pages - 1, pages, at - 1, at, at + 1].filter((n) => n >= 1 && n <= pages));
    const sorted = [...wanted].sort((a, b) => a - b);
    return sorted.flatMap((n, i) => (i && n - sorted[i - 1] > 1 ? ['gap' + n, n] : [n]));
});

onMounted(async () => {
    load();
    if (session.user.is_staff) {
        companies.value = (await get('/companies').catch(() => ({ companies: [] }))).companies;
    }
});
</script>

<template>
    <PageHead title="Transactions" :sub="`${session.user.is_staff ? (companyName || 'All companies') + ' · every' : 'Every'} payment made with ${session.mode} keys. Open one to see each step it took.`">
        <a v-if="can('payments.export')" :href="exportUrl()" class="btn btn-line">Download as a file</a>
    </PageHead>

    <div class="mb-4 flex flex-wrap gap-1" role="group" aria-label="Status">
        <button
            v-for="[value, label] in STATUSES"
            :key="value"
            type="button"
            :aria-pressed="filters.status === value"
            class="min-h-11 border px-4 text-[15px] font-semibold"
            :class="filters.status === value ? 'border-ink bg-ink text-white' : 'border-line bg-white text-ink hover:border-ink'"
            @click="setStatus(value)"
        >
            {{ label }}
        </button>
    </div>

    <form class="mb-5 border border-line bg-white p-4" role="search" @submit.prevent="apply()">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-[2_1_260px]">
                <label class="label" for="q">Search</label>
                <input id="q" v-model.trim="filters.q" class="field" type="search" placeholder="Reference, payment id, bank reference or phone" />
            </div>
            <div v-if="session.user.is_staff" class="min-w-0 flex-[1_1_180px]">
                <label class="label" for="company">Company</label>
                <SelectBox id="company" v-model="filters.company_id" :options="[{ value: '', label: 'All companies' }, ...companies.map((c) => ({ value: String(c.id), label: c.name }))]" @change="apply()" />
            </div>
            <div class="min-w-0 flex-[1_1_140px]">
                <label class="label" for="from">From</label>
                <input id="from" v-model="filters.from" class="field" type="date" />
            </div>
            <div class="min-w-0 flex-[1_1_140px]">
                <label class="label" for="to">To</label>
                <input id="to" v-model="filters.to" class="field" type="date" />
            </div>
        </div>

        <div v-show="showMore" id="more-filters" class="mt-3 flex flex-wrap items-end gap-3 border-t border-line pt-3">
            <div class="min-w-0 flex-[1_1_160px]">
                <label class="label" for="method">Way of paying</label>
                <SelectBox id="method" v-model="filters.method" :options="[{ value: '', label: 'Any' }, { value: 'ecocash', label: 'EcoCash' }, { value: 'card', label: 'Card' }, { value: 'bank', label: 'Bank account' }]" />
            </div>
            <div class="min-w-0 flex-[1_1_120px]">
                <label class="label" for="currency">Currency</label>
                <SelectBox id="currency" v-model="filters.currency" :options="[{ value: '', label: 'Any' }, 'USD', 'ZWG']" />
            </div>
            <div class="min-w-0 flex-[1_1_120px]">
                <label class="label" for="min">Amount from</label>
                <input id="min" v-model.trim="filters.min" class="field" type="number" min="0" step="0.01" inputmode="decimal" />
            </div>
            <div class="min-w-0 flex-[1_1_120px]">
                <label class="label" for="max">Amount to</label>
                <input id="max" v-model.trim="filters.max" class="field" type="number" min="0" step="0.01" inputmode="decimal" />
            </div>
            <div class="min-w-0 flex-[1_1_170px]">
                <label class="label" for="callback">Callback to the company</label>
                <SelectBox id="callback" v-model="filters.callback" :options="[{ value: '', label: 'Any' }, { value: 'delivered', label: 'Delivered' }, { value: 'failed', label: 'Failed' }, { value: 'none', label: 'Not sent' }]" />
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button type="submit" class="btn btn-dark">Search</button>
            <button v-if="anyFilter" type="button" class="btn btn-line" @click="clear">Clear all</button>
            <button type="button" class="min-h-11 px-1 text-[15px] font-medium text-gold-ink underline underline-offset-2" :aria-expanded="showMore" aria-controls="more-filters" @click="showMore = !showMore">
                {{ showMore ? 'Fewer filters' : 'More filters' }}<template v-if="!showMore && extraCount"> ({{ extraCount }} set)</template>
            </button>
        </div>
    </form>

    <p v-if="problem" role="alert" class="mb-4 border-l-4 border-ink bg-white px-4 py-3 font-medium">{{ problem }}</p>
    <p v-if="!result && !problem" class="text-mute">Loading…</p>

    <template v-if="result">
        <div class="mb-2 flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
            <p class="text-[15px]" aria-live="polite">
                <strong class="font-semibold">{{ result.total.toLocaleString() }} {{ result.total === 1 ? 'transaction' : 'transactions' }}</strong>
                <template v-for="p in result.summary.paid" :key="p.currency">
                    <span class="text-mute"> · </span>{{ money(p.total, p.currency) }} paid <span class="text-mute">({{ p.count }})</span>
                    <template v-if="result.can_see_charges">
                        <span class="text-mute"> · </span>bank's revenue {{ money(p.bank_revenue, p.currency) }}<span class="text-mute"> · </span>our margin profit <strong class="font-semibold">{{ money(p.gateway_profit, p.currency) }}</strong>
                    </template>
                </template>
            </p>
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <div class="flex items-center gap-2">
                    <span class="text-mute" aria-hidden="true">Order</span>
                    <SelectBox v-model="filters.sort" small fit label="Order" :options="[{ value: '', label: 'Newest first' }, { value: 'oldest', label: 'Oldest first' }, { value: 'amount_high', label: 'Largest amount' }, { value: 'amount_low', label: 'Smallest amount' }]" @change="apply()" />
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-mute" aria-hidden="true">Show</span>
                    <SelectBox v-model="filters.per_page" small fit label="Rows per page" :options="[{ value: '10', label: '10' }, { value: '', label: '25' }, { value: '50', label: '50' }, { value: '100', label: '100' }]" @change="apply()" />
                </div>
            </div>
        </div>

        <PaymentTable :payments="result.payments" empty="No transactions match these filters." />

        <nav v-if="result.total" aria-label="Pages" class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-mute">Showing {{ result.from }}–{{ result.to }} of {{ result.total.toLocaleString() }}</p>
            <div v-if="result.pages > 1" class="flex flex-wrap items-center gap-1">
                <button type="button" class="btn btn-line !px-3" :disabled="result.page <= 1" @click="apply(result.page - 1)">Previous</button>
                <template v-for="n in pageList" :key="n">
                    <span v-if="typeof n === 'string'" class="px-1 text-mute" aria-hidden="true">…</span>
                    <button
                        v-else
                        type="button"
                        class="btn min-w-11 !px-2 tabular-nums"
                        :class="n === result.page ? 'btn-dark' : 'btn-line'"
                        :aria-current="n === result.page ? 'page' : undefined"
                        :aria-label="`Page ${n}`"
                        @click="apply(n)"
                    >
                        {{ n }}
                    </button>
                </template>
                <button type="button" class="btn btn-line !px-3" :disabled="result.page >= result.pages" @click="apply(result.page + 1)">Next</button>
            </div>
        </nav>
    </template>
</template>
