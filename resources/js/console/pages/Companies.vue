<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { get, post } from '../api.js';
import PageHead from '../components/PageHead.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { when } from '../format.js';
import { can, session } from '../session.js';

const router = useRouter();
const companies = ref(null);
const problem = ref('');

const waiting = computed(() => (companies.value || []).filter((c) => c.status === 'pending').length);

const adding = ref(false);
const name = ref('');
const website = ref('');
const busy = ref(false);
const formProblem = ref('');

onMounted(async () => {
    // A member has exactly one company: take them straight to it.
    if (!session.user.is_staff) {
        router.replace(`/companies/${session.user.company.id}`);
        return;
    }
    try {
        companies.value = (await get('/companies')).companies;
    } catch (e) {
        problem.value = e.message;
    }
});

async function create() {
    busy.value = true;
    formProblem.value = '';
    try {
        const { company } = await post('/companies', { name: name.value, website: website.value || null });
        router.push(`/companies/${company.id}`);
    } catch (e) {
        formProblem.value = Object.values(e.errors || {})[0]?.[0] || e.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <PageHead title="Companies" sub="Each company has its own keys, payments and team.">
        <button v-if="can('companies.manage') && !adding" type="button" class="btn btn-gold" @click="adding = true">Add company</button>
    </PageHead>

    <form v-if="adding" class="mb-6 grid max-w-xl gap-4 border border-line bg-white p-5" @submit.prevent="create">
        <h2 class="text-lg font-semibold">New company</h2>
        <p v-if="formProblem" role="alert" class="border-l-4 border-ink bg-paper px-4 py-3 text-sm font-medium">{{ formProblem }}</p>
        <div>
            <label class="label" for="company-name">Name</label>
            <input id="company-name" v-model.trim="name" class="field" type="text" required maxlength="120" placeholder="BlessLuxe" autofocus />
        </div>
        <div>
            <label class="label" for="company-site">Website <span class="font-normal text-mute">(optional)</span></label>
            <input id="company-site" v-model.trim="website" class="field" type="url" placeholder="https://blessluxe.com" />
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="submit" class="btn btn-gold" :disabled="busy">{{ busy ? 'Creating…' : 'Create company' }}</button>
            <button type="button" class="btn btn-line" @click="adding = false">Cancel</button>
        </div>
    </form>

    <p v-if="problem" role="alert" class="border-l-4 border-ink bg-white px-4 py-3 font-medium">{{ problem }}</p>
    <p v-else-if="!companies" class="text-mute">Loading…</p>

    <p v-if="companies && waiting" role="status" class="mb-5 border border-line border-l-4 border-l-[#c56a00] bg-white px-4 py-3">
        <strong class="font-semibold">{{ waiting }} {{ waiting === 1 ? 'company is' : 'companies are' }} waiting for approval.</strong>
        Open it to approve or suspend. Until then it can only make test payments.
    </p>

    <div v-if="companies" class="relative overflow-x-auto border border-line bg-white">
        <table class="w-full min-w-[640px] border-collapse text-left text-[15px]">
            <thead>
                <tr class="bg-paper text-[13px] text-[#454545]">
                    <th scope="col" class="px-4 py-3 font-semibold">Company</th>
                    <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                    <th scope="col" class="px-4 py-3 text-right font-semibold">Payments</th>
                    <th scope="col" class="px-4 py-3 text-right font-semibold">Team</th>
                    <th scope="col" class="px-4 py-3 font-semibold">Added</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="c in companies" :key="c.id" class="border-t border-line hover:bg-[#fffbf3]">
                    <td class="px-4 py-3.5">
                        <router-link :to="`/companies/${c.id}`" class="font-semibold text-ink underline decoration-line underline-offset-4 hover:decoration-ink">{{ c.name }}</router-link>
                        <div v-if="c.website" class="text-[13px] text-mute">{{ c.website.replace(/^https?:\/\//, '') }}</div>
                    </td>
                    <td class="px-4 py-3.5"><StatusBadge :status="c.status === 'pending' ? 'awaiting' : c.status" /></td>
                    <td class="px-4 py-3.5 text-right tabular-nums">{{ c.payments_count }}</td>
                    <td class="px-4 py-3.5 text-right tabular-nums">{{ c.users_count }}</td>
                    <td class="whitespace-nowrap px-4 py-3.5 text-mute">{{ when(c.created_at) }}</td>
                </tr>
                <tr v-if="!companies.length">
                    <td colspan="5" class="px-4 py-10 text-center text-mute">No companies yet. Add BlessLuxe to get started.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
