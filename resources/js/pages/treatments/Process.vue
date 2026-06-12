<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconCalendarEvent,
    IconCircleCheck,
    IconClipboardList,
    IconPhone,
    IconStethoscope,
    IconUser,
    IconWalk,
} from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import PageHeader from '@/components/PageHeader.vue';
import CaseLinkSection from '@/components/treatments/CaseLinkSection.vue';
import ClinicalFieldsSection from '@/components/treatments/ClinicalFieldsSection.vue';
import FollowUpSection from '@/components/treatments/FollowUpSection.vue';
import { provideTreatmentForm } from '@/components/treatments/formContext';
import LineItemsEditor from '@/components/treatments/LineItemsEditor.vue';
import PaymentSection from '@/components/treatments/PaymentSection.vue';
import TreatmentTotalsPanel from '@/components/treatments/TreatmentTotalsPanel.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import AppLayout from '@/layouts/AppLayout.vue';
import { show as patientShow } from '@/routes/patients';
import { complete } from '@/routes/treatments';
import type {
    TreatmentFormData,
    TreatmentProcessProps,
    TreatmentServiceOption,
} from '@/types/treatment';
import { combineDateTime } from '@/utils/appointmentTime';

defineOptions({ layout: AppLayout });

const props = defineProps<TreatmentProcessProps>();

const { t } = useI18n();
const { can } = useCan();
const confirm = useConfirm();
const { formatDateTime } = useDateTime();

const form = useForm<TreatmentFormData>({
    details: {
        complaint: props.treatment.details.complaint ?? '',
        diagnosis: props.treatment.details.diagnosis ?? '',
        treatment_process: props.treatment.details.treatment_process ?? '',
    },
    notes: props.treatment.notes ?? '',
    discount_amount: null,
    services: [],
    products: [],
    // Default to picking the most recent open case; fall back to no linking.
    case_mode: props.openCases.length ? 'existing' : 'none',
    case_id: props.openCases.length ? props.openCases[0].id : null,
    new_case_title: '',
    payment: { mode: 'received', rows: [{ method: null, amount: null }] },
    follow_up: {
        mode: 'none',
        date: null,
        time: '',
        count: 3,
        interval: 'weekly',
        occurrences: [],
        service_id: null,
        appointment_type_id: null,
        duration_minutes: null,
    },
});

provideTreatmentForm(form);

const canPay = can('transactions.create');
const canScheduleFollowUp = can('appointments.create');

function serviceById(id: number | null): TreatmentServiceOption | undefined {
    return id === null ? undefined : props.services.find((s) => s.id === id);
}

// Clinical templates follow the FIRST service line — re-applied every time its service changes,
// but a field is only replaced while it still holds the previously applied template (or is
// empty); text the doctor edited is never overwritten.
const appliedTemplates = {
    complaint: '',
    diagnosis: '',
    treatment_process: '',
};

function applyTemplates(service: TreatmentServiceOption | undefined): void {
    const fields = [
        ['complaint', service?.default_complaint ?? ''],
        ['diagnosis', service?.default_diagnosis ?? ''],
        ['treatment_process', service?.default_treatment_process ?? ''],
    ] as const;

    for (const [field, template] of fields) {
        const current = form.details[field];

        if (current === '' || current === appliedTemplates[field]) {
            form.details[field] = template;
            appliedTemplates[field] = template;
        }
    }
}

watch(
    () => form.services[0]?.service_id ?? null,
    (id) => applyTemplates(serviceById(id)),
);

// The appointment's visit-intent service preselects the first line (price from catalog,
// freely editable); the watcher above applies its clinical templates.
const bookedService = serviceById(props.treatment.appointment.service_id);

if (bookedService) {
    form.services.push({
        service_id: bookedService.id,
        quantity: 1,
        unit_price: Number(bookedService.price),
        discount_amount: null,
    });
}

type OccurrencePayload = {
    starts_at: string;
    duration_minutes: number | null;
    appointment_type_id: number | null;
};

// Build the clinic-local occurrence objects for the submit payload, dropping any incomplete row.
// Each occurrence carries its own type/duration (single mode uses the section-level fields).
function occurrencesFor(
    followUp: TreatmentFormData['follow_up'],
): OccurrencePayload[] {
    if (followUp.mode === 'none') {
        return [];
    }

    const rows =
        followUp.mode === 'single'
            ? [
                  {
                      date: followUp.date,
                      time: followUp.time,
                      duration_minutes: followUp.duration_minutes,
                      appointment_type_id: followUp.appointment_type_id,
                  },
              ]
            : followUp.occurrences;

    return rows
        .map((row) => ({
            starts_at: combineDateTime(row.date, row.time),
            duration_minutes: row.duration_minutes,
            appointment_type_id: row.appointment_type_id,
        }))
        .filter((row): row is OccurrencePayload => row.starts_at !== null);
}

