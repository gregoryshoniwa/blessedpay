<script setup>
defineProps({ heading: { type: String, required: true } });
</script>

<!-- The frame around sign-in and invitation: brand and an anti-phishing reminder
     on black, the form on white. Stacks on a phone. -->
<template>
    <div class="flex min-h-screen flex-wrap bg-white text-ink">
        <div class="gridbg flex min-w-0 flex-[1_1_360px] flex-col justify-between gap-12 bg-black px-6 py-10 text-white sm:px-8">
            <a href="/" class="flex items-center gap-2.5">
                <img src="/mark.png" alt="" width="78" height="48" class="h-12 w-[78px] object-cover" />
                <span class="font-display text-2xl font-bold">Blessed<span class="text-gold-bright">Pay</span></span>
            </a>
            <div class="max-w-md">
                <p class="font-display text-3xl font-bold leading-tight">Check the address before you type.</p>
                <p class="mt-4 text-haze">
                    This page is always at <strong class="font-mono font-medium text-gold-bright">pay.blessluxe.com</strong>.
                    We never ask for your password by phone, email or WhatsApp.
                </p>
            </div>
            <p class="text-sm text-[#a8a8a8]">Payments are processed by TN CyberTech Bank.</p>
        </div>

        <div class="flex min-w-0 flex-[999_1_400px] items-center justify-center px-5 py-12">
            <div class="w-full max-w-md">
                <h1 class="font-display text-3xl font-bold">{{ heading }}</h1>
                <slot />
            </div>
        </div>
    </div>
</template>
