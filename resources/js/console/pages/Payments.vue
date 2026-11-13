<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get } from '../api.js';
import PageHead from '../components/PageHead.vue';
import PaymentTable from '../components/PaymentTable.vue';
import { can, session } from '../session.js';

const route = useRoute();
const router = useRouter();

// The address bar holds the filters, so a filtered list can be bookmarked or shared.
const filters = reactive({
    q: route.query.q || '',
    status: route.query.status || '',
    company_id: route.query.company_id || '',
    from: route.query.from || '',
    to: route.query.to || '',
});
const page = ref(Number(route.query.page) || 1);

const result = ref(null);
const companies = ref([]);
const problem = ref('');

const params = () => ({ ...filters, mode: session.mode, page: page.value });

async function load() {
    problem.value = '';
    try {
        result.value = await get('/payments', params());
    } catch (e) {
        problem.value = Object.values(e.errors || {})[0]?.[0] || e.message;
    }
}

function apply(toPage = 1) {
    const query = Object.fromEntries(Object.entries({ ...filters, page: toPage > 1 ? toPage : '' }).filter(([, v]) => v !== ''));
    router.push({ path: '/payments', query });
}

function clear() {
    router.push('/payments');
}

const exportUrl = () => {
    const query = new URLSearchParams(Object.entries({ ...filters, mode: session.mode }).filter(([, v]) => v !== ''));
    return '/console-api/payments/export?' + query.toString();
};

onMounted(async () => {
    load();
    if (session.user.is_staff) {
        companies.value = (await get('/companies').catch(() => ({ companies: [] }))).companies;
    }
});
</script>

<template>
    <PageHead title="Payments" :sub="session.mode === 'test' ? 'Showing test payments.' : 'Showing live payments.'">
        <a v-if="can('payments.export')" :href="exportUrl()" class="btn btn-line">Download as a file</a>
    </PageHead>

    <form class="mb-5 flex flex-wrap items-end gap-3" role="search" @submit.prevent="apply()">
        <div class="min-w-0 flex-[1_1_240px]">
            <label class="label" for="q">Reference or payment id</label>
            <input id="q" v-model.trim="filters.q" class="field" type="search" placeholder="BLX-10482" />
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select id="status" v-model="filters.status" class="field">
                <option value="">Any</option>
                <option value="paid">Paid</option>
                <option value="pending">Pending</option>
                <option value="failed">Failed</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
        <div v-if="session.user.is_staff && companies.length > 1">
            <label class="label" for="company">Company</label>
            <select id="company" v-model="filters.company_id" class="field">
                <option value="">All</option>
                <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
        </div>
        <div>
            <label class="label" for="from">From</label>
            <input id="from" v-model="filters.from" class="field" type="date" />
        </div>
        <div>
            <label class="label" for="to">To</label>
            <input id="to" v-model="filters.to" class="field" type="date" />
        </div>
        <button type="submit" class="btn btn-dark">Search</button>
        <button type="button" class="btn btn-line" @click="clear">Clear</button>
    </form>

    <p v-if="problem" role="alert" class="mb-4 border-l-4 border-ink bg-white px-4 py-3 font-medium">{{ problem }}</p>
    <p v-if="!result && !problem" class="text-mute">Loading…</p>

    <template v-if="result">
        <p class="mb-2 text-sm text-mute" aria-live="polite">{{ result.total }} {{ result.total === 1 ? 'payment' : 'payments' }}</p>
        <PaymentTable :payments="result.payments" empty="No payments match." />

        <nav v-if="result.pages > 1" aria-label="Pages" class="mt-4 flex items-center justify-between gap-3">
            <button type="button" class="btn btn-line" :disabled="result.page <= 1" @click="apply(result.page - 1)">Newer</button>
            <span class="text-sm text-mute">Page {{ result.page }} of {{ result.pages }}</span>
            <button type="button" class="btn btn-line" :disabled="result.page >= result.pages" @click="apply(result.page + 1)">Older</button>
        </nav>
    </template>
</template>