form.transform((data) => ({
    details: data.details,
    notes: data.notes,
    discount_amount: data.discount_amount,
    services: data.services.map((line) => ({
        service_id: line.service_id,
        quantity: line.quantity,
        unit_price: line.unit_price,
        discount_amount: line.discount_amount,
    })),
    products: data.products.map((line) => ({
        product_id: line.product_id,
        quantity: line.quantity,
        unit_price: line.unit_price,
        discount_amount: line.discount_amount,
    })),
    case_mode: data.case_mode,
    case_id: data.case_mode === 'existing' ? data.case_id : null,
    new_case_title: data.case_mode === 'new' ? data.new_case_title : null,
    // One transaction per row; an untouched empty row is dropped, not validated.
    payments:
        data.payment.mode === 'none'
            ? []
            : data.payment.rows
                  .filter((row) => row.amount !== null || row.method !== null)
                  .map((row) => ({ amount: row.amount, method: row.method })),
    follow_up: {
        mode: data.follow_up.mode,
        service_id: data.follow_up.service_id,
        // none → []; single → the one date+time; package → the editable rows.
        occurrences: occurrencesFor(data.follow_up),
    },
}));

function submit(): void {
    confirm.require({
        header: t('treatment.confirm.title'),
        message: t('treatment.confirm.message'),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('treatment.confirm.accept') },
        accept: () => form.put(complete(props.treatment.id).url),
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('treatment.process_title')" />

        <PageHeader
            :title="t('treatment.process_title')"
            :description="t('treatment.process_subtitle')"
            :breadcrumbs="[
                {
                    label: t('nav.patients'),
                    href: patientShow(treatment.patient.id).url,
                },
                { label: treatment.patient.full_name },
                { label: t('treatment.process_title') },
            ]"
        >
            <template #actions>
                <ButtonLink
                    :href="patientShow(treatment.patient.id).url"
                    :label="t('common.back')"
                    severity="secondary"
                    outlined
                >
                    <template #icon>
                        <IconArrowLeft />
                    </template>
                </ButtonLink>
            </template>
        </PageHeader>

        <!-- Patient + appointment context for the visit being recorded. -->
        <section
            class="flex flex-col gap-4 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:flex-row sm:items-center sm:justify-between sm:p-8"
        >
            <div class="flex items-center gap-3">
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary-600"
                >
                    <IconUser class="size-6" />
                </span>
                <div class="flex flex-col">
                    <span class="text-lg font-semibold text-surface-900">
                        {{ treatment.patient.full_name }}
                    </span>
                    <span
                        v-if="treatment.patient.phone"
                        class="flex items-center gap-1 text-sm text-surface-500"
                    >
                        <IconPhone class="size-4" />
                        {{ treatment.patient.phone }}
                    </span>
                </div>
            </div>

            <div class="flex flex-col gap-2 sm:items-end">
                <span
                    class="flex items-center gap-2 text-xl font-semibold text-surface-900"
                >
                    <IconCalendarEvent class="size-6 text-primary-600" />
                    {{ formatDateTime(treatment.appointment.starts_at) }}
                </span>
                <div
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-surface-600"
                >
                    <Tag
                        v-if="treatment.appointment.is_walk_in"
                        severity="warn"
                        :value="t('appointment_list.walk_in_badge')"
                    >
                        <template #icon>
                            <IconWalk class="size-3.5" />
                        </template>
                    </Tag>
                    <span
                        v-if="treatment.appointment.appointment_type"
                        class="flex items-center gap-1.5"
                    >
                        <span
                            class="size-3 shrink-0 rounded-full"
                            :style="{
                                backgroundColor:
                                    treatment.appointment.appointment_type
                                        .color,
                            }"
                        />
                        {{ treatment.appointment.appointment_type.name }}
                    </span>
                    <span class="flex items-center gap-1">
                        <IconStethoscope class="size-4" />
                        {{ treatment.doctor.display_name }}
                    </span>
                    <span v-if="bookedService" class="flex items-center gap-1">
                        <IconClipboardList class="size-4" />
                        {{ bookedService.name }}
                    </span>
                </div>
            </div>
        </section>

        <p
            v-if="treatment.patient.notes"
            class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm whitespace-pre-line text-amber-800"
        >
            {{ treatment.patient.notes }}
        </p>

        <form
            novalidate
            class="grid grid-cols-1 items-start gap-6 lg:grid-cols-5"
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-6 lg:col-span-3">
                <ClinicalFieldsSection />
                <LineItemsEditor kind="service" :options="services" />
                <LineItemsEditor kind="product" :options="products" />
                <FollowUpSection
                    v-if="canScheduleFollowUp"
                    :doctor-id="treatment.doctor.id"
                    :services="services"
                    :appointment-types="appointmentTypes"
                />
            </div>

            <!-- Sticky so totals/payment stay visible while editing the long left column; the
                 max-height + inner scroll keeps the bottom reachable on short viewports. -->
            <div
                class="flex flex-col gap-6 lg:sticky lg:top-2 lg:col-span-2 lg:max-h-[calc(100vh-1rem)] lg:overflow-y-auto"
            >
                <CaseLinkSection :open-cases="openCases" />
                <TreatmentTotalsPanel
                    :services="services"
                    :products="products"
                />
                <PaymentSection v-if="canPay" />
            </div>

            <div class="lg:col-span-5">
                <Button
                    type="submit"
                    :label="t('treatment.submit')"
                    :loading="form.processing"
                    class="w-full sm:w-auto"
                >
                    <template #icon>
                        <IconCircleCheck class="size-5" />
                    </template>
                </Button>
            </div>
        </form>
    </div>
</template>
