<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconCalendarEvent,
    IconLink,
    IconNotes,
    IconPencil,
    IconStethoscope,
} from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import CaseStatusTag from '@/components/CaseStatusTag.vue';
import EmptyState from '@/components/EmptyState.vue';
import EntityLinkRow from '@/components/EntityLinkRow.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionCard from '@/components/SectionCard.vue';
import TreatmentStatusTag from '@/components/TreatmentStatusTag.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import AppLayout from '@/layouts/AppLayout.vue';
import { index } from '@/routes/cases';
import { update as updateFollowUp } from '@/routes/cases/follow-up';
import { update as updateNotes } from '@/routes/cases/notes';
import { update as updateStatus } from '@/routes/cases/status';
import { update as updateTitle } from '@/routes/cases/title';
import { link as linkTreatments } from '@/routes/cases/treatments';
import { show as patientShow } from '@/routes/patients';
import { show as treatmentShow } from '@/routes/treatments';
import type {
    CaseFollowUpFormData,
    CaseNotesFormData,
    CaseShowProps,
    CaseTitleFormData,
} from '@/types/case';
import type { CaseStatus } from '@/types/enums';
import { parseDateString, toDateString } from '@/utils/datetime';

defineOptions({ layout: AppLayout });

const props = defineProps<CaseShowProps>();

// `case` is a JS reserved word, so the `case` prop cannot be referenced bare in the template.
const caseRecord = computed(() => props.case);

const { t } = useI18n();
const confirm = useConfirm();
const { can } = useCan();
const { formatDate, formatDateOnly } = useDateTime();
const { formatMoney } = useMoney();

// Mirror the server policy: mutate any clinic case with viewAll, else only own.
const canManage = computed(
    () =>
        can('cases.update') &&
        (can('cases.viewAll') || props.case.doctor.id === props.ownDoctorId),
);

const canLink = computed(() => canManage.value && props.case.status === 'open');

const ACTION_KEY: Record<CaseStatus, string> = {
    open: 'reopen',
    suspended: 'suspend',
    follow_up: 'follow_up',
    closed: 'close',
};

const ACTION_SEVERITY: Record<CaseStatus, string> = {
    open: 'success',
    suspended: 'warn',
    follow_up: 'info',
    closed: 'danger',
};

function transitionLabel(target: CaseStatus): string {
    return t(`case.actions.${ACTION_KEY[target]}`);
}

function submitStatus(target: CaseStatus): void {
    router.patch(
        updateStatus(props.case.id).url,
        { status: target },
        { preserveScroll: true },
    );
}

function onTransition(target: CaseStatus): void {
    // → Follow-up requires a date, so collect it in a dedicated dialog instead of a bare confirm.
    if (target === 'follow_up') {
        followUpStatusForm.clearErrors();
        followUpStatusForm.follow_up_date = props.case.follow_up_date
            ? parseDateString(props.case.follow_up_date)
            : null;
        followUpStatusForm.follow_up_note = props.case.follow_up_note ?? '';
        showFollowUpStatusDialog.value = true;

        return;
    }

    confirm.require({
        header: t('case.confirm.status_title'),
        message: t(`case.confirm.status_${target}`),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: {
            label: transitionLabel(target),
            severity: ACTION_SEVERITY[target],
        },
        accept: () => submitStatus(target),
    });
}

// → Follow-up status transition (date required).
const showFollowUpStatusDialog = ref(false);
const followUpStatusForm = useForm<CaseFollowUpFormData>({
    follow_up_date: null,
    follow_up_note: '',
});

function submitFollowUpStatus(): void {
    followUpStatusForm
        .transform((data) => ({
            status: 'follow_up',
            follow_up_date: data.follow_up_date
                ? toDateString(data.follow_up_date)
                : null,
            follow_up_note: data.follow_up_note || null,
        }))
        .patch(updateStatus(props.case.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                showFollowUpStatusDialog.value = false;
            },
        });
}

// Inline title editor (guarded by canEditTitle = within the edit window).
const editingTitle = ref(false);
const titleForm = useForm<CaseTitleFormData>({ title: props.case.title });

