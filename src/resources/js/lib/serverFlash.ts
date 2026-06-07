/**
 * Read-once accessor for data that Laravel flashed to the session during the
 * previous request — populated by app.blade.php into window.__SERVER_FLASH__.
 *
 * Used by the login page to surface validation errors and old form input
 * after the server redirected back from a failed POST. Once consumed, the
 * flash is cleared so a subsequent navigation does not re-display stale
 * messages.
 */

interface ServerFlash {
    errors: Record<string, string[]> | null;
    old: Record<string, unknown> | null;
}

declare global {
    interface Window {
        __SERVER_FLASH__?: ServerFlash;
    }
}

export function consumeServerFlash(): ServerFlash {
    const flash: ServerFlash = {
        errors: window.__SERVER_FLASH__?.errors ?? null,
        old: window.__SERVER_FLASH__?.old ?? null,
    };

    // One-shot: drop the global so re-mounts don't replay it.
    if (typeof window !== 'undefined') {
        window.__SERVER_FLASH__ = { errors: null, old: null };
    }

    return flash;
}

export function firstFlashMessage(errors: Record<string, string[]> | null): string | null {
    if (!errors) {
        return null;
    }

    for (const messages of Object.values(errors)) {
        const [first] = messages;

        if (typeof first === 'string' && first !== '') {
            return first;
        }
    }

    return null;
}
