<script setup>
import { onMounted, ref } from 'vue';
import { get } from '../api.js';
import PageHead from '../components/PageHead.vue';
import PaymentTable from '../components/PaymentTable.vue';
import { money } from '../format.js';
import { session } from '../session.js';

const data = ref(null);
const problem = ref('');

onMounted(async () => {
    try {
        data.value = await get('/overview', { mode: session.mode });
    } catch (e) {
        problem.value = e.message;
    }
});

const totals = (rows) => (rows.length ? rows.map((r) => money(r.total, r.currency)).join(' · ') : 'Nothing yet');
const counts = (rows) => {
    const n = rows.reduce((sum, r) => sum + r.count, 0);
    return `${n} ${n === 1 ? 'payment' : 'payments'}`;
};
</script>

<template>
    <PageHead title="Overview" :sub="session.mode === 'test' ? 'Test payments: no real money has moved.' : 'Live payments.'">
        <router-link to="/payments" class="btn btn-dark">All payments</router-link>
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
                <dd class="text-sm"><router-link :to="{ path: '/payments', query: { status: 'pending' } }" class="link">See pending</router-link></dd>
            </div>
            <div class="bg-white p-5">
                <dt class="text-sm text-mute">Failed or cancelled, last 7 days</dt>
                <dd class="mt-1 font-display text-2xl font-bold">{{ data.failed_week }}</dd>
            </div>
        </dl>

        <p v-if="data.callbacks_failed" role="status" class="mt-5 border border-line border-l-4 border-l-[#c56a00] bg-white px-4 py-3">
            <strong class="font-semibold">{{ data.callbacks_failed }} {{ data.callbacks_failed === 1 ? 'payment has' : 'payments have' }} not reached the company's system.</strong>
            Open the payment and choose "Send again".
        </p>

        <h2 class="mb-3 mt-8 text-xl font-semibold">Latest payments</h2>
        <PaymentTable :payments="data.recent" :empty="`No ${session.mode} payments yet. Create a company, make a ${session.mode} key, and start one.`" />
    </template>
</template>
