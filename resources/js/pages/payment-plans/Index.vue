<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconBell,
    IconCalendarDollar,
    IconCash,
    IconClockDollar,
    IconSearch,
} from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import StatCard from '@/components/dashboard/StatCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import InstallmentStatusTag from '@/components/payment-plans/InstallmentStatusTag.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import AppLayout from '@/layouts/AppLayout.vue';
import { show as patientShow } from '@/routes/patients';
import { installments as installmentsRoute } from '@/routes/payment-plans';
import {
    collect,
    remind as remindRoute,
} from '@/routes/payment-plans/installments';
import type { InstallmentStatus, PaymentMethod } from '@/types/enums';
import type {
    PaymentPlanIndexProps,
    PendingInstallment,
} from '@/types/payment-plan';
import { parseDateString, toDateString } from '@/utils/datetime';

defineOptions({ layout: AppLayout });

const props = defineProps<PaymentPlanIndexProps>();

const { t } = useI18n();
const { can } = useCan();
const confirm = useConfirm();
const { formatDateOnly } = useDateTime();
const { formatMoney } = useMoney();

const canCollect = computed(() => can('transactions.create'));
const canRemind = computed(() => can('paymentPlans.sendReminder'));

// Status + date-range filters round-trip to the server (the repository defaults to pending-only and
// broadens on filter[status]); patient-name search filters the loaded rows client-side.
const status = ref<InstallmentStatus | null>(
    (props.query.filter.status as InstallmentStatus) || null,
);
const dateRange = ref<(Date | null)[] | null>(
    props.query.filter.due_after || props.query.filter.due_before
        ? [
              props.query.filter.due_after
                  ? parseDateString(props.query.filter.due_after)
                  : null,
              props.query.filter.due_before
                  ? parseDateString(props.query.filter.due_before)
                  : null,
          ]
        : null,
);
const patientSearch = ref('');
const loading = ref(false);

const statusOptions = computed(() =>
    (['pending', 'paid', 'cancelled'] as InstallmentStatus[]).map((value) => ({
        value,
        label: t(`payment_plan.installment_status.${value}`),
    })),
);

function reload(): void {
    const filter: Record<string, string> = {};

    if (status.value) {
        filter.status = status.value;
    }

    if (dateRange.value?.[0]) {
        filter.due_after = toDateString(dateRange.value[0]);
    }

    if (dateRange.value?.[1]) {
        filter.due_before = toDateString(dateRange.value[1]);
    }

    router.get(
        installmentsRoute().url,
        Object.keys(filter).length ? { filter } : {},
        {
            only: ['installments', 'stats', 'query'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
        },
    );
}

watch([status, dateRange], reload);

const visibleInstallments = computed(() => {
    const query = patientSearch.value.trim().toLocaleLowerCase('tr');

    if (!query) {
        return props.installments;
    }

    return props.installments.filter((row) =>
        row.patient_name.toLocaleLowerCase('tr').includes(query),
    );
});

const showEmptyState = computed(
    () =>
        props.installments.length === 0 &&
        !status.value &&
        dateRange.value === null,
);

// --- Collect dialog ---
const collectTarget = ref<PendingInstallment | null>(null);
const showCollect = ref(false);

const collectForm = useForm<{
    payment_method: PaymentMethod | null;
    paid_at: Date | null;
    note: string;
}>({
    payment_method: null,
    paid_at: null,
    note: '',
});

const methodOptions = computed(() =>
    (['cash', 'card', 'transfer', 'cheque'] as PaymentMethod[]).map(
        (method) => ({
            value: method,
            label: t(`payment.method.${method}`),
        }),
    ),
);

const today = new Date();

function openCollect(row: PendingInstallment): void {
    collectTarget.value = row;
    collectForm.reset();
    collectForm.clearErrors();
    showCollect.value = true;
}

function submitCollect(): void {
    if (!collectTarget.value) {
        return;
    }

    collectForm
        .transform((data) => ({
            ...data,
            paid_at: data.paid_at ? toDateString(data.paid_at) : null,
        }))
        .post(collect(collectTarget.value.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                showCollect.value = false;
                collectTarget.value = null;
            },
        });
}

