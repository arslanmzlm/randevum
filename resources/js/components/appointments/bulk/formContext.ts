import { inject, provide } from 'vue';
import type { InjectionKey } from 'vue';
import type { BulkAppointmentForm } from '@/types/appointment';

// The bulk-appointment form is shared mutable state across the field partials (patient picker,
// occurrence generator + rows). Provide/inject (not props) so children bind v-model directly
// without tripping vue/no-mutating-props — an Inertia form is stable reactive state, not a prop.
// A dedicated context because the shipped PatientPicker / FollowUpOccurrenceRow inject their own
// (create-appointment / treatment) forms; the bulk screen reuses their leaf components, not them.
const bulkAppointmentFormKey: InjectionKey<BulkAppointmentForm> = Symbol(
    'bulkAppointmentForm',
);

export function provideBulkAppointmentForm(form: BulkAppointmentForm): void {
    provide(bulkAppointmentFormKey, form);
}

export function useBulkAppointmentForm(): BulkAppointmentForm {
    const form = inject(bulkAppointmentFormKey);

    if (!form) {
        throw new Error(
            'useBulkAppointmentForm() must be used inside a component that provides the bulk appointment form.',
        );
    }

    return form;
}
