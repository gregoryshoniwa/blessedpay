<script setup>
import { money, when } from '../format.js';
import { session } from '../session.js';
import StatusBadge from './StatusBadge.vue';

defineProps({ payments: { type: Array, required: true }, empty: { type: String, default: 'No payments yet.' } });
</script>

<template>
    <div class="relative overflow-x-auto border border-line bg-white">
        <table class="w-full min-w-[760px] border-collapse text-left text-[15px]">
            <thead>
                <tr class="bg-paper text-[13px] text-[#454545]">
                    <th scope="col" class="px-4 py-3 font-semibold">Reference</th>
                    <th v-if="session.user.is_staff" scope="col" class="px-4 py-3 font-semibold">Company</th>
                    <th scope="col" class="px-4 py-3 text-right font-semibold">Amount</th>
                    <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                    <th scope="col" class="px-4 py-3 font-semibold">Callback</th>
                    <th scope="col" class="px-4 py-3 font-semibold">Started</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in payments" :key="p.id" class="border-t border-line hover:bg-[#fffbf3]">
                    <td class="px-4 py-3.5">
                        <router-link :to="`/payments/${p.id}`" class="font-semibold text-ink underline decoration-line underline-offset-4 hover:decoration-ink">{{ p.reference }}</router-link>
                        <div class="font-mono text-xs text-mute">{{ p.id }}</div>
                    </td>
                    <td v-if="session.user.is_staff" class="px-4 py-3.5">{{ p.company?.name }}</td>
                    <td class="whitespace-nowrap px-4 py-3.5 text-right font-medium tabular-nums">{{ money(p.amount, p.currency) }}</td>
                    <td class="px-4 py-3.5"><StatusBadge :status="p.status" /></td>
                    <td class="px-4 py-3.5">
                        <StatusBadge v-if="p.callback_status" :status="p.callback_status" />
                        <span v-else class="text-mute">—</span>
                    </td>
                    <td class="whitespace-nowrap px-4 py-3.5 text-mute">{{ when(p.created_at) }}</td>
                </tr>
                <tr v-if="!payments.length">
                    <td :colspan="session.user.is_staff ? 6 : 5" class="px-4 py-10 text-center text-mute">{{ empty }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
