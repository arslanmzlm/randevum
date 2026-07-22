import { inject, provide } from 'vue';
import type { InjectionKey } from 'vue';
import type { NewPatientFormData } from '@/types/appointment';

/**
 * The slice of an appointment form the shared PatientPicker reads and mutates: the picked patient
 * id, the new-patient fields, and the error bag. Both the create and bulk pages own a full Inertia
 * form (create / bulk) that structurally satisfies this, and provide it under this narrow key so
 * one PatientPicker serves both without either page's fuller form leaking into the component.
 */
export interface PatientPickerForm {
    patient_id: number | null;
    new_patient: NewPatientFormData;
    readonly errors: Partial<Record<string, string>>;
}

const patientFormKey: InjectionKey<PatientPickerForm> =
    Symbol('patientPickerForm');

export function providePatientForm(form: PatientPickerForm): void {
    provide(patientFormKey, form);
}

export function usePatientForm(): PatientPickerForm {
    const form = inject(patientFormKey);

    if (!form) {
        throw new Error(
            'usePatientForm() must be used inside a component that provides the patient form.',
        );
    }

    return form;
}
