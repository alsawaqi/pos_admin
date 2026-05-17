<script setup lang="ts">
import {
    ArrowLeft,
    CheckCircle2,
    FileText,
    History,
    ListTree,
    Loader2,
    PauseCircle,
    PlayCircle,
    Upload,
    XCircle,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatusPill from '@/Components/Admin/StatusPill.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    deleteMerchantDocument,
    getMerchant,
    listMerchantDocuments,
    merchantDocumentDownloadUrl,
    rejectMerchantDocument,
    transitionMerchantStatus,
    uploadMerchantDocument,
    verifyMerchantDocument,
    type CompanyDocument,
    type CompanyStatus,
    type DocumentType,
    type MerchantDetail,
} from '@/lib/api/merchants';
import { PlatformPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();
const route = useRoute();
const router = useRouter();

const merchant = ref<MerchantDetail | null>(null);
const documents = ref<CompanyDocument[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const activeTab = ref<'overview' | 'documents' | 'activities' | 'history'>('overview');

const uploadForm = ref<{ type: DocumentType; file: File | null; issued_at: string; expires_at: string; notes: string }>({
    type: 'cr_certificate',
    file: null,
    issued_at: '',
    expires_at: '',
    notes: '',
});
const uploading = ref(false);
const uploadError = ref<string | null>(null);

const transitionForm = ref<{ target: CompanyStatus | ''; reason: string }>({ target: '', reason: '' });
const transitioning = ref(false);
const transitionError = ref<string | null>(null);

const documentTypes: { value: DocumentType; label: string }[] = [
    { value: 'cr_certificate', label: 'CR Certificate' },
    { value: 'vat_certificate', label: 'VAT Certificate' },
    { value: 'municipality_license', label: 'Municipality License' },
    { value: 'chamber_certificate', label: 'Chamber Certificate' },
    { value: 'owner_id_card', label: 'Owner ID Card' },
    { value: 'lease_agreement', label: 'Lease Agreement' },
    { value: 'signature_authority', label: 'Signature Authority' },
    { value: 'bank_letter', label: 'Bank Letter' },
    { value: 'other', label: 'Other' },
];

const allowedTransitions = computed<CompanyStatus[]>(() => {
    if (!merchant.value?.status) {
        return [];
    }
    const map: Record<CompanyStatus, CompanyStatus[]> = {
        onboarding: ['active', 'inactive'],
        active: ['suspended', 'inactive'],
        suspended: ['active', 'inactive'],
        inactive: [],
    };
    return map[merchant.value.status] ?? [];
});

const statusTone = computed(() => {
    return {
        onboarding: 'amber',
        active: 'green',
        suspended: 'rose',
        inactive: 'slate',
    }[merchant.value?.status ?? 'onboarding'] as string;
});

function statusLabel(status: CompanyStatus | null | undefined): string {
    if (!status) {
        return '—';
    }
    return status.charAt(0).toUpperCase() + status.slice(1);
}

async function fetchMerchant(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const response = await getMerchant(String(route.params.uuid));
        merchant.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load merchant';
    } finally {
        loading.value = false;
    }
}

async function fetchDocuments(): Promise<void> {
    if (!merchant.value) {
        return;
    }
    const response = await listMerchantDocuments(merchant.value.uuid);
    documents.value = response.data;
}

function onTabChange(tab: typeof activeTab.value): void {
    activeTab.value = tab;
    if (tab === 'documents') {
        void fetchDocuments();
    }
}

function onFileChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    uploadForm.value.file = target.files?.[0] ?? null;
}

