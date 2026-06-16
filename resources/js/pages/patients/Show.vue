<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconCalendarEvent,
    IconCash,
    IconChevronRight,
    IconFolderOff,
    IconFolders,
    IconLink,
    IconMail,
    IconMessage,
    IconNotes,
    IconPencil,
    IconPhone,
    IconPlus,
    IconTrash,
    IconUser,
} from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppointmentStatusTag from '@/components/AppointmentStatusTag.vue';
import ButtonLink from '@/components/ButtonLink.vue';
import CaseStatusTag from '@/components/CaseStatusTag.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordPaymentDialog from '@/components/payments/RecordPaymentDialog.vue';
import TransactionList from '@/components/payments/TransactionList.vue';
import SmsStatusTag from '@/components/SmsStatusTag.vue';
import TreatmentStatusTag from '@/components/TreatmentStatusTag.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import AppLayout from '@/layouts/AppLayout.vue';
import { store as caseStore, show as caseShow } from '@/routes/cases';
import { link as caseLinkTreatments } from '@/routes/cases/treatments';
import { destroy, edit, index } from '@/routes/patients';
import { update as updateNotes } from '@/routes/patients/notes';
import { show as treatmentShow } from '@/routes/treatments';
import type { PatientCaseItem } from '@/types/case';
import type {
    PatientAppointmentItem,
    PatientNotesFormData,
    PatientShowProps,
} from '@/types/patient';
import type { PatientTreatmentHistoryItem } from '@/types/treatment';

defineOptions({ layout: AppLayout });

const props = defineProps<PatientShowProps>();

const { t } = useI18n();
const confirm = useConfirm();
const { can } = useCan();
const { formatDate, formatDateOnly, formatDateTime, formatRange, isPast } =
    useDateTime();
const { formatMoney } = useMoney();
const canManage = computed(() => can('patients.update'));
const canEditNotes = computed(() => can('patients.note.update'));
const canRecordPayment = computed(() => can('transactions.create'));
const canViewBalance = computed(() => can('transactions.viewAny'));

// A negative remaining means the patient overpaid — the clinic owes them (a credit).
const remainingIsCredit = computed(
    () => props.balance != null && Number(props.balance.remaining) < 0,
);

const showPaymentDialog = ref(false);

const birthDateLabel = computed(() => {
    if (!props.patient.birth_date) {
        return t('patient.not_specified');
    }

    const formatted = formatDateOnly(props.patient.birth_date);

    return props.patient.age !== null
        ? `${formatted} · ${t('patient.age_value', { age: props.patient.age })}`
        : formatted;
});

const genderLabel = computed(() =>
    props.patient.gender
        ? t(`patient.gender.${props.patient.gender}`)
        : t('patient.not_specified'),
);

const contactRows = computed(() => [
    {
        icon: IconPhone,
        label: t('patient.fields.phone'),
        value: props.patient.phone,
    },
    {
        icon: IconPhone,
        label: t('patient.fields.contact_phone'),
        value: props.patient.contact_phone,
    },
    {
        icon: IconMail,
        label: t('patient.fields.email'),
        value: props.patient.email,
    },
    {
        icon: IconCalendarEvent,
        label: t('patient.fields.birth_date'),
        value: birthDateLabel.value,
    },
    {
        icon: IconUser,
        label: t('patient.fields.gender'),
        value: genderLabel.value,
    },
]);

const editingNotes = ref(false);

const notesForm = useForm<PatientNotesFormData>({
    notes: props.patient.notes ?? '',
});

function startEditNotes(): void {
    notesForm.clearErrors();
    notesForm.notes = props.patient.notes ?? '';
    editingNotes.value = true;
}

function cancelEditNotes(): void {
    notesForm.clearErrors();
    notesForm.notes = props.patient.notes ?? '';
    editingNotes.value = false;
}

function saveNotes(): void {
    notesForm.patch(updateNotes(props.patient.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            editingNotes.value = false;
        },
    });
}

function removePatient(): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('patient.remove_confirm', { name: props.patient.full_name }),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('common.delete'), severity: 'danger' },
        accept: () => router.delete(destroy(props.patient.id).url),
    });
}

// Appointments — upcoming vs past split client-side; an in-progress one counts as
// upcoming until it ends. Server sends newest-first, so upcoming reverses to soonest-first.
const canViewAppointments = computed(() => can('appointments.viewAny'));
const upcomingAppointments = computed<PatientAppointmentItem[]>(() =>
    props.appointments.filter((a) => !isPast(a.ends_at)).reverse(),
);
const pastAppointments = computed<PatientAppointmentItem[]>(() =>
    props.appointments.filter((a) => isPast(a.ends_at)),
);

