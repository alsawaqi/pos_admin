<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { apiGet, apiDelete } from '@/lib/api';
const props = defineProps<{ deviceUuid: string }>();
const codes = ref<{ id: number; created_at: string; expires_at: string }[]>([]);
const error = ref('');
async function load(): Promise<void> {
    try {
        codes.value = (await apiGet<{ data: typeof codes.value }>(`/admin/api/v1/devices/${props.deviceUuid}/activation-tokens`)).data;
    } catch (e) { error.value = e instanceof Error ? e.message : 'Could not load activation codes.'; }
}
async function revoke(id: number): Promise<void> {
    try {
        await apiDelete(`/admin/api/v1/devices/${props.deviceUuid}/activation-tokens/${id}`);
        await load();
    } catch (e) { error.value = e instanceof Error ? e.message : 'Could not revoke code.'; }
}
onMounted(load);
watch(() => props.deviceUuid, load);
</script>
<template>
    <section class="rounded-xl border bg-white p-6">
        <h2 class="font-semibold">Outstanding activation codes</h2>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-if="!codes.length" class="text-sm">No outstanding codes.</p>
        <ul><li v-for="code in codes" :key="code.id" class="mt-3 text-sm">
            Code #{{ code.id }} · expires {{ code.expires_at }}
            <button type="button" class="ml-2 text-rose-700 underline" @click="revoke(code.id)">Revoke</button>
        </li></ul>
        <button type="button" class="mt-3 underline" @click="load">Refresh</button>
    </section>
</template>
