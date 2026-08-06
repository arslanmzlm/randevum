<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    IconBriefcase,
    IconCalendarEvent,
    IconCash,
    IconClockHour4,
    IconHistory,
    IconStethoscope,
    IconTag,
    IconUser,
    IconUserPlus,
    IconWalk,
} from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppointmentCancelDialog from '@/components/appointments/AppointmentCancelDialog.vue';
import AppointmentStatusTimeline from '@/components/appointments/AppointmentStatusTimeline.vue';
import AppointmentStatusTag from '@/components/AppointmentStatusTag.vue';
import ButtonLink from '@/components/ButtonLink.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionCard from '@/components/SectionCard.vue';
import SmsLogList from '@/components/sms/SmsLogList.vue';
import TreatmentStatusTag from '@/components/TreatmentStatusTag.vue';
import { useAppointmentActionMenu } from '@/composables/useAppointmentActionMenu';
import type { AppointmentActionTarget } from '@/composables/useAppointmentActionMenu';
import { useAppointmentActions } from '@/composables/useAppointmentActions';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import { useTreatmentActions } from '@/composables/useTreatmentActions';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as appointmentsIndex } from '@/routes/appointments';
import { show as patientShow } from '@/routes/patients';
import { show as treatmentShow } from '@/routes/treatments';
import type { AppointmentShowProps } from '@/types/appointment';

defineOptions({ layout: AppLayout });

const props = defineProps<AppointmentShowProps>();

const { t } = useI18n();
const { formatDate, formatTime, formatDateTime } = useDateTime();
const { formatMoney } = useMoney();

const actions = useAppointmentActions(props.ownDoctorId);
const treatmentActions = useTreatmentActions(props.ownDoctorId);

const target = computed<AppointmentActionTarget | null>(() => ({
    id: props.appointment.id,
    doctor_id: props.appointment.doctor_id,
    status: props.appointment.status,
    starts_at: props.appointment.starts_at,
    treatment_id: props.appointment.treatment_id,
}));

const { primaryAction, secondaryActions, destructiveActions } =
    useAppointmentActionMenu(actions, treatmentActions, target);

const { cancelReason } = actions;

const timeLabel = computed(
    () =>
        `${formatDate(props.appointment.starts_at)} · ${formatTime(props.appointment.starts_at)} – ${formatTime(props.appointment.ends_at)}`,
);

// The appointments table has no note column — the only free text about a visit is the reason
// captured on the cancel / no-show transition, so the callout is derived from the timeline.
const reasonCallout = computed(() => {
    const entry = [...props.statusLogs]
        .reverse()
        .find(
            (log) =>
                (log.to_status === 'cancelled' ||
                    log.to_status === 'no_show') &&
                !!log.reason,
        );

    if (!entry) {
        return null;
    }

    return {
        label:
            entry.to_status === 'cancelled'
                ? t('appointment_detail.cancel_reason')
                : t('appointment_detail.no_show_reason'),
        text: entry.reason,
    };
});

