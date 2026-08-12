<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import PatientSearchSelect from '@/components/PatientSearchSelect.vue';
import RequiredMark from '@/components/RequiredMark.vue';
import { cases as casesForPatient, store } from '@/routes/follow-ups';
import type {
    FollowUpCaseOption,
    FollowUpFormData,
    FollowUpTypeOption,
} from '@/types/followUp';
import type { PatientSearchResult } from '@/types/patient';
import { toDateString } from '@/utils/datetime';

// Manual follow-up creation. Opened from the dashboard widget (patient picked here) or from a
// case panel (patient + case pre-bound, so neither is asked for).
const props = withDefaults(
    defineProps<{
        types: FollowUpTypeOption[];
        /** Pre-bound patient — hides the search field. */
        patient?: { id: number; full_name: string } | null;
        /** Pre-bound case — hides the case select. */
        caseId?: number | null;
    }>(),
    { patient: null, caseId: null },
);

const visible = defineModel<boolean>('visible', { required: true });

const { t } = useI18n();

const selectedPatient = ref<PatientSearchResult | null>(null);
const cases = ref<FollowUpCaseOption[]>([]);
const casesLoading = ref(false);

const form = useForm<FollowUpFormData>({
    patient_id: null,
    case_id: null,
    follow_up_type_id: null,
    due_date: null,
    note: '',
});

const showPatientField = computed(() => props.patient === null);
const showCaseField = computed(
    () => props.caseId === null && form.patient_id !== null,
);

watch(visible, (open) => {
    if (!open) {
        return;
    }

    form.clearErrors();
    form.reset();
    form.patient_id = props.patient?.id ?? null;
    form.case_id = props.caseId;
    selectedPatient.value = null;
    cases.value = [];
});

async function loadCases(patientId: number): Promise<void> {
    casesLoading.value = true;

    try {
        const response = await fetch(
            casesForPatient({ query: { patient_id: patientId } }).url,
            { headers: { Accept: 'application/json' } },
        );

        cases.value = response.ok
            ? ((await response.json()) as { data: FollowUpCaseOption[] }).data
            : [];
    } catch {
        cases.value = [];
    } finally {
        casesLoading.value = false;
    }
}

function onPatientSelect(patient: PatientSearchResult): void {
    form.patient_id = patient.id;
    form.case_id = null;
    cases.value = [];

    void loadCases(patient.id);
}

// Clearing the typeahead has to clear the dependent case list too, or a case from the previous
// patient would stay selected and fail server-side validation.
watch(selectedPatient, (patient) => {
    if (patient === null) {
        form.patient_id = null;
        form.case_id = null;
        cases.value = [];
    }
});

function submit(): void {
    form.transform((data) => ({
        patient_id: data.patient_id,
        case_id: data.case_id,
        follow_up_type_id: data.follow_up_type_id,
        due_date: data.due_date ? toDateString(data.due_date) : null,
        note: data.note.trim() || null,
    })).post(store().url, {
        preserveScroll: true,
        onSuccess: () => {
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
        :header="t('follow_up.actions.add')"
        class="w-full max-w-md"
    >
        <form novalidate class="flex flex-col gap-5" @submit.prevent="submit">
            <!-- Not a FormField: the typeahead always renders its own placeholder, which
                 would collide with FloatLabel's in-field label (PatientPicker precedent). -->
            <div v-if="showPatientField" class="form-group">
                <label class="mb-1 block text-sm text-muted">
                    {{ t('follow_up.fields.patient')
                    }}<RequiredMark />
                </label>
                <PatientSearchSelect
                    v-model="selectedPatient"
                    :invalid="Boolean(form.errors.patient_id)"
                    @select="onPatientSelect"
                />
                <small
                    v-if="form.errors.patient_id"
                    class="text-xs text-red-500"
                >
                    {{ form.errors.patient_id }}
                </small>
            </div>
            <div
                v-else
                class="rounded-lg border border-surface-200 bg-surface-50 px-3 py-2"
            >
                <span class="text-xs text-surface-500">
                    {{ t('follow_up.fields.patient') }}
                </span>
                <p class="text-sm font-medium text-surface-900">
                    {{ patient?.full_name }}
                </p>
            </div>

            <FormField
                v-if="showCaseField"
                :label="t('follow_up.fields.case')"
                :error="form.errors.case_id"
                :hint="t('follow_up.hints.case')"
            >
                <Select
                    v-model="form.case_id"
                    :options="cases"
                    option-label="title"
                    option-value="id"
                    :loading="casesLoading"
                    :empty-message="t('follow_up.no_cases')"
                    show-clear
                    fluid
                />
            </FormField>

            <FormField
                :label="t('follow_up.fields.type')"
                :error="form.errors.follow_up_type_id"
                required
            >
                <Select
                    v-model="form.follow_up_type_id"
                    :options="types"
                    option-label="name"
                    option-value="id"
                    :empty-message="t('follow_up.no_types')"
                    fluid
                />
            </FormField>

            <FormField
                :label="t('follow_up.fields.due_date')"
                :error="form.errors.due_date"
                required
            >
                <DatePicker
                    v-model="form.due_date"
                    date-format="dd.mm.yy"
                    show-button-bar
                    fluid
                />
            </FormField>

            <FormField
                :label="t('follow_up.fields.note')"
                :error="form.errors.note"
            >
                <Textarea v-model="form.note" rows="3" auto-resize fluid />
            </FormField>

            <div class="mt-1 flex justify-end gap-2">
                <Button
                    type="button"
                    severity="secondary"
                    text
                    :label="t('common.cancel')"
                    :disabled="form.processing"
                    @click="visible = false"
                />
                <Button
                    type="submit"
                    :label="t('follow_up.actions.create')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </Dialog>
</template>
