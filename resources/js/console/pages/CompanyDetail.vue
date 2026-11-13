<script setup>
import SelectBox from '../components/SelectBox.vue';
import { onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { del, get, post, put } from '../api.js';
import PageHead from '../components/PageHead.vue';
import StatusBadge from '../components/StatusBadge.vue';
import TeamPanel from '../components/TeamPanel.vue';
import { ago, when } from '../format.js';
import { can, session } from '../session.js';

const route = useRoute();
const id = Number(route.params.id);

const company = ref(null);
const problem = ref('');
const note = ref('');

const form = reactive({ name: '', website: '', fee_bearer: 'merchant', embed_origins: '' });
const saving = ref(false);

const keys = ref([]);
const keyForm = reactive({ name: '', mode: 'test' });
const newKey = ref('');
const keyProblem = ref('');
const secret = ref('');
const copied = ref('');

const origin = window.location.origin;

onMounted(async () => {
    try {
        company.value = (await get(`/companies/${id}`)).company;
        form.name = company.value.name;
        form.website = company.value.website || '';
        form.fee_bearer = company.value.fee_bearer;
        form.embed_origins = company.value.embed_origins.join('\n');
        if (can('keys.view')) keys.value = (await get(`/companies/${id}/keys`)).keys;
    } catch (e) {
        problem.value = e.status === 404 ? 'No such company.' : e.message;
    }
});

const firstError = (e) => Object.values(e.errors || {})[0]?.[0] || e.message;

async function save() {
    saving.value = true;
    note.value = '';
    try {
        const sites = form.embed_origins.split(/[\s,]+/).map((s) => s.trim().replace(/\/+$/, '')).filter(Boolean);
        company.value = (await put(`/companies/${id}`, { name: form.name, website: form.website || null, fee_bearer: form.fee_bearer, embed_origins: sites })).company;
        note.value = 'Saved.';
    } catch (e) {
        note.value = firstError(e);
    } finally {
        saving.value = false;
    }
}

async function setStatus(status) {
    const warn = `Suspend ${company.value.name}? Its API keys stop working and its members are signed out until it is restored.`;
    if (status === 'suspended' && !window.confirm(warn)) return;
    try {
        const was = company.value.status;
        company.value = (await put(`/companies/${id}`, { status })).company;
        note.value = status === 'suspended' ? 'Company suspended.' : was === 'pending' ? 'Company approved. It can now use live keys.' : 'Company restored.';
    } catch (e) {
        note.value = e.message;
    }
}

async function createKey() {
    keyProblem.value = '';
    newKey.value = '';
    try {
        const data = await post(`/companies/${id}/keys`, { name: keyForm.name, mode: keyForm.mode });
        newKey.value = data.plain;
        keys.value.unshift(data.key);
        keyForm.name = '';
    } catch (e) {
        keyProblem.value = firstError(e);
    }
}

async function revokeKey(key) {
    if (!window.confirm(`Revoke "${key.name}"? Anything using this key stops working at once. This cannot be undone.`)) return;
    try {
        const data = await del(`/companies/${id}/keys/${key.id}`);
        keys.value = keys.value.map((k) => (k.id === key.id ? data.key : k));
    } catch (e) {
        keyProblem.value = e.message;
    }
}

async function showSecret() {
    try {
        secret.value = (await get(`/companies/${id}/secret`)).secret;
    } catch (e) {
        keyProblem.value = e.message;
    }
}

async function rotateSecret() {
    if (!window.confirm('Replace the signing secret? Callbacks will fail to verify until the new secret is set in the company\'s system.')) return;
    try {
        secret.value = (await post(`/companies/${id}/secret`)).secret;
    } catch (e) {
        keyProblem.value = e.message;
    }
}

async function copy(text, what) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = what;
        setTimeout(() => (copied.value = ''), 2500);
    } catch {
        /* clipboard blocked: the text is selectable on screen */
    }
}
</script>

