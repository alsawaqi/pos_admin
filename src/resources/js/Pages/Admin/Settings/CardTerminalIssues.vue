<script setup lang="ts">
import { onMounted, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import { apiGet, apiPost, ApiError } from '@/lib/api';
type Row = Record<string, string | number | null>;
type Page = { data: Row[]; current_page: number; last_page: number; total: number };
type Section = 'payments' | 'devices' | 'reversals';
const sections: Section[] = ['payments', 'devices', 'reversals'];
const titles = { payments: 'Mismatched card payments', devices: 'Blocked devices', reversals: 'Uncertain reversals' };
const data = ref<Record<Section, Page> | null>(null);
const pages = ref({ payments_page: 1, devices_page: 1, reversals_page: 1 });
const error = ref('');
const busy = ref(false);
const selected = ref<Row | null>(null);
const outcome = ref('declined');
const evidence = ref('');
const authCode = ref('');
const transaction = ref('');
async function load(): Promise<void> {
    error.value = '';
    try {
        const response = await apiGet<{ data: Record<Section, Page> }>('/admin/api/v1/card-terminal-issues', { query: pages.value });
        data.value = response.data;
    } catch (err) { error.value = err instanceof Error ? err.message : 'Could not load terminal issues.'; }
}
function open(row: Row): void {
    selected.value = row; outcome.value = 'declined'; evidence.value = ''; authCode.value = ''; transaction.value = '';
}
async function resolve(): Promise<void> {
    if (!selected.value || !evidence.value.trim() || busy.value) return;
    busy.value = true; error.value = '';
    try {
        await apiPost('/admin/api/v1/payment-reversals/' + selected.value.uuid + '/resolve', {
            outcome: outcome.value, evidence_note: evidence.value,
            reversal_auth_code: authCode.value || null, reversal_transaction_id: transaction.value || null,
        });
        selected.value = null; await load();
    } catch (err) {
        error.value = err instanceof ApiError ? (err.firstValidationMessage() ?? err.message) : 'Could not resolve reversal.';
    } finally { busy.value = false; }
}
async function unblock(row: Row): Promise<void> {
    busy.value = true;
    try { await apiPost('/admin/api/v1/devices/' + row.uuid + '/unblock-card-tenders'); await load(); }
    catch (err) { error.value = err instanceof Error ? err.message : 'Could not unblock device.'; }
    finally { busy.value = false; }
}
function page(section: Section, change: number): void {
    pages.value[(section + '_page') as keyof typeof pages.value] += change; void load();
}
onMounted(load);
</script>
<template>
    <AdminLayout title="Card terminal issues">
        <h1 class="text-2xl font-semibold text-slate-900">Card terminal issues</h1>
        <p v-if="error" role="alert" class="my-4 rounded-lg bg-rose-50 p-4 text-rose-700">{{ error }}</p>
        <div v-if="data" class="mt-6 space-y-8">
            <section v-for="section in sections" :key="section" class="overflow-hidden rounded-xl border bg-white">
                <h2 class="border-b p-4 text-lg font-medium">{{ titles[section] }} ({{ data[section].total }})</h2>
                <div class="overflow-auto"><table class="w-full text-left text-sm">
                    <tbody class="divide-y">
                        <tr v-for="row in data[section].data" :key="String(row.uuid)">
                            <td class="p-4"><dl class="grid gap-1 sm:grid-cols-2">
                                <template v-for="(value, key) in row" :key="key"><div><dt class="inline text-slate-500">{{ String(key).replaceAll('_', ' ') }}: </dt><dd class="inline">{{ value ?? '—' }}</dd></div></template>
                            </dl></td>
                            <td v-if="section === 'reversals'" class="p-4"><button class="font-medium text-teal-700 underline" @click="open(row)">Resolve</button></td>
                            <td v-if="section === 'devices'" class="p-4"><button :disabled="busy" class="font-medium text-teal-700 underline" @click="unblock(row)">Unblock</button><p class="mt-1 text-xs text-slate-500">Device configuration refresh is still required.</p></td>
                        </tr>
                        <tr v-if="!data[section].data.length"><td class="p-4 text-slate-500">No issues to review.</td></tr>
                    </tbody>
                </table></div>
                <div class="flex justify-between border-t p-3"><button :disabled="data[section].current_page <= 1" @click="page(section, -1)">Previous</button><span>{{ data[section].current_page }} / {{ data[section].last_page }}</span><button :disabled="data[section].current_page >= data[section].last_page" @click="page(section, 1)">Next</button></div>
            </section>
        </div>
        <BaseModal v-if="selected" title="Resolve uncertain reversal" :loading="busy" @close="selected = null">
            <form id="resolve-reversal" class="space-y-4" @submit.prevent="resolve">
                <p class="text-sm">{{ selected.kind }} · {{ selected.amount }} OMR · {{ selected.uuid }}</p>
                <p v-if="error" role="alert" class="text-sm text-rose-700">{{ error }}</p>
                <label class="block">Bank outcome<select v-model="outcome" class="mt-1 block w-full rounded border p-2"><option value="declined">Declined — bank did not reverse funds</option><option value="approved">Approved — bank reversed funds</option></select></label>
                <label class="block">Evidence note<textarea v-model="evidence" required maxlength="255" class="mt-1 block w-full rounded border p-2" /></label>
                <label class="block">Reversal authorization code<input v-model="authCode" maxlength="32" class="mt-1 block w-full rounded border p-2"></label>
                <label class="block">Reversal transaction ID<input v-model="transaction" maxlength="64" class="mt-1 block w-full rounded border p-2"></label>
            </form>
            <template #footer><button :disabled="busy" @click="selected = null">Cancel</button><button form="resolve-reversal" type="submit" :disabled="busy || !evidence.trim()" class="rounded bg-teal-700 px-4 py-2 text-white">Apply confirmed outcome</button></template>
        </BaseModal>
    </AdminLayout>
</template>