function startEditTitle(): void {
    titleForm.clearErrors();
    titleForm.title = props.case.title;
    editingTitle.value = true;
}

function saveTitle(): void {
    titleForm.patch(updateTitle(props.case.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            editingTitle.value = false;
        },
    });
}

// Inline notes editor.
const editingNotes = ref(false);
const notesForm = useForm<CaseNotesFormData>({
    notes: props.case.notes ?? '',
});

function startEditNotes(): void {
    notesForm.clearErrors();
    notesForm.notes = props.case.notes ?? '';
    editingNotes.value = true;
}

function saveNotes(): void {
    notesForm.patch(updateNotes(props.case.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            editingNotes.value = false;
        },
    });
}

// Follow-up panel (independent of status — a closed case may still carry a reminder).
const editingFollowUp = ref(false);
const followUpForm = useForm<CaseFollowUpFormData>({
    follow_up_date: props.case.follow_up_date
        ? parseDateString(props.case.follow_up_date)
        : null,
    follow_up_note: props.case.follow_up_note ?? '',
});

function startEditFollowUp(): void {
    followUpForm.clearErrors();
    followUpForm.follow_up_date = props.case.follow_up_date
        ? parseDateString(props.case.follow_up_date)
        : null;
    followUpForm.follow_up_note = props.case.follow_up_note ?? '';
    editingFollowUp.value = true;
}

function saveFollowUp(): void {
    followUpForm
        .transform((data) => ({
            follow_up_date: data.follow_up_date
                ? toDateString(data.follow_up_date)
                : null,
            follow_up_note: data.follow_up_note || null,
        }))
        .patch(updateFollowUp(props.case.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                editingFollowUp.value = false;
            },
        });
}

// Link ungrouped treatments dialog.
const showLinkDialog = ref(false);
const linkForm = useForm<{ treatment_ids: number[] }>({ treatment_ids: [] });

function openLinkDialog(): void {
    linkForm.clearErrors();
    linkForm.treatment_ids = [];
    showLinkDialog.value = true;
}

function submitLink(): void {
    linkForm.post(linkTreatments(props.case.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            showLinkDialog.value = false;
        },
    });
}

