import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

/**
 * Warns before leaving a page that holds an unsaved draft — both on tab close/reload and on
 * in-app Inertia navigation.
 *
 * `window.confirm` rather than PrimeVue's ConfirmDialog: Inertia's `before` handler is
 * synchronous, so an async dialog cannot block the visit. The ConfirmDialog rule targets
 * destructive server-side actions, which leaving a page is not.
 */
export function useUnsavedChanges(
    isDirty: () => boolean,
    message: string,
    /**
     * URLs whose non-GET visit IS the save (never guarded). Every other mutating visit —
     * creating a role, deleting one, reverting to defaults — remounts the page and would
     * silently discard the draft, so those are guarded like a navigation.
     */
    saveUrls: () => string[] = () => [],
): void {
    function onBeforeUnload(event: BeforeUnloadEvent): void {
        if (isDirty()) {
            event.preventDefault();
        }
    }

    let stopBefore: (() => void) | null = null;

    onMounted(() => {
        window.addEventListener('beforeunload', onBeforeUnload);

        stopBefore = router.on('before', (event) => {
            const { method, url } = event.detail.visit;

            if (
                method !== 'get' &&
                saveUrls().some((saveUrl) => url.toString().endsWith(saveUrl))
            ) {
                return;
            }

            if (isDirty() && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    onUnmounted(() => {
        window.removeEventListener('beforeunload', onBeforeUnload);
        stopBefore?.();
    });
}
