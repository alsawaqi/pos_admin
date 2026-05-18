import { nextTick, ref, type Ref } from 'vue';
import { refreshCsrf } from '@/lib/api';

export function useFreshCsrfNativePost(csrfToken: Ref<string>) {
    const isSubmitting = ref(false);

    async function submitWithFreshCsrf(event: SubmitEvent): Promise<void> {
        const form = event.currentTarget;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (isSubmitting.value) {
            event.preventDefault();

            return;
        }

        event.preventDefault();
        isSubmitting.value = true;

        try {
            const freshToken = await refreshCsrf();

            if (freshToken !== '') {
                csrfToken.value = freshToken;
                await nextTick();
                syncHiddenToken(form, freshToken);
            }
        } catch {
            // If the lightweight refresh endpoint is unavailable, continue
            // with the current token so the native POST remains the source
            // of truth for auth errors and redirects.
        }

        form.submit();
    }

    return {
        isSubmitting,
        submitWithFreshCsrf,
    };
}

function syncHiddenToken(form: HTMLFormElement, token: string): void {
    const tokenField = form.querySelector<HTMLInputElement>('input[name="_token"]');

    if (tokenField) {
        tokenField.value = token;
    }
}