async function submitUpload(): Promise<void> {
    if (!merchant.value || !uploadForm.value.file) {
        uploadError.value = t('merchants.documents.errors.no_file');
        return;
    }

    uploading.value = true;
    uploadError.value = null;

    try {
        await uploadMerchantDocument(merchant.value.uuid, uploadForm.value.file, uploadForm.value.type, {
            issued_at: uploadForm.value.issued_at || undefined,
            expires_at: uploadForm.value.expires_at || undefined,
            notes: uploadForm.value.notes || undefined,
        });
        uploadForm.value.file = null;
        uploadForm.value.issued_at = '';
        uploadForm.value.expires_at = '';
        uploadForm.value.notes = '';
        const fileInput = document.querySelector<HTMLInputElement>('#document-file-input');
        if (fileInput) {
            fileInput.value = '';
        }
        await fetchDocuments();
    } catch (err) {
        const payload = (err as { payload?: { errors?: Record<string, string[]> } }).payload;
        uploadError.value = payload?.errors
            ? Object.values(payload.errors).flat()[0] ?? 'Upload failed'
            : err instanceof Error ? err.message : 'Upload failed';
    } finally {
        uploading.value = false;
    }
}

async function verifyDocument(doc: CompanyDocument): Promise<void> {
    if (!merchant.value) {
        return;
    }
    await verifyMerchantDocument(merchant.value.uuid, doc.uuid);
    await fetchDocuments();
}

async function rejectDocument(doc: CompanyDocument): Promise<void> {
    if (!merchant.value) {
        return;
    }
    const reason = window.prompt(t('merchants.documents.reject_prompt'));
    if (!reason) {
        return;
    }
    await rejectMerchantDocument(merchant.value.uuid, doc.uuid, { reason });
    await fetchDocuments();
}

async function removeDocument(doc: CompanyDocument): Promise<void> {
    if (!merchant.value) {
        return;
    }
    if (!window.confirm(t('merchants.documents.delete_confirm'))) {
        return;
    }
    await deleteMerchantDocument(merchant.value.uuid, doc.uuid);
    await fetchDocuments();
}

async function submitTransition(): Promise<void> {
    if (!merchant.value || !transitionForm.value.target) {
        return;
    }

    transitioning.value = true;
    transitionError.value = null;

    try {
        const response = await transitionMerchantStatus(merchant.value.uuid, {
            target_status: transitionForm.value.target,
            reason: transitionForm.value.reason || undefined,
        });
        merchant.value = response.data;
        transitionForm.value.target = '';
        transitionForm.value.reason = '';
    } catch (err) {
        if (err instanceof ApiError) {
            transitionError.value = err.firstValidationMessage() ?? (typeof err.payload === 'object' && err.payload !== null && 'message' in err.payload ? String((err.payload as { message: unknown }).message) : err.message);
        } else {
            transitionError.value = err instanceof Error ? err.message : 'Status transition failed';
        }
    } finally {
        transitioning.value = false;
    }
}

function documentStatusTone(doc: CompanyDocument): string {
    return {
        pending: 'amber',
        verified: 'green',
        rejected: 'rose',
        expired: 'slate',
    }[doc.verification_status] ?? 'slate';
}

onMounted(() => void fetchMerchant());
</script>

