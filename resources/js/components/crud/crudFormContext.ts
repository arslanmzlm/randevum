import type { InertiaForm } from '@inertiajs/vue3';
import { inject, provide } from 'vue';
import type { ComputedRef, InjectionKey } from 'vue';
import type { CrudFormValues } from '@/types/crud';

// CrudDialog owns the Inertia form and injects it into the entity's field partial, which binds
// v-model straight to it — a `:form` prop would trip vue/no-mutating-props. The key carries the
// erased form shape because one dialog serves every entity; the accessor restores the real type.
const crudFormKey: InjectionKey<InertiaForm<CrudFormValues>> =
    Symbol('crudForm');

export function provideCrudForm(form: InertiaForm<CrudFormValues>): void {
    provide(crudFormKey, form);
}

export function useCrudForm<TForm extends object>(): InertiaForm<TForm> {
    const form = inject(crudFormKey);

    if (!form) {
        throw new Error(
            'useCrudForm() must be used inside a CrudDialog field partial.',
        );
    }

    return form as unknown as InertiaForm<TForm>;
}

// Page data a field partial needs but the form doesn't carry (clinic currency, category
// suggestions). Passed to the dialog by whichever page opens it, so the same partial works from a
// list screen and from a select's quick-add. Injected as a ref so a partial reload that refreshes
// the page prop reaches an already-mounted dialog.
const crudContextKey: InjectionKey<ComputedRef<object>> = Symbol('crudContext');

export function provideCrudContext(context: ComputedRef<object>): void {
    provide(crudContextKey, context);
}

export function useCrudContext<
    TContext extends object,
>(): ComputedRef<TContext> {
    const context = inject(crudContextKey);

    if (!context) {
        throw new Error(
            'useCrudContext() needs a `context` prop on the CrudDialog.',
        );
    }

    return context as ComputedRef<TContext>;
}

// Whether the open dialog is editing an existing row. A field partial needs it when a field only
// belongs to one mode (a product's opening stock is set on create, adjusted from the list after).
const crudIsEditKey: InjectionKey<ComputedRef<boolean>> = Symbol('crudIsEdit');

export function provideCrudIsEdit(isEdit: ComputedRef<boolean>): void {
    provide(crudIsEditKey, isEdit);
}

export function useCrudIsEdit(): ComputedRef<boolean> {
    const isEdit = inject(crudIsEditKey);

    if (!isEdit) {
        throw new Error(
            'useCrudIsEdit() must be used inside a CrudDialog field partial.',
        );
    }

    return isEdit;
}
