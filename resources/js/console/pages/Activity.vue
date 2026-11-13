<script setup>
import { onMounted, ref } from 'vue';
import { get } from '../api.js';
import PageHead from '../components/PageHead.vue';
import { when } from '../format.js';

const result = ref(null);
const problem = ref('');

async function load(page = 1) {
    try {
        result.value = await get('/activity', { page });
    } catch (e) {
        problem.value = e.message;
    }
}
onMounted(() => load());
</script>

<template>
    <PageHead title="Activity log" sub="Who did what in the console. Entries cannot be edited or deleted." />

    <p v-if="problem" role="alert" class="border-l-4 border-ink bg-white px-4 py-3 font-medium">{{ problem }}</p>
    <p v-else-if="!result" class="text-mute">Loading…</p>

    <template v-else>
        <div class="relative overflow-x-auto border border-line bg-white">
            <table class="w-full min-w-[600px] border-collapse text-left text-[15px]">
                <thead>
                    <tr class="bg-paper text-[13px] text-[#454545]">
                        <th scope="col" class="px-4 py-3 font-semibold">When</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Who</th>
                        <th scope="col" class="px-4 py-3 font-semibold">What</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in result.entries" :key="entry.id" class="border-t border-line">
                        <td class="whitespace-nowrap px-4 py-3 text-mute">{{ when(entry.at) }}</td>
                        <td class="px-4 py-3 font-medium">{{ entry.actor }}</td>
                        <td class="px-4 py-3">{{ entry.summary }}</td>
                    </tr>
                    <tr v-if="!result.entries.length">
                        <td colspan="3" class="px-4 py-10 text-center text-mute">Nothing recorded yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="result.pages > 1" aria-label="Pages" class="mt-4 flex items-center justify-between gap-3">
            <button type="button" class="btn btn-line" :disabled="result.page <= 1" @click="load(result.page - 1)">Newer</button>
            <span class="text-sm text-mute">Page {{ result.page }} of {{ result.pages }}</span>
            <button type="button" class="btn btn-line" :disabled="result.page >= result.pages" @click="load(result.page + 1)">Older</button>
        </nav>
    </template>
</template>