<template>
    <AdminLayout>
        <div v-if="loading" class="flex items-center justify-center gap-3 py-20 text-slate-500">
            <Loader2 class="size-5 animate-spin" />
            <span>{{ t('common.loading') }}</span>
        </div>

        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
            {{ error }}
        </div>

        <section v-else-if="merchant" class="space-y-6">
            <div>
                <button type="button" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-500 transition hover:text-slate-800" @click="router.back()">
                    <ArrowLeft class="size-4" />
                    {{ t('common.back') }}
                </button>
            </div>

            <header class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('merchants.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">{{ merchant.name }}</h1>
                    <p v-if="merchant.name_ar" dir="rtl" class="mt-1 text-lg text-slate-600">{{ merchant.name_ar }}</p>
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-500">
                        <StatusPill :label="statusLabel(merchant.status)" :tone="statusTone" />
                        <span class="text-slate-400">·</span>
                        <span>CR {{ merchant.cr_number }}</span>
                        <span v-if="merchant.vat_number" class="text-slate-400">·</span>
                        <span v-if="merchant.vat_number">VAT {{ merchant.vat_number }}</span>
                    </div>
                </div>

                <div
                    v-if="can(PlatformPermission.MerchantsTransitionStatus) && allowedTransitions.length > 0"
                    class="flex flex-col gap-2 rounded-lg border border-slate-200 bg-white p-4 shadow-sm lg:w-80"
                >
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.status_panel.title') }}</p>
                    <select
                        v-model="transitionForm.target"
                        class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100"
                    >
                        <option value="">{{ t('merchants.status_panel.select_placeholder') }}</option>
                        <option v-for="t in allowedTransitions" :key="t" :value="t">{{ statusLabel(t) }}</option>
                    </select>
                    <input
                        v-if="transitionForm.target === 'suspended' || transitionForm.target === 'inactive'"
                        v-model="transitionForm.reason"
                        type="text"
                        class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100"
                        :placeholder="t('merchants.status_panel.reason_placeholder')"
                    >
                    <button
                        type="button"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow transition hover:bg-slate-800 disabled:opacity-60"
                        :disabled="!transitionForm.target || transitioning"
                        @click="submitTransition"
                    >
                        <PlayCircle v-if="transitionForm.target === 'active'" class="size-4" />
                        <PauseCircle v-else-if="transitionForm.target === 'suspended'" class="size-4" />
                        <XCircle v-else class="size-4" />
                        {{ transitioning ? t('merchants.status_panel.submitting') : t('merchants.status_panel.submit') }}
                    </button>
                    <p v-if="transitionError" class="text-xs font-medium text-rose-700">{{ transitionError }}</p>
                </div>
            </header>

            <nav class="flex gap-2 overflow-x-auto border-b border-slate-200">
                <button
                    v-for="tab in [
                        { key: 'overview', label: t('merchants.tabs.overview') },
                        { key: 'documents', label: t('merchants.tabs.documents') },
                        { key: 'activities', label: t('merchants.tabs.activities') },
                        { key: 'history', label: t('merchants.tabs.history') },
                    ] as const"
                    :key="tab.key"
                    type="button"
                    class="border-b-2 px-4 py-3 text-sm font-semibold transition"
                    :class="activeTab === tab.key ? 'border-teal-600 text-teal-700' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    @click="onTabChange(tab.key)"
                >
                    {{ tab.label }}
                </button>
            </nav>

            <section v-if="activeTab === 'overview'" class="grid gap-6 lg:grid-cols-2">
                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.overview.compliance') }}</h3>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <dt class="text-slate-500">{{ t('merchants.fields.cr_number') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.cr_number ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.cr_issue_date') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.cr_issue_date ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.cr_expiry_date') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.cr_expiry_date ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.establishment_date') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.establishment_date ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.vat_number') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.vat_number ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.tax_number') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.tax_number ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.chamber_number') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.chamber_of_commerce_number ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.municipality_number') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.municipality_license_number ?? '—' }}</dd>
                    </dl>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.overview.owner_contact') }}</h3>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <dt class="text-slate-500">{{ t('merchants.fields.owner_name') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.owner.full_name_en ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.civil_id') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.owner.civil_id ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.nationality') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.owner.nationality ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.owner_phone') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.owner.phone ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.owner_email') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.owner.email ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.contact_name') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.contact.name ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.contact_email') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.contact.email ?? '—' }}</dd>
                    </dl>
                </div>
            </section>

            <section v-if="activeTab === 'documents'" class="space-y-6">
                <div v-if="can(PlatformPermission.MerchantDocumentsUpload)" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.documents.upload_title') }}</h3>
                    <div class="mt-4 grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                        <select
                            v-model="uploadForm.type"
                            class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium"
                        >
                            <option v-for="opt in documentTypes" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                        <input
                            id="document-file-input"
                            type="file"
                            accept="application/pdf,image/jpeg,image/png"
                            class="text-sm"
                            @change="onFileChange"
                        >
                        <input v-model="uploadForm.issued_at" type="date" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                        <input v-model="uploadForm.expires_at" type="date" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                    </div>
                    <button
                        type="button"
                        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow transition hover:bg-slate-800 disabled:opacity-60"
                        :disabled="uploading || !uploadForm.file"
                        @click="submitUpload"
                    >
                        <Upload class="size-4" />
                        {{ uploading ? t('merchants.documents.uploading') : t('merchants.documents.upload_button') }}
                    </button>
                    <p v-if="uploadError" class="mt-2 text-xs font-medium text-rose-700">{{ uploadError }}</p>
                </div>

                <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div v-if="documents.length === 0" class="flex flex-col items-center gap-3 p-10 text-slate-500">
                        <FileText class="size-8 text-slate-300" />
                        <p class="text-sm font-medium">{{ t('merchants.documents.empty') }}</p>
                    </div>

                    <ul v-else class="divide-y divide-slate-100">
                        <li v-for="doc in documents" :key="doc.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-950">{{ doc.original_name }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ doc.document_type.replace(/_/g, ' ') }} ·
                                    {{ (doc.size_bytes / 1024).toFixed(1) }} KB
                                    <span v-if="doc.expires_at">· {{ t('merchants.documents.expires_on', { date: doc.expires_at }) }}</span>
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <StatusPill :label="doc.verification_status" :tone="documentStatusTone(doc)" />
                                <a
                                    :href="merchantDocumentDownloadUrl(merchant.uuid, doc.uuid)"
                                    class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                >
                                    {{ t('merchants.documents.download') }}
                                </a>
                                <button
                                    v-if="can(PlatformPermission.MerchantDocumentsVerify) && doc.verification_status !== 'verified'"
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100"
                                    @click="verifyDocument(doc)"
                                >
                                    <CheckCircle2 class="size-3.5" />
                                    {{ t('merchants.documents.verify') }}
                                </button>
                                <button
                                    v-if="can(PlatformPermission.MerchantDocumentsVerify) && doc.verification_status !== 'rejected'"
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-lg bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100"
                                    @click="rejectDocument(doc)"
                                >
                                    <XCircle class="size-3.5" />
                                    {{ t('merchants.documents.reject') }}
                                </button>
                                <button
                                    v-if="can(PlatformPermission.MerchantDocumentsVerify)"
                                    type="button"
                                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                                    @click="removeDocument(doc)"
                                >
                                    {{ t('common.delete') }}
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>
            </section>

            <section v-if="activeTab === 'activities'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.activities.title') }}</h3>
                    <ListTree class="size-5 text-slate-400" />
                </div>

                <div v-if="merchant.activities.length === 0" class="mt-6 rounded-lg border border-dashed border-slate-200 bg-slate-50 p-5 text-center text-sm text-slate-500">
                    {{ t('merchants.activities.empty') }}
                </div>

                <ul v-else class="mt-4 grid gap-2 sm:grid-cols-2">
                    <li
                        v-for="activity in merchant.activities"
                        :key="activity.id"
                        class="flex items-start justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3"
                    >
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ activity.code }}</p>
                            <p class="text-sm font-semibold text-slate-950">{{ activity.name_en }}</p>
                            <p dir="rtl" class="text-xs text-slate-500">{{ activity.name_ar }}</p>
                        </div>
                        <StatusPill v-if="activity.is_primary" :label="t('merchants.activities.primary')" tone="green" />
                    </li>
                </ul>
            </section>

            <section v-if="activeTab === 'history'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.history.title') }}</h3>
                    <History class="size-5 text-slate-400" />
                </div>

                <ol v-if="merchant.status_history && merchant.status_history.length > 0" class="mt-6 space-y-4">
                    <li v-for="entry in merchant.status_history" :key="entry.id" class="flex gap-3">
                        <span class="mt-1 size-2 shrink-0 rounded-full bg-teal-500" />
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                {{ entry.from_status ? statusLabel(entry.from_status) + ' → ' : '' }}{{ statusLabel(entry.to_status) }}
                            </p>
                            <p v-if="entry.reason" class="text-sm text-slate-600">{{ entry.reason }}</p>
                            <p class="mt-1 text-xs text-slate-400">{{ entry.changed_at }}</p>
                        </div>
                    </li>
                </ol>
                <p v-else class="mt-4 text-sm text-slate-500">{{ t('merchants.history.empty') }}</p>
            </section>

            <div>
                <RouterLink to="/admin/merchants" class="text-sm font-semibold text-teal-700 hover:text-teal-900">
                    ← {{ t('merchants.back_to_list') }}
                </RouterLink>
            </div>
        </section>
    </AdminLayout>
</template>