const summaryRows = computed(() => [
    {
        key: 'doctor',
        icon: IconStethoscope,
        label: t('appointment_detail.fields.doctor'),
        value: props.appointment.doctor_name,
    },
    {
        key: 'datetime',
        icon: IconClockHour4,
        label: t('appointment_detail.fields.datetime'),
        value: timeLabel.value,
    },
    {
        key: 'service',
        icon: IconBriefcase,
        label: t('appointment_detail.fields.service'),
        value: props.appointment.service_name,
    },
    {
        key: 'type',
        icon: IconTag,
        label: t('appointment_detail.fields.type'),
        value: props.appointment.appointment_type?.name ?? null,
        color: props.appointment.appointment_type?.color ?? null,
    },
    {
        key: 'created_by',
        icon: IconUserPlus,
        label: t('appointment_detail.fields.created_by'),
        value: props.appointment.created_by_name,
    },
    {
        key: 'created_at',
        icon: IconCalendarEvent,
        label: t('appointment_detail.fields.created_at'),
        value: formatDateTime(props.appointment.created_at),
    },
]);
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('appointment_detail.title')" />

        <PageHeader
            :title="t('appointment_detail.title')"
            :description="t('appointment_detail.subtitle')"
            :breadcrumbs="[
                {
                    label: t('nav.appointments'),
                    href: appointmentsIndex().url,
                },
                { label: t('appointment_detail.breadcrumb') },
            ]"
        >
            <template #actions>
                <Button
                    v-if="primaryAction"
                    type="button"
                    :label="primaryAction.label"
                    @click="primaryAction.run()"
                >
                    <template #icon>
                        <component :is="primaryAction.icon" class="size-4" />
                    </template>
                </Button>
                <Button
                    v-for="action in secondaryActions"
                    :key="action.key"
                    type="button"
                    severity="secondary"
                    outlined
                    :label="action.label"
                    @click="action.run()"
                >
                    <template #icon>
                        <component :is="action.icon" class="size-4" />
                    </template>
                </Button>
                <Button
                    v-for="action in destructiveActions"
                    :key="action.key"
                    type="button"
                    severity="danger"
                    outlined
                    :label="action.label"
                    @click="action.run()"
                >
                    <template #icon>
                        <component :is="action.icon" class="size-4" />
                    </template>
                </Button>
            </template>
        </PageHeader>

        <SectionCard
            :icon="IconUser"
            :title="t('appointment_detail.sections.summary')"
        >
            <div class="flex flex-col gap-5">
                <div class="flex flex-wrap items-center gap-3">
                    <Link
                        :href="patientShow(appointment.patient_id).url"
                        class="truncate text-base font-semibold text-primary-600 transition-colors hover:text-primary-700 hover:underline"
                    >
                        {{ appointment.patient_name }}
                    </Link>
                    <AppointmentStatusTag :status="appointment.status" />
                    <Tag v-if="appointment.is_walk_in" severity="warn">
                        <template #icon>
                            <IconWalk class="size-3.5" />
                        </template>
                        {{ t('appointment_detail.fields.walk_in') }}
                    </Tag>
                </div>

                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div
                        v-for="row in summaryRows"
                        :key="row.key"
                        class="flex items-center gap-2"
                    >
                        <component
                            :is="row.icon"
                            class="size-4 shrink-0 text-surface-400"
                        />
                        <dt class="shrink-0 text-surface-500">
                            {{ row.label }}:
                        </dt>
                        <dd
                            class="flex min-w-0 items-center gap-1.5 font-medium text-surface-800"
                        >
                            <span
                                v-if="row.color"
                                class="size-2.5 shrink-0 rounded-full"
                                :style="{ backgroundColor: row.color }"
                                aria-hidden="true"
                            />
                            <span v-if="row.value" class="truncate">
                                {{ row.value }}
                            </span>
                            <span v-else class="text-surface-400">—</span>
                        </dd>
                    </div>
                </dl>
            </div>
        </SectionCard>

        <SectionCard
            :icon="IconHistory"
            :title="t('appointment_detail.sections.status_history')"
        >
            <AppointmentStatusTimeline :entries="statusLogs" />
        </SectionCard>

        <SectionCard
            :icon="IconStethoscope"
            :title="t('appointment_detail.sections.treatment')"
        >
            <div v-if="treatment" class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center gap-3">
                    <TreatmentStatusTag :status="treatment.status" />
                    <span
                        v-if="treatment.completed_at"
                        class="text-xs text-surface-500"
                    >
                        {{ formatDateTime(treatment.completed_at) }}
                    </span>
                    <span
                        class="ml-auto inline-flex items-center gap-1.5 font-medium text-surface-900"
                    >
                        <IconCash class="size-4 text-surface-400" />
                        {{ formatMoney(treatment.total_amount) }}
                    </span>
                </div>

                <dl class="flex flex-col gap-3 text-sm">
                    <div class="flex flex-col gap-1">
                        <dt class="text-xs text-surface-500">
                            {{ t('appointment_detail.treatment.complaint') }}
                        </dt>
                        <dd
                            v-if="treatment.complaint"
                            class="whitespace-pre-line text-surface-700"
                        >
                            {{ treatment.complaint }}
                        </dd>
                        <dd v-else class="text-surface-400">
                            {{ t('treatment.empty_field') }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt class="text-xs text-surface-500">
                            {{ t('appointment_detail.treatment.diagnosis') }}
                        </dt>
                        <dd
                            v-if="treatment.diagnosis"
                            class="whitespace-pre-line text-surface-700"
                        >
                            {{ treatment.diagnosis }}
                        </dd>
                        <dd v-else class="text-surface-400">
                            {{ t('treatment.empty_field') }}
                        </dd>
                    </div>
                </dl>

                <ButtonLink
                    :href="treatmentShow(treatment.id).url"
                    :label="t('appointment_detail.treatment.go_to')"
                    severity="secondary"
                    outlined
                    class="w-fit"
                >
                    <template #icon>
                        <IconStethoscope class="size-4" />
                    </template>
                </ButtonLink>
            </div>

            <p v-else class="text-sm text-surface-400">
                {{ t('appointment_detail.treatment.empty') }}
            </p>
        </SectionCard>

        <Message v-if="reasonCallout" severity="warn" :closable="false">
            <span class="font-medium">{{ reasonCallout.label }}:</span>
            {{ reasonCallout.text }}
        </Message>

        <SmsLogList
            v-if="smsLogs"
            :logs="smsLogs"
            :title="t('appointment_detail.sections.sms')"
            :empty-message="t('appointment_detail.sms.empty')"
        />

        <AppointmentCancelDialog v-model="cancelReason" />
    </div>
</template>
