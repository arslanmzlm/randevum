<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconCalendarEvent,
    IconCash,
    IconFileText,
    IconFolder,
    IconListDetails,
    IconPaperclip,
    IconStethoscope,
    IconUser,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import MediaGallery from '@/components/media/MediaGallery.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordPaymentDialog from '@/components/payments/RecordPaymentDialog.vue';
import TransactionList from '@/components/payments/TransactionList.vue';
import SectionCard from '@/components/SectionCard.vue';
import TreatmentStatusTag from '@/components/TreatmentStatusTag.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import AppLayout from '@/layouts/AppLayout.vue';
import { show as patientShow } from '@/routes/patients';
import { report as treatmentReport } from '@/routes/treatments';
import type { TreatmentLine, TreatmentShowProps } from '@/types/treatment';

defineOptions({ layout: AppLayout });

const props = defineProps<TreatmentShowProps>();

const { t } = useI18n();
const { can } = useCan();
const { formatDateTime } = useDateTime();
const { formatMoney } = useMoney();

const canRecordPayment = computed(() => can('transactions.create'));
const canViewTransactions = computed(() => can('transactions.viewAny'));

// Balance is always derived, never stored (paid = SUM(transactions); remaining = total − paid).
const remaining = computed(() =>
    Math.max(
        0,
        Number(props.treatment.total_amount) -
            Number(props.treatment.paid_total),
    ),
);

const showPaymentDialog = ref(false);

// The pill-labeled Şikayet / Tanı / Tedavi Süreci blocks from the detay reference.
const clinicalBlocks = computed(() => [
    {
        label: t('treatment.fields.complaint'),
        value: props.treatment.details.complaint,
    },
    {
        label: t('treatment.fields.diagnosis'),
        value: props.treatment.details.diagnosis,
    },
    {
        label: t('treatment.fields.treatment_process'),
        value: props.treatment.details.treatment_process,
    },
]);

