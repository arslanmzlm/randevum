import { router } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';
import { onMounted, ref, shallowRef, watch } from 'vue';
import { useI18n } from 'vue-i18n';

type RouteUrl = { url: string };

type CrudDialogOptions<TItem> = {
    /** Lang key prefix — shares the resource's, e.g. `<lang>.remove_confirm`. */
    lang: string;
    destroy?: (id: number) => RouteUrl;
    /** Server-resolved row behind a `?edit=<id>` deep link, so it opens off-page too. */
    editing?: () => TItem | null;
    /** Gate for `?new=1`: without create rights the link must not open an unsubmittable form. */
    canCreate?: () => boolean;
};

/**
 * List-page side of the CRUD frame: dialog open state, the row being edited, the delete
 * confirmation, and the address-bar mirror (`?new=1` / `?edit=<id>`).
 */
export function useCrudDialog<TItem extends { id: number }>(
    options: CrudDialogOptions<TItem>,
) {
    const { t } = useI18n();
    const confirm = useConfirm();

    const visible = ref(false);
    const item = shallowRef<TItem | null>(null);

    function openCreate(): void {
        item.value = null;
        visible.value = true;
    }

    function openEdit(row: TItem): void {
        item.value = row;
        visible.value = true;
    }

    // Mirror the open dialog in the URL so a refresh or a shared link lands back on it.
    // replaceState, not push: the dialog is a state of this page, not a navigation step, and
    // pushing raw history entries would desync Inertia's own history handling. The existing state
    // is carried over — Inertia keeps its page snapshot and scroll positions there, and replacing
    // it with a fresh object turns a later back/forward into a full reload.
    watch([visible, item], () => {
        const url = new URL(window.location.href);

        url.searchParams.delete('new');
        url.searchParams.delete('edit');

        if (visible.value) {
            if (item.value) {
                url.searchParams.set('edit', String(item.value.id));
            } else {
                url.searchParams.set('new', '1');
            }
        }

        window.history.replaceState(window.history.state, '', url);
    });

    onMounted(() => {
        const editing = options.editing?.() ?? null;

        if (editing) {
            openEdit(editing);

            return;
        }

        const wantsCreate = new URLSearchParams(window.location.search).has(
            'new',
        );

        if (wantsCreate && (options.canCreate?.() ?? true)) {
            openCreate();
        }
    });

    // `name` is passed in rather than read off the row: not every entity has one (an expense is
    // named by its category and amount), and the confirmation has to say what is being removed.
    function confirmDelete(row: TItem, name: string): void {
        if (!options.destroy) {
            return;
        }

        const destroy = options.destroy;

        confirm.require({
            header: t('common.confirm_title'),
            message: t(`${options.lang}.remove_confirm`, { name }),
            rejectProps: {
                label: t('common.cancel'),
                severity: 'secondary',
                outlined: true,
            },
            acceptProps: { label: t('common.delete'), severity: 'danger' },
            accept: () =>
                router.delete(destroy(row.id).url, { preserveScroll: true }),
        });
    }

    return { visible, item, openCreate, openEdit, confirmDelete };
}
