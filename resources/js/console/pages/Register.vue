<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import AuthFrame from '../components/AuthFrame.vue';
import { post } from '../api.js';
import { adopt } from '../session.js';

const router = useRouter();

const form = reactive({ company_name: '', website: '', name: '', email: '', password: '', password_confirmation: '' });
const busy = ref(false);
const problem = ref('');
const errors = ref({});

async function submit() {
    problem.value = '';
    errors.value = {};
    if (form.password.length < 12) {
        errors.value = { password: ['Choose a password of 12 characters or more.'] };
        return;
    }
    if (form.password !== form.password_confirmation) {
        errors.value = { password_confirmation: ['The two passwords do not match.'] };
        return;
    }
    busy.value = true;
    try {
        const data = await post('/register', { ...form, website: form.website || null });
        adopt(data);
        router.replace(`/companies/${data.user.company.id}`);
    } catch (e) {
        errors.value = e.errors || {};
        if (!Object.keys(errors.value).length) problem.value = e.message;
    } finally {
        busy.value = false;
    }
}

const err = (field) => errors.value[field]?.[0];
</script>

<template>
    <AuthFrame heading="Create an account">
        <p class="mt-2 text-mute">Set up your company and get test keys straight away. Live payments open once BlessedPay has approved your company.</p>

        <form class="mt-6 grid gap-4" novalidate @submit.prevent="submit">
            <p v-if="problem" role="alert" class="border-l-4 border-ink bg-paper px-4 py-3 text-sm font-medium">{{ problem }}</p>

            <fieldset class="grid gap-4">
                <legend class="mb-3 font-mono text-xs tracking-wide text-gold-ink">YOUR COMPANY</legend>
                <div>
                    <label class="label" for="company_name">Company name</label>
                    <input id="company_name" v-model.trim="form.company_name" class="field" type="text" autocomplete="organization" required maxlength="120" :aria-invalid="!!err('company_name')" aria-describedby="company_name-err" />
                    <p v-if="err('company_name')" id="company_name-err" class="mt-1.5 text-sm font-medium">{{ err('company_name') }}</p>
                </div>
                <div>
                    <label class="label" for="website">Website <span class="font-normal text-mute">(optional)</span></label>
                    <input id="website" v-model.trim="form.website" class="field" type="url" autocomplete="url" placeholder="https://" :aria-invalid="!!err('website')" aria-describedby="website-err" />
                    <p v-if="err('website')" id="website-err" class="mt-1.5 text-sm font-medium">{{ err('website') }}</p>
                </div>
            </fieldset>

            <fieldset class="grid gap-4">
                <legend class="mb-3 mt-2 font-mono text-xs tracking-wide text-gold-ink">YOU</legend>
                <div>
                    <label class="label" for="name">Your name</label>
                    <input id="name" v-model.trim="form.name" class="field" type="text" autocomplete="name" required maxlength="120" :aria-invalid="!!err('name')" aria-describedby="name-err" />
                    <p v-if="err('name')" id="name-err" class="mt-1.5 text-sm font-medium">{{ err('name') }}</p>
                </div>
                <div>
                    <label class="label" for="email">Work email</label>
                    <input id="email" v-model.trim="form.email" class="field" type="email" autocomplete="username" required :aria-invalid="!!err('email')" aria-describedby="email-err" />
                    <p v-if="err('email')" id="email-err" class="mt-1.5 text-sm font-medium">{{ err('email') }}</p>
                </div>
                <div>
                    <label class="label" for="password">Choose a password</label>
                    <input id="password" v-model="form.password" class="field" type="password" autocomplete="new-password" required :aria-invalid="!!err('password')" aria-describedby="password-help" />
                    <p id="password-help" class="mt-1.5 text-sm" :class="err('password') ? 'font-medium' : 'text-mute'">{{ err('password') || '12 characters or more. A few unrelated words works well.' }}</p>
                </div>
                <div>
                    <label class="label" for="password_confirmation">Type it again</label>
                    <input id="password_confirmation" v-model="form.password_confirmation" class="field" type="password" autocomplete="new-password" required :aria-invalid="!!err('password_confirmation')" aria-describedby="again-err" />
                    <p v-if="err('password_confirmation')" id="again-err" class="mt-1.5 text-sm font-medium">{{ err('password_confirmation') }}</p>
                </div>
            </fieldset>

            <button type="submit" class="btn btn-gold py-3.5 text-base" :disabled="busy">{{ busy ? 'Creating your account…' : 'Create account' }}</button>
        </form>

        <p class="mt-6">Already have an account? <router-link to="/login" class="link">Sign in</router-link></p>
    </AuthFrame>
</template>
