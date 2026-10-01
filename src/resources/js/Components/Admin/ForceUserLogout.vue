<script setup lang="ts">
/**
 * "Force logout" (Super Admin) — ends every session of a user.
 *
 * LAUNCH-P1 low finding: it ran on a single click. It now asks for
 * confirmation first, because the user is signed out everywhere at once.
 */
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import { apiPost } from '@/lib/api';
import { authState } from '@/stores/auth';

const props = defineProps<{ userId: number | string; userName?: string }>();
const { locale } = useI18n();
const busy = ref(false);
const message = ref('');
const confirming = ref(false);

async function forceLogout(): Promise<void> {
    busy.value = true;
    message.value = '';
    try {
        await apiPost('/admin/api/v1/users/' + props.userId + '/force-logout', {});
        message.value = locale.value === 'ar' ? 'تم إنهاء الجلسات' : 'Sessions ended';
    } catch {
        message.value = locale.value === 'ar' ? 'تعذر إنهاء الجلسات' : 'Could not end sessions';
    } finally {
        busy.value = false;
        confirming.value = false;
    }
}
</script>
<template>
    <span v-if="authState.user?.roles?.includes('platform_super_admin')">
        <button
            type="button"
            :disabled="busy"
            class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold disabled:opacity-50"
            data-testid="force-logout"
            @click="confirming = true"
        >
            {{ locale === 'ar' ? 'إنهاء كل الجلسات' : 'Force logout' }}
        </button>
        <span role="status" class="ms-2 text-xs">{{ message }}</span>
        <ConfirmDialog
            v-if="confirming"
            :title="locale === 'ar' ? 'إنهاء كل الجلسات؟' : 'End all sessions?'"
            :message="locale === 'ar'
                ? `سيتم تسجيل خروج ${props.userName ?? 'هذا المستخدم'} من كل الأجهزة والمتصفحات فورًا.`
                : `${props.userName ?? 'This user'} will be signed out of every browser and device right away.`"
            :confirm-label="locale === 'ar' ? 'إنهاء الجلسات' : 'Force logout'"
            :loading="busy"
            @confirm="forceLogout"
            @cancel="confirming = false"
        />
    </span>
</template>
