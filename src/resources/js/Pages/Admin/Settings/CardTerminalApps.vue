<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import { apiGet, apiRequest, ApiError } from '@/lib/api';

type Provider = {
    provider: string; label: string; package: string | null;
    refund_needs_transaction_id: boolean; void_needs_session_id: boolean;
};
type Profile = {
    softpos_provider: string; softpos_package: string | null; currency_code: string;
    refund_needs_transaction_id: boolean; void_needs_session_id: boolean;
    is_active: boolean; notes: string | null; provider_changed_at: string | null;
    updated_at: string; updated_by?: { name: string } | null;
};
type Bank = {
    id: number; name: string; short_name: string | null; is_active: boolean;
    profile: Profile | null; devices: { uuid: string; name: string | null; serial_number: string }[];
};
const banks = ref<Bank[]>([]);
const providers = ref<Provider[]>([]);
const selected = ref<Bank | null>(null);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const formError = ref('');
const confirmed = ref(false);
const confirmationRequired = ref(false);
const affectedDevices = ref<Bank['devices']>([]);
const form = reactive({
    softpos_provider: 'none', softpos_package: '', currency_code: '0512',
    refund_needs_transaction_id: false, void_needs_session_id: false, is_active: true, notes: '',
});
async function load(): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
        const response = await apiGet<{ data: Bank[]; providers: Provider[] }>('/admin/api/v1/bank-softpos-profiles');
        banks.value = response.data;
        providers.value = response.providers;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Could not load card terminal apps.';
    } finally { loading.value = false; }
}
function edit(bank: Bank): void {
    selected.value = bank;
    formError.value = '';
    confirmed.value = false;
    confirmationRequired.value = false;
    affectedDevices.value = bank.devices;
    Object.assign(form, {
        softpos_provider: bank.profile?.softpos_provider ?? 'none',
        softpos_package: bank.profile?.softpos_package ?? '',
        currency_code: bank.profile?.currency_code ?? '0512',
        refund_needs_transaction_id: bank.profile?.refund_needs_transaction_id ?? false,
        void_needs_session_id: bank.profile?.void_needs_session_id ?? false,
        is_active: bank.profile?.is_active ?? true,
        notes: bank.profile?.notes ?? '',
    });
}
function providerChanged(): void {
    const provider = providers.value.find(item => item.provider === form.softpos_provider);
    if (provider) {
        form.softpos_package = provider.package ?? '';
        form.refund_needs_transaction_id = provider.refund_needs_transaction_id;
        form.void_needs_session_id = provider.void_needs_session_id;
    }
    confirmed.value = false;
    confirmationRequired.value = Boolean(selected.value?.profile
        && selected.value.profile.softpos_provider !== form.softpos_provider && affectedDevices.value.length);
}
async function save(): Promise<void> {
    if (!selected.value || saving.value || (confirmationRequired.value && !confirmed.value)) return;
    saving.value = true;
    formError.value = '';
    try {
        await apiRequest('/admin/api/v1/bank-softpos-profiles/' + selected.value.id, {
            method: 'PUT',
            body: { ...form, softpos_package: form.softpos_package || null, confirm_provider_change: confirmed.value },
        });
        selected.value = null;
        await load();
    } catch (err) {
        if (err instanceof ApiError && err.status === 409) {
            const payload = err.payload as { code?: string; devices?: Bank['devices'] };
            if (payload.code === 'softpos_provider_change_needs_confirmation') {
                affectedDevices.value = payload.devices ?? [];
                confirmationRequired.value = true;
                confirmed.value = false;
            }
        }
        formError.value = err instanceof ApiError
            ? (err.firstValidationMessage() ?? err.message)
            : 'Could not save the card terminal app.';
    } finally { saving.value = false; }
}
onMounted(load);
</script>

