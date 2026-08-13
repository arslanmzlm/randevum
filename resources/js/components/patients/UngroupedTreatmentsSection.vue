<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconFolderOff, IconLink, IconPlus } from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import RecordName from '@/components/RecordName.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import { store as caseStore } from '@/routes/cases';
import { link as caseLinkTreatments } from '@/routes/cases/treatments';
import type { PatientCaseItem } from '@/types/case';
import type { PatientTreatmentHistoryItem } from '@/types/treatment';

const props = defineProps<{
    treatments: PatientTreatmentHistoryItem[];
    cases: PatientCaseItem[];
    patientId: number;
    ownDoctorId: number | null;
}>();

const { t } = useI18n();
const { can } = useCan();
const { formatDate } = useDateTime();
const { formatMoney } = useMoney();

// Ungrouped = completed treatment with no case yet (the only kind eligible for linking).
const ungroupedTreatments = computed<PatientTreatmentHistoryItem[]>(() =>
    props.treatments.filter(
        (item) => item.case_id === null && item.status === 'completed',
    ),
);

// Only Open cases accept retrospective treatment links (server-enforced).
const linkableOpenCases = computed<PatientCaseItem[]>(() =>
    props.cases.filter((c) => c.status === 'open'),
);

const canCreateCases = computed(() => can('cases.create'));
const canLinkCases = computed(() => can('cases.update'));
const canGroupTreatments = computed(
    () => canCreateCases.value || canLinkCases.value,
);

// A case is created/linked for the treatments' shared doctor; a non-viewAll user is
// limited to their own doctor profile (mirrors the server policy + service guard).
function canActOnDoctor(doctorId: number): boolean {
    return can('cases.viewAll') || doctorId === props.ownDoctorId;
}

function isActionable(item: PatientTreatmentHistoryItem): boolean {
    return canGroupTreatments.value && canActOnDoctor(item.doctor_id);
}

const selectedTreatmentIds = ref<number[]>([]);

// The selection must share one doctor (server requirement) — derive it from the first pick.
const selectedDoctorId = computed<number | null>(() => {
    const first = props.treatments.find(
        (item) => item.id === selectedTreatmentIds.value[0],
    );

    return first ? first.doctor_id : null;
});

function isSelectable(item: PatientTreatmentHistoryItem): boolean {
    return (
        selectedDoctorId.value === null ||
        item.doctor_id === selectedDoctorId.value
    );
}

const canActOnSelection = computed(
    () =>
        selectedDoctorId.value !== null &&
        canActOnDoctor(selectedDoctorId.value),
);

// New-case dialog.
const showCreateCaseDialog = ref(false);
const createCaseForm = useForm<{ title: string }>({ title: '' });

function openCreateCaseDialog(): void {
    createCaseForm.clearErrors();
    createCaseForm.title = '';
    showCreateCaseDialog.value = true;
}

function submitCreateCase(): void {
    createCaseForm
        .transform((data) => ({
            title: data.title,
            patient_id: props.patientId,
            doctor_id: selectedDoctorId.value,
            treatment_ids: selectedTreatmentIds.value,
        }))
        .post(caseStore().url);
}

// Link-to-open-case dialog.
const showLinkCaseDialog = ref(false);
const selectedOpenCaseId = ref<number | null>(null);
const linkCaseForm = useForm<{ treatment_ids: number[] }>({
    treatment_ids: [],
});

function openLinkCaseDialog(): void {
    linkCaseForm.clearErrors();
    selectedOpenCaseId.value = null;
    showLinkCaseDialog.value = true;
}

function submitLinkCase(): void {
    if (selectedOpenCaseId.value === null) {
        return;
    }

    linkCaseForm.treatment_ids = selectedTreatmentIds.value;
    linkCaseForm.post(caseLinkTreatments(selectedOpenCaseId.value).url);
}
</script>

