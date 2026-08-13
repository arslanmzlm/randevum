import { http, usePage } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { nextTick, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { PatientPickerForm } from '@/components/appointments/patientFormContext';
import { restore } from '@/routes/patients';
import type { PatientSearchResult, RestorablePatient } from '@/types/patient';

/**
 * Inline new-patient booking whose phone belongs to a soft-deleted patient: the server bounces
 * back with `flash.restorable_patient` instead of a validation error, so the booking forms can
 * offer a restore. Shared by the single and bulk create screens — both drive the same
 * PatientPicker over the same patient slice, only the surrounding form differs.
 *
 * Restoring never submits the appointment: it flips the form to the existing-patient side with
 * the restored patient picked, and the user sends the (irreversible) booking themselves.
 *
 * `onRestored` hands the patient to the picker — call its exposed `selectPatient`.
 */
export function useRestorablePatient(
    form: PatientPickerForm,
    onRestored: (patient: PatientSearchResult) => void,
): void {
    const page = usePage();
    const confirm = useConfirm();
    const toast = useToast();
    const { t } = useI18n();

    function prompt(restorable: RestorablePatient | null): void {
        if (!restorable) {
            return;
        }

        confirm.require({
            header: t('patient.restore.title'),
            message: t('patient.restore.message', {
                name: restorable.full_name,
            }),
            rejectProps: {
                label: t('patient.restore.reject'),
                severity: 'secondary',
                outlined: true,
            },
            acceptProps: { label: t('patient.restore.accept') },
            accept: () =>
                void restorePatient(restorable.id, restorable.full_name),
        });
    }

    // The bounce can either remount this page (flash already in the props at setup) or land on the
    // live one. An `immediate` watch would cover the first case too early — <ConfirmDialog/> is a
    // later sibling in the layout and only subscribes to the bus on mount — so flush it after mount.
    onMounted(() =>
        nextTick(() => prompt(page.props.flash.restorable_patient)),
    );
    watch(() => page.props.flash.restorable_patient, prompt);

    async function restorePatient(id: number, fullName: string): Promise<void> {
        // A router visit would follow the restore redirect to the patient detail page and throw
        // away the half-filled booking form, so this goes out as a standalone XHR (same client
        // Inertia uses, so the CSRF header is handled) and the page stays put.
        try {
            await http.getClient().request({
                method: 'post',
                url: restore(id).url,
                headers: { Accept: 'text/html, application/xhtml+xml' },
            });
        } catch {
            toast.add({
                severity: 'error',
                summary: t('appointment.restore_patient.failed'),
                life: 5000,
            });

            return;
        }

        // The phone the user typed is the one the restored record holds — that collision is what
        // triggered the prompt — so the picker can show the patient without a round-trip.
        const phone = form.new_patient.phone.trim();

        onRestored({ id, full_name: fullName, phone: phone || null });

        form.patient_id = id;
        form.new_patient.first_name = '';
        form.new_patient.last_name = '';
        form.new_patient.phone = '';
        form.new_patient.email = '';

        toast.add({
            severity: 'info',
            summary: t('appointment.restore_patient.restored'),
            detail: t('appointment.restore_patient.restored_hint'),
            life: 8000,
        });
    }
}
