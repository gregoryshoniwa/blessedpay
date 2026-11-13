<script setup>
import SelectBox from '../components/SelectBox.vue';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get } from '../api.js';
import PageHead from '../components/PageHead.vue';
import PaymentTable from '../components/PaymentTable.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { money } from '../format.js';
import { session } from '../session.js';

const route = useRoute();
const router = useRouter();
const data = ref(null);
const problem = ref('');
const companies = ref([]);

// The address bar holds the company and the dates, so a view can be bookmarked.
const companyId = ref(session.user.is_staff ? String(route.query.company_id || '') : '');
const dates = reactive({ from: String(route.query.from || ''), to: String(route.query.to || '') });
const company = computed(() => companies.value.find((c) => String(c.id) === companyId.value));
const scope = computed(() => (companyId.value ? { company_id: companyId.value } : {}));

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const today = new Date();
const daysAgo = (n) => iso(new Date(today.getFullYear(), today.getMonth(), today.getDate() - n));
const PRESETS = [
    ['Today', iso(today), iso(today)],
    ['Last 7 days', daysAgo(6), iso(today)],
    ['This month', '', ''], // the default when no dates are given
    ['Last 30 days', daysAgo(29), iso(today)],
    ['All time', '2020-01-01', iso(today)],
];
const isPreset = ([, from, to]) => dates.from === from && dates.to === to;

function go(extra = {}) {
    const query = Object.fromEntries(Object.entries({ ...scope.value, from: dates.from, to: dates.to, ...extra }).filter(([, v]) => v !== '' && v != null));
    router.push({ path: '/', query });
}
const preset = ([, from, to]) => go({ from, to });

onMounted(async () => {
    try {
        data.value = await get('/overview', { mode: session.mode, ...scope.value, ...(dates.from ? { from: dates.from } : {}), ...(dates.to ? { to: dates.to } : {}) });
        if (session.user.is_staff) companies.value = (await get('/companies')).companies;
    } catch (e) {
        problem.value = Object.values(e.errors || {})[0]?.[0] || e.message;
    }
});

