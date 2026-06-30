<script setup lang="ts">
/**
 * Marketing → Slider Builder (create + edit). Set the slider's name, interval,
 * status, and display window; pick approved content advertiser-by-advertiser
 * into a drag-orderable loop; and target it at the SPECIFIC devices that run the
 * slider app (filterable by branch).
 */

import { AlertTriangle, ArrowLeft, Camera, Check, ChevronDown, ChevronUp, GripVertical, Monitor, Pencil, Plus, Search, Trash2, Upload, Video } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Sortable from 'sortablejs';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError } from '@/lib/api';
import {
    checkSliderConflicts,
    createSlider,
    getSlider,
    getSliderOptions,
    replaceSliderContent,
    updateSlider,
    uploadSliderContent,
    type ContentType,
    type SliderConflict,
    type SliderOptions,
    type SliderOptionsContent,
    type SliderOptionsDevice,
    type SliderStatus,
    type UploadedContentAsset,
} from '@/lib/api/marketingSliders';
import ImageEditorModal from '@/Components/ImageEditorModal.vue';

const route = useRoute();
const router = useRouter();

const uuid = computed(() => (typeof route.params.uuid === 'string' ? route.params.uuid : null));
const isEdit = computed(() => uuid.value !== null);

const loading = ref(true);
const saving = ref(false);
const error = ref<string | null>(null);
const fieldErrors = ref<Record<string, string[]>>({});

const options = reactive<SliderOptions>({ content: [], branches: [], devices: [] });

const form = reactive<{ name: string; loop_interval_seconds: number; status: SliderStatus; starts_at: string; ends_at: string }>({
    name: '',
    loop_interval_seconds: 8,
    status: 'draft',
    starts_at: '',
    ends_at: '',
});

interface BuilderItem {
    content_asset_id: number;
    advertiser_id: number | null;
    duration_seconds: number;
    title: string;
    type: ContentType;
    url: string | null;
    thumbnail_url: string | null;
    brand: string | null;
}
const items = ref<BuilderItem[]>([]);

// ---- Content picker (advertiser-first) -----------------------------------
const selectedAdvertiserId = ref<number | 'all' | 'admin'>('all');
const contentSearch = ref('');
const typeFilter = ref<'all' | 'image' | 'video'>('all');
const hideAdded = ref(false);
const addMode = ref<'library' | 'upload'>('library');

const adminUploadCount = computed(() => options.content.filter((c) => c.advertiser_id == null).length);

const advertiserOptions = computed(() => {
    const m = new Map<number, { id: number; brand: string; count: number }>();
    for (const c of options.content) {
        if (c.advertiser_id == null) continue;
        const e = m.get(c.advertiser_id) ?? { id: c.advertiser_id, brand: c.advertiser?.brand_name ?? `#${c.advertiser_id}`, count: 0 };
        e.count++;
        m.set(c.advertiser_id, e);
    }
    return Array.from(m.values()).sort((a, b) => a.brand.localeCompare(b.brand));
});

const selectedIds = computed(() => new Set(items.value.map((i) => i.content_asset_id)));
const filteredContent = computed(() => {
    const q = contentSearch.value.trim().toLowerCase();
    return options.content.filter((c) => {
        if (selectedAdvertiserId.value === 'admin') {
            if (c.advertiser_id != null) return false;
        } else if (selectedAdvertiserId.value !== 'all' && c.advertiser_id !== selectedAdvertiserId.value) {
            return false;
        }
        if (typeFilter.value !== 'all' && c.type !== typeFilter.value) return false;
        if (hideAdded.value && selectedIds.value.has(c.id)) return false;
        if (q && !c.title.toLowerCase().includes(q)) return false;
        return true;
    });
});
// How many of the currently-shown tiles are not yet in the slider (for "Add all shown").
const shownAddableCount = computed(() => filteredContent.value.reduce((n, c) => (selectedIds.value.has(c.id) ? n : n + 1), 0));

// ---- Direct admin upload (image/video straight into a slider) -------------
const fileInput = ref<HTMLInputElement | null>(null);
const photoInput = ref<HTMLInputElement | null>(null);
const videoInput = ref<HTMLInputElement | null>(null);
const uploadFile = ref<File | null>(null);
const uploadPreview = ref<string | null>(null);
const uploadTitle = ref('');
const uploading = ref(false);
const uploadError = ref<string | null>(null);
const uploadIsImage = computed(() => uploadFile.value?.type.startsWith('image/') ?? false);

