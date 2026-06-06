import { inject,  provide } from 'vue';
import type {InjectionKey} from 'vue';
import type { AppointmentForm } from '@/types/appointment';

// The create-appointment form is shared mutable state across the field partials. Provide/inject
// (not props) so children can bind v-model directly without tripping vue/no-mutating-props — an
// Inertia form is a stable reactive object designed to be mutated anywhere, not a render-derived prop.
const appointmentFormKey: InjectionKey<AppointmentForm> =
    Symbol('appointmentForm');

export function provideAppointmentForm(form: AppointmentForm): void {
    provide(appointmentFormKey, form);
}

export function useAppointmentForm(): AppointmentForm {
    const form = inject(appointmentFormKey);

    if (!form) {
        throw new Error(
            'useAppointmentForm() must be used inside a component that provides the appointment form.',
        );
    }

    return form;
}