function remind(row: PendingInstallment): void {
    confirm.require({
        header: t('payment_plan.remind_confirm_title'),
        message: t('payment_plan.remind_confirm_message', {
            name: row.patient_name,
        }),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('payment_plan.remind_action') },
        accept: () =>
            router.post(remindRoute(row.id).url, {}, { preserveScroll: true }),
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('payment_plan.title')" />

        <PageHeader
            :title="t('payment_plan.title')"
            :description="t('payment_plan.subtitle')"
            :breadcrumbs="[{ label: t('nav.payment_plans') }]"
        />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                :icon="IconAlertTriangle"
                accent="rose"
                :label="t('payment_plan.stats.overdue_count')"
                :value="stats.overdue_count"
            />
            <StatCard
                :icon="IconCash"
                accent="rose"
                :label="t('payment_plan.stats.overdue_total')"
                :value="formatMoney(stats.overdue_total)"
            />
            <StatCard
                :icon="IconClockDollar"
                accent="amber"
                :label="t('payment_plan.stats.due_soon_count')"
                :value="stats.due_soon_count"
            />
            <StatCard
                :icon="IconCalendarDollar"
                accent="amber"
                :label="t('payment_plan.stats.due_soon_total')"
                :value="formatMoney(stats.due_soon_total)"
            />
        </div>

        <EmptyState
            v-if="showEmptyState"
            :icon="IconCalendarDollar"
            :message="t('payment_plan.empty_installments')"
        />

        <SectionCard v-else padding="p-2 sm:p-3">
            <div
                class="flex flex-col gap-2 p-2 sm:flex-row sm:flex-wrap sm:items-center"
            >
                <IconField>
                    <InputIcon>
                        <IconSearch class="size-4 text-surface-400" />
                    </InputIcon>
                    <InputText
                        v-model="patientSearch"
                        :placeholder="t('payment_plan.search_placeholder')"
                        class="w-full sm:w-64"
                    />
                </IconField>
                <Select
                    v-model="status"
                    :options="statusOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('payment_plan.filter_status')"
                    show-clear
                    class="w-full sm:w-48"
                />
                <DatePicker
                    v-model="dateRange"
                    selection-mode="range"
                    :number-of-months="2"
                    :manual-input="false"
                    date-format="dd.mm.yy"
                    show-button-bar
                    :placeholder="t('payment_plan.filter_date_range')"
                    class="w-full sm:w-64"
                    :pt="{ panel: { class: 'daterange-panel-centered' } }"
                />
            </div>

            <DataTable
                :value="visibleInstallments"
                data-key="id"
                paginator
                removable-sort
                :rows="10"
                :rows-per-page-options="[10, 20, 50]"
                :loading="loading"
                class="text-sm"
            >
                <Column
                    field="patient_name"
                    :header="t('payment_plan.columns.patient')"
                    sortable
                >
                    <template #body="{ data }">
                        <Link
                            :href="patientShow(data.patient_id).url"
                            class="font-medium text-primary-600 hover:underline"
                        >
                            {{ data.patient_name }}
                        </Link>
                    </template>
                </Column>

                <Column
                    field="due_date"
                    :header="t('payment_plan.columns.due_date')"
                    sortable
                    class="w-40"
                >
                    <template #body="{ data }">
                        <span
                            class="flex items-center gap-1.5"
                            :class="
                                data.is_overdue
                                    ? 'font-medium text-red-500'
                                    : 'text-surface-700'
                            "
                        >
                            <IconAlertTriangle
                                v-if="data.is_overdue"
                                class="size-4 shrink-0"
                            />
                            {{ formatDateOnly(data.due_date) }}
                        </span>
                    </template>
                </Column>

                <Column
                    field="sequence"
                    :header="t('payment_plan.columns.sequence')"
                    class="w-24"
                >
                    <template #body="{ data }">
                        <span class="text-surface-500">
                            {{ data.sequence }}
                        </span>
                    </template>
                </Column>

                <Column
                    field="amount"
                    :header="t('payment_plan.columns.amount')"
                    sortable
                    class="w-32"
                >
                    <template #body="{ data }">
                        <span class="font-medium text-surface-800">
                            {{ formatMoney(data.amount) }}
                        </span>
                    </template>
                </Column>

                <Column
                    field="status"
                    :header="t('payment_plan.columns.status')"
                    class="w-32"
                >
                    <template #body="{ data }">
                        <InstallmentStatusTag
                            :status="data.status"
                            :overdue="data.is_overdue"
                        />
                    </template>
                </Column>

                <Column
                    :header="t('payment_plan.columns.actions')"
                    class="w-44"
                >
                    <template #body="{ data }">
                        <div
                            v-if="data.status === 'pending'"
                            class="flex items-center gap-1"
                        >
                            <Button
                                v-if="canCollect"
                                type="button"
                                size="small"
                                :label="t('payment_plan.collect_action')"
                                @click="openCollect(data)"
                            >
                                <template #icon>
                                    <IconCash class="mr-1 size-4" />
                                </template>
                            </Button>
                            <Button
                                v-if="canRemind"
                                type="button"
                                severity="secondary"
                                outlined
                                size="small"
                                :aria-label="t('payment_plan.remind_action')"
                                @click="remind(data)"
                            >
                                <IconBell class="size-4" />
                            </Button>
                        </div>
                    </template>
                </Column>

                <template #empty>
                    <div
                        class="px-6 py-10 text-center text-sm text-surface-500"
                    >
                        {{ t('payment_plan.empty_filtered') }}
                    </div>
                </template>
            </DataTable>
        </SectionCard>

        <Dialog
            v-model:visible="showCollect"
            modal
            :header="t('payment_plan.collect_title')"
            :style="{ width: '28rem' }"
            :dismissable-mask="!collectForm.processing"
        >
            <form
                v-if="collectTarget"
                class="flex flex-col gap-5 pt-2"
                @submit.prevent="submitCollect"
            >
                <p class="text-sm text-surface-500">
                    {{
                        t('payment_plan.collect_hint', {
                            name: collectTarget.patient_name,
                            amount: formatMoney(collectTarget.amount),
                        })
                    }}
                </p>

                <FormField
                    :label="t('payment.method_label')"
                    :error="collectForm.errors.payment_method"
                    required
                >
                    <Select
                        v-model="collectForm.payment_method"
                        :options="methodOptions"
                        option-label="label"
                        option-value="value"
                        fluid
                    />
                </FormField>

                <FormField
                    :label="t('payment.paid_at')"
                    :error="collectForm.errors.paid_at"
                >
                    <DatePicker
                        v-model="collectForm.paid_at"
                        date-format="dd.mm.yy"
                        :max-date="today"
                        fluid
                    />
                </FormField>

                <FormField
                    :label="t('payment.note')"
                    :error="collectForm.errors.note"
                >
                    <Textarea
                        v-model="collectForm.note"
                        rows="3"
                        auto-resize
                        fluid
                    />
                </FormField>

                <div class="flex justify-end gap-2 pt-1">
                    <Button
                        type="button"
                        severity="secondary"
                        outlined
                        :label="t('common.cancel')"
                        :disabled="collectForm.processing"
                        @click="showCollect = false"
                    />
                    <Button
                        type="submit"
                        :label="t('payment_plan.collect_submit')"
                        :loading="collectForm.processing"
                    />
                </div>
            </form>
        </Dialog>
    </div>
</template>