// Filerobot editor — edits an already-uploaded admin IMAGE asset, saves back.
const editorAsset = ref<{ id: number; url: string; title: string } | null>(null);

function onFilePicked(e: Event): void {
    const f = (e.target as HTMLInputElement).files?.[0] ?? null;
    if (!f) return;
    if (uploadPreview.value) URL.revokeObjectURL(uploadPreview.value);
    uploadFile.value = f;
    uploadTitle.value = f.name.replace(/\.[^.]+$/, '').slice(0, 200);
    uploadPreview.value = URL.createObjectURL(f);
    uploadError.value = null;
}

function clearUpload(): void {
    if (uploadPreview.value) URL.revokeObjectURL(uploadPreview.value);
    uploadFile.value = null;
    uploadPreview.value = null;
    uploadTitle.value = '';
    uploadError.value = null;
    if (fileInput.value) fileInput.value.value = '';
}

function pushUploadedAsset(asset: UploadedContentAsset): SliderOptionsContent {
    const c: SliderOptionsContent = {
        id: asset.id,
        title: asset.title,
        type: asset.type,
        status: asset.status,
        url: asset.url,
        thumbnail_url: asset.thumbnail_url,
        duration_seconds: asset.duration_seconds,
        advertiser_id: null,
        advertiser: null,
    };
    const idx = options.content.findIndex((x) => x.id === c.id);
    if (idx >= 0) options.content.splice(idx, 1, c);
    else options.content.unshift(c);
    return c;
}

async function doUpload(): Promise<void> {
    if (!uploadFile.value) { uploadError.value = 'Choose a file first.'; return; }
    if (!uploadTitle.value.trim()) { uploadError.value = 'Give it a title.'; return; }
    uploading.value = true;
    uploadError.value = null;
    try {
        const asset = await uploadSliderContent(uploadFile.value, uploadTitle.value.trim());
        addItem(pushUploadedAsset(asset));
        clearUpload();
        addMode.value = 'library';
        selectedAdvertiserId.value = 'admin';
    } catch (e) {
        uploadError.value = e instanceof ApiError ? e.message : 'Upload failed.';
    } finally {
        uploading.value = false;
    }
}

/** Open the Filerobot editor for an admin image asset already in the picker. */
function editAsset(c: SliderOptionsContent): void {
    if (c.type !== 'image' || c.advertiser_id != null || !c.url) return;
    editorAsset.value = { id: c.id, url: c.url, title: c.title };
}

/** Editor saved a new file → replace the asset, refresh its preview everywhere. */
async function onEditorSave(file: File): Promise<void> {
    const target = editorAsset.value;
    if (!target) return;
    const asset = await replaceSliderContent(target.id, file);
    const fresh = pushUploadedAsset(asset);
    // refresh any slider item that uses this asset (new url bypasses the cache).
    for (const it of items.value) {
        if (it.content_asset_id === fresh.id) {
            it.url = fresh.url;
            it.thumbnail_url = fresh.thumbnail_url;
            it.title = fresh.title;
        }
    }
    editorAsset.value = null;
}

function addItem(c: SliderOptionsContent): void {
    if (selectedIds.value.has(c.id)) return;
    items.value.push({
        content_asset_id: c.id,
        advertiser_id: c.advertiser_id,
        duration_seconds: c.duration_seconds ?? form.loop_interval_seconds,
        title: c.title,
        type: c.type,
        url: c.url,
        thumbnail_url: c.thumbnail_url,
        brand: c.advertiser?.brand_name ?? null,
    });
}

function addAllShown(): void {
    for (const c of filteredContent.value) addItem(c);
}

function removeItem(index: number): void {
    items.value.splice(index, 1);
}

function removeByAssetId(id: number): void {
    const idx = items.value.findIndex((i) => i.content_asset_id === id);
    if (idx >= 0) items.value.splice(idx, 1);
}

function moveItem(index: number, dir: -1 | 1): void {
    const to = index + dir;
    if (to < 0 || to >= items.value.length) return;
    reorder(index, to);
}

function reorder(from: number, to: number): void {
    if (from === to) return;
    const copy = [...items.value];
    const [moved] = copy.splice(from, 1);
    if (moved === undefined) return;
    copy.splice(to, 0, moved);
    items.value = copy;
}

