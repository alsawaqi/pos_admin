<script setup lang="ts">
import { Banknote, CheckCircle2, Upload } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError } from '@/lib/api';
import { listBanks, type Bank } from '@/lib/api/banks';
import {
    commitReconciliation,
    previewReconciliation,
    type ReconciliationPreview,
} from '@/lib/api/bankReconciliation';
import { usePermissions } from '@/composables/usePermissions';
import { PlatformPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();
const canManage = computed(() => can(PlatformPermission.SettingsManage));

const banks = ref<Bank[]>([]);
const bankId = ref<number | null>(null);
const statementDate = ref('');
const file = ref<File | null>(null);

const preview = ref<ReconciliationPreview | null>(null);
const previewing = ref(false);
const committing = ref(false);
const error = ref<string | null>(null);
const flash = ref<{ type: 'success' | 'error'; text: string } | null>(null);

onMounted(async () => {
    try {
        banks.value = (await listBanks()).data;
    } catch {
        banks.value = [];
    }
});

function onFile(event: Event): void {
    const target = event.target as HTMLInputElement;
    file.value = target.files?.[0] ?? null;
}

const canPreview = computed(() => canManage.value && bankId.value !== null && statementDate.value !== '' && file.value !== null);

async function runPreview(): Promise<void> {
    if (bankId.value === null || file.value === null) {
        return;
    }
    previewing.value = true;
    error.value = null;
    flash.value = null;
    preview.value = null;
    try {
        const response = await previewReconciliation(bankId.value, statementDate.value, file.value);
        preview.value = response.data;
    } catch (err) {
        if (err instanceof ApiError) {
            error.value = err.firstValidationMessage() ?? err.message;
        } else {
            error.value = err instanceof Error ? err.message : 'Preview failed';
        }
    } finally {
        previewing.value = false;
    }
}

const matchedPaymentIds = computed(() => preview.value?.matched.map((m) => m.payment.id) ?? []);

async function runCommit(): Promise<void> {
    if (matchedPaymentIds.value.length === 0) {
        return;
    }
    committing.value = true;
    flash.value = null;
    try {
        const response = await commitReconciliation(matchedPaymentIds.value);
        flash.value = { type: 'success', text: t('bank_reconciliation.flash.committed', { count: response.data.reconciled }) };
        await runPreview();
    } catch (err) {
        flash.value = { type: 'error', text: err instanceof Error ? err.message : 'Commit failed' };
    } finally {
        committing.value = false;
    }
}

function money(value: number | null): string {
    return value === null || value === undefined ? '—' : Number(value).toFixed(3);
}
</script>

<template>
    <AdminLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-slate-950">{{ t('bank_reconciliation.title') }}</h1>
                <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ t('bank_reconciliation.subtitle') }}</p>
            </div>

            <div v-if="flash" :class="flash.type === 'success' ? 'border-teal-200 bg-teal-50 text-teal-700' : 'border-rose-200 bg-rose-50 text-rose-700'" class="rounded-lg border px-4 py-3 text-sm font-semibold">
                {{ flash.text }}
            </div>

            <!-- Upload form -->
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('bank_reconciliation.fields.bank') }}</span>
                        <select v-model="bankId" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option :value="null">—</option>
                            <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('bank_reconciliation.fields.statement_date') }}</span>
                        <input v-model="statementDate" type="date" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('bank_reconciliation.fields.file') }}</span>
                        <input type="file" accept=".xlsx,.xls,.csv,.txt" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold" @change="onFile">
                    </label>
                </div>
                <p class="mt-3 text-xs text-slate-500">{{ t('bank_reconciliation.fields.file_hint') }}</p>
                <div class="mt-4 flex justify-end">
                    <button type="button" :disabled="!canPreview || previewing" class="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" @click="runPreview">
                        <Upload class="size-4" />
                        {{ previewing ? t('bank_reconciliation.previewing') : t('bank_reconciliation.preview_button') }}
                    </button>
                </div>
                <p v-if="error" class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ error }}</p>
            </section>

            <!-- Results -->
            <template v-if="preview">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div class="rounded-lg border border-slate-200 bg-white p-3"><p class="text-xs text-slate-500">{{ t('bank_reconciliation.summary.statement') }}</p><p class="mt-1 text-lg font-bold text-slate-900">{{ preview.summary.statement_rows }}</p></div>
                    <div class="rounded-lg border border-teal-200 bg-teal-50 p-3"><p class="text-xs text-teal-700">{{ t('bank_reconciliation.summary.matched') }}</p><p class="mt-1 text-lg font-bold text-teal-800">{{ preview.summary.matched_rows }}</p></div>
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3"><p class="text-xs text-amber-700">{{ t('bank_reconciliation.summary.mismatch') }}</p><p class="mt-1 text-lg font-bold text-amber-800">{{ preview.summary.amount_mismatch_rows }}</p></div>
                    <div class="rounded-lg border border-rose-200 bg-rose-50 p-3"><p class="text-xs text-rose-700">{{ t('bank_reconciliation.summary.missing') }}</p><p class="mt-1 text-lg font-bold text-rose-800">{{ preview.summary.missing_in_db_rows }}</p></div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3"><p class="text-xs text-slate-500">{{ t('bank_reconciliation.summary.db_only') }}</p><p class="mt-1 text-lg font-bold text-slate-900">{{ preview.summary.db_only_rows }}</p></div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3"><p class="text-xs text-slate-500">{{ t('bank_reconciliation.summary.invalid') }}</p><p class="mt-1 text-lg font-bold text-slate-900">{{ preview.summary.invalid_rows }}</p></div>
                </div>

                <!-- Commit -->
                <div v-if="canManage && preview.summary.matched_rows > 0" class="flex items-center justify-between rounded-lg border border-teal-200 bg-teal-50 px-4 py-3">
                    <span class="text-sm font-medium text-teal-800">{{ t('bank_reconciliation.commit_hint', { count: preview.summary.matched_rows }) }}</span>
                    <button type="button" :disabled="committing" class="inline-flex items-center gap-2 rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:opacity-60" @click="runCommit">
                        <CheckCircle2 class="size-4" />
                        {{ committing ? t('bank_reconciliation.committing') : t('bank_reconciliation.commit_button') }}
                    </button>
                </div>

                <!-- Matched -->
                <section v-if="preview.matched.length" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-700">{{ t('bank_reconciliation.buckets.matched') }}</h2>
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50"><tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ t('bank_reconciliation.table.terminal') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ t('bank_reconciliation.table.auth_code') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ t('bank_reconciliation.table.amount') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ t('bank_reconciliation.table.status') }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="m in preview.matched" :key="m.payment.id">
                                <td class="px-4 py-2 font-mono text-slate-700">{{ m.statement.terminal_id }}</td>
                                <td class="px-4 py-2 font-mono text-slate-700">{{ m.statement.auth_code }}</td>
                                <td class="px-4 py-2 font-medium text-slate-900">{{ money(m.payment.amount) }}</td>
                                <td class="px-4 py-2"><span v-if="m.payment.pending_reconciliation" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">{{ t('bank_reconciliation.pending_badge') }}</span><span v-else class="text-xs text-slate-500">{{ m.payment.status }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <!-- Amount mismatches -->
                <section v-if="preview.amount_mismatches.length" class="overflow-hidden rounded-lg border border-amber-200 bg-white shadow-sm">
                    <h2 class="border-b border-amber-100 bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-800">{{ t('bank_reconciliation.buckets.mismatch') }}</h2>
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50"><tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ t('bank_reconciliation.table.terminal') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ t('bank_reconciliation.table.statement_amount') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ t('bank_reconciliation.table.payment_amount') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ t('bank_reconciliation.table.difference') }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(m, i) in preview.amount_mismatches" :key="i">
                                <td class="px-4 py-2 font-mono text-slate-700">{{ m.statement.terminal_id }}</td>
                                <td class="px-4 py-2 text-slate-700">{{ money(m.statement.gross_amount) }}</td>
                                <td class="px-4 py-2 text-slate-700">{{ money(m.payment.amount) }}</td>
                                <td class="px-4 py-2 font-medium text-amber-700">{{ money(m.amount_difference) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <!-- Missing in DB -->
                <section v-if="preview.missing_in_db.length" class="overflow-hidden rounded-lg border border-rose-200 bg-white shadow-sm">
                    <h2 class="border-b border-rose-100 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-800">{{ t('bank_reconciliation.buckets.missing') }}</h2>
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50"><tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ t('bank_reconciliation.table.terminal') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ t('bank_reconciliation.table.auth_code') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ t('bank_reconciliation.table.amount') }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(m, i) in preview.missing_in_db" :key="i">
                                <td class="px-4 py-2 font-mono text-slate-700">{{ m.statement.terminal_id }}</td>
                                <td class="px-4 py-2 font-mono text-slate-700">{{ m.statement.auth_code }}</td>
                                <td class="px-4 py-2 text-slate-700">{{ money(m.statement.gross_amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <!-- DB only (unsettled by the bank) -->
                <section v-if="preview.db_only.length" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-700">{{ t('bank_reconciliation.buckets.db_only') }}</h2>
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50"><tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ t('bank_reconciliation.table.terminal') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ t('bank_reconciliation.table.auth_code') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ t('bank_reconciliation.table.amount') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ t('bank_reconciliation.table.captured') }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="p in preview.db_only" :key="p.id">
                                <td class="px-4 py-2 font-mono text-slate-700">{{ p.terminal_id ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-slate-700">{{ p.auth_code ?? '—' }}</td>
                                <td class="px-4 py-2 text-slate-700">{{ money(p.amount) }}</td>
                                <td class="px-4 py-2 text-xs text-slate-500">{{ p.captured_at ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </template>

            <div v-else class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-slate-200 p-10 text-center text-sm text-slate-500">
                <Banknote class="size-8 text-slate-300" />
                {{ t('bank_reconciliation.empty') }}
            </div>
        </div>
    </AdminLayout>
</template>
