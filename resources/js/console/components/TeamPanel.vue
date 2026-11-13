<script setup>
import SelectBox from './SelectBox.vue';
import { computed, onMounted, ref } from 'vue';
import { del, get, post, put } from '../api.js';
import { ago, when } from '../format.js';
import StatusBadge from './StatusBadge.vue';

// companyId null = BlessedPay staff; a number = that company's members.
const props = defineProps({ companyId: { type: Number, default: null } });

const data = ref(null);
const problem = ref('');
const note = ref('');

const inviting = ref(false);
const email = ref('');
const role = ref('');
const busy = ref(false);
const formProblem = ref('');
const link = ref('');
const copied = ref(false);

const ROLE_HELP = {
    super_admin: 'Everything, including staff, roles and secrets.',
    operations: 'Creates and suspends companies, manages company teams, re-sends callbacks.',
    support: 'Reads payments and teams. Changes nothing.',
    auditor: 'Reads payments and the activity log, and can export. Changes nothing.',
    owner: 'Everything for this company.',
    admin: 'Everything for this company except changing the owner.',
    finance: 'Sees every payment and exports reports. No keys, no team changes.',
    developer: 'Manages API keys and the callback secret, re-sends callbacks. No exports, no team changes.',
    viewer: 'Looks up payments. Changes nothing and sees nothing secret.',
};

// A member cannot hand out ownership; the server enforces it too.
const inviteRoles = computed(() =>
    Object.entries(data.value?.roles || {}).filter(([key]) => key !== 'owner' || data.value.can_name_owner),
);

async function load() {
    try {
        const params = props.companyId ? { company_id: props.companyId } : undefined;
        data.value = await get('/team', params);
        if (!role.value) role.value = props.companyId ? 'viewer' : 'support';
    } catch (e) {
        problem.value = e.message;
    }
}
onMounted(load);

async function run(action, done) {
    note.value = '';
    try {
        await action();
        note.value = done;
        await load();
    } catch (e) {
        note.value = e.message;
    }
}

const changeRole = (user, role) => run(() => put(`/team/${user.id}`, { role }), `${user.name} is now ${data.value.roles[role]}.`).then(() => load());
const setStatus = (user, status) =>
    run(() => put(`/team/${user.id}`, { status }), status === 'active' ? `${user.name} can sign in again.` : `${user.name} is suspended and signed out.`);
function remove(user) {
    if (!window.confirm(`Remove ${user.name}? They are signed out at once and can no longer sign in.`)) return;
    run(() => del(`/team/${user.id}`), `${user.name} was removed.`);
}

async function invite() {
    busy.value = true;
    formProblem.value = '';
    link.value = '';
    try {
        const body = { email: email.value, role: role.value };
        if (props.companyId) body.company_id = props.companyId;
        link.value = (await post('/invitations', body)).link;
        email.value = '';
        await load();
    } catch (e) {
        formProblem.value = Object.values(e.errors || {})[0]?.[0] || e.message;
    } finally {
        busy.value = false;
    }
}

async function resend(invitation) {
    note.value = '';
    try {
        link.value = (await post(`/invitations/${invitation.id}/resend`)).link;
        inviting.value = true;
        await load();
    } catch (e) {
        note.value = e.message;
    }
}
const revoke = (invitation) => run(() => del(`/invitations/${invitation.id}`), `The invitation for ${invitation.email} was revoked.`);

async function copy() {
    try {
        await navigator.clipboard.writeText(link.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2500);
    } catch {
        /* clipboard blocked: the link is selectable on screen */
    }
}
</script>

