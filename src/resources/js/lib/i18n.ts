import { createI18n } from 'vue-i18n';
import ar from '../locales/ar.json';
import en from '../locales/en.json';

/**
 * Multilanguage support is scaffolded but intentionally not exposed in the
 * UI yet. The team is shipping English-only through the system build-out
 * and will re-enable Arabic + RTL as the final phase. Keep the locale files
 * in sync so we don't have to re-translate later.
 */

export type SupportedLocale = 'en' | 'ar';

const DEFAULT_LOCALE: SupportedLocale = 'en';

export const i18n = createI18n({
    legacy: false,
    locale: DEFAULT_LOCALE,
    fallbackLocale: DEFAULT_LOCALE,
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en,
        ar,
    },
});

export function applyDocumentDirection(): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.lang = DEFAULT_LOCALE;
    document.documentElement.dir = 'ltr';
}
