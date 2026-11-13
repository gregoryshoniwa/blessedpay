<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { get, put } from '../api.js';
import PageHead from '../components/PageHead.vue';
import { money } from '../format.js';
import { session } from '../session.js';

// The bank's fee per way of paying, the gateway's own margin, how both grow
// with the amount, and where the money from paid payments went.
const data = ref(null);
const problem = ref('');
const note = ref('');
const busy = ref(false);

const currency = ref('USD');
const method = ref('card');
const bearer = ref('customer');
const amount = ref('');
const period = ref('month');

const METHODS = [
    ['card', 'Card (Visa, Mastercard)'],
    ['ecocash', 'EcoCash'],
    ['bank', 'Bank account'],
];
const form = reactive({ bank: { card: {}, ecocash: {}, bank: {} }, margin: {} });

function fill() {
    const rules = data.value.rules[currency.value];
    for (const [id] of METHODS) form.bank[id] = { percent: String(rules.bank[id].percent), minimum: rules.bank[id].minimum.toFixed(2) };
    form.margin = { percent: String(rules.margin.percent), fixed: rules.margin.fixed.toFixed(2) };
}

async function load(keepForm = false) {
    problem.value = '';
    try {
        data.value = await get('/charges', {
            mode: session.mode, currency: currency.value, method: method.value, bearer: bearer.value,
            ...(Number(amount.value) > 0 ? { amount: amount.value } : {}),
        });
        if (!keepForm) fill();
    } catch (e) {
        problem.value = Object.values(e.errors || {})[0]?.[0] || e.message;
    }
}

async function save() {
    busy.value = true;
    note.value = '';
    problem.value = '';
    try {
        data.value = await put(`/charges?mode=${session.mode}&currency=${currency.value}&method=${method.value}&bearer=${bearer.value}`, { currency: currency.value, bank: form.bank, margin: form.margin });
        fill();
        note.value = `Saved. The new ${currency.value} charges apply to payments made from now on; payments already paid keep the figures they were paid with.`;
    } catch (e) {
        problem.value = Object.values(e.errors || {})[0]?.[0] || e.message;
    } finally {
        busy.value = false;
    }
}

const rules = computed(() => data.value.rules[currency.value]);
const rows = computed(() => (data.value.quote ? [{ ...data.value.quote, mine: true }, ...data.value.examples] : data.value.examples));
const totals = computed(() => data.value[period.value === 'month' ? 'month' : 'all_time']);
const names = computed(() => Object.fromEntries(data.value.companies.map((c) => [c.id, c.name])));
const profit = computed(() => totals.value.reduce((by, r) => ({ ...by, [r.currency]: (by[r.currency] || 0) + Number(r.gateway_profit) }), {}));
const sumOf = (key) => {
    const by = totals.value.reduce((acc, r) => ({ ...acc, [r.currency]: (acc[r.currency] || 0) + Number(r[key]) }), {});
    return Object.keys(by).length ? Object.entries(by).map(([c, v]) => money(v, c)).join(' · ') : '—';
};
const methodName = computed(() => METHODS.find(([id]) => id === method.value)[1]);
// In words, for the chosen way of paying.
const bankRule = computed(() => {
    const r = rules.value.bank[method.value];
    return `${r.percent}%${r.minimum > 0 ? `, at least ${money(r.minimum, currency.value)}` : ''}`;
});
const marginRule = computed(() => {
    const m = rules.value.margin;
    if (!m.percent && !m.fixed) return 'none';
    return [m.percent ? `${m.percent}% of the order` : '', m.fixed ? money(m.fixed, currency.value) : ''].filter(Boolean).join(' + ');
});

onMounted(() => load());
</script>

