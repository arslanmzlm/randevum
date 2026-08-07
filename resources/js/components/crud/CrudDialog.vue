<script
    setup
    lang="ts"
    generic="TForm extends object, TItem extends { id: number }"
>
import { useForm, useHttp } from '@inertiajs/vue3';
import type { InertiaForm } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    provideCrudContext,
    provideCrudForm,
    provideCrudIsEdit,
} from '@/components/crud/crudFormContext';
import type { CrudFormValues, CrudResource } from '@/types/crud';

// One create/edit dialog for every entity: it owns the form, seeds it on open, routes the submit
// to store or update, and closes on success. `item=null` → create, a row → edit.
//
// Two submit modes. 'inertia' (list screens) posts a normal Inertia visit: the server flashes a
// toast and re-renders the list. 'inline' (a quick-add inside another form) posts a standalone
// XHR so the page around it is never re-rendered, and hands the saved row back through `saved`.
const props = withDefaults(
    defineProps<{
        resource: CrudResource<TForm, TItem>;
        item: TItem | null;
        /** Extra page data the field partial needs (currency, suggestion lists). */
        context?: object;
        mode?: 'inertia' | 'inline';
    }>(),
    { context: () => ({}), mode: 'inertia' },
);

const emit = defineEmits<{ saved: [item: TItem] }>();

const visible = defineModel<boolean>('visible', { required: true });

const { t } = useI18n();
const toast = useToast();

// Erased to CrudFormValues: a single component can't carry every entity's form type through
// Inertia's form generics, and the field partials re-type it on the way out. The mode never
// changes for a mounted dialog, so picking the form kind once here is safe.
const inline = props.mode === 'inline';

const httpForm = inline
    ? useHttp<CrudFormValues, { data: TItem; message?: string }>(
          props.resource.empty() as unknown as CrudFormValues,
      )
    : null;

const form = (httpForm ??
    useForm<CrudFormValues>(
        props.resource.empty() as unknown as CrudFormValues,
    )) as unknown as InertiaForm<CrudFormValues>;

provideCrudForm(form);
provideCrudContext(computed(() => props.context));

// A resource without `update` is immutable (transactions), so an item passed in can only ever be
// displayed as a create — the frame is shared rather than forked into a second dialog component.
const isEdit = computed(
    () => props.item !== null && props.resource.update !== undefined,
);

provideCrudIsEdit(isEdit);

const header = computed(() =>
    t(`${props.resource.lang}.${isEdit.value ? 'edit_title' : 'create_title'}`),
);

const submitLabel = computed(() =>
    t(`${props.resource.lang}.${isEdit.value ? 'save' : 'create_submit'}`),
);

// Seed from the target each time the dialog opens — the same instance serves both modes, so a
// previous edit must not leak into the next create.
watch(visible, (open) => {
    if (!open) {
        return;
    }

    const toForm = props.resource.toForm;

    form.clearErrors();
    form.defaults(
        (props.item && toForm
            ? toForm(props.item)
            : props.resource.empty()) as unknown as CrudFormValues,
    );
    form.reset();
});

function submit(): void {
    const transform = props.resource.transform;

    if (transform) {
        form.transform(
            (data) => transform(data as unknown as TForm) as CrudFormValues,
        );
    }

    const update = props.resource.update;
    const url =
        props.item && update
            ? update(props.item.id).url
            : props.resource.store().url;

    if (httpForm) {
        // This path is a plain XHR, so nothing else surfaces its failures: a 422 lands on the
        // form fields, anything else has to be announced here or the dialog just sits there.
        const options = {
            onHttpException: (response: { status: number }): void => {
                toast.add({
                    severity: 'error',
                    summary:
                        response.status === 419
                            ? t('common.session_expired')
                            : t('common.form_error'),
                    life: 5000,
                });
            },
            onNetworkError: (): void => {
                toast.add({
                    severity: 'error',
                    summary: t('common.form_error'),
                    life: 5000,
                });
            },
        };

        const request = isEdit.value
            ? httpForm.put(url, options)
            : httpForm.post(url, options);

        // useHttp resolves only on a 2xx — a 422 rejects after filling the field errors and any
        // other status rejects through the handlers above — so this branch is the success case,
        // and the rejection it re-throws was already reported there.
        request
            .then((response) => {
                visible.value = false;

                // The server sends the same message its flash toast would carry.
                if (response.message) {
                    toast.add({
                        severity: 'success',
                        summary: response.message,
                        life: 4000,
                    });
                }

                emit('saved', response.data);
            })
            .catch(() => {});

        return;
    }

    form[isEdit.value ? 'put' : 'post'](url, {
        preserveScroll: true,
        onSuccess: (): void => {
            visible.value = false;
        },
    });
}
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        :draggable="false"
        :header="header"
        :class="resource.width ?? 'w-full max-w-md'"
    >
        <form novalidate class="flex flex-col gap-5" @submit.prevent="submit">
            <component :is="resource.fields" />

            <div class="mt-1 flex justify-end gap-2">
                <Button
                    type="button"
                    severity="secondary"
                    text
                    :label="t('common.cancel')"
                    @click="visible = false"
                />
                <Button
                    type="submit"
                    :label="submitLabel"
                    :loading="form.processing"
                />
            </div>
        </form>
    </Dialog>
</template>
