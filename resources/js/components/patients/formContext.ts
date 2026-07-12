import { inject, provide } from 'vue';
import type { InjectionKey } from 'vue';
import type { PatientForm } from '@/types/patient';

// The patient create/edit form is shared mutable state across the field partials. Provide/inject
// (not props) so children can bind v-model directly without tripping vue/no-mutating-props — an
// Inertia form is a stable reactive object designed to be mutated anywhere, not a render-derived prop.
// Each page owns its own useForm (distinct defaults/transform/submit); the partials only need the shape.
const patientFormKey: InjectionKey<PatientForm> = Symbol('patientForm');

export function providePatientForm(form: PatientForm): void {
    provide(patientFormKey, form);
}

export function usePatientForm(): PatientForm {
    const form = inject(patientFormKey);

    if (!form) {
        throw new Error(
            'usePatientForm() must be used inside a component that provides the patient form.',
        );
    }

    return form;
}