const totals = (rows) => (rows?.length ? rows.map((r) => money(r.total, r.currency)).join(' · ') : '—');
const counts = (rows) => {
    const n = (rows || []).reduce((sum, r) => sum + r.count, 0);
    return `${n} ${n === 1 ? 'payment' : 'payments'}`;
};
const day = (value) => new Date(value + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
const periodText = computed(() => {
    const p = data.value?.period;
    if (!p) return '';
    if (p.from && p.to) return p.from === p.to ? day(p.from) : `${day(p.from)} to ${day(p.to)}`;
    return p.from ? `since ${day(p.from)}` : `up to ${day(p.to)}`;
});
// Carry the dates into the transactions list.
const listQuery = (extra = {}) => ({ ...scope.value, ...(data.value?.period.from ? { from: data.value.period.from } : {}), ...(data.value?.period.to ? { to: data.value.period.to } : {}), ...extra });
const sub = computed(() => {
    const what = session.mode === 'test' ? 'Test payments: no real money has moved.' : 'Live payments.';
    return session.user.is_staff ? `${company.value ? company.value.name : 'All companies'} · ${what}` : what;
});
</script>

<template>
    <PageHead title="Overview" :sub="sub">
        <div v-if="session.user.is_staff && companies.length" class="flex items-center gap-2 text-sm">
            <span class="text-mute" aria-hidden="true">Company</span>
            <SelectBox v-model="companyId" fit label="Company" :options="[{ value: '', label: 'All companies' }, ...companies.map((c) => ({ value: String(c.id), label: c.name }))]" @change="go()" />
        </div>
        <router-link :to="{ path: '/payments', query: listQuery() }" class="btn btn-dark">All transactions</router-link>
    </PageHead>

    <!-- The period every "paid" figure below is for -->
    <form class="mb-5 flex flex-wrap items-end gap-x-4 gap-y-3 border border-line bg-white p-4" @submit.prevent="go()">
        <div class="flex flex-wrap gap-1" role="group" aria-label="Period">
            <button
                v-for="p in PRESETS"
                :key="p[0]"
                type="button"
                :aria-pressed="isPreset(p)"
                class="min-h-11 border px-3.5 text-[15px] font-semibold"
                :class="isPreset(p) ? 'border-ink bg-ink text-white' : 'border-line bg-white hover:border-ink'"
                @click="preset(p)"
            >
                {{ p[0] }}
            </button>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="label" for="o-from">From</label>
                <input id="o-from" v-model="dates.from" class="field !w-auto" type="date" />
            </div>
            <div>
                <label class="label" for="o-to">To</label>
                <input id="o-to" v-model="dates.to" class="field !w-auto" type="date" />
            </div>
            <button type="submit" class="btn btn-line">Show</button>
        </div>
    </form>

    <p v-if="problem" role="alert" class="border-l-4 border-ink bg-white px-4 py-3 font-medium">{{ problem }}</p>
    <p v-else-if="!data" class="text-mute">Loading…</p>

    <template v-else>
        <p class="mb-2 text-sm text-mute" aria-live="polite">Paid figures are for <strong class="font-semibold text-ink">{{ periodText }}</strong>.</p>

        <dl class="grid gap-px border border-line bg-line sm:grid-cols-2" :class="data.can_see_charges ? 'xl:grid-cols-5' : 'xl:grid-cols-3'">
            <div class="bg-white p-5">
                <dt class="text-sm text-mute">Paid</dt>
                <dd class="mt-1 font-display text-2xl font-bold">{{ totals(data.paid) }}</dd>
                <dd class="text-sm text-mute">{{ counts(data.paid) }}</dd>
            </div>
            <template v-if="data.can_see_charges">
                <div class="bg-white p-5">
                    <dt class="text-sm text-mute">Bank's revenue</dt>
                    <dd class="mt-1 font-display text-2xl font-bold">{{ totals(data.bank_revenue) }}</dd>
                    <dd class="text-sm text-mute">Fees the bank kept</dd>
                </div>
                <div class="border-t-4 border-gold bg-white p-5 pt-4">
                    <dt class="text-sm text-mute">Our margin profit</dt>
                    <dd class="mt-1 font-display text-2xl font-bold">{{ totals(data.gateway_profit) }}</dd>
                    <dd class="text-sm"><router-link to="/charges" class="link">Charges</router-link></dd>
                </div>
            </template>
            <div class="bg-white p-5">
                <dt class="text-sm text-mute">Waiting on the shopper or bank</dt>
                <dd class="mt-1 font-display text-2xl font-bold">{{ data.pending }}</dd>
                <dd class="text-sm"><router-link :to="{ path: '/payments', query: { status: 'pending', ...scope } }" class="link">See pending</router-link> <span class="text-mute">(right now)</span></dd>
            </div>
            <div class="bg-white p-5">
                <dt class="text-sm text-mute">Failed or cancelled</dt>
                <dd class="mt-1 font-display text-2xl font-bold">{{ data.failed }}</dd>
                <dd class="text-sm"><router-link :to="{ path: '/payments', query: listQuery({ status: 'failed' }) }" class="link">See failed</router-link></dd>
            </div>
        </dl>

        <p v-if="data.callbacks_failed" role="status" class="mt-5 border border-line border-l-4 border-l-[#c56a00] bg-white px-4 py-3">
            <strong class="font-semibold">{{ data.callbacks_failed }} {{ data.callbacks_failed === 1 ? 'payment has' : 'payments have' }} not reached the company's system.</strong>
            <router-link :to="{ path: '/payments', query: { callback: 'failed', ...scope } }" class="link ml-1">See them</router-link>, open one and choose "Send callback again".
        </p>

        <!-- Staff, all companies: the same figures one line per company. -->
        <template v-if="data.companies">
            <h2 class="mb-3 mt-8 text-xl font-semibold">By company</h2>
            <div class="relative overflow-x-auto border border-line bg-white">
                <table class="w-full min-w-[860px] border-collapse text-left text-[15px]">
                    <thead>
                        <tr class="bg-paper text-[13px] text-[#454545]">
                            <th scope="col" class="px-4 py-3 font-semibold">Company</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Paid</th>
                            <template v-if="data.can_see_charges">
                                <th scope="col" class="px-4 py-3 text-right font-semibold">Bank's revenue</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold">Our margin profit</th>
                            </template>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Pending</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Failed</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Callbacks failed</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in data.companies" :key="c.id" class="border-t border-line hover:bg-[#fffbf3]">
                            <td class="px-4 py-3.5">
                                <router-link :to="{ path: '/', query: { ...route.query, company_id: c.id } }" class="font-semibold text-ink underline decoration-line underline-offset-4 hover:decoration-ink">{{ c.name }}</router-link>
                                <StatusBadge v-if="c.status !== 'active'" :status="c.status === 'pending' ? 'awaiting' : c.status" class="ml-2" />
                                <div class="text-xs text-mute">{{ c.transactions }} {{ c.transactions === 1 ? 'transaction' : 'transactions' }} in all</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-right font-medium tabular-nums">
                                {{ totals(c.paid) }}
                                <div v-if="c.paid.length" class="text-xs font-normal text-mute">{{ counts(c.paid) }}</div>
                            </td>
                            <template v-if="data.can_see_charges">
                                <td class="whitespace-nowrap px-4 py-3.5 text-right tabular-nums">{{ totals(c.bank_revenue) }}</td>
                                <td class="whitespace-nowrap px-4 py-3.5 text-right font-semibold tabular-nums">{{ totals(c.gateway_profit) }}</td>
                            </template>
                            <td class="px-4 py-3.5 text-right tabular-nums">{{ c.pending }}</td>
                            <td class="px-4 py-3.5 text-right tabular-nums">{{ c.failed }}</td>
                            <td class="px-4 py-3.5 text-right tabular-nums" :class="c.callbacks_failed ? 'font-semibold' : ''">{{ c.callbacks_failed }}</td>
                            <td class="px-2 py-2 text-right">
                                <router-link :to="{ path: '/payments', query: listQuery({ company_id: c.id }) }" class="icon-btn" :aria-label="`Transactions for ${c.name}`" :title="`Transactions for ${c.name}`">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h10" /></svg>
                                </router-link>
                            </td>
                        </tr>
                        <tr v-if="!data.companies.length">
                            <td :colspan="data.can_see_charges ? 8 : 6" class="px-4 py-10 text-center text-mute">No companies yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <h2 class="mb-3 mt-8 text-xl font-semibold">Latest transactions<template v-if="company"> for {{ company.name }}</template></h2>
        <PaymentTable :payments="data.recent" :empty="`No ${session.mode} payments yet. Create a company, make a ${session.mode} key, and start one.`" />
    </template>
</template>
