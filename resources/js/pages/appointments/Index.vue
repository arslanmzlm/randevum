<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { IconCalendarWeek, IconSearch } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppointmentStatusTag from '@/components/AppointmentStatusTag.vue';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { useTableFilters } from '@/composables/useTableFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { index } from '@/routes/appointments';
import { show } from '@/routes/patients';
import type { AppointmentIndexProps } from '@/types/appointment';
import { MVP_APPOINTMENT_STATUSES } from '@/utils/appointmentStatus';
import { parseDateString } from '@/utils/datetime';

defineOptions({ layout: AppLayout });

const props = defineProps<AppointmentIndexProps>();

const { t } = useI18n();
const { can } = useCan();
const { formatDate, formatTime } = useDateTime();

const canViewAll = computed(() => can('appointments.viewAll'));

const { state, loading, first, sortField, sortOrder, onPage, onSort } =
    useTableFilters<{
        status: string[];
        doctor_id: number | null;
        start_date: Date | null;
        end_date: Date | null;
    }>({
        url: index().url,
        only: ['appointments', 'query'],
        currentPage: props.appointments.meta.current_page,
        search: props.query.filter.search,
        sort: props.query.sort,
        perPage: props.query.per_page,
        filters: {
            status: {
                type: 'array',
                value: props.query.filter.status
                    ? props.query.filter.status.split(',')
                    : [],
            },
            doctor_id: {
                type: 'number',
                value: props.query.filter.doctor_id,
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
        state.status.length > 0 ||
        state.doctor_id !== null ||
        state.start_date !== null ||
        state.end_date !== null,
);

// Big empty state only when the clinic genuinely has no appointments (not a filtered miss).
const showEmptyState = computed(
    () => props.appointments.meta.total === 0 && !hasActiveFilters.value,
);

const statusOptions = computed(() =>
    MVP_APPOINTMENT_STATUSES.map((status) => ({
        label: t(`appointment.status.${status}`),
        value: status,
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
        <Head :title="t('appointment_list.title')" />

        <PageHeader
            :title="t('appointment_list.title')"
            :description="t('appointment_list.subtitle')"
            :breadcrumbs="[{ label: t('nav.appointments') }]"
        />

        <div
            v-if="showEmptyState"
            class="flex flex-col items-center justify-center gap-3 rounded-xl border border-surface-200 bg-surface-0 px-6 py-16 text-center"
        >
            <IconCalendarWeek class="size-10 text-surface-300" />
            <p class="text-sm text-surface-500">
                {{ t('appointment_list.empty') }}
            </p>
        </div>

        <section
            v-else
            class="rounded-xl border border-surface-200 bg-surface-0 p-2 sm:p-3"
        >
            <DataTableWrapper
                :value="appointments.data"
                :total-records="appointments.meta.total"
                :rows="state.per_page"
                :first="first"
                :loading="loading"
                :sort-field="sortField"
                :sort-order="sortOrder"
                @page="onPage"
                @sort="onSort"
            >
                <template #toolbar>
                    <IconField>
                        <InputIcon>
                            <IconSearch class="size-4 text-surface-400" />
                        </InputIcon>
                        <InputText
                            v-model="state.search"
                            :placeholder="
                                t('appointment_list.search_placeholder')
                            "
                            class="w-full sm:w-72"
                        />
                    </IconField>
                    <MultiSelect
                        v-model="state.status"
                        :options="statusOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('appointment_list.filter_status')"
                        :max-selected-labels="2"
                        show-clear
                        class="w-full sm:w-64"
                    />
                    <Select
                        v-if="canViewAll"
                        v-model="state.doctor_id"
                        :options="doctors"
                        option-label="display_name"
                        option-value="id"
                        :placeholder="t('appointment_list.filter_doctor')"
                        show-clear
                        class="w-full sm:w-52"
                    />
                    <DatePicker
                        v-model="dateRange"
                        selection-mode="range"
                        :number-of-months="2"
                        :manual-input="false"
                        date-format="dd.mm.yy"
                        show-button-bar
                        :placeholder="t('appointment_list.filter_date_range')"
                        class="w-full sm:w-64"
                        :pt="{ panel: { class: 'daterange-panel-centered' } }"
                    />
                </template>

                <Column
                    field="starts_at"
                    :header="t('appointment_list.columns.datetime')"
                    sortable
                    class="w-48"
                >
                    <template #body="{ data }">
                        <div class="flex flex-col">
                            <span class="font-medium text-surface-800">
                                {{ formatDate(data.starts_at) }}
                            </span>
                            <span class="text-xs text-surface-500">
                                {{ formatTime(data.starts_at) }} –
                                {{ formatTime(data.ends_at) }}
                            </span>
                        </div>
                    </template>
                </Column>

                <Column :header="t('appointment_list.columns.patient')">
                    <template #body="{ data }">
                        <div class="flex min-w-0 items-center gap-2">
                            <Link
                                :href="show(data.patient_id).url"
                                class="truncate font-medium text-primary-600 transition-colors hover:text-primary-700"
                            >
                                {{ data.patient_name }}
                            </Link>
                            <Tag
                                v-if="data.is_walk_in"
                                severity="warn"
                                :value="t('appointment_list.walk_in_badge')"
                            />
                        </div>
                    </template>
                </Column>

                <Column :header="t('appointment_list.columns.doctor')">
                    <template #body="{ data }">
                        <span class="text-surface-700">
                            {{ data.doctor_name }}
                        </span>
                    </template>
                </Column>

                <Column :header="t('appointment_list.columns.service')">
                    <template #body="{ data }">
                        <span v-if="data.service_name" class="text-surface-700">
                            {{ data.service_name }}
                        </span>
                        <span
                            v-else-if="data.appointment_type"
                            class="inline-flex items-center gap-2 text-surface-700"
                        >
                            <span
                                class="size-2.5 shrink-0 rounded-full"
                                :style="{
                                    backgroundColor:
                                        data.appointment_type.color,
                                }"
                            />
                            {{ data.appointment_type.name }}
                        </span>
                        <span v-else class="text-surface-400">—</span>
                    </template>
                </Column>

                <Column
                    field="status"
                    :header="t('appointment_list.columns.status')"
                    class="w-36"
                >
                    <template #body="{ data }">
                        <AppointmentStatusTag :status="data.status" />
                    </template>
                </Column>

                <template #empty>
                    <div
                        class="px-6 py-10 text-center text-sm text-surface-500"
                    >
                        {{ t('appointment_list.empty_filtered') }}
                    </div>
                </template>
            </DataTableWrapper>
        </section>
    </div>
</template>
