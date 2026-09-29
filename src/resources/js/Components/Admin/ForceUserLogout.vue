<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { apiPost } from '@/lib/api';
import { authState } from '@/stores/auth';
const props = defineProps<{ userId: number | string }>();
const { locale } = useI18n();
const busy = ref(false);
const message = ref('');
async function forceLogout() {
    busy.value = true;
    message.value = '';
    try {
        await apiPost('/admin/api/v1/users/' + props.userId + '/force-logout', {});
        message.value = locale.value === 'ar' ? 'تم إنهاء الجلسات' : 'Sessions ended';
    } catch {
        message.value = locale.value === 'ar' ? 'تعذر إنهاء الجلسات' : 'Could not end sessions';
    } finally { busy.value = false; }
}
</script>
<template>
    <span v-if="authState.user?.roles?.includes('platform_super_admin')">
        <button type="button" :disabled="busy" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold disabled:opacity-50" @click="forceLogout">
            {{ locale === 'ar' ? 'إنهاء كل الجلسات' : 'Force logout' }}
        </button>
        <span role="status" class="ms-2 text-xs">{{ message }}</span>
    </span>
</template>
