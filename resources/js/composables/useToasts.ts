import { router, usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { nextTick, onMounted, onUnmounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface FlashToast {
    severity?: 'success' | 'info' | 'warn' | 'error';
    summary: string;
    detail?: string | null;
    life?: number;
}

/**
 * Bridges server-flashed toasts (`flash.toasts`), validation errors, and
 * non-Inertia error responses (429/419) to PrimeVue's Toast. Call once from a
 * component mounted on every page (see AppToaster.vue).
 *
 * Field-level errors still render inline in the form; a failed request only
 * adds ONE generic error toast, never one per field.
 */
export function useToasts(): void {
    const page = usePage();
    const toast = useToast();
    const { t } = useI18n();

    function showFlashToasts(): void {
        const flash = page.props.flash as { toasts?: FlashToast[] } | undefined;

        for (const item of flash?.toasts ?? []) {
            toast.add({
                severity: item.severity ?? 'info',
                summary: item.summary,
                detail: item.detail ?? undefined,
                life: item.life ?? 4000,
            });
        }
    }

    function showErrorToast(): void {
        const errors = page.props.errors as Record<string, string> | undefined;

        if (errors && Object.keys(errors).length > 0) {
            toast.add({
                severity: 'error',
                summary: t('common.form_error'),
                life: 5000,
            });
        }
    }

    // Initial flush must wait until <Toast/> has mounted and subscribed to the
    // event bus — an `immediate` watch fires during setup and the toast is lost.
    onMounted(() =>
        nextTick(() => {
            showFlashToasts();
            showErrorToast();
        }),
    );

    // Validation errors arrive in-place on the same page (no remount), so watch them.
    watch(() => page.props.errors, showErrorToast, { deep: true });

    // Flash toasts can also arrive via a partial reload on a persistent layout
    // (e.g. settings update redirects back) — the toaster never remounts, so the
    // onMounted flush won't fire; watch the flash prop to catch those.
    watch(() => page.props.flash, showFlashToasts, { deep: true });

    // Non-Inertia responses (429 throttle, 419 expired) — show a toast instead of
    // Inertia's default raw-response modal.
    onMounted(() => {
        const off = router.on('httpException', (event) => {
            const status = event.detail.response?.status;

            if (status === 429 || status === 419) {
                event.preventDefault();
                toast.add({
                    severity: 'warn',
                    summary:
                        status === 429
                            ? t('common.too_many_requests')
                            : t('common.session_expired'),
                    life: 6000,
                });
            }
        });
        onUnmounted(off);
    });
}
