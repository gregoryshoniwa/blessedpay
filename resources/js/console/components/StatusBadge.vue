<script setup>
import { computed } from 'vue';

const props = defineProps({ status: { type: String, required: true } });

// Told apart by lightness and wording, not by red-versus-green alone.
const LOOKS = {
    paid: ['Paid', 'bg-[#e3f0f8] text-[#0f4f7a]'],
    active: ['Active', 'bg-[#e3f0f8] text-[#0f4f7a]'],
    delivered: ['Delivered', 'bg-[#e3f0f8] text-[#0f4f7a]'],
    pending: ['Pending', 'bg-[#fbebd3] text-[#7a4200]'],
    awaiting: ['Awaiting approval', 'bg-[#fbebd3] text-[#7a4200]'],
    invited: ['Invited', 'bg-[#fbebd3] text-[#7a4200]'],
    failed: ['Failed', 'bg-ink text-white'],
    cancelled: ['Cancelled', 'bg-[#eaeaea] text-[#3d3d3d]'],
    suspended: ['Suspended', 'bg-[#eaeaea] text-[#3d3d3d]'],
    revoked: ['Revoked', 'bg-[#eaeaea] text-[#3d3d3d]'],
    test: ['Test', 'bg-gold text-black'],
    live: ['Live', 'bg-ink text-white'],
};

const look = computed(() => LOOKS[props.status] || [props.status, 'bg-[#eaeaea] text-[#3d3d3d]']);
</script>

<template>
    <span class="inline-block whitespace-nowrap px-2.5 py-1 text-[13px] font-semibold" :class="look[1]">{{ look[0] }}</span>
</template>
