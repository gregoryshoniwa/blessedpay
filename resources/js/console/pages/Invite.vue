<script setup>
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AuthFrame from '../components/AuthFrame.vue';
import { get, post } from '../api.js';
import { adopt } from '../session.js';

const route = useRoute();
const router = useRouter();
const token = route.params.token;

const invite = ref(null);
const dead = ref('');
const name = ref('');
const password = ref('');
const again = ref('');
const busy = ref(false);
const problem = ref('');

onMounted(async () => {
    try {
        invite.value = await get(`/invitations/${token}`);
    } catch (e) {
        dead.value = e.message;
    }
});

async function submit() {
    problem.value = '';
    if (password.value.length < 12) {
        problem.value = 'Choose a password of 12 characters or more.';
        return;
    }
    if (password.value !== again.value) {
        problem.value = 'The two passwords do not match.';
        return;
    }
    busy.value = true;
    try {
        adopt(await post(`/invitations/${token}`, { name: name.value, password: password.value, password_confirmation: again.value }));
        router.replace('/');
    } catch (e) {
        problem.value = Object.values(e.errors || {})[0]?.[0] || e.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthFrame heading="Join BlessedPay">
        <div v-if="dead" class="mt-6">
            <p role="alert" class="border-l-4 border-ink bg-paper px-4 py-3 font-medium">{{ dead }}</p>
            <router-link to="/login" class="link mt-4 inline-block">Go to sign in</router-link>
        </div>

        <p v-else-if="!invite" class="mt-6 text-mute">Checking your invitation…</p>

        <form v-else class="mt-4 grid gap-4" @submit.prevent="submit">
            <p class="text-mute">
                You are joining <strong class="text-ink">{{ invite.company || 'BlessedPay staff' }}</strong> as
                <strong class="text-ink">{{ invite.role_label }}</strong
                >, signing in with <strong class="text-ink">{{ invite.email }}</strong
                >.
            </p>
            <p v-if="problem" role="alert" class="border-l-4 border-ink bg-paper px-4 py-3 text-sm font-medium">{{ problem }}</p>

            <div>
                <label class="label" for="name">Your name</label>
                <input id="name" v-model="name" class="field" type="text" autocomplete="name" required maxlength="120" />
            </div>
            <div>
                <label class="label" for="new-password">Choose a password</label>
                <input id="new-password" v-model="password" class="field" type="password" autocomplete="new-password" required aria-describedby="pw-help" />
                <p id="pw-help" class="mt-1.5 text-sm text-mute">12 characters or more. A few unrelated words works well.</p>
            </div>
            <div>
                <label class="label" for="again">Type it again</label>
                <input id="again" v-model="again" class="field" type="password" autocomplete="new-password" required />
            </div>
            <button type="submit" class="btn btn-gold py-3.5 text-base" :disabled="busy">{{ busy ? 'Creating your account…' : 'Create account and sign in' }}</button>
        </form>
    </AuthFrame>
</template>
