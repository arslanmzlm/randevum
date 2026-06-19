<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconAlertTriangle, IconMessage, IconSearch } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import SmsStatusTag from '@/components/SmsStatusTag.vue';
import { useDateTime } from '@/composables/useDateTime';
import { useTableFilters } from '@/composables/useTableFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { index } from '@/routes/sms-logs';
import type { SmsType } from '@/types/enums';
import type { SmsLogIndexProps } from '@/types/smsLog';
import { parseDateString } from '@/utils/datetime';
import { SMS_STATUSES } from '@/utils/smsStatus';

defineOptions({ layout: AppLayout });

const props = defineProps<SmsLogIndexProps>();

const { t } = useI18n();
const { formatDateTime } = useDateTime();

const { state, loading, first, onPage } = useTableFilters<{
    status: string | null;
    type: string | null;
    start_date: Date | null;
    end_date: Date | null;
}>({
    url: index().url,
    only: ['smsLogs', 'query'],
    currentPage: props.smsLogs.meta.current_page,
    search: props.query.filter.search,
    perPage: props.query.per_page,
    filters: {
        status: {
            type: 'string',
            value: props.query.filter.status || null,
        },
        type: {
            type: 'string',
            value: props.query.filter.type || null,
        },
        start_date: {
            type: 'date',
            value: props.query.filter.start_date
                ? parseDateString(props.query.filter.start_date)
                : null,
        },
        end_date: {
            type: 'date',
            value: props.query.filter.end_date
                ? parseDateString(props.query.filter.end_date)
                : null,
        },
    },
});

const hasActiveFilters = computed(
    () =>
        !!state.search ||
        state.status !== null ||
        state.type !== null ||
        state.start_date !== null ||
        state.end_date !== null,
);

// Big empty state only when the clinic has genuinely sent no SMS (not a filtered miss).
const showEmptyState = computed(
    () => props.smsLogs.meta.total === 0 && !hasActiveFilters.value,
);

const statusOptions = computed(() =>
    SMS_STATUSES.map((status) => ({
        label: t(`sms.status.${status}`),
        value: status,
    })),
);

// Every SmsType is loggable (incl. otp), so list them all in the filter.
const SMS_TYPES: SmsType[] = [
    'appointment_created',
    'appointment_cancelled',
    'appointment_rescheduled',
    'reminder_24h',
    'reminder_1h',
    'balance_reminder',
    'otp',
];

const typeOptions = computed(() =>
    SMS_TYPES.map((type) => ({
        label: t(`sms.type.${type}`),
        value: type,
    })),
);

// PrimeVue range DatePicker binds one [start, end] array; bridge it to the two
// separate filter params. Mid-selection the array is [start, null] (valid partial filter).
const dateRange = computed<(Date | null)[] | null>({
    get: () =>
        state.start_date || state.end_date
            ? [state.start_date, state.end_date]
            : null,
    set: (value) => {
        state.start_date = value?.[0] ?? null;
        state.end_date = value?.[1] ?? null;
    },
});
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('sms.log.title')" />

        <PageHeader
            :title="t('sms.log.title')"
            :description="t('sms.log.subtitle')"
            :breadcrumbs="[{ label: t('nav.sms_logs') }]"
        />

        <EmptyState
            v-if="showEmptyState"
            :icon="IconMessage"
            :message="t('sms.log.empty')"
        />

        <DataTableWrapper
            v-else
            :value="smsLogs.data"
            :total-records="smsLogs.meta.total"
            :rows="state.per_page"
            :first="first"
            :loading="loading"
            @page="onPage"
        >
            <template #toolbar>
                <IconField>
                    <InputIcon>
                        <IconSearch class="size-4 text-surface-400" />
                    </InputIcon>
                    <InputText
                        v-model="state.search"
                        :placeholder="t('sms.log.search_placeholder')"
                        class="w-full sm:w-64"
                    />
                </IconField>
                <Select
                    v-model="state.status"
                    :options="statusOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('sms.log.filter_status')"
                    show-clear
                    class="w-full sm:w-48"
                />
                <Select
                    v-model="state.type"
                    :options="typeOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('sms.log.filter_type')"
                    show-clear
                    class="w-full sm:w-56"
                />
                <DatePicker
                    v-model="dateRange"
                    selection-mode="range"
                    :number-of-months="2"
                    :manual-input="false"
                    date-format="dd.mm.yy"
                    show-button-bar
                    :placeholder="t('sms.log.filter_date_range')"
                    class="w-full sm:w-64"
                    :pt="{ panel: { class: 'daterange-panel-centered' } }"
                />
            </template>

            <Column
                field="created_at"
                :header="t('sms.log.columns.datetime')"
                class="w-44"
            >
                <template #body="{ data }">
                    <span class="text-surface-700">
                        {{ formatDateTime(data.created_at) }}
                    </span>
                </template>
            </Column>

            <Column :header="t('sms.log.columns.type')" class="w-44">
                <template #body="{ data }">
                    <span class="text-surface-700">
                        {{ t(`sms.type.${data.type}`) }}
                    </span>
                </template>
            </Column>

            <Column :header="t('sms.log.columns.recipient')">
                <template #body="{ data }">
                    <div class="flex min-w-0 flex-col">
                        <span class="truncate font-medium text-surface-800">
                            {{
                                data.patient_name ||
                                data.phone ||
                                t('sms.log.no_recipient')
                            }}
                        </span>
                        <span
                            v-if="data.patient_name && data.phone"
                            class="text-xs text-surface-500"
                        >
                            {{ data.phone }}
                        </span>
                    </div>
                </template>
            </Column>

            <Column
                field="status"
                :header="t('sms.log.columns.status')"
                class="w-32"
            >
                <template #body="{ data }">
                    <div class="flex items-center gap-1.5">
                        <SmsStatusTag :status="data.status" />
                        <IconAlertTriangle
                            v-if="data.error"
                            v-tooltip.top="
                                `${t('sms.log.error_label')}: ${data.error}`
                            "
                            class="size-4 shrink-0 text-red-500"
                        />
                    </div>
                </template>
            </Column>

            <Column :header="t('sms.log.columns.body')">
                <template #body="{ data }">
                    <span
                        v-tooltip.top="data.body"
                        class="line-clamp-2 max-w-md text-sm text-surface-600"
                    >
                        {{ data.body }}
                    </span>
                </template>
            </Column>

            <template #empty>
                <div class="px-6 py-10 text-center text-sm text-surface-500">
                    {{ t('sms.log.empty_filtered') }}
                </div>
            </template>
        </DataTableWrapper>
    </div>
</template>
