import { createI18n } from 'vue-i18n';
import ar from '../locales/ar.json';
import en from '../locales/en.json';

export type SupportedLocale = 'en' | 'ar';

const STORAGE_KEY = 'pos_admin.locale';
const DEFAULT_LOCALE: SupportedLocale = 'en';
const RTL_LOCALES: ReadonlySet<SupportedLocale> = new Set(['ar']);

function detectInitialLocale(): SupportedLocale {
    if (typeof window === 'undefined') {
        return DEFAULT_LOCALE;
    }

    const stored = window.localStorage.getItem(STORAGE_KEY);

    if (stored === 'en' || stored === 'ar') {
        return stored;
    }

    return DEFAULT_LOCALE;
}

export const i18n = createI18n({
    legacy: false,
    locale: detectInitialLocale(),
    fallbackLocale: DEFAULT_LOCALE,
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en,
        ar,
    },
});

export function setLocale(locale: SupportedLocale): void {
    i18n.global.locale.value = locale;

    if (typeof window !== 'undefined') {
        window.localStorage.setItem(STORAGE_KEY, locale);
    }

    applyDocumentDirection(locale);
}

export function applyDocumentDirection(locale: SupportedLocale = i18n.global.locale.value as SupportedLocale): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.lang = locale;
    document.documentElement.dir = RTL_LOCALES.has(locale) ? 'rtl' : 'ltr';
}

export function isRtl(locale: SupportedLocale = i18n.global.locale.value as SupportedLocale): boolean {
    return RTL_LOCALES.has(locale);
}
