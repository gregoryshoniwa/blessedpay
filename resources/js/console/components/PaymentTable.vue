<script setup>
import { computed } from 'vue';
import { METHODS, money, when } from '../format.js';
import { can, session } from '../session.js';
import StatusBadge from './StatusBadge.vue';

defineProps({ payments: { type: Array, required: true }, empty: { type: String, default: 'No payments yet.' } });

// What the bank kept and the gateway's margin are for those who may see charges (the server only sends them then).
const charges = computed(() => can('charges.view'));
// The mark of what was paid with (the same files the checkout shows), named for screen readers.
const MARKS = {
    ecocash: [{ src: '/pay/ecocash.svg', alt: 'EcoCash', w: 62 }],
    card: [{ src: '/pay/visa.svg', alt: 'Card: Visa', w: 40 }, { src: '/pay/mastercard.svg', alt: 'or Mastercard', w: 28 }],
    bank: [{ src: '/pay/tncb.png', alt: 'TN CyberTech Bank account', w: 70 }],
};
const columns = computed(() => 6 + (session.user.is_staff ? 1 : 0) + (charges.value ? 3 : 0));
const plain = (value) => (value === null || value === undefined ? '—' : Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
</script>

<template>
    <div class="relative overflow-x-auto border border-line bg-white">
        <table class="w-full border-collapse text-left text-[15px]" :class="charges ? 'min-w-[1020px]' : 'min-w-[760px]'">
            <thead>
                <tr class="bg-paper text-[13px] text-[#454545]">
                    <th scope="col" class="px-4 py-3 font-semibold">Reference</th>
                    <th v-if="session.user.is_staff" scope="col" class="px-4 py-3 font-semibold">Company</th>
                    <th scope="col" class="px-4 py-3 text-right font-semibold">Amount</th>
                    <template v-if="charges">
                        <th scope="col" class="px-3 py-3 text-right font-semibold" title="Added on top for the shopper to pay">Fee on top</th>
                        <th scope="col" class="px-3 py-3 text-right font-semibold" title="What the bank kept">Bank fee</th>
                        <th scope="col" class="px-3 py-3 text-right font-semibold" title="The gateway's margin: our profit">Our margin</th>
                    </template>
                    <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                    <th scope="col" class="px-4 py-3 font-semibold">Paid with</th>
                    <th scope="col" class="px-4 py-3 font-semibold">Started</th>
                    <th scope="col" class="px-2 py-3"><span class="sr-only">Open</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in payments" :key="p.id" class="border-t border-line hover:bg-[#fffbf3]">
                    <td class="px-4 py-3">
                        <router-link :to="`/payments/${p.id}`" class="font-semibold text-ink underline decoration-line underline-offset-4 hover:decoration-ink">{{ p.reference }}</router-link>
                        <div class="font-mono text-xs text-mute">{{ p.id }}</div>
                    </td>
                    <td v-if="session.user.is_staff" class="whitespace-nowrap px-4 py-3">{{ p.company?.name }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums">{{ money(p.amount, p.currency) }}</td>
                    <template v-if="charges">
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ Number(p.fee) > 0 ? plain(p.fee) : '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ plain(p.bank_fee) }}</td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums" :class="Number(p.margin) > 0 ? 'font-semibold' : ''">{{ plain(p.margin) }}</td>
                    </template>
                    <td class="px-4 py-3">
                        <StatusBadge :status="p.status" />
                        <!-- There is no callback column: only a callback that FAILED is worth a line here. -->
                        <div v-if="p.callback_status === 'failed'" class="mt-1 text-xs font-semibold text-[#9b1c1c]">Callback failed</div>
                    </td>
                    <td class="whitespace-nowrap px-4 py-3">
                        <span v-if="MARKS[p.method]" class="flex items-center gap-2" :title="METHODS[p.method]">
                            <img v-for="mark in MARKS[p.method]" :key="mark.src" :src="mark.src" :alt="mark.alt" :width="mark.w" :style="{ width: mark.w + 'px' }" class="max-h-6 object-contain mix-blend-multiply" />
                        </span>
                        <template v-else-if="p.method">{{ METHODS[p.method] || p.method }}</template>
                        <span v-else class="text-mute">—</span>
                        <div v-if="p.tries > 1 || (p.tries === 1 && !p.method)" class="text-xs text-mute">{{ p.tries }} {{ p.tries === 1 ? 'try' : 'tries' }}</div>
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 text-mute">{{ when(p.created_at) }}</td>
                    <td class="px-2 py-1.5 text-right">
                        <router-link :to="`/payments/${p.id}`" class="icon-btn" :aria-label="`See every step of ${p.reference}`" title="See every step">
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></svg>
                        </router-link>
                    </td>
                </tr>
                <tr v-if="!payments.length">
                    <td :colspan="columns" class="px-4 py-10 text-center text-mute">{{ empty }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