const metaRows = computed(() => {
    const rows = [
        {
            label: t('case.fields.opened_at'),
            value: formatDate(props.case.opened_at),
        },
    ];

    if (props.case.suspended_at) {
        rows.push({
            label: t('case.fields.suspended_at'),
            value: formatDate(props.case.suspended_at),
        });
    }

    if (props.case.closed_at) {
        rows.push({
            label: t('case.fields.closed_at'),
            value: formatDate(props.case.closed_at),
        });
    }

    return rows;
});
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="caseRecord.title" />

        <PageHeader
            :title="caseRecord.title"
            :breadcrumbs="[
                { label: t('nav.cases'), href: index().url },
                { label: caseRecord.title },
            ]"
        >
            <template #actions>
                <ButtonLink
                    :href="index().url"
                    :label="t('case.back')"
                    severity="secondary"
                    outlined
                >
                    <template #icon>
                        <IconArrowLeft />
                    </template>
                </ButtonLink>
                <template v-if="canManage">
                    <Button
                        v-for="target in allowedTransitions"
                        :key="target"
                        type="button"
                        :severity="ACTION_SEVERITY[target]"
                        :outlined="target !== 'closed'"
                        :label="transitionLabel(target)"
                        @click="onTransition(target)"
                    />
                </template>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <SectionCard
                    :icon="IconStethoscope"
                    :title="t('case.sections.info')"
                >
                    <template #actions>
                        <CaseStatusTag :status="caseRecord.status" />
                    </template>

                    <div class="flex flex-col gap-5">
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-surface-500">
                                    {{ t('case.fields.title') }}
                                </span>
                                <Button
                                    v-if="
                                        canManage &&
                                        canEditTitle &&
                                        !editingTitle
                                    "
                                    type="button"
                                    severity="secondary"
                                    text
                                    size="small"
                                    class="ml-auto"
                                    :label="t('case.edit_title')"
                                    @click="startEditTitle"
                                >
                                    <template #icon>
                                        <IconPencil class="size-4" />
                                    </template>
                                </Button>
                            </div>

                            <template v-if="editingTitle">
                                <InputText
                                    v-model="titleForm.title"
                                    fluid
                                    :invalid="Boolean(titleForm.errors.title)"
                                    :aria-label="t('case.fields.title')"
                                />
                                <small
                                    v-if="titleForm.errors.title"
                                    class="text-red-500"
                                >
                                    {{ titleForm.errors.title }}
                                </small>
                                <div class="mt-1 flex justify-end gap-2">
                                    <Button
                                        type="button"
                                        severity="secondary"
                                        outlined
                                        size="small"
                                        :label="t('common.cancel')"
                                        :disabled="titleForm.processing"
                                        @click="editingTitle = false"
                                    />
                                    <Button
                                        type="button"
                                        size="small"
                                        :label="t('case.save')"
                                        :loading="titleForm.processing"
                                        @click="saveTitle"
                                    />
                                </div>
                            </template>
                            <p
                                v-else
                                class="text-sm font-medium text-surface-900"
                            >
                                {{ caseRecord.title }}
                            </p>
                        </div>

                        <dl
                            class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2"
                        >
                            <div class="flex flex-col gap-0.5">
                                <dt class="text-xs text-surface-500">
                                    {{ t('case.fields.patient') }}
                                </dt>
                                <dd>
                                    <Link
                                        :href="
                                            patientShow(caseRecord.patient.id)
                                                .url
                                        "
                                        class="text-sm font-medium text-primary-600 transition-colors hover:text-primary-700"
                                    >
                                        {{ caseRecord.patient.full_name }}
                                    </Link>
                                </dd>
                            </div>
                            <div class="flex flex-col gap-0.5">
                                <dt class="text-xs text-surface-500">
                                    {{ t('case.fields.doctor') }}
                                </dt>
                                <dd class="text-sm text-surface-900">
                                    {{ caseRecord.doctor.display_name }}
                                </dd>
                            </div>
                            <div
                                v-for="(row, idx) in metaRows"
                                :key="idx"
                                class="flex flex-col gap-0.5"
                            >
                                <dt class="text-xs text-surface-500">
                                    {{ row.label }}
                                </dt>
                                <dd class="text-sm text-surface-900">
                                    {{ row.value }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </SectionCard>

                <SectionCard
                    :icon="IconStethoscope"
                    :title="t('case.sections.treatments')"
                >
                    <template #actions>
                        <Button
                            v-if="canLink && ungroupedTreatments.length"
                            type="button"
                            severity="secondary"
                            outlined
                            size="small"
                            :label="t('case.link.add')"
                            @click="openLinkDialog"
                        >
                            <template #icon>
                                <IconLink class="size-4" />
                            </template>
                        </Button>
                    </template>

                    <ul
                        v-if="caseRecord.treatments.length"
                        class="flex flex-col gap-2"
                    >
                        <li
                            v-for="item in caseRecord.treatments"
                            :key="item.id"
                        >
                            <EntityLinkRow
                                :href="treatmentShow(item.id).url"
                                :title="item.title || t('treatment.untitled')"
                                :meta="
                                    item.completed_at
                                        ? formatDate(item.completed_at)
                                        : t('treatment.in_progress')
                                "
                            >
                                <template #status>
                                    <TreatmentStatusTag :status="item.status" />
                                </template>
                                <template #trailing>
                                    <span
                                        class="text-sm font-medium text-surface-700"
                                    >
                                        {{ formatMoney(item.total_amount) }}
                                    </span>
                                </template>
                            </EntityLinkRow>
                        </li>
                    </ul>

                    <EmptyState
                        v-else
                        variant="dashed"
                        :icon="IconStethoscope"
                        :message="t('case.no_treatments')"
                    />
                </SectionCard>
            </div>

            <div class="flex flex-col gap-6">
                <section
                    class="flex flex-col gap-3 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                >
                    <header class="flex items-center gap-2">
                        <IconNotes class="size-5 text-surface-500" />
                        <h3 class="text-sm font-semibold text-surface-900">
                            {{ t('case.sections.notes') }}
                        </h3>
                        <Button
                            v-if="canManage && !editingNotes"
                            type="button"
                            severity="secondary"
                            outlined
                            size="small"
                            class="ml-auto"
                            :label="t('case.notes_edit')"
                            @click="startEditNotes"
                        >
                            <template #icon>
                                <IconPencil class="size-4" />
                            </template>
                        </Button>
                    </header>

                    <template v-if="editingNotes">
                        <Textarea
                            v-model="notesForm.notes"
                            rows="5"
                            auto-resize
                            fluid
                            :invalid="Boolean(notesForm.errors.notes)"
                            :placeholder="t('case.notes_placeholder')"
                            :aria-label="t('case.sections.notes')"
                        />
                        <small
                            v-if="notesForm.errors.notes"
                            class="text-red-500"
                        >
                            {{ notesForm.errors.notes }}
                        </small>
                        <div class="flex justify-end gap-2">
                            <Button
                                type="button"
                                severity="secondary"
                                outlined
                                size="small"
                                :label="t('common.cancel')"
                                :disabled="notesForm.processing"
                                @click="editingNotes = false"
                            />
                            <Button
                                type="button"
                                size="small"
                                :label="t('case.save')"
                                :loading="notesForm.processing"
                                @click="saveNotes"
                            />
                        </div>
                    </template>
                    <template v-else>
                        <p
                            v-if="caseRecord.notes"
                            class="text-sm whitespace-pre-line text-surface-700"
                        >
                            {{ caseRecord.notes }}
                        </p>
                        <p v-else class="text-sm text-surface-400">
                            {{ t('case.no_notes') }}
                        </p>
                    </template>
                </section>

                <section
                    class="flex flex-col gap-3 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                >
                    <header class="flex items-center gap-2">
                        <IconCalendarEvent class="size-5 text-surface-500" />
                        <h3 class="text-sm font-semibold text-surface-900">
                            {{ t('case.sections.follow_up') }}
                        </h3>
                        <Button
                            v-if="canManage && !editingFollowUp"
                            type="button"
                            severity="secondary"
                            outlined
                            size="small"
                            class="ml-auto"
                            :label="t('case.follow_up_edit')"
                            @click="startEditFollowUp"
                        >
                            <template #icon>
                                <IconPencil class="size-4" />
                            </template>
                        </Button>
                    </header>

                    <template v-if="editingFollowUp">
                        <DatePicker
                            v-model="followUpForm.follow_up_date"
                            date-format="dd.mm.yy"
                            show-button-bar
                            fluid
                            :invalid="
                                Boolean(followUpForm.errors.follow_up_date)
                            "
                            :placeholder="t('case.fields.follow_up_date')"
                            :aria-label="t('case.fields.follow_up_date')"
                        />
                        <small
                            v-if="followUpForm.errors.follow_up_date"
                            class="text-red-500"
                        >
                            {{ followUpForm.errors.follow_up_date }}
                        </small>
                        <Textarea
                            v-model="followUpForm.follow_up_note"
                            rows="3"
                            auto-resize
                            fluid
                            :invalid="
                                Boolean(followUpForm.errors.follow_up_note)
                            "
                            :placeholder="t('case.fields.follow_up_note')"
                            :aria-label="t('case.fields.follow_up_note')"
                        />
                        <div class="flex justify-end gap-2">
                            <Button
                                type="button"
                                severity="secondary"
                                outlined
                                size="small"
                                :label="t('common.cancel')"
                                :disabled="followUpForm.processing"
                                @click="editingFollowUp = false"
                            />
                            <Button
                                type="button"
                                size="small"
                                :label="t('case.save')"
                                :loading="followUpForm.processing"
                                @click="saveFollowUp"
                            />
                        </div>
                    </template>
                    <template v-else>
                        <div
                            v-if="caseRecord.follow_up_date"
                            class="flex flex-col gap-1"
                        >
                            <span class="text-sm font-medium text-surface-900">
                                {{ formatDateOnly(caseRecord.follow_up_date) }}
                            </span>
                            <p
                                v-if="caseRecord.follow_up_note"
                                class="text-sm whitespace-pre-line text-surface-600"
                            >
                                {{ caseRecord.follow_up_note }}
                            </p>
                        </div>
                        <p v-else class="text-sm text-surface-400">
                            {{ t('case.no_follow_up') }}
                        </p>
                    </template>
                </section>
            </div>
        </div>

        <Dialog
            v-model:visible="showFollowUpStatusDialog"
            modal
            :header="t('case.actions.follow_up')"
            :style="{ width: '28rem' }"
        >
            <div class="flex flex-col gap-4">
                <p class="text-sm text-surface-600">
                    {{ t('case.follow_up_status_hint') }}
                </p>
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-surface-500">
                        {{ t('case.fields.follow_up_date') }}
                    </label>
                    <DatePicker
                        v-model="followUpStatusForm.follow_up_date"
                        date-format="dd.mm.yy"
                        fluid
                        :invalid="
                            Boolean(followUpStatusForm.errors.follow_up_date)
                        "
                        :aria-label="t('case.fields.follow_up_date')"
                    />
                    <small
                        v-if="followUpStatusForm.errors.follow_up_date"
                        class="text-red-500"
                    >
                        {{ followUpStatusForm.errors.follow_up_date }}
                    </small>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-surface-500">
                        {{ t('case.fields.follow_up_note') }}
                    </label>
                    <Textarea
                        v-model="followUpStatusForm.follow_up_note"
                        rows="3"
                        auto-resize
                        fluid
                        :aria-label="t('case.fields.follow_up_note')"
                    />
                </div>
            </div>
            <template #footer>
                <Button
                    type="button"
                    severity="secondary"
                    outlined
                    :label="t('common.cancel')"
                    :disabled="followUpStatusForm.processing"
                    @click="showFollowUpStatusDialog = false"
                />
                <Button
                    type="button"
                    severity="info"
                    :label="t('case.actions.follow_up')"
                    :loading="followUpStatusForm.processing"
                    @click="submitFollowUpStatus"
                />
            </template>
        </Dialog>

        <Dialog
            v-model:visible="showLinkDialog"
            modal
            :header="t('case.link.title')"
            :style="{ width: '32rem' }"
        >
            <div class="flex flex-col gap-3">
                <p class="text-sm text-surface-600">
                    {{ t('case.link.hint') }}
                </p>
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="item in ungroupedTreatments"
                        :key="item.id"
                        class="flex items-center gap-3 rounded-lg border border-surface-200 p-3"
                    >
                        <Checkbox
                            v-model="linkForm.treatment_ids"
                            :value="item.id"
                            :input-id="`link-treatment-${item.id}`"
                        />
                        <label
                            :for="`link-treatment-${item.id}`"
                            class="flex min-w-0 flex-1 cursor-pointer flex-col gap-0.5"
                        >
                            <span
                                class="truncate text-sm font-medium text-surface-900"
                            >
                                {{ item.title || t('treatment.untitled') }}
                            </span>
                            <span class="text-xs text-surface-500">
                                {{
                                    item.completed_at
                                        ? formatDate(item.completed_at)
                                        : t('treatment.in_progress')
                                }}
                            </span>
                        </label>
                        <span class="text-sm font-medium text-surface-700">
                            {{ formatMoney(item.total_amount) }}
                        </span>
                    </li>
                </ul>
                <small
                    v-if="linkForm.errors.treatment_ids"
                    class="text-red-500"
                >
                    {{ linkForm.errors.treatment_ids }}
                </small>
            </div>
            <template #footer>
                <Button
                    type="button"
                    severity="secondary"
                    outlined
                    :label="t('common.cancel')"
                    :disabled="linkForm.processing"
                    @click="showLinkDialog = false"
                />
                <Button
                    type="button"
                    :label="t('case.link.submit')"
                    :disabled="linkForm.treatment_ids.length === 0"
                    :loading="linkForm.processing"
                    @click="submitLink"
                />
            </template>
        </Dialog>
    </div>
</template>
