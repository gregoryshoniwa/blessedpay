<script setup>
import { computed, onMounted, ref } from 'vue';
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

// Staff can look at one company at a time: ?company_id= narrows every figure on the page.
const companyId = ref(session.user.is_staff ? String(route.query.company_id || '') : '');
const company = computed(() => companies.value.find((c) => String(c.id) === companyId.value));
const scope = computed(() => (companyId.value ? { company_id: companyId.value } : {}));

onMounted(async () => {
    try {
        data.value = await get('/overview', { mode: session.mode, ...scope.value });
        if (session.user.is_staff) companies.value = (await get('/companies')).companies;
    } catch (e) {
        problem.value = e.message;
    }
});

const pick = () => router.push({ path: '/', query: scope.value });
const totals = (rows) => (rows.length ? rows.map((r) => money(r.total, r.currency)).join(' · ') : 'Nothing yet');
const counts = (rows) => {
    const n = rows.reduce((sum, r) => sum + r.count, 0);
    return `${n} ${n === 1 ? 'payment' : 'payments'}`;
};
const sub = computed(() => {
    const what = session.mode === 'test' ? 'Test payments: no real money has moved.' : 'Live payments.';
    return session.user.is_staff ? `${company.value ? company.value.name : 'All companies'} · ${what}` : what;
});
</script>

<template>
    <PageHead title="Overview" :sub="sub">
        <label v-if="session.user.is_staff && companies.length" class="flex items-center gap-2 text-sm">
            <span class="text-mute">Company</span>
            <select v-model="companyId" class="field !w-auto" @change="pick">
                <option value="">All companies</option>
                <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
        </label>
        <router-link :to="{ path: '/payments', query: scope }" class="btn btn-dark">All transactions</router-link>
    </PageHead>

    <p v-if="problem" role="alert" class="border-l-4 border-ink bg-white px-4 py-3 font-medium">{{ problem }}</p>
    <p v-else-if="!data" class="text-mute">Loading…</p>

    <template v-else>
        <dl class="grid gap-px border border-line bg-line sm:grid-cols-2 xl:grid-cols-4">
            <div class="bg-white p-5">
                <dt class="text-sm text-mute">Paid today</dt>
                <dd class="mt-1 font-display text-2xl font-bold">{{ totals(data.paid_today) }}</dd>
                <dd class="text-sm text-mute">{{ counts(data.paid_today) }}</dd>
            </div>
            <div class="bg-white p-5">
                <dt class="text-sm text-mute">Paid this month</dt>
                <dd class="mt-1 font-display text-2xl font-bold">{{ totals(data.paid_month) }}</dd>
                <dd class="text-sm text-mute">{{ counts(data.paid_month) }}</dd>
            </div>
            <div class="bg-white p-5">
                <dt class="text-sm text-mute">Waiting on the shopper or bank</dt>
                <dd class="mt-1 font-display text-2xl font-bold">{{ data.pending }}</dd>
                <dd class="text-sm"><router-link :to="{ path: '/payments', query: { status: 'pending', ...scope } }" class="link">See pending</router-link></dd>
            </div>
            <div class="bg-white p-5">
                <dt class="text-sm text-mute">Failed or cancelled, last 7 days</dt>
                <dd class="mt-1 font-display text-2xl font-bold">{{ data.failed_week }}</dd>
                <dd class="text-sm"><router-link :to="{ path: '/payments', query: { status: 'failed', ...scope } }" class="link">See failed</router-link></dd>
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
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Paid today</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Paid this month</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Pending</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Failed, 7 days</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Callbacks failed</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in data.companies" :key="c.id" class="border-t border-line hover:bg-[#fffbf3]">
                            <td class="px-4 py-3.5">
                                <router-link :to="{ path: '/', query: { company_id: c.id } }" class="font-semibold text-ink underline decoration-line underline-offset-4 hover:decoration-ink">{{ c.name }}</router-link>
                                <StatusBadge v-if="c.status !== 'active'" :status="c.status === 'pending' ? 'awaiting' : c.status" class="ml-2" />
                                <div class="text-xs text-mute">{{ c.transactions }} {{ c.transactions === 1 ? 'transaction' : 'transactions' }} in all</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-right tabular-nums">{{ c.paid_today.length ? totals(c.paid_today) : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-right font-medium tabular-nums">
                                {{ c.paid_month.length ? totals(c.paid_month) : '—' }}
                                <div v-if="c.paid_month.length" class="text-xs font-normal text-mute">{{ counts(c.paid_month) }}</div>
                            </td>
                            <td class="px-4 py-3.5 text-right tabular-nums">{{ c.pending }}</td>
                            <td class="px-4 py-3.5 text-right tabular-nums">{{ c.failed_week }}</td>
                            <td class="px-4 py-3.5 text-right tabular-nums" :class="c.callbacks_failed ? 'font-semibold' : ''">{{ c.callbacks_failed }}</td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-right">
                                <router-link :to="{ path: '/payments', query: { company_id: c.id } }" class="link text-sm" :aria-label="`Transactions for ${c.name}`">Transactions</router-link>
                            </td>
                        </tr>
                        <tr v-if="!data.companies.length">
                            <td colspan="7" class="px-4 py-10 text-center text-mute">No companies yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <h2 class="mb-3 mt-8 text-xl font-semibold">Latest transactions<template v-if="company"> for {{ company.name }}</template></h2>
        <PaymentTable :payments="data.recent" :empty="`No ${session.mode} payments yet. Create a company, make a ${session.mode} key, and start one.`" />
    </template>
</template>
