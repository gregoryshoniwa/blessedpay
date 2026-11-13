<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { can, session, setMode, signOut } from './session.js';

const route = useRoute();
const router = useRouter();

const links = computed(() => {
    const user = session.user;
    if (!user) return [];
    return [
        { to: '/', label: 'Overview', exact: true },
        { to: '/payments', label: 'Payments' },
        user.is_staff
            ? { to: '/companies', label: 'Companies' }
            : { to: `/companies/${user.company.id}`, label: 'Company and keys' },
        { to: '/try', label: 'Try it' },
        can('team.view') && { to: '/team', label: user.is_staff ? 'Staff' : 'Team' },
        can('activity.view') && { to: '/activity', label: 'Activity log' },
    ].filter(Boolean);
});

const isCurrent = (link) => (link.exact ? route.path === link.to : route.path.startsWith(link.to));

async function leave() {
    await signOut();
    router.push('/login');
}
</script>

<template>
    <router-view v-if="route.meta.public" />

    <div v-else-if="session.user" class="flex min-h-screen flex-wrap bg-paper">
        <aside class="flex min-w-0 flex-[1_1_240px] flex-col gap-5 bg-black px-4 py-5 text-white md:max-w-[264px]">
            <router-link to="/" class="flex items-center gap-2">
                <img src="/mark.png" alt="" width="58" height="36" class="h-9 w-[58px] object-cover" />
                <span class="font-display text-lg font-bold">Blessed<span class="text-gold-bright">Pay</span></span>
            </router-link>

            <div class="border border-[#3a3a3a] px-3 py-2.5">
                <div class="text-xs text-[#a8a8a8]">{{ session.user.is_staff ? 'BlessedPay staff' : 'Company' }}</div>
                <div class="font-semibold">{{ session.user.is_staff ? session.user.role_label : session.user.company.name }}</div>
                <div v-if="session.user.company?.status === 'pending'" class="mt-1 text-xs text-gold-bright">Awaiting approval · test only</div>
            </div>

            <nav aria-label="Console" class="flex flex-row flex-wrap gap-0.5 md:flex-col">
                <router-link
                    v-for="link in links"
                    :key="link.to"
                    :to="link.to"
                    :aria-current="isCurrent(link) ? 'page' : undefined"
                    class="border-l-[3px] px-3 py-3 text-[15px]"
                    :class="isCurrent(link) ? 'border-gold bg-[#1f1f1f] font-semibold text-white' : 'border-transparent text-haze hover:text-white'"
                >
                    {{ link.label }}
                </router-link>
            </nav>

            <div class="mt-auto grid gap-3 border-t border-edge pt-4">
                <fieldset>
                    <legend class="mb-1.5 text-xs text-[#a8a8a8]">Showing payments made with</legend>
                    <div class="grid grid-cols-2 border border-[#3a3a3a]">
                        <button
                            v-for="mode in ['test', 'live']"
                            :key="mode"
                            type="button"
                            :aria-pressed="session.mode === mode"
                            class="min-h-11 px-3 text-sm font-semibold"
                            :class="session.mode === mode ? 'bg-gold text-black' : 'text-haze hover:text-white'"
                            @click="setMode(mode)"
                        >
                            {{ mode === 'test' ? 'Test keys' : 'Live keys' }}
                        </button>
                    </div>
                </fieldset>
                <div class="text-sm">
                    <div class="truncate font-medium">{{ session.user.name }}</div>
                    <div class="truncate text-[#a8a8a8]">{{ session.user.email }}</div>
                </div>
                <button type="button" class="btn border-[#6b6b6b] text-white hover:border-white" @click="leave">Sign out</button>
            </div>
        </aside>

        <main class="min-w-0 flex-[999_1_560px] px-4 pb-12 pt-7 sm:px-6">
            <router-view :key="route.fullPath + session.mode" />
        </main>
    </div>
</template>
