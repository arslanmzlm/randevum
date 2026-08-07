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
            // The page's own save/delete/revert are non-GET visits — never guard those.
            if (event.detail.visit.method !== 'get') {
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