<template>
    <PageHead title="Charges" sub="What a payment costs, how that grows with the amount, and where the money went." />

    <p v-if="problem" role="alert" class="mb-4 border-l-4 border-[#9b1c1c] bg-white px-4 py-3 font-medium">{{ problem }}</p>
    <p v-if="note" role="status" class="mb-4 border border-line border-l-4 border-l-gold bg-white px-4 py-3">{{ note }}</p>
    <p v-if="!data && !problem" class="text-mute">Loading…</p>

    <template v-if="data">
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="flex gap-1" role="group" aria-label="Currency">
                <button v-for="c in ['USD', 'ZWG']" :key="c" type="button" :aria-pressed="currency === c" class="min-h-11 border px-4 text-[15px] font-semibold" :class="currency === c ? 'border-ink bg-ink text-white' : 'border-line bg-white hover:border-ink'" @click="((currency = c), load())">
                    {{ c }}
                </button>
            </div>
            <p class="text-sm text-mute">Charges are set separately for each currency.</p>
        </div>

        <div class="grid grid-cols-[minmax(0,1fr)] gap-6 xl:grid-cols-[minmax(0,9fr)_minmax(0,14fr)]">
            <!-- The rules -->
            <form class="self-start border border-line bg-white p-5" @submit.prevent="save">
                <h2 class="text-lg font-semibold">1. The bank's fee</h2>
                <p class="mt-1 text-sm text-mute">What TN CyberTech Bank keeps from each payment before it credits the account. Set it exactly as the bank charges it, so the figures here match the bank statement.</p>

                <div class="relative mt-4 overflow-x-auto">
                    <table class="w-full min-w-[340px] border-collapse text-left text-[15px]">
                        <thead>
                            <tr class="text-[13px] text-[#454545]">
                                <th scope="col" class="pb-2 pr-3 font-semibold">Way of paying</th>
                                <th scope="col" class="pb-2 pr-3 font-semibold">Percent</th>
                                <th scope="col" class="pb-2 font-semibold">At least</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="[id, label] in METHODS" :key="id" class="border-t border-line">
                                <th scope="row" class="py-2.5 pr-3 font-medium">{{ label }}</th>
                                <td class="py-2.5 pr-3">
                                    <label class="sr-only" :for="`p-${id}`">{{ label }} percent</label>
                                    <span class="flex items-center gap-1.5"><input :id="`p-${id}`" v-model.trim="form.bank[id].percent" class="field !w-20 !px-2 tabular-nums" type="number" min="0" max="15" step="0.001" inputmode="decimal" :disabled="!data.can_edit" /> %</span>
                                </td>
                                <td class="py-2.5">
                                    <label class="sr-only" :for="`m-${id}`">{{ label }} minimum</label>
                                    <input :id="`m-${id}`" v-model.trim="form.bank[id].minimum" class="field !w-20 !px-2 tabular-nums" type="number" min="0" max="1000" step="0.01" inputmode="decimal" :disabled="!data.can_edit" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h2 class="mt-7 text-lg font-semibold">2. The gateway margin</h2>
                <p class="mt-1 text-sm text-mute">BlessedPay's own charge on each paid payment, on top of the bank's fee. It is what the figures below call gateway profit.</p>
                <div class="mt-4 flex flex-wrap gap-4">
                    <div>
                        <label class="label" for="mp">Percent of the order</label>
                        <span class="flex items-center gap-1.5"><input id="mp" v-model.trim="form.margin.percent" class="field !w-28 tabular-nums" type="number" min="0" max="15" step="0.001" inputmode="decimal" :disabled="!data.can_edit" /> %</span>
                    </div>
                    <div>
                        <label class="label" for="mf">Plus a fixed amount ({{ currency }})</label>
                        <input id="mf" v-model.trim="form.margin.fixed" class="field !w-28 tabular-nums" type="number" min="0" max="1000" step="0.01" inputmode="decimal" :disabled="!data.can_edit" />
                    </div>
                </div>

                <template v-if="data.can_edit">
                    <button type="submit" class="btn btn-dark mt-6" :disabled="busy">{{ busy ? 'Saving…' : `Save the ${currency} charges` }}</button>
                    <p class="mt-2 text-sm text-mute">Applies to payments made from now on. The change is written to the activity log.</p>
                </template>
                <p v-else class="mt-6 text-sm text-mute">These are set by BlessedPay staff.</p>

                <h2 class="mt-7 text-lg font-semibold">3. Who pays them</h2>
                <p class="mt-1 text-sm text-mute">Each company chooses on its own page: added on top for the shopper, or taken from what the company receives.</p>
                <ul class="mt-3 grid gap-px border border-line bg-line text-[15px]">
                    <li v-for="c in data.companies" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 bg-white px-3 py-2.5">
                        <router-link :to="`/companies/${c.id}`" class="link">{{ c.name }}</router-link>
                        <span>{{ c.fee_bearer === 'customer' ? 'The shopper pays, on top' : 'The company absorbs them' }}</span>
                    </li>
                </ul>
            </form>

            <div class="grid min-w-0 grid-cols-[minmax(0,1fr)] content-start gap-6">
                <!-- How it grows -->
                <section aria-labelledby="grow" class="border border-line bg-white p-5">
                    <h2 id="grow" class="text-lg font-semibold">How it grows with the amount</h2>
                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        <div class="min-w-0 flex-[1_1_170px]">
                            <label class="label" for="g-method">Way of paying</label>
                            <select id="g-method" v-model="method" class="field" @change="load(true)">
                                <option v-for="[id, label] in METHODS" :key="id" :value="id">{{ label }}</option>
                            </select>
                        </div>
                        <div class="min-w-0 flex-[1_1_190px]">
                            <label class="label" for="g-bearer">Who pays the charges</label>
                            <select id="g-bearer" v-model="bearer" class="field" @change="load(true)">
                                <option value="customer">The shopper, on top</option>
                                <option value="merchant">The company absorbs them</option>
                            </select>
                        </div>
                        <form class="flex min-w-0 flex-[1_1_200px] items-end gap-2" @submit.prevent="load(true)">
                            <div class="min-w-0 flex-1">
                                <label class="label" for="g-amount">Try an amount</label>
                                <input id="g-amount" v-model.trim="amount" class="field tabular-nums" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="e.g. 37.50" />
                            </div>
                            <button type="submit" class="btn btn-line">Work out</button>
                        </form>
                    </div>

                    <p class="mt-4 border-l-4 border-gold bg-paper px-3 py-2.5 text-[15px]">
                        <strong class="font-semibold">{{ methodName }} in {{ currency }}:</strong> the bank keeps {{ bankRule }}; the gateway margin is {{ marginRule }}.
                        <template v-if="rules.bank[method].minimum > 0"> On small payments the bank's minimum is the whole fee, which is why a {{ money(1, currency) }} order carries {{ money(rules.bank[method].minimum, currency) }}; the percentage takes over on larger ones.</template>
                    </p>

                    <div class="relative mt-4 overflow-x-auto border border-line">
                        <table class="w-full min-w-[560px] border-collapse text-right text-[15px] tabular-nums [&_td]:px-2.5 [&_th]:px-2.5">
                            <thead>
                                <tr class="bg-paper text-[13px] text-[#454545]">
                                    <th scope="col" class="px-3 py-2.5 text-left font-semibold">Order ({{ currency }})</th>
                                    <th scope="col" class="px-3 py-2.5 font-semibold">Shopper pays</th>
                                    <th scope="col" class="px-3 py-2.5 font-semibold">Bank keeps</th>
                                    <th scope="col" class="px-3 py-2.5 font-semibold">Bank credits</th>
                                    <th scope="col" class="px-3 py-2.5 font-semibold">Gateway margin</th>
                                    <th scope="col" class="px-3 py-2.5 font-semibold">Company keeps</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(r, i) in rows" :key="i" class="border-t border-line" :class="r.mine ? 'bg-[#fffaec] font-semibold' : ''">
                                    <th scope="row" class="whitespace-nowrap px-3 py-2 text-left font-medium">{{ r.amount }}<span v-if="r.mine" class="block text-xs font-normal text-mute">your amount</span></th>
                                    <td class="px-3 py-2">{{ r.charged }}<span v-if="Number(r.fee) > 0" class="block text-xs font-normal text-mute">+{{ r.fee }}</span></td>
                                    <td class="px-3 py-2">{{ r.bank_fee }}</td>
                                    <td class="px-3 py-2">{{ r.bank_credits }}</td>
                                    <td class="px-3 py-2">{{ r.margin }}</td>
                                    <td class="px-3 py-2">{{ r.company_keeps }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-2 text-sm text-mute">Shopper pays − bank keeps = bank credits. Bank credits − gateway margin = company keeps. Every line is to the cent.</p>
                </section>

                <!-- Where it went -->
                <section aria-labelledby="went" class="border border-line bg-white p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="went" class="text-lg font-semibold">Where the money went <span class="font-normal text-mute">({{ session.mode }} payments)</span></h2>
                        <div class="flex gap-1" role="group" aria-label="Period">
                            <button v-for="[key, label] in [['month', 'This month'], ['all', 'All time']]" :key="key" type="button" :aria-pressed="period === key" class="min-h-10 border px-3 text-sm font-semibold" :class="period === key ? 'border-ink bg-ink text-white' : 'border-line bg-white hover:border-ink'" @click="period = key">
                                {{ label }}
                            </button>
                        </div>
                    </div>

                    <dl class="mt-4 grid gap-px border border-line bg-line sm:grid-cols-3">
                        <div class="bg-white p-4">
                            <dt class="text-sm text-mute">Gateway profit</dt>
                            <dd class="mt-1 font-display text-2xl font-bold">{{ Object.keys(profit).length ? Object.entries(profit).map(([c, v]) => money(v, c)).join(' · ') : '—' }}</dd>
                        </div>
                        <div class="bg-white p-4">
                            <dt class="text-sm text-mute">Kept by the bank</dt>
                            <dd class="mt-1 font-display text-2xl font-bold">{{ sumOf('bank_kept') }}</dd>
                        </div>
                        <div class="bg-white p-4">
                            <dt class="text-sm text-mute">Credited by the bank</dt>
                            <dd class="mt-1 font-display text-2xl font-bold">{{ sumOf('bank_credited') }}</dd>
                        </div>
                    </dl>

                    <div class="relative mt-4 overflow-x-auto border border-line">
                        <table class="w-full min-w-[600px] border-collapse text-right text-[15px] tabular-nums [&_td]:px-2.5 [&_th]:px-2.5">
                            <thead>
                                <tr class="bg-paper text-[13px] text-[#454545]">
                                    <th scope="col" class="px-3 py-2.5 text-left font-semibold">Company</th>
                                    <th scope="col" class="px-3 py-2.5 font-semibold">Shoppers paid</th>
                                    <th scope="col" class="px-3 py-2.5 font-semibold">Bank kept</th>
                                    <th scope="col" class="px-3 py-2.5 font-semibold">Bank credited</th>
                                    <th scope="col" class="px-3 py-2.5 font-semibold">Gateway profit</th>
                                    <th scope="col" class="px-3 py-2.5 font-semibold">Company kept</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in totals" :key="r.company_id + r.currency" class="border-t border-line">
                                    <th scope="row" class="whitespace-nowrap px-3 py-2 text-left font-medium">{{ names[r.company_id] || 'Company' }}<span class="block text-xs font-normal text-mute">{{ r.payments }} paid · {{ r.currency }}</span></th>
                                    <td class="px-3 py-2">{{ r.shoppers_paid }}</td>
                                    <td class="px-3 py-2">{{ r.bank_kept }}</td>
                                    <td class="px-3 py-2 font-semibold">{{ r.bank_credited }}</td>
                                    <td class="px-3 py-2 font-semibold">{{ r.gateway_profit }}</td>
                                    <td class="px-3 py-2">{{ r.companies_kept }}</td>
                                </tr>
                                <tr v-if="!totals.length">
                                    <td colspan="6" class="px-3 py-8 text-center text-mute">No paid {{ session.mode }} payments {{ period === 'month' ? 'this month' : 'yet' }}.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-2 text-sm text-mute">
                        "Bank credited" is the figure to compare with the bank statement. The bank pays everything into one account: the gateway profit is counted here, it is not held apart at the bank.
                        Each payment shows the same breakdown on its own page.
                    </p>
                </section>
            </div>
        </div>
    </template>
</template>