// ---- Drag & drop ordering (SortableJS: works with mouse AND touch) --------
// Native HTML5 drag never fires touchstart, so it was dead on phones. SortableJS
// drives reorder from the grip handle on both. The <li> are keyed by
// content_asset_id, so updating the array to match Sortable's DOM move keeps Vue
// and the DOM consistent (no duplicate/flicker). Up/down buttons remain as a
// precise, always-available fallback.
const listEl = ref<HTMLElement | null>(null);
let sortable: Sortable | null = null;

watch(
    listEl,
    (el) => {
        sortable?.destroy();
        sortable = null;
        if (el) {
            sortable = Sortable.create(el, {
                handle: '.drag-handle',
                animation: 150,
                ghostClass: 'slider-item-ghost',
                onUpdate: (e) => {
                    const { oldIndex, newIndex, item, from } = e;
                    if (oldIndex == null || newIndex == null) return;
                    // Revert SortableJS's physical DOM move, then drive the order
                    // from data — so Vue's keyed render is the single source of
                    // truth (avoids the two-systems-mutate-one-subtree desync).
                    from.insertBefore(item, from.children[oldIndex > newIndex ? oldIndex + 1 : oldIndex] ?? null);
                    reorder(oldIndex, newIndex);
                },
            });
        }
    },
    { flush: 'post' },
);

onBeforeUnmount(() => {
    sortable?.destroy();
    sortable = null;
});

// ---- Targeting: specific devices ------------------------------------------
const selectedDeviceIds = ref<number[]>([]);
const deviceBranchFilter = ref<number | 'all'>('all');
const deviceSearch = ref('');

const deviceById = computed(() => {
    const m = new Map<number, SliderOptionsDevice>();
    for (const d of options.devices) m.set(d.id, d);
    return m;
});

const filteredDevices = computed(() => {
    const q = deviceSearch.value.trim().toLowerCase();
    return options.devices.filter((d) => {
        if (deviceBranchFilter.value !== 'all' && d.branch_id !== deviceBranchFilter.value) return false;
        if (q && !(d.name ?? '').toLowerCase().includes(q)) return false;
        return true;
    });
});

function toggleDevice(id: number): void {
    const i = selectedDeviceIds.value.indexOf(id);
    if (i === -1) selectedDeviceIds.value.push(id);
    else selectedDeviceIds.value.splice(i, 1);
}

// ---- Competitor advisory (non-blocking) -----------------------------------
const conflicts = ref<SliderConflict[]>([]);
const advertiserIds = computed(() => Array.from(new Set(items.value.map((i) => i.advertiser_id).filter((x): x is number => x !== null))));
const branchIds = computed(() => Array.from(new Set(
    selectedDeviceIds.value.map((id) => deviceById.value.get(id)?.branch_id).filter((x): x is number => x != null),
)));

let conflictTimer: number | null = null;
watch([() => advertiserIds.value.join(','), () => branchIds.value.join(',')], () => {
    if (conflictTimer) window.clearTimeout(conflictTimer);
    conflictTimer = window.setTimeout(() => void runConflictCheck(), 350);
});

async function runConflictCheck(): Promise<void> {
    if (advertiserIds.value.length === 0 || branchIds.value.length === 0) {
        conflicts.value = [];
        return;
    }
    try {
        conflicts.value = (await checkSliderConflicts(advertiserIds.value, branchIds.value)).data.conflicts;
    } catch {
        conflicts.value = [];
    }
}

async function loadAll(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const opts = await getSliderOptions();
        options.content = opts.data.content;
        options.branches = opts.data.branches;
        options.devices = opts.data.devices;

        if (uuid.value) {
            const slider = (await getSlider(uuid.value)).data;
            form.name = slider.name;
            form.loop_interval_seconds = slider.loop_interval_seconds;
            form.status = slider.status;
            form.starts_at = slider.starts_at ? slider.starts_at.slice(0, 10) : '';
            form.ends_at = slider.ends_at ? slider.ends_at.slice(0, 10) : '';
            items.value = (slider.items ?? []).map((it) => ({
                content_asset_id: it.content_asset_id,
                advertiser_id: it.advertiser_id,
                duration_seconds: it.duration_seconds,
                title: it.content?.title ?? `#${it.content_asset_id}`,
                type: it.content?.type ?? 'image',
                url: it.content?.url ?? null,
                thumbnail_url: it.content?.thumbnail_url ?? null,
                brand: it.advertiser?.brand_name ?? null,
            }));
            selectedDeviceIds.value = (slider.targets ?? [])
                .filter((t) => t.device_id !== null)
                .map((t) => t.device_id as number);
        }
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load';
    } finally {
        loading.value = false;
    }

    void runConflictCheck();
}