<template>
    <p v-if="session.user.is_staff" class="mb-3 text-sm"><router-link to="/companies" class="link">Back to companies</router-link></p>

    <p v-if="problem" role="alert" class="border-l-4 border-ink bg-white px-4 py-3 font-medium">{{ problem }}</p>
    <p v-else-if="!company" class="text-mute">Loading…</p>

    <template v-else>
        <PageHead :title="company.name" :sub="`${company.payments_count} payments · added ${when(company.created_at)}`">
            <StatusBadge :status="company.status === 'pending' ? 'awaiting' : company.status" />
            <router-link :to="{ path: '/payments', query: session.user.is_staff ? { company_id: company.id } : {} }" class="btn btn-dark">See payments</router-link>
        </PageHead>

        <div v-if="company.status === 'pending'" role="status" class="mb-5 flex flex-wrap items-center justify-between gap-3 border border-line border-l-4 border-l-[#c56a00] bg-white px-4 py-3">
            <p v-if="can('companies.manage')"><strong class="font-semibold">This company signed itself up and is waiting for your decision.</strong> Until it is approved it can only make test payments.</p>
            <p v-else><strong class="font-semibold">Your company is waiting for approval.</strong> You can create test keys and try payments now. Live payments open once BlessedPay approves it.</p>
            <button v-if="can('companies.manage')" type="button" class="btn btn-gold" @click="setStatus('active')">Approve company</button>
        </div>

        <p v-if="note" role="status" class="mb-5 border border-line border-l-4 border-l-gold bg-white px-4 py-3">{{ note }}</p>

        <div class="grid grid-cols-[minmax(0,1fr)] gap-8">
            <!-- Details -->
            <section aria-labelledby="details-h" class="max-w-2xl">
                <h2 id="details-h" class="mb-3 text-xl font-semibold">Details</h2>
                <form class="grid gap-4 border border-line bg-white p-5" @submit.prevent="save">
                    <div>
                        <label class="label" for="name">Name shown to shoppers on the pay page</label>
                        <input id="name" v-model.trim="form.name" class="field" type="text" required maxlength="120" :disabled="!can('company.edit')" />
                    </div>
                    <div>
                        <label class="label" for="website">Website <span class="font-normal text-mute">(optional)</span></label>
                        <input id="website" v-model.trim="form.website" class="field" type="url" placeholder="https://" :disabled="!can('company.edit')" />
                    </div>
                    <fieldset class="grid gap-2" :disabled="!can('company.edit')">
                        <legend class="label">Who pays the processing fee? <router-link v-if="can('charges.view')" to="/charges" class="link font-normal">See the charges</router-link></legend>
                        <label class="flex cursor-pointer items-start gap-3 border p-3.5" :class="form.fee_bearer === 'merchant' ? 'border-ink bg-[#fffbf3] outline outline-1 outline-ink' : 'border-[#c9c6bd]'">
                            <input v-model="form.fee_bearer" type="radio" value="merchant" class="mt-1 size-5 flex-none accent-ink" />
                            <span><strong class="font-semibold">{{ company.name }} absorbs it</strong><span class="block text-sm text-[#454545]">Shoppers pay exactly the order total. The fee comes out of what you receive.</span></span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 border p-3.5" :class="form.fee_bearer === 'customer' ? 'border-ink bg-[#fffbf3] outline outline-1 outline-ink' : 'border-[#c9c6bd]'">
                            <input v-model="form.fee_bearer" type="radio" value="customer" class="mt-1 size-5 flex-none accent-ink" />
                            <span><strong class="font-semibold">The shopper pays it</strong><span class="block text-sm text-[#454545]">The fee for the way of paying they choose is added at the checkout, so you receive the full order total. Applies to new payments.</span></span>
                        </label>
                    </fieldset>
                    <div>
                        <label class="label" for="sites">Other sites allowed to show the pop-up checkout <span class="font-normal text-mute">(optional)</span></label>
                        <textarea id="sites" v-model="form.embed_origins" class="field font-mono text-sm" rows="2" placeholder="https://shop.example.com" :disabled="!can('company.edit')" aria-describedby="sites-help"></textarea>
                        <p id="sites-help" class="mt-1.5 text-sm text-mute">One per line. Your website above, and the site each payment returns to, are always allowed.</p>
                    </div>
                    <div v-if="can('company.edit')" class="flex flex-wrap items-center gap-2">
                        <button type="submit" class="btn btn-gold" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
                        <template v-if="can('companies.manage')">
                            <button v-if="company.status !== 'suspended'" type="button" class="btn btn-line" @click="setStatus('suspended')">Suspend company</button>
                            <button v-else type="button" class="btn btn-line" @click="setStatus('active')">Restore company</button>
                        </template>
                    </div>
                </form>
            </section>

            <!-- Integration -->
            <section v-if="can('keys.view')" aria-labelledby="keys-h">
                <h2 id="keys-h" class="mb-1 text-xl font-semibold">API keys</h2>
                <p class="mb-3 max-w-2xl text-mute">
                    A key lets {{ company.name }}'s system start payments. Test keys use a pretend bank and move no money.
                    {{ company.live_ready ? 'Live keys take real payments through TN CyberTech Bank.' : 'Live keys will work once the bank connection is set up on this server.' }}
                </p>

                <p v-if="keyProblem" role="alert" class="mb-3 border-l-4 border-ink bg-white px-4 py-3 text-sm font-medium">{{ keyProblem }}</p>

                <div v-if="newKey" role="status" class="mb-4 max-w-2xl border border-ink bg-[#fffbf3] p-5">
                    <p class="font-semibold">Copy this key now. It will not be shown again.</p>
                    <p class="mt-2 break-all bg-white p-3 font-mono text-sm">{{ newKey }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" class="btn btn-dark" @click="copy(newKey, 'key')">{{ copied === 'key' ? 'Copied' : 'Copy key' }}</button>
                        <button type="button" class="btn btn-line" @click="newKey = ''">I have saved it</button>
                    </div>
                </div>

                <form v-if="can('keys.manage')" class="mb-4 flex max-w-2xl flex-wrap items-end gap-3 border border-line bg-white p-5" @submit.prevent="createKey">
                    <div class="min-w-0 flex-[1_1_220px]">
                        <label class="label" for="key-name">What is this key for?</label>
                        <input id="key-name" v-model.trim="keyForm.name" class="field" type="text" required maxlength="80" placeholder="Shop checkout" />
                    </div>
                    <div>
                        <label class="label" for="key-mode">Kind</label>
                        <SelectBox id="key-mode" v-model="keyForm.mode" :options="[{ value: 'test', label: 'Test' }, { value: 'live', label: company.status !== 'active' ? 'Live (after approval)' : 'Live', disabled: company.status !== 'active' }]" />
                    </div>
                    <button type="submit" class="btn btn-gold">Create key</button>
                </form>

                <div class="relative overflow-x-auto border border-line bg-white">
                    <table class="w-full min-w-[640px] border-collapse text-left text-[15px]">
                        <thead>
                            <tr class="bg-paper text-[13px] text-[#454545]">
                                <th scope="col" class="px-4 py-3 font-semibold">Key</th>
                                <th scope="col" class="px-4 py-3 font-semibold">Kind</th>
                                <th scope="col" class="px-4 py-3 font-semibold">Last used</th>
                                <th scope="col" class="px-4 py-3 font-semibold"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="key in keys" :key="key.id" class="border-t border-line" :class="key.revoked_at ? 'text-mute' : ''">
                                <td class="px-4 py-3.5">
                                    <div class="font-semibold">{{ key.name }}</div>
                                    <div class="font-mono text-xs text-mute">{{ key.prefix }}…</div>
                                </td>
                                <td class="px-4 py-3.5"><StatusBadge :status="key.mode" /></td>
                                <td class="whitespace-nowrap px-4 py-3.5 text-mute">{{ ago(key.last_used_at) }}</td>
                                <td class="whitespace-nowrap px-4 py-3.5 text-right">
                                    <StatusBadge v-if="key.revoked_at" status="revoked" />
                                    <button v-else-if="can('keys.manage')" type="button" class="link min-h-11" @click="revokeKey(key)">Revoke</button>
                                </td>
                            </tr>
                            <tr v-if="!keys.length">
                                <td colspan="4" class="px-4 py-10 text-center text-mute">No keys yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="can('keys.manage')" class="mt-6 max-w-2xl border border-line bg-white p-5">
                    <h3 class="text-lg font-semibold">Callback signing secret</h3>
                    <p class="mt-1 text-sm text-[#454545]">
                        {{ company.name }}'s system uses this to prove a "paid" message really came from BlessedPay. Looking at it is recorded in the activity log.
                    </p>
                    <p v-if="secret" class="mt-3 break-all bg-paper p-3 font-mono text-sm">{{ secret }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button v-if="!secret" type="button" class="btn btn-dark" @click="showSecret">Show secret</button>
                        <button v-else type="button" class="btn btn-dark" @click="copy(secret, 'secret')">{{ copied === 'secret' ? 'Copied' : 'Copy secret' }}</button>
                        <button type="button" class="btn btn-line" @click="rotateSecret">Replace with a new one</button>
                    </div>
                </div>

                <details class="mt-6 max-w-3xl border border-line bg-white p-5">
                    <summary class="text-lg font-semibold">How to connect</summary>
                    <div class="mt-4 grid gap-4 text-[15px]">
                        <p><strong class="font-semibold">1. Start a payment</strong> and send the shopper to <code class="font-mono text-sm">redirect_url</code>. Repeat the same <code class="font-mono text-sm">Idempotency-Key</code> on a retry and no second payment is made.</p>
<pre class="overflow-x-auto bg-coal p-4 font-mono text-[13px] leading-relaxed text-[#ededed]">POST {{ origin }}/api/v1/payments
Authorization: Bearer sk_test_...
Idempotency-Key: order-10482
Content-Type: application/json

{ "amount": "48.00", "currency": "USD", "reference": "BLX-10482",
  "return_url": "https://…/checkout/return",
  "callback_url": "https://…/api/pay/callback",
  "customer": { "phone": "0771234567" } }   ← optional: saves the shopper typing it

→ { "id": "pay_...", "status": "pending", "charged": "48.00", "redirect_url": "…" }</pre>
                        <p><strong class="font-semibold">Or keep the shopper on your page</strong> with the pop-up. Add the script once, then open the same <code class="font-mono text-sm">redirect_url</code>. Treat <code class="font-mono text-sm">onSuccess</code> as a hint only and still do step 2.</p>
<pre class="overflow-x-auto bg-coal p-4 font-mono text-[13px] leading-relaxed text-[#ededed]">&lt;script src="{{ origin }}/js/blessedpay.js"&gt;&lt;/script&gt;

BlessedPay.open({
  url: payment.redirect_url,
  onSuccess: (p) =&gt; { /* ask your server to confirm p.id */ },
  onCancel:  (p) =&gt; { },
});</pre>
                        <p><strong class="font-semibold">2. Check it</strong> when the shopper comes back. Never trust the status in the return address; ask.</p>
<pre class="overflow-x-auto bg-coal p-4 font-mono text-[13px] leading-relaxed text-[#ededed]">GET {{ origin }}/api/v1/payments/pay_...
→ { "id": "pay_...", "status": "paid" | "pending" | "failed" | "cancelled", … }</pre>
                        <p><strong class="font-semibold">3. Verify the callback.</strong> We POST the result to <code class="font-mono text-sm">callback_url</code>. Accept it only if the signature matches, and answer 200. The <code class="font-mono text-sm">X-BlessedPay-Event</code> header is the same on every retry, so a repeat can be ignored.</p>
<pre class="overflow-x-auto bg-coal p-4 font-mono text-[13px] leading-relaxed text-[#ededed]">X-BlessedPay-Signature: t=1794570000,v1=&lt;hex&gt;
X-BlessedPay-Event: pay_....paid

expected = hmac_sha256( t + "." + raw_request_body, signing_secret )
accept only if expected == v1  (and t is recent)</pre>
                    </div>
                </details>
            </section>

            <!-- Team -->
            <section v-if="can('team.view')" aria-labelledby="team-h">
                <h2 id="team-h" class="mb-3 text-xl font-semibold">Team</h2>
                <TeamPanel :company-id="company.id" />
            </section>
        </div>
    </template>
</template>