<template>
    <p v-if="problem" role="alert" class="border-l-4 border-ink bg-white px-4 py-3 font-medium">{{ problem }}</p>
    <p v-else-if="!data" class="text-mute">Loading…</p>

    <div v-else class="grid grid-cols-[minmax(0,1fr)] gap-5">
        <p v-if="note" role="status" class="border border-line border-l-4 border-l-gold bg-white px-4 py-3">{{ note }}</p>

        <div v-if="data.can_manage">
            <button v-if="!inviting" type="button" class="btn btn-gold" @click="inviting = true">Invite {{ companyId ? 'a member' : 'a staff member' }}</button>

            <form v-else class="grid max-w-2xl gap-4 border border-line bg-white p-5" @submit.prevent="invite">
                <h3 class="text-lg font-semibold">Invite {{ companyId ? 'a member' : 'a staff member' }}</h3>
                <p class="-mt-2 text-sm text-mute">They choose their own password from a link that works once and expires in 7 days.</p>
                <p v-if="formProblem" role="alert" class="border-l-4 border-ink bg-paper px-4 py-3 text-sm font-medium">{{ formProblem }}</p>

                <div>
                    <label class="label" :for="`invite-email-${companyId}`">Work email</label>
                    <input :id="`invite-email-${companyId}`" v-model.trim="email" class="field" type="email" required autocomplete="off" />
                </div>

                <fieldset class="grid gap-2">
                    <legend class="label">Role: choose the least access that lets them do their job</legend>
                    <label
                        v-for="[key, label] in inviteRoles"
                        :key="key"
                        class="flex cursor-pointer items-start gap-3 border p-3.5"
                        :class="role === key ? 'border-ink bg-[#fffbf3] outline outline-1 outline-ink' : 'border-[#c9c6bd] bg-white'"
                    >
                        <input v-model="role" type="radio" :value="key" class="mt-1 size-5 flex-none accent-ink" />
                        <span>
                            <strong class="font-semibold">{{ label }}</strong>
                            <span class="block text-sm text-[#454545]">{{ ROLE_HELP[key] }}</span>
                        </span>
                    </label>
                </fieldset>

                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-gold" :disabled="busy">{{ busy ? 'Sending…' : 'Send invitation' }}</button>
                    <button type="button" class="btn btn-line" @click="((inviting = false), (link = ''))">Close</button>
                </div>

                <div v-if="link" role="status" class="border border-line border-l-4 border-l-gold bg-paper p-4">
                    <p class="font-semibold">Invitation ready.</p>
                    <p class="mt-1 text-sm text-[#454545]">We tried to email it. You can also send this link yourself; it is shown only now.</p>
                    <p class="mt-2 break-all bg-white p-2.5 font-mono text-xs">{{ link }}</p>
                    <button type="button" class="btn btn-dark mt-3" @click="copy">{{ copied ? 'Copied' : 'Copy link' }}</button>
                </div>
            </form>
        </div>

        <div class="relative overflow-x-auto border border-line bg-white">
            <table class="w-full min-w-[720px] border-collapse text-left text-[15px]">
                <thead>
                    <tr class="bg-paper text-[13px] text-[#454545]">
                        <th scope="col" class="px-4 py-3 font-semibold">Person</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Role</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Last active</th>
                        <th scope="col" class="px-4 py-3 font-semibold"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in data.users" :key="user.id" class="border-t border-line">
                        <td class="px-4 py-3.5">
                            <div class="font-semibold">{{ user.name }} <span v-if="user.is_you" class="font-normal text-mute">(you)</span></div>
                            <div class="text-[13px] text-mute">{{ user.email }}</div>
                        </td>
                        <td class="px-4 py-3.5">
                            <template v-if="data.can_manage && !user.is_you && user.role !== 'owner'">
                                <label class="sr-only" :for="`role-${user.id}`">Role for {{ user.name }}</label>
                                <SelectBox :id="`role-${user.id}`" fit :model-value="user.role" :options="Object.entries(data.roles).map(([value, label]) => ({ value, label, disabled: value === 'owner' }))" @change="changeRole(user, $event)" />
                            </template>
                            <template v-else>{{ user.role_label }}</template>
                        </td>
                        <td class="px-4 py-3.5"><StatusBadge :status="user.status" /></td>
                        <td class="whitespace-nowrap px-4 py-3.5 text-mute">{{ ago(user.last_active_at) }}</td>
                        <td class="whitespace-nowrap px-4 py-3.5 text-right">
                            <template v-if="data.can_manage && !user.is_you && user.role !== 'owner'">
                                <button v-if="user.status === 'active'" type="button" class="link mr-4 min-h-11" @click="setStatus(user, 'suspended')">Suspend</button>
                                <button v-else type="button" class="link mr-4 min-h-11" @click="setStatus(user, 'active')">Restore</button>
                                <button type="button" class="link min-h-11" @click="remove(user)">Remove</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!data.users.length">
                        <td colspan="5" class="px-4 py-10 text-center text-mute">Nobody yet. Invite the first person above.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <section v-if="data.invitations.length" aria-label="Waiting to join" class="grid gap-2">
            <h3 class="text-lg font-semibold">Waiting to join</h3>
            <div v-for="invitation in data.invitations" :key="invitation.id" class="flex flex-wrap items-center justify-between gap-3 border border-line bg-white px-5 py-4">
                <div class="min-w-0">
                    <div class="truncate font-semibold">{{ invitation.email }}</div>
                    <div class="text-[13px] text-mute">
                        Invited as {{ invitation.role_label }}<template v-if="invitation.invited_by"> by {{ invitation.invited_by }}</template>.
                        {{ invitation.expired ? 'Expired' : 'Expires' }} {{ when(invitation.expires_at) }}.
                    </div>
                </div>
                <div v-if="data.can_manage" class="flex gap-2">
                    <button type="button" class="btn btn-line" @click="resend(invitation)">New link</button>
                    <button type="button" class="btn btn-line" @click="revoke(invitation)">Revoke</button>
                </div>
            </div>
        </section>
    </div>
</template>
