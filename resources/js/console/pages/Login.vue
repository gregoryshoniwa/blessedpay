<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AuthFrame from '../components/AuthFrame.vue';
import { signIn } from '../session.js';

const route = useRoute();
const router = useRouter();

const email = ref('');
const password = ref('');
const busy = ref(false);
const problem = ref('');

async function submit() {
    busy.value = true;
    problem.value = '';
    try {
        await signIn(email.value, password.value);
        const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : '/';
        router.replace(next);
    } catch (e) {
        problem.value = e.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthFrame heading="Sign in">
        <form class="mt-6 grid gap-4" @submit.prevent="submit">
            <p v-if="problem" role="alert" class="border-l-4 border-ink bg-paper px-4 py-3 text-sm font-medium">{{ problem }}</p>

            <div>
                <label class="label" for="email">Work email</label>
                <input id="email" v-model="email" class="field" type="email" autocomplete="username" required autofocus />
            </div>
            <div>
                <label class="label" for="password">Password</label>
                <input id="password" v-model="password" class="field" type="password" autocomplete="current-password" required />
            </div>
            <button type="submit" class="btn btn-gold py-3.5 text-base" :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in' }}</button>
        </form>

        <p class="mt-6">New here? <router-link to="/register" class="link">Create an account for your company</router-link></p>
        <p class="mt-3 text-sm text-mute">
            Joining a company that is already here? Ask its owner to invite you. If you have lost your password,
            ask an admin to remove your account and invite you again.
        </p>
    </AuthFrame>
</template>