const hasServiceLines = computed(() => props.treatment.serviceLines.length > 0);
const hasProductLines = computed(() => props.treatment.productLines.length > 0);
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('treatment.show_title')" />

        <PageHeader
            :title="t('treatment.show_title')"
            :description="formatDateTime(treatment.appointment.starts_at)"
            :breadcrumbs="[
                {
                    label: t('nav.patients'),
                    href: patientShow(treatment.patient.id).url,
                },
                { label: treatment.patient.full_name },
                { label: t('treatment.show_title') },
            ]"
        >
            <template #actions>
                <!-- Binary PDF stream: plain anchor to a new tab, never an Inertia visit. -->
                <Button
                    as="a"
                    :href="treatmentReport(treatment.id).url"
                    target="_blank"
                    rel="noopener"
                    :label="t('treatment.report.download')"
                >
                    <template #icon>
                        <IconFileText />
                    </template>
                </Button>
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

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <!-- Clinical record: pill-labeled sections (Şikayet / Tanı / Tedavi Süreci). -->
                <SectionCard
                    :icon="IconStethoscope"
                    :title="t('treatment.sections.clinical')"
                >
                    <div class="flex flex-col gap-5">
                        <div
                            v-for="block in clinicalBlocks"
                            :key="block.label"
                            class="flex flex-col gap-2"
                        >
                            <span
                                class="w-fit rounded-full bg-primary-50 px-4 py-1 text-sm font-semibold text-primary-700"
                            >
                                {{ block.label }}
                            </span>
                            <p
                                v-if="block.value"
                                class="text-sm whitespace-pre-line text-surface-700"
                            >
                                {{ block.value }}
                            </p>
                            <p v-else class="text-sm text-surface-400">
                                {{ t('treatment.empty_field') }}
                            </p>
                        </div>

                        <div v-if="treatment.notes" class="flex flex-col gap-1">
                            <span class="text-xs text-surface-500">
                                {{ t('treatment.fields.notes') }}
                            </span>
                            <p
                                class="text-sm whitespace-pre-line text-surface-700"
                            >
                                {{ treatment.notes }}
                            </p>
                        </div>
                    </div>
                </SectionCard>

                <!-- Line items -->
                <SectionCard
                    v-if="hasServiceLines || hasProductLines"
                    :icon="IconListDetails"
                    :title="t('treatment.sections.line_items')"
                >
                    <div class="flex flex-col gap-5">
                        <DataTable
                            v-if="hasServiceLines"
                            :value="treatment.serviceLines"
                            size="small"
                        >
                            <Column
                                :header="t('treatment.lines.service')"
                                field="name"
                            />
                            <Column
                                :header="t('treatment.lines.quantity')"
                                class="w-20 text-right"
                            >
                                <template
                                    #body="{ data }: { data: TreatmentLine }"
                                >
                                    {{ data.quantity }}
                                </template>
                            </Column>
                            <Column
                                :header="t('treatment.lines.unit_price')"
                                class="w-28 text-right"
                            >
                                <template
                                    #body="{ data }: { data: TreatmentLine }"
                                >
                                    {{ formatMoney(data.unit_price) }}
                                </template>
                            </Column>
                            <Column
                                :header="t('treatment.lines.subtotal')"
                                class="w-28 text-right"
                            >
                                <template
                                    #body="{ data }: { data: TreatmentLine }"
                                >
                                    {{ formatMoney(data.subtotal) }}
                                </template>
                            </Column>
                        </DataTable>

                        <DataTable
                            v-if="hasProductLines"
                            :value="treatment.productLines"
                            size="small"
                        >
                            <Column
                                :header="t('treatment.lines.product')"
                                field="name"
                            />
                            <Column
                                :header="t('treatment.lines.quantity')"
                                class="w-20 text-right"
                            >
                                <template
                                    #body="{ data }: { data: TreatmentLine }"
                                >
                                    {{ data.quantity }}
                                </template>
                            </Column>
                            <Column
                                :header="t('treatment.lines.unit_price')"
                                class="w-28 text-right"
                            >
                                <template
                                    #body="{ data }: { data: TreatmentLine }"
                                >
                                    {{ formatMoney(data.unit_price) }}
                                </template>
                            </Column>
                            <Column
                                :header="t('treatment.lines.subtotal')"
                                class="w-28 text-right"
                            >
                                <template
                                    #body="{ data }: { data: TreatmentLine }"
                                >
                                    {{ formatMoney(data.subtotal) }}
                                </template>
                            </Column>
                        </DataTable>
                    </div>
                </SectionCard>

                <!-- Payments / collections recorded against this treatment. -->
                <SectionCard
                    v-if="canViewTransactions"
                    :icon="IconCash"
                    :title="t('balance.transactions_title')"
                >
                    <TransactionList
                        :transactions="treatment.transactions ?? []"
                    />
                </SectionCard>

                <!-- Attached files / photos (doctor + assistant only; server-gated). -->
                <SectionCard
                    v-if="treatment.media?.length"
                    :icon="IconPaperclip"
                    :title="t('media.title')"
                >
                    <MediaGallery :items="treatment.media" />
                </SectionCard>
            </div>

            <!-- Summary sidebar -->
            <section
                class="flex flex-col gap-5 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
            >
                <header class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <IconUser class="size-5 text-surface-500" />
                        <h2 class="text-base font-semibold text-surface-900">
                            {{ treatment.patient.full_name }}
                        </h2>
                        <Tag
                            v-if="treatment.patient.is_deleted"
                            severity="danger"
                            :value="t('patient.deleted_badge')"
                        />
                    </div>
                    <TreatmentStatusTag :status="treatment.status" />
                </header>

                <dl class="flex flex-col gap-3 text-sm">
                    <div class="flex items-center gap-2">
                        <IconStethoscope class="size-4 text-surface-400" />
                        <dt class="text-surface-500">
                            {{ t('treatment.summary.doctor') }}:
                        </dt>
                        <dd class="font-medium text-surface-800">
                            {{ treatment.doctor.display_name }}
                        </dd>
                    </div>
                    <div
                        v-if="treatment.completed_at"
                        class="flex items-center gap-2"
                    >
                        <IconCalendarEvent class="size-4 text-surface-400" />
                        <dt class="text-surface-500">
                            {{ t('treatment.summary.completed_at') }}:
                        </dt>
                        <dd class="font-medium text-surface-800">
                            {{ formatDateTime(treatment.completed_at) }}
                        </dd>
                    </div>
                    <div v-if="treatment.case" class="flex items-center gap-2">
                        <IconFolder class="size-4 text-surface-400" />
                        <dt class="text-surface-500">
                            {{ t('treatment.summary.case') }}:
                        </dt>
                        <dd class="font-medium text-surface-800">
                            {{ treatment.case.title }}
                        </dd>
                    </div>
                </dl>

                <dl
                    class="flex flex-col gap-2 border-t border-surface-200 pt-4 text-sm"
                >
                    <div class="flex items-center justify-between">
                        <dt class="text-surface-500">
                            {{ t('treatment.totals.subtotal') }}
                        </dt>
                        <dd class="text-surface-800">
                            {{ formatMoney(treatment.subtotal_amount) }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-surface-500">
                            {{ t('treatment.totals.discount') }}
                        </dt>
                        <dd class="text-surface-800">
                            {{ formatMoney(treatment.discount_amount) }}
                        </dd>
                    </div>
                    <div
                        class="flex items-center justify-between border-t border-surface-200 pt-2"
                    >
                        <dt class="font-semibold text-surface-900">
                            {{ t('treatment.totals.total') }}
                        </dt>
                        <dd class="font-semibold text-surface-900">
                            {{ formatMoney(treatment.total_amount) }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-surface-500">
                            {{ t('treatment.summary.paid') }}
                        </dt>
                        <dd class="text-green-600">
                            {{ formatMoney(treatment.paid_total) }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-surface-500">
                            {{ t('treatment.summary.remaining') }}
                        </dt>
                        <dd class="font-medium text-surface-900">
                            {{ formatMoney(remaining) }}
                        </dd>
                    </div>
                </dl>

                <Button
                    v-if="canRecordPayment && remaining > 0"
                    type="button"
                    :label="t('payment.record_button')"
                    @click="showPaymentDialog = true"
                >
                    <template #icon>
                        <IconCash class="size-4" />
                    </template>
                </Button>
            </section>
        </div>

        <RecordPaymentDialog
            v-if="canRecordPayment"
            v-model:visible="showPaymentDialog"
            :patient-id="treatment.patient.id"
            :treatment-id="treatment.id"
            :remaining="remaining"
        />
    </div>
</template>