onMounted(() => void loadAll());

async function save(): Promise<void> {
    saving.value = true;
    error.value = null;
    fieldErrors.value = {};

    const payload = {
        name: form.name,
        loop_interval_seconds: form.loop_interval_seconds,
        status: form.status,
        starts_at: form.starts_at || null,
        ends_at: form.ends_at || null,
        items: items.value.map((i) => ({ content_asset_id: i.content_asset_id, duration_seconds: i.duration_seconds })),
        targets: selectedDeviceIds.value.map((id) => ({ device_id: id, branch_id: deviceById.value.get(id)?.branch_id ?? null })),
    };

    try {
        if (uuid.value) await updateSlider(uuid.value, payload);
        else await createSlider(payload);
        void router.push({ name: 'admin.marketing.sliders.index' });
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            fieldErrors.value = err.payload.errors;
            error.value = err.firstValidationMessage() ?? 'Please fix the highlighted fields.';
        } else {
            error.value = err instanceof Error ? err.message : 'Save failed';
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <button type="button" class="grid size-9 place-items-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" aria-label="Back" @click="router.push({ name: 'admin.marketing.sliders.index' })">
                        <ArrowLeft class="size-4" />
                    </button>
                    <h1 class="text-2xl font-semibold tracking-tight text-slate-950">{{ isEdit ? 'Edit slider' : 'New slider' }}</h1>
                </div>
                <button
                    type="button"
                    :disabled="saving || loading"
                    class="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:bg-slate-800 disabled:opacity-60"
                    @click="save"
                >{{ saving ? 'Saving…' : 'Save slider' }}</button>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ error }}</div>

            <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">Loading…</div>

            <template v-else>
                <!-- Settings -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <label class="block sm:col-span-2">
                            <span class="text-sm font-medium text-slate-700">Name *</span>
                            <input v-model="form.name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="fieldErrors.name" class="mt-1 text-xs text-rose-600">{{ fieldErrors.name[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">Status</span>
                            <select v-model="form.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option value="draft">Draft</option>
                                <option value="active">Active</option>
                                <option value="paused">Paused</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">Default interval (s)</span>
                            <input v-model.number="form.loop_interval_seconds" type="number" min="2" max="120" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">Starts</span>
                            <input v-model="form.starts_at" type="date" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">Ends</span>
                            <input v-model="form.ends_at" type="date" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="fieldErrors.ends_at" class="mt-1 text-xs text-rose-600">{{ fieldErrors.ends_at[0] }}</p>
                        </label>
                    </div>
                </div>

                <!-- Items -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-950">Slider items <span class="text-sm font-normal text-slate-500">({{ items.length }})</span></h2>
                    <p class="mt-1 text-sm text-slate-500">Drag the grip <span class="align-middle">⠿</span> or use the ↑/↓ buttons to reorder — item 1 plays first.</p>
                    <p v-if="fieldErrors.items" class="mt-1 text-sm text-rose-600">{{ fieldErrors.items[0] }}</p>

                    <div v-if="items.length === 0" class="mt-3 rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                        No items yet — pick an advertiser below and add their content.
                    </div>
                    <ol ref="listEl" v-else class="mt-3 space-y-2">
                        <li
                            v-for="(item, index) in items"
                            :key="item.content_asset_id"
                            class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-slate-200 bg-white p-2.5"
                        >
                            <span class="drag-handle grid size-9 shrink-0 cursor-grab touch-none place-items-center rounded-md text-slate-300 hover:bg-slate-100 active:cursor-grabbing sm:size-8" title="Drag to reorder"><GripVertical class="size-4" /></span>
                            <span class="grid size-6 shrink-0 place-items-center rounded-full bg-slate-100 text-xs font-bold text-slate-500">{{ index + 1 }}</span>
                            <div class="grid size-12 shrink-0 place-items-center overflow-hidden rounded-lg bg-slate-100 text-slate-400">
                                <img v-if="item.type === 'image' && item.url" :src="item.url" :alt="item.title" class="size-full object-cover">
                                <img v-else-if="item.thumbnail_url" :src="item.thumbnail_url" :alt="item.title" class="size-full object-cover">
                                <Video v-else class="size-5" />
                            </div>
                            <div class="min-w-0 flex-1 basis-32">
                                <p class="truncate text-sm font-semibold text-slate-950">{{ item.title }}</p>
                                <p class="truncate text-xs text-slate-500">{{ item.brand ?? '—' }}</p>
                            </div>
                            <div class="flex basis-full items-center justify-end gap-1 sm:ml-auto sm:basis-auto">
                                <label class="mr-1 flex items-center gap-1 text-xs text-slate-500">
                                    <input v-model.number="item.duration_seconds" type="number" min="2" max="120" class="w-14 rounded-lg border border-slate-200 px-2 py-1.5 text-sm">
                                    s
                                </label>
                                <button type="button" class="grid size-9 place-items-center rounded-md text-slate-500 hover:bg-slate-100 disabled:opacity-30 sm:size-8" :disabled="index === 0" title="Move up" @click="moveItem(index, -1)"><ChevronUp class="size-4" /></button>
                                <button type="button" class="grid size-9 place-items-center rounded-md text-slate-500 hover:bg-slate-100 disabled:opacity-30 sm:size-8" :disabled="index === items.length - 1" title="Move down" @click="moveItem(index, 1)"><ChevronDown class="size-4" /></button>
                                <button type="button" class="grid size-9 place-items-center rounded-md text-rose-600 hover:bg-rose-50 sm:size-8" title="Remove" @click="removeItem(index)"><Trash2 class="size-4" /></button>
                            </div>
                        </li>
                    </ol>

                    <!-- Add content: pick from the library or upload your own -->
                    <div class="mt-5 border-t border-slate-100 pt-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-slate-700">Add content</h3>
                            <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs font-semibold">
                                <button type="button" class="rounded-md px-3 py-1.5 transition" :class="addMode === 'library' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'" @click="addMode = 'library'">Library</button>
                                <button type="button" class="rounded-md px-3 py-1.5 transition" :class="addMode === 'upload' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'" @click="addMode = 'upload'">Upload</button>
                            </div>
                        </div>

                        <!-- LIBRARY (advertiser + admin content) -->
                        <template v-if="addMode === 'library'">
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <label class="min-w-0 flex-1 basis-44">
                                    <span class="sr-only">Source</span>
                                    <select v-model="selectedAdvertiserId" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                        <option value="all">All sources</option>
                                        <option v-if="adminUploadCount" value="admin">Admin uploads ({{ adminUploadCount }})</option>
                                        <option v-for="a in advertiserOptions" :key="a.id" :value="a.id">{{ a.brand }} ({{ a.count }})</option>
                                    </select>
                                </label>
                                <label class="flex min-w-0 flex-1 basis-44 items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-slate-500">
                                    <Search class="size-4 shrink-0" />
                                    <input v-model="contentSearch" type="search" placeholder="Search title" class="w-full bg-transparent text-sm outline-none">
                                </label>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2">
                                <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs font-semibold">
                                    <button type="button" class="rounded-md px-3 py-1.5 transition" :class="typeFilter === 'all' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'" @click="typeFilter = 'all'">All</button>
                                    <button type="button" class="rounded-md px-3 py-1.5 transition" :class="typeFilter === 'image' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'" @click="typeFilter = 'image'">Images</button>
                                    <button type="button" class="rounded-md px-3 py-1.5 transition" :class="typeFilter === 'video' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'" @click="typeFilter = 'video'">Videos</button>
                                </div>
                                <label class="inline-flex cursor-pointer items-center gap-1.5 text-xs font-medium text-slate-600">
                                    <input v-model="hideAdded" type="checkbox" class="size-3.5 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                    Hide added
                                </label>
                                <span class="text-xs text-slate-400">{{ filteredContent.length }} shown · {{ selectedIds.size }} in slider</span>
                                <button v-if="shownAddableCount" type="button" class="ml-auto rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="addAllShown">Add all shown ({{ shownAddableCount }})</button>
                            </div>

                            <p v-if="filteredContent.length === 0" class="mt-3 text-sm text-slate-500">
                                {{ hideAdded && selectedIds.size > 0
                                    ? 'Everything that matches is already in the slider — uncheck “Hide added” to see it.'
                                    : (contentSearch.trim() || typeFilter !== 'all' || selectedAdvertiserId !== 'all')
                                        ? 'No content matches these filters.'
                                        : 'No content yet — switch to Upload to add your own.' }}
                            </p>
                            <div v-else class="mt-3 grid max-h-80 grid-cols-2 gap-3 overflow-auto sm:grid-cols-3 lg:grid-cols-4">
                                <div v-for="c in filteredContent" :key="c.id" class="overflow-hidden rounded-xl border border-slate-200">
                                    <div class="relative grid h-24 place-items-center bg-slate-100 text-slate-400">
                                        <img v-if="c.type === 'image' && c.url" :src="c.url" :alt="c.title" class="size-full object-cover">
                                        <img v-else-if="c.thumbnail_url" :src="c.thumbnail_url" :alt="c.title" class="size-full object-cover">
                                        <Video v-else class="size-6" />
                                        <button v-if="c.type === 'image' && c.advertiser_id == null && c.url" type="button" title="Edit image" class="absolute right-1 top-1 grid size-6 place-items-center rounded-md bg-white/90 text-slate-700 shadow hover:bg-white" @click="editAsset(c)">
                                            <Pencil class="size-3.5" />
                                        </button>
                                    </div>
                                    <div class="p-2">
                                        <p class="truncate text-xs font-semibold text-slate-900">{{ c.title }}</p>
                                        <p class="truncate text-[11px] text-slate-500">{{ c.advertiser?.brand_name ?? 'Admin upload' }}</p>
                                        <div class="mt-1.5">
                                            <button
                                                v-if="!selectedIds.has(c.id)"
                                                type="button"
                                                class="inline-flex w-full items-center justify-center gap-1 rounded-md bg-teal-600 px-2 py-1 text-xs font-semibold text-white transition hover:bg-teal-700"
                                                title="Add to slider"
                                                @click="addItem(c)"
                                            >
                                                <Plus class="size-3" /> Add
                                            </button>
                                            <div v-else class="flex items-center gap-1">
                                                <span class="inline-flex flex-1 items-center justify-center gap-1 rounded-md bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">
                                                    <Check class="size-3" /> Added
                                                </span>
                                                <button
                                                    type="button"
                                                    class="grid size-7 shrink-0 place-items-center rounded-md text-rose-600 ring-1 ring-inset ring-rose-200 transition hover:bg-rose-50"
                                                    title="Remove from slider"
                                                    @click="removeByAssetId(c.id)"
                                                >
                                                    <Trash2 class="size-3.5" />
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <!-- UPLOAD (admin drops media straight into the slider) -->
                        <div v-else class="mt-3">
                            <!-- Separate camera inputs so phones open the Camera (photo) and the
                                 camcorder (video) directly. Neutral `capture` (no facing) opens the
                                 full native camera so the user can switch front/back; the plain
                                 input is the gallery/files picker. -->
                            <input ref="fileInput" type="file" accept="image/*,video/*" class="hidden" @change="onFilePicked">
                            <input ref="photoInput" type="file" accept="image/*" capture class="hidden" @change="onFilePicked">
                            <input ref="videoInput" type="file" accept="video/*" capture class="hidden" @change="onFilePicked">
                            <div v-if="!uploadFile" class="grid place-items-center rounded-xl border-2 border-dashed border-slate-200 p-8 text-center">
                                <Upload class="size-7 text-slate-400" />
                                <div class="mt-3 flex flex-wrap items-center justify-center gap-2">
                                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700" @click="photoInput?.click()">
                                        <Camera class="size-4" /> Take a photo
                                    </button>
                                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800" @click="videoInput?.click()">
                                        <Video class="size-4" /> Record a video
                                    </button>
                                </div>
                                <button type="button" class="mt-3 text-xs font-medium text-slate-500 underline hover:text-slate-700" @click="fileInput?.click()">or choose from your files · up to 50&nbsp;MB</button>
                            </div>
                            <div v-else class="rounded-xl border border-slate-200 p-4">
                                <div class="flex flex-col gap-4 sm:flex-row">
                                    <div class="grid h-28 w-full shrink-0 place-items-center overflow-hidden rounded-lg bg-slate-100 sm:w-40">
                                        <img v-if="uploadIsImage && uploadPreview" :src="uploadPreview" alt="preview" class="size-full object-cover">
                                        <video v-else-if="uploadPreview" :src="uploadPreview" class="size-full object-cover" muted></video>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <label class="block text-xs font-semibold text-slate-600">Title</label>
                                        <input v-model="uploadTitle" maxlength="200" type="text" placeholder="Title shown in the library" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                        <p class="mt-1 truncate text-xs text-slate-400">{{ uploadFile.name }} · {{ (uploadFile.size / 1048576).toFixed(1) }} MB</p>
                                        <div class="mt-3 flex flex-wrap items-center gap-2">
                                            <button type="button" :disabled="uploading" class="inline-flex items-center gap-1.5 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-60" @click="doUpload">
                                                <Plus class="size-4" /> {{ uploading ? 'Uploading…' : 'Upload + add to slider' }}
                                            </button>
                                            <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50" @click="clearUpload">Cancel</button>
                                        </div>
                                        <p v-if="uploadError" class="mt-2 text-xs font-medium text-rose-600">{{ uploadError }}</p>
                                        <p class="mt-2 text-xs text-slate-400">Images can be edited after uploading — tap the pencil on the tile in the library.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Targeting: devices -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-950">Where it plays</h2>
                    <p class="mt-1 text-sm text-slate-500">Pick the specific screens that run the slider. Filter by branch to narrow them down.</p>

                    <!-- Competitor advisory (non-blocking) -->
                    <div v-if="conflicts.length" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <div class="flex items-center gap-2 text-sm font-semibold text-amber-800">
                            <AlertTriangle class="size-4" />
                            Competitor heads-up
                        </div>
                        <ul class="mt-2 space-y-1 text-sm text-amber-800">
                            <li v-for="(c, i) in conflicts" :key="i">
                                <span class="font-semibold">{{ c.advertiser_brand }}</span> ({{ c.category }}) would play at
                                <span class="font-semibold">{{ c.merchant_name ?? 'a merchant' }}</span> — a competitor (their brand {{ c.competitor_brand }}){{ c.branch_count > 1 ? ` · ${c.branch_count} branches` : '' }}.
                            </li>
                        </ul>
                        <p class="mt-2 text-xs text-amber-700">This is only a heads-up — you can still save.</p>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <label class="block">
                            <span class="sr-only">Branch</span>
                            <select v-model="deviceBranchFilter" class="w-56 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option value="all">All branches</option>
                                <option v-for="b in options.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </label>
                        <label class="flex w-56 items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-slate-500">
                            <Search class="size-4" />
                            <input v-model="deviceSearch" type="search" placeholder="Search device name" class="w-full bg-transparent text-sm outline-none">
                        </label>
                        <span class="text-xs font-semibold text-slate-500">{{ selectedDeviceIds.length }} device(s) selected</span>
                    </div>

                    <p v-if="filteredDevices.length === 0" class="mt-3 text-sm text-slate-500">No devices match.</p>
                    <div v-else class="mt-3 grid max-h-72 grid-cols-1 gap-2 overflow-auto sm:grid-cols-2 lg:grid-cols-3">
                        <label
                            v-for="d in filteredDevices"
                            :key="d.id"
                            class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 text-sm transition"
                            :class="selectedDeviceIds.includes(d.id) ? 'border-teal-500 bg-teal-50' : 'border-slate-200 hover:bg-slate-50'"
                        >
                            <input type="checkbox" :checked="selectedDeviceIds.includes(d.id)" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500" @change="toggleDevice(d.id)">
                            <Monitor class="size-4 shrink-0 text-slate-400" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold text-slate-900">{{ d.name ?? `Device #${d.id}` }}</p>
                                <p class="truncate text-xs text-slate-500">{{ d.branch_name ?? 'Unassigned' }}</p>
                            </div>
                            <span v-if="d.in_use && !selectedDeviceIds.includes(d.id)" class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700" title="Already used by another slider">In use</span>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide" :class="d.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-500'">{{ d.status }}</span>
                        </label>
                    </div>
                    <p class="mt-2 text-xs text-slate-400">Tip: a branch can have many screens, but only the ones you pick here will run this slider.</p>
                </div>
            </template>
        </section>

        <ImageEditorModal
            v-if="editorAsset"
            :src="editorAsset.url"
            :title="editorAsset.title"
            @save="onEditorSave"
            @close="editorAsset = null"
        />
    </AdminLayout>
</template>

<style>
/* SortableJS drop placeholder shown while a slider item is being dragged. */
.slider-item-ghost { opacity: 0.4; }
</style>