<template>
    <SectionCard v-if="ungroupedTreatments.length">
        <template #title>
            <div class="flex flex-col gap-1">
                <div class="flex items-center gap-2">
                    <IconFolderOff class="size-5 text-surface-500" />
                    <h2 class="text-lg font-semibold text-surface-900">
                        {{ t('patient.sections.ungrouped') }}
                    </h2>
                </div>
                <p class="text-sm text-surface-500">
                    {{ t('patient.ungrouped.hint') }}
                </p>
            </div>
        </template>

        <div class="flex flex-col gap-4">
            <ul class="flex flex-col gap-2">
                <li
                    v-for="item in ungroupedTreatments"
                    :key="item.id"
                    class="flex items-center gap-3 rounded-xl border border-surface-200 p-3"
                >
                    <Checkbox
                        v-if="isActionable(item)"
                        v-model="selectedTreatmentIds"
                        :value="item.id"
                        :disabled="!isSelectable(item)"
                        :input-id="`ungrouped-${item.id}`"
                    />
                    <label
                        :for="`ungrouped-${item.id}`"
                        class="flex min-w-0 flex-1 flex-col gap-0.5"
                        :class="isActionable(item) ? 'cursor-pointer' : ''"
                    >
                        <span
                            class="truncate text-sm font-medium text-surface-900"
                        >
                            {{ item.title || t('treatment.untitled') }}
                        </span>
                        <span
                            class="flex min-w-0 items-center gap-1 text-xs text-surface-500"
                        >
                            <span class="shrink-0">
                                {{
                                    item.completed_at
                                        ? formatDate(item.completed_at)
                                        : t('treatment.in_progress')
                                }}
                                ·
                            </span>
                            <RecordName
                                :name="item.doctor_name"
                                :deleted="item.doctor_is_deleted"
                                text-class="truncate"
                            />
                        </span>
                    </label>
                    <span class="text-sm font-medium text-surface-700">
                        {{ formatMoney(item.total_amount) }}
                    </span>
                </li>
            </ul>

            <div
                v-if="selectedTreatmentIds.length && canActOnSelection"
                class="flex flex-wrap items-center justify-end gap-2 border-t border-surface-200 pt-4"
            >
                <span class="mr-auto text-sm text-surface-500">
                    {{
                        t('patient.ungrouped.selected', {
                            count: selectedTreatmentIds.length,
                        })
                    }}
                </span>
                <Button
                    v-if="canLinkCases && linkableOpenCases.length"
                    type="button"
                    severity="secondary"
                    outlined
                    :label="t('patient.ungrouped.link_existing')"
                    @click="openLinkCaseDialog"
                >
                    <template #icon>
                        <IconLink class="size-4" />
                    </template>
                </Button>
                <Button
                    v-if="canCreateCases"
                    type="button"
                    :label="t('patient.ungrouped.create_case')"
                    @click="openCreateCaseDialog"
                >
                    <template #icon>
                        <IconPlus class="size-4" />
                    </template>
                </Button>
            </div>
        </div>

        <Dialog
            v-model:visible="showCreateCaseDialog"
            modal
            :header="t('patient.ungrouped.create_case')"
            :style="{ width: '28rem' }"
        >
            <div class="flex flex-col gap-2">
                <label for="new-case-title" class="text-xs text-surface-500">
                    {{ t('case.fields.title') }}
                </label>
                <InputText
                    id="new-case-title"
                    v-model="createCaseForm.title"
                    fluid
                    :invalid="Boolean(createCaseForm.errors.title)"
                    :placeholder="t('patient.ungrouped.title_placeholder')"
                />
                <small v-if="createCaseForm.errors.title" class="text-red-500">
                    {{ createCaseForm.errors.title }}
                </small>
            </div>
            <template #footer>
                <Button
                    type="button"
                    severity="secondary"
                    outlined
                    :label="t('common.cancel')"
                    :disabled="createCaseForm.processing"
                    @click="showCreateCaseDialog = false"
                />
                <Button
                    type="button"
                    :label="t('patient.ungrouped.create_submit')"
                    :loading="createCaseForm.processing"
                    @click="submitCreateCase"
                />
            </template>
        </Dialog>

        <Dialog
            v-model:visible="showLinkCaseDialog"
            modal
            :header="t('patient.ungrouped.link_existing')"
            :style="{ width: '28rem' }"
        >
            <div class="flex flex-col gap-2">
                <label class="text-xs text-surface-500">
                    {{ t('patient.ungrouped.select_case') }}
                </label>
                <Select
                    v-model="selectedOpenCaseId"
                    :options="linkableOpenCases"
                    option-label="title"
                    option-value="id"
                    :placeholder="t('patient.ungrouped.select_case')"
                    fluid
                />
                <small
                    v-if="linkCaseForm.errors.treatment_ids"
                    class="text-red-500"
                >
                    {{ linkCaseForm.errors.treatment_ids }}
                </small>
            </div>
            <template #footer>
                <Button
                    type="button"
                    severity="secondary"
                    outlined
                    :label="t('common.cancel')"
                    :disabled="linkCaseForm.processing"
                    @click="showLinkCaseDialog = false"
                />
                <Button
                    type="button"
                    :label="t('patient.ungrouped.link_submit')"
                    :disabled="selectedOpenCaseId === null"
                    :loading="linkCaseForm.processing"
                    @click="submitLinkCase"
                />
            </template>
        </Dialog>
    </SectionCard>
</template>
