<script setup>
defineProps({ title: { type: String, required: true }, sub: { type: String, default: '' } });
</script>

<template>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="font-display text-[28px] font-bold leading-tight">{{ title }}</h1>
            <p v-if="sub" class="mt-1 text-mute">{{ sub }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2"><slot /></div>
    </div>
</template>
