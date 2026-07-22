import { inject, provide } from 'vue';
import type { InjectionKey } from 'vue';
import type { AnamnesisForm } from '@/types/anamnesis';

// The anamnesis form is shared mutable state between the section and its vertical field partial.
// Provide/inject (not props) so the field partial binds v-model directly without tripping
// vue/no-mutating-props — an Inertia form is stable reactive state. The section owns useForm/submit.
const anamnesisFormKey: InjectionKey<AnamnesisForm> = Symbol('anamnesisForm');

export function provideAnamnesisForm(form: AnamnesisForm): void {
    provide(anamnesisFormKey, form);
}

export function useAnamnesisForm(): AnamnesisForm {
    const form = inject(anamnesisFormKey);

    if (!form) {
        throw new Error(
            'useAnamnesisForm() must be used inside a component that provides the anamnesis form.',
        );
    }

    return form;
}
