import { inject, provide } from 'vue';
import type { InjectionKey } from 'vue';
import type { ClinicForm } from '@/types/clinic';

// The clinic-settings form is shared mutable state across the field partials. Provide/inject
// (not props) so children can bind v-model directly without tripping vue/no-mutating-props — an
// Inertia form is a stable reactive object designed to be mutated anywhere, not a render-derived prop.
const clinicFormKey: InjectionKey<ClinicForm> = Symbol('clinicForm');

export function provideClinicForm(form: ClinicForm): void {
    provide(clinicFormKey, form);
}

export function useClinicForm(): ClinicForm {
    const form = inject(clinicFormKey);

    if (!form) {
        throw new Error(
            'useClinicForm() must be used inside a component that provides the clinic form.',
        );
    }

    return form;
}
