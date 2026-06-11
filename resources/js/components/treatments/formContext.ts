import { inject, provide } from 'vue';
import type { InjectionKey } from 'vue';
import type { TreatmentForm } from '@/types/treatment';

// The Process form is shared mutable state across the field partials (clinical fields, line editors,
// case link, payment, follow-up). Provide/inject (not props) so children bind v-model directly
// without tripping vue/no-mutating-props — an Inertia form is stable reactive state meant to be
// mutated anywhere. The page owns useForm/transform/submit.
const treatmentFormKey: InjectionKey<TreatmentForm> = Symbol('treatmentForm');

export function provideTreatmentForm(form: TreatmentForm): void {
    provide(treatmentFormKey, form);
}

export function useTreatmentForm(): TreatmentForm {
    const form = inject(treatmentFormKey);

    if (!form) {
        throw new Error(
            'useTreatmentForm() must be used inside a component that provides the treatment form.',
        );
    }

    return form;
}