// Cases — open (active) vs closed split client-side from the `cases` prop.
const openCases = computed<PatientCaseItem[]>(() =>
    props.cases.filter((c) => c.status !== 'closed'),
);
const closedCases = computed<PatientCaseItem[]>(() =>
    props.cases.filter((c) => c.status === 'closed'),
);
// Only Open cases accept retrospective treatment links (server-enforced).
const linkableOpenCases = computed<PatientCaseItem[]>(() =>
    props.cases.filter((c) => c.status === 'open'),
);

// Ungrouped = completed treatment with no case yet (the only kind eligible for linking).
const ungroupedTreatments = computed<PatientTreatmentHistoryItem[]>(() =>
    props.treatments.filter(
        (item) => item.case_id === null && item.status === 'completed',
    ),
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
            patient_id: props.patient.id,
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
    <div class="flex flex-col gap-6">
        <Head :title="patient.full_name" />

        <PageHeader
            :title="patient.full_name"
            :description="t('patient.detail_subtitle')"
            :breadcrumbs="[
                { label: t('nav.patients'), href: index().url },
                { label: patient.full_name },
            ]"
        >
            <template #actions>
                <ButtonLink
                    :href="index().url"
                    :label="t('patient.back')"
                    severity="secondary"
                    outlined
                >
                    <template #icon>
                        <IconArrowLeft />
                    </template>
                </ButtonLink>
                <Button
                    v-if="canRecordPayment"
                    type="button"
                    severity="secondary"
                    outlined
                    :label="t('payment.record_button')"
                    @click="showPaymentDialog = true"
                >
                    <template #icon>
                        <IconCash />
                    </template>
                </Button>
                <ButtonLink
                    v-if="canManage"
                    :href="edit(patient.id).url"
                    :label="t('patient.edit')"
                >
                    <template #icon>
                        <IconPencil />
                    </template>
                </ButtonLink>
                <Button
                    v-if="canManage"
                    type="button"
                    severity="danger"
                    outlined
                    :label="t('patient.remove')"
                    @click="removePatient"
                >
                    <template #icon>
                        <IconTrash />
                    </template>
                </Button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            <section
                class="flex flex-col gap-5 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8 lg:col-span-2"
            >
                <header class="flex items-center gap-2">
                    <IconUser class="size-5 text-surface-500" />
                    <h2 class="text-lg font-semibold text-surface-900">
                        {{ t('patient.sections.profile') }}
                    </h2>
                    <Tag
                        v-if="patient.is_legacy"
                        severity="warn"
                        :value="t('patient.legacy_badge')"
                        class="ml-auto"
                    />
                </header>

                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div
                        v-for="(row, idx) in contactRows"
                        :key="idx"
                        class="flex items-start gap-3"
                    >
                        <component
                            :is="row.icon"
                            class="mt-0.5 size-4 shrink-0 text-surface-400"
                        />
                        <div class="flex min-w-0 flex-col">
                            <dt class="text-xs text-surface-500">
                                {{ row.label }}
                            </dt>
                            <dd class="text-sm text-surface-900">
                                {{ row.value || t('patient.not_specified') }}
                            </dd>
                        </div>
                    </div>
                </dl>

                <div
                    class="flex items-center justify-between gap-4 rounded-lg border border-surface-200 p-4"
                >
                    <span class="text-sm font-medium text-surface-900">
                        {{ t('patient.fields.notification_enabled') }}
                    </span>
                    <Tag
                        :severity="
                            patient.notification_enabled
                                ? 'success'
                                : 'secondary'
                        "
                        :value="
                            patient.notification_enabled
                                ? t('patient.notifications_on')
                                : t('patient.notifications_off')
                        "
                    />
                </div>

                <div class="flex flex-col gap-2">
                    <header class="flex items-center gap-2">
                        <IconNotes class="size-5 text-surface-500" />
                        <h3 class="text-sm font-semibold text-surface-900">
                            {{ t('patient.sections.notes') }}
                        </h3>
                        <Button
                            v-if="canEditNotes && !editingNotes"
                            type="button"
                            severity="secondary"
                            outlined
                            size="small"
                            class="ml-auto"
                            :label="t('patient.notes_edit')"
                            @click="startEditNotes"
                        >
                            <template #icon>
                                <IconPencil />
                            </template>
                        </Button>
                    </header>

                    <template v-if="editingNotes">
                        <Textarea
                            v-model="notesForm.notes"
                            rows="4"
                            auto-resize
                            fluid
                            :invalid="Boolean(notesForm.errors.notes)"
                            :placeholder="t('patient.notes_placeholder')"
                            :aria-label="t('patient.sections.notes')"
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
                                @click="cancelEditNotes"
                            />
                            <Button
                                type="button"
                                size="small"
                                :label="t('patient.save')"
                                :loading="notesForm.processing"
                                @click="saveNotes"
                            />
                        </div>
                    </template>

                    <template v-else>
                        <p
                            v-if="patient.notes"
                            class="text-sm whitespace-pre-line text-surface-700"
                        >
                            {{ patient.notes }}
                        </p>
                        <p v-else class="text-sm text-surface-400">
                            {{ t('patient.no_notes') }}
                        </p>
                    </template>
                </div>
            </section>

            <section
                class="flex flex-col gap-4 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
            >
                <header class="flex items-center gap-2">
                    <IconNotes class="size-5 text-surface-500" />
                    <h2 class="text-lg font-semibold text-surface-900">
                        {{ t('patient.sections.treatments') }}
                    </h2>
                </header>

                <ul v-if="treatments.length" class="flex flex-col gap-2">
                    <li v-for="item in treatments" :key="item.id">
                        <Link
                            :href="treatmentShow(item.id).url"
                            class="flex items-center gap-3 rounded-xl border border-surface-200 p-3 transition-colors hover:border-primary-300 hover:bg-surface-50"
                        >
                            <div class="flex min-w-0 flex-1 flex-col gap-1">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="truncate text-sm font-medium text-surface-900"
                                    >
                                        {{
                                            item.title ||
                                            t('treatment.untitled')
                                        }}
                                    </span>
                                    <TreatmentStatusTag :status="item.status" />
                                </div>
                                <span class="text-xs text-surface-500">
                                    {{
                                        item.completed_at
                                            ? formatDate(item.completed_at)
                                            : t('treatment.in_progress')
                                    }}
                                    · {{ item.doctor_name }}
                                </span>
                                <span
                                    v-if="item.case_title"
                                    class="truncate text-xs text-surface-400"
                                >
                                    {{ item.case_title }}
                                </span>
                            </div>
                            <span class="text-sm font-medium text-surface-700">
                                {{ formatMoney(item.total_amount) }}
                            </span>
                            <IconChevronRight
                                class="size-4 shrink-0 text-surface-400"
                            />
                        </Link>
                    </li>
                </ul>

                <div
                    v-else
                    class="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-surface-200 px-4 py-10 text-center"
                >
                    <IconNotes class="size-8 text-surface-300" />
                    <p class="text-sm text-surface-500">
                        {{ t('patient.no_treatments') }}
                    </p>
                    <p class="text-xs text-surface-400">
                        {{ t('patient.no_treatments_hint') }}
                    </p>
                </div>
            </section>
        </div>

        <section
            v-if="canViewAppointments"
            class="flex flex-col gap-4 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
        >
            <header class="flex items-center gap-2">
                <IconCalendarEvent class="size-5 text-surface-500" />
                <h2 class="text-lg font-semibold text-surface-900">
                    {{ t('patient.sections.appointments') }}
                </h2>
            </header>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="flex flex-col gap-2">
                    <h3 class="text-sm font-semibold text-surface-700">
                        {{ t('patient.appointments.upcoming') }}
                    </h3>
                    <ul
                        v-if="upcomingAppointments.length"
                        class="flex flex-col gap-2"
                    >
                        <li
                            v-for="item in upcomingAppointments"
                            :key="item.id"
                            class="flex items-center gap-3 rounded-xl border border-surface-200 p-3"
                        >
                            <div class="flex min-w-0 flex-1 flex-col gap-1">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="text-sm font-medium text-surface-900"
                                    >
                                        {{
                                            formatRange(
                                                item.starts_at,
                                                item.ends_at,
                                            )
                                        }}
                                    </span>
                                    <AppointmentStatusTag
                                        :status="item.status"
                                    />
                                    <Tag
                                        v-if="item.is_walk_in"
                                        severity="secondary"
                                        :value="t('appointment.walk_in')"
                                    />
                                </div>
                                <span
                                    class="flex items-center gap-1.5 text-xs text-surface-500"
                                >
                                    {{ item.doctor_name }}
                                    <template v-if="item.service_name">
                                        · {{ item.service_name }}
                                    </template>
                                    <template v-if="item.appointment_type">
                                        ·
                                        <span
                                            class="inline-block size-2 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    item.appointment_type.color,
                                            }"
                                        />
                                        {{ item.appointment_type.name }}
                                    </template>
                                </span>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-surface-400">
                        {{ t('patient.appointments.no_upcoming') }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <h3 class="text-sm font-semibold text-surface-700">
                        {{ t('patient.appointments.past') }}
                    </h3>
                    <ul
                        v-if="pastAppointments.length"
                        class="flex flex-col gap-2"
                    >
                        <li
                            v-for="item in pastAppointments"
                            :key="item.id"
                            class="flex items-center gap-3 rounded-xl border border-surface-200 p-3"
                        >
                            <div class="flex min-w-0 flex-1 flex-col gap-1">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="text-sm font-medium text-surface-900"
                                    >
                                        {{
                                            formatRange(
                                                item.starts_at,
                                                item.ends_at,
                                            )
                                        }}
                                    </span>
                                    <AppointmentStatusTag
                                        :status="item.status"
                                    />
                                    <Tag
                                        v-if="item.is_walk_in"
                                        severity="secondary"
                                        :value="t('appointment.walk_in')"
                                    />
                                </div>
                                <span
                                    class="flex items-center gap-1.5 text-xs text-surface-500"
                                >
                                    {{ item.doctor_name }}
                                    <template v-if="item.service_name">
                                        · {{ item.service_name }}
                                    </template>
                                    <template v-if="item.appointment_type">
                                        ·
                                        <span
                                            class="inline-block size-2 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    item.appointment_type.color,
                                            }"
                                        />
                                        {{ item.appointment_type.name }}
                                    </template>
                                </span>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-surface-400">
                        {{ t('patient.appointments.no_past') }}
                    </p>
                </div>
            </div>
        </section>

        <section
            class="flex flex-col gap-4 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
        >
            <header class="flex items-center gap-2">
                <IconMessage class="size-5 text-surface-500" />
                <h2 class="text-lg font-semibold text-surface-900">
                    {{ t('sms.log.patient.title') }}
                </h2>
            </header>

            <ul v-if="smsLogs.length" class="flex flex-col gap-2">
                <li
                    v-for="log in smsLogs"
                    :key="log.id"
                    class="flex flex-col gap-1 rounded-xl border border-surface-200 p-3"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-medium text-surface-900">
                            {{ t(`sms.type.${log.type}`) }}
                        </span>
                        <SmsStatusTag :status="log.status" small />
                        <span class="ml-auto text-xs text-surface-500">
                            {{ formatDateTime(log.created_at) }}
                        </span>
                    </div>
                    <p class="text-sm whitespace-pre-line text-surface-600">
                        {{ log.body }}
                    </p>
                    <p v-if="log.error" class="text-xs text-red-500">
                        {{ t('sms.log.error_label') }}: {{ log.error }}
                    </p>
                </li>
            </ul>
            <p v-else class="text-sm text-surface-400">
                {{ t('sms.log.patient.empty') }}
            </p>
        </section>

        <section
            v-if="cases.length"
            class="flex flex-col gap-4 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
        >
            <header class="flex items-center gap-2">
                <IconFolders class="size-5 text-surface-500" />
                <h2 class="text-lg font-semibold text-surface-900">
                    {{ t('patient.sections.cases') }}
                </h2>
            </header>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="flex flex-col gap-2">
                    <h3 class="text-sm font-semibold text-surface-700">
                        {{ t('patient.cases.open') }}
                    </h3>
                    <ul v-if="openCases.length" class="flex flex-col gap-2">
                        <li v-for="item in openCases" :key="item.id">
                            <Link
                                :href="caseShow(item.id).url"
                                class="flex items-center gap-3 rounded-xl border border-surface-200 p-3 transition-colors hover:border-primary-300 hover:bg-surface-50"
                            >
                                <div class="flex min-w-0 flex-1 flex-col gap-1">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="truncate text-sm font-medium text-surface-900"
                                        >
                                            {{ item.title }}
                                        </span>
                                        <CaseStatusTag :status="item.status" />
                                    </div>
                                    <span class="text-xs text-surface-500">
                                        {{
                                            t(
                                                'patient.cases.treatments_count',
                                                {
                                                    count: item.treatments_count,
                                                },
                                            )
                                        }}
                                        <template v-if="item.follow_up_date">
                                            ·
                                            {{
                                                formatDateOnly(
                                                    item.follow_up_date,
                                                )
                                            }}
                                        </template>
                                    </span>
                                </div>
                                <IconChevronRight
                                    class="size-4 shrink-0 text-surface-400"
                                />
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-surface-400">
                        {{ t('patient.cases.no_open') }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <h3 class="text-sm font-semibold text-surface-700">
                        {{ t('patient.cases.closed') }}
                    </h3>
                    <ul v-if="closedCases.length" class="flex flex-col gap-2">
                        <li v-for="item in closedCases" :key="item.id">
                            <Link
                                :href="caseShow(item.id).url"
                                class="flex items-center gap-3 rounded-xl border border-surface-200 p-3 transition-colors hover:border-primary-300 hover:bg-surface-50"
                            >
                                <div class="flex min-w-0 flex-1 flex-col gap-1">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="truncate text-sm font-medium text-surface-900"
                                        >
                                            {{ item.title }}
                                        </span>
                                        <CaseStatusTag :status="item.status" />
                                    </div>
                                    <span class="text-xs text-surface-500">
                                        {{
                                            t(
                                                'patient.cases.treatments_count',
                                                {
                                                    count: item.treatments_count,
                                                },
                                            )
                                        }}
                                    </span>
                                </div>
                                <IconChevronRight
                                    class="size-4 shrink-0 text-surface-400"
                                />
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-surface-400">
                        {{ t('patient.cases.no_closed') }}
                    </p>
                </div>
            </div>
        </section>

        <section
            v-if="ungroupedTreatments.length"
            class="flex flex-col gap-4 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
        >
            <header class="flex flex-col gap-1">
                <div class="flex items-center gap-2">
                    <IconFolderOff class="size-5 text-surface-500" />
                    <h2 class="text-lg font-semibold text-surface-900">
                        {{ t('patient.sections.ungrouped') }}
                    </h2>
                </div>
                <p class="text-sm text-surface-500">
                    {{ t('patient.ungrouped.hint') }}
                </p>
            </header>

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
                        <span class="text-xs text-surface-500">
                            {{
                                item.completed_at
                                    ? formatDate(item.completed_at)
                                    : t('treatment.in_progress')
                            }}
                            · {{ item.doctor_name }}
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
        </section>

        <section
            v-if="canViewBalance && balance"
            class="flex flex-col gap-5 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
        >
            <header class="flex items-center gap-2">
                <IconCash class="size-5 text-surface-500" />
                <h2 class="text-lg font-semibold text-surface-900">
                    {{ t('balance.section_title') }}
                </h2>
            </header>

            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div
                    class="flex flex-col gap-1 rounded-lg border border-surface-200 p-4"
                >
                    <dt class="text-xs text-surface-500">
                        {{ t('balance.total') }}
                    </dt>
                    <dd class="text-lg font-semibold text-surface-900">
                        {{ formatMoney(balance.total) }}
                    </dd>
                </div>
                <div
                    class="flex flex-col gap-1 rounded-lg border border-surface-200 p-4"
                >
                    <dt class="text-xs text-surface-500">
                        {{ t('balance.paid') }}
                    </dt>
                    <dd class="text-lg font-semibold text-green-600">
                        {{ formatMoney(balance.paid) }}
                    </dd>
                </div>
                <div
                    class="flex flex-col gap-1 rounded-lg border border-surface-200 p-4"
                >
                    <dt class="text-xs text-surface-500">
                        {{
                            remainingIsCredit
                                ? t('balance.credit')
                                : t('balance.remaining')
                        }}
                    </dt>
                    <dd
                        class="text-lg font-semibold"
                        :class="
                            remainingIsCredit
                                ? 'text-green-600'
                                : 'text-surface-900'
                        "
                    >
                        {{
                            formatMoney(
                                remainingIsCredit
                                    ? Math.abs(Number(balance.remaining))
                                    : balance.remaining,
                            )
                        }}
                    </dd>
                </div>
            </dl>

            <div class="flex flex-col gap-3">
                <h3 class="text-sm font-semibold text-surface-700">
                    {{ t('balance.transactions_title') }}
                </h3>
                <TransactionList
                    :transactions="transactions ?? []"
                    show-treatment
                />
            </div>
        </section>

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

        <RecordPaymentDialog
            v-if="canRecordPayment"
            v-model:visible="showPaymentDialog"
            :patient-id="patient.id"
            :treatments="treatments"
        />
    </div>
</template>