<template>
    <AdminLayout title="Card terminal apps">
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">Card terminal apps</h1>
                <p class="mt-2 text-sm text-slate-600">Choose the card payment app used by each bank’s assigned devices.</p>
            </div>
            <p v-if="error" role="alert" class="rounded-xl bg-rose-50 p-4 text-rose-700">{{ error }} <button class="underline" @click="load">Retry</button></p>
            <p v-if="loading" role="status">Loading banks…</p>
            <div v-else class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr>
                        <th class="p-4">Bank / short name</th><th class="p-4">Active</th><th class="p-4">SoftPOS app / package</th>
                        <th class="p-4">Currency / requirements</th><th class="p-4">Last change / by</th><th class="p-4">Edit</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="bank in banks" :key="bank.id">
                            <td class="p-4 font-medium">{{ bank.name }}<span class="block text-xs text-slate-500">{{ bank.short_name ?? '—' }}</span></td>
                            <td class="p-4">{{ bank.is_active ? 'Bank active' : 'Bank inactive' }}<span class="block text-xs">{{ bank.profile?.is_active ? 'Profile active' : 'Profile inactive / absent' }}</span></td>
                            <td class="p-4">{{ providers.find(p => p.provider === bank.profile?.softpos_provider)?.label ?? 'Not configured' }}<span class="block font-mono text-xs text-slate-500">{{ bank.profile?.softpos_package ?? '—' }}</span></td>
                            <td class="p-4">{{ bank.profile?.currency_code ?? '—' }}
                                <span v-if="bank.profile?.refund_needs_transaction_id" class="block text-xs">Refund: original transaction ID</span>
                                <span v-if="bank.profile?.void_needs_session_id" class="block text-xs">Void: session ID</span>
                            </td>
                            <td class="p-4 text-xs">{{ bank.profile?.updated_at ?? '—' }}<span class="block">{{ bank.profile?.updated_by?.name ?? '—' }}</span></td>
                            <td class="p-4"><button class="font-medium text-teal-700 underline" :aria-label="'Edit ' + bank.name" @click="edit(bank)">Edit</button></td>
                        </tr>
                        <tr v-if="!banks.length"><td colspan="6" class="p-6 text-slate-500">No banks available.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <BaseModal v-if="selected" :title="'Card terminal app — ' + selected.name" :loading="saving" size="xl" @close="selected = null">
            <form id="softpos-profile-form" class="space-y-4" @submit.prevent="save">
                <p v-if="formError" role="alert" class="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ formError }}</p>
                <label class="block text-sm font-medium">SoftPOS app
                    <select v-model="form.softpos_provider" class="mt-1 block w-full rounded-lg border p-2" @change="providerChanged">
                        <option v-for="provider in providers" :key="provider.provider" :value="provider.provider">{{ provider.label }}</option>
                    </select>
                </label>
                <label class="block text-sm font-medium">Android package
                    <input v-model="form.softpos_package" :disabled="form.softpos_provider === 'none'" :required="form.softpos_provider !== 'none'" maxlength="128" class="mt-1 block w-full rounded-lg border p-2 font-mono">
                </label>
                <label class="block text-sm font-medium">Currency code
                    <input v-model="form.currency_code" required pattern="[0-9]{4}" maxlength="4" class="mt-1 block w-full rounded-lg border p-2">
                </label>
                <label class="flex gap-2 text-sm"><input v-model="form.refund_needs_transaction_id" type="checkbox">Refund requires the original transaction ID</label>
                <label class="flex gap-2 text-sm"><input v-model="form.void_needs_session_id" type="checkbox">Void requires a session ID</label>
                <label class="flex gap-2 text-sm"><input v-model="form.is_active" type="checkbox">Profile active</label>
                <label class="block text-sm font-medium">Notes<textarea v-model="form.notes" maxlength="10000" class="mt-1 block w-full rounded-lg border p-2" /></label>
                <div v-if="confirmationRequired" class="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">
                    <p>This changes the card terminal app for these assigned devices:</p>
                    <ul class="list-inside list-disc"><li v-for="device in affectedDevices" :key="device.uuid">{{ device.name || device.serial_number }}</li></ul>
                    <label class="flex gap-2"><input v-model="confirmed" type="checkbox">I confirm this provider change.</label>
                </div>
            </form>
            <template #footer>
                <button class="rounded-lg border px-4 py-2" :disabled="saving" @click="selected = null">Cancel</button>
                <button form="softpos-profile-form" type="submit" class="rounded-lg bg-teal-700 px-4 py-2 text-white disabled:opacity-50" :disabled="saving || (confirmationRequired && !confirmed)">{{ saving ? 'Saving…' : 'Save' }}</button>
            </template>
        </BaseModal>
    </AdminLayout>
</template>
