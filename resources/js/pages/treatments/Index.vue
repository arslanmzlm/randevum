<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { IconChevronRight, IconNotes, IconSearch } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import PatientNameLink from '@/components/patients/PatientNameLink.vue';
import TreatmentStatusTag from '@/components/TreatmentStatusTag.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import { useTableFilters } from '@/composables/useTableFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, show as treatmentShow } from '@/routes/treatments';
import type { TreatmentIndexProps } from '@/types/treatment';
import { parseDateString } from '@/utils/datetime';
import { FILTER_NONE } from '@/utils/filterValues';
import { shouldFilterSelect } from '@/utils/selectFilter';
import { MVP_TREATMENT_STATUSES } from '@/utils/treatmentStatus';

defineOptions({ layout: AppLayout });

const props = defineProps<TreatmentIndexProps>();

const { t } = useI18n();
const { can } = useCan();
const { formatDate } = useDateTime();
const { formatMoney } = useMoney();

// Mirrors the server-side narrowing: without viewAll the list is already limited to the user's own
// treatments, so the doctor filter would only ever have one option.
const canViewAll = computed(() => can('treatments.viewAll'));

const { state, loading, first, sortField, sortOrder, onPage, onSort } =
    useTableFilters<{
        status: string[];
        doctor_id: string[];
        service_id: string[];
        start_date: Date | null;
        end_date: Date | null;
    }>({
        url: index().url,
        only: ['treatments', 'query'],
        currentPage: props.treatments.meta.current_page,
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
                type: 'array',
                value: props.query.filter.doctor_id ?? [],
            },
            service_id: {
                type: 'array',
                value: props.query.filter.service_id
                    ? props.query.filter.service_id.split(',')
                    : [],
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

// MultiSelect binds numeric doctor ids; the filter state keeps string ids (URL/CSV canonical form).
const selectedDoctorIds = computed<number[]>({
    get: () => state.doctor_id.map(Number),
    set: (ids) => {
        state.doctor_id = ids.map(String);
    },
});

const hasActiveFilters = computed(
    () =>
        !!state.search ||
        state.status.length > 0 ||
        state.doctor_id.length > 0 ||
        state.service_id.length > 0 ||
        state.start_date !== null ||
        state.end_date !== null,
);

// Big empty state only when the clinic genuinely has no treatments (not a filtered miss).
const showEmptyState = computed(
    () => props.treatments.meta.total === 0 && !hasActiveFilters.value,
);

const statusOptions = computed(() =>
    MVP_TREATMENT_STATUSES.map((status) => ({
        label: t(`treatment.status.${status}`),
        value: status,
    })),
);

// "Belirtilmemiş" is the last plain option — it matches treatments with no service line.
const serviceFilterOptions = computed(() => [
    ...props.services.map((service) => ({
        label: service.name,
        value: String(service.id),
    })),
    { label: t('common.unspecified'), value: FILTER_NONE },
]);

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
        <Head :title="t('treatment_list.title')" />

        <PageHeader
            :title="t('treatment_list.title')"
            :description="t('treatment_list.subtitle')"
            :breadcrumbs="[{ label: t('nav.treatments') }]"
        />

        <EmptyState
            v-if="showEmptyState"
            :icon="IconNotes"
            :message="t('treatment_list.empty')"
        />

        <DataTableWrapper
            v-else
            :value="treatments.data"
            :total-records="treatments.meta.total"
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
                        :placeholder="t('treatment_list.search_placeholder')"
                        class="w-full sm:w-72"
                    />
                </IconField>
                <MultiSelect
                    v-model="state.status"
                    :options="statusOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('treatment_list.filter_status')"
                    :max-selected-labels="2"
                    show-clear
                    class="w-full sm:w-64"
                />
                <MultiSelect
                    v-if="canViewAll"
                    v-model="selectedDoctorIds"
                    :options="doctors"
                    option-label="display_name"
                    option-value="id"
                    :placeholder="t('treatment_list.filter_doctor')"
                    :max-selected-labels="0"
                    :selected-items-label="`{0} ${t('common.doctor_selected_suffix')}`"
                    show-clear
                    :filter="shouldFilterSelect(doctors.length)"
                    :filter-placeholder="t('common.search')"
                    class="w-full sm:w-52"
                />
                <!-- Always rendered: "Belirtilmemiş" is catalog-independent, so hiding the
                     select on an empty catalog would strip the only way to filter treatments
                     that have no service line. -->
                <MultiSelect
                    v-model="state.service_id"
                    :options="serviceFilterOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('treatment_list.filter_service')"
                    :max-selected-labels="1"
                    show-clear
                    :filter="shouldFilterSelect(serviceFilterOptions.length)"
                    :filter-placeholder="t('common.search')"
                    class="w-full sm:w-52"
                />
                <DatePicker
                    v-model="dateRange"
                    selection-mode="range"
                    :number-of-months="2"
                    :manual-input="false"
                    date-format="dd.mm.yy"
                    show-button-bar
                    :placeholder="t('treatment_list.filter_date_range')"
                    class="w-full sm:w-64"
                    :pt="{ panel: { class: 'daterange-panel-centered' } }"
                />
            </template>

            <Column
                field="created_at"
                :header="t('treatment_list.columns.date')"
                sortable
                class="w-36"
            >
                <template #body="{ data }">
                    <span class="font-medium text-surface-800">
                        {{ formatDate(data.created_at) }}
                    </span>
                </template>
            </Column>

            <Column :header="t('treatment_list.columns.patient')">
                <template #body="{ data }">
                    <PatientNameLink
                        :patient="data.patient"
                        class="truncate font-medium"
                    />
                </template>
            </Column>

            <Column :header="t('treatment_list.columns.doctor')">
                <template #body="{ data }">
                    <span class="text-surface-700">
                        {{ data.doctor.display_name }}
                    </span>
                </template>
            </Column>

            <Column :header="t('treatment_list.columns.services')">
                <template #body="{ data }">
                    <span
                        v-if="data.service_names.length"
                        class="text-surface-700"
                    >
                        {{ data.service_names.join(', ') }}
                    </span>
                    <span v-else class="text-surface-400">—</span>
                </template>
            </Column>

            <Column
                field="total_amount"
                :header="t('treatment_list.columns.amount')"
                sortable
                class="w-32 text-right"
            >
                <template #body="{ data }">
                    <span class="font-medium text-surface-800">
                        {{ formatMoney(data.total_amount) }}
                    </span>
                </template>
            </Column>

            <Column
                field="status"
                :header="t('treatment_list.columns.status')"
                class="w-36"
            >
                <template #body="{ data }">
                    <TreatmentStatusTag :status="data.status" />
                </template>
            </Column>

            <Column :header="t('treatment_list.columns.actions')" class="w-24">
                <template #body="{ data }">
                    <div class="flex justify-end">
                        <Link
                            :href="treatmentShow(data.id).url"
                            class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 transition-colors hover:text-primary-700 hover:underline"
                        >
                            {{ t('treatment_list.view') }}
                            <IconChevronRight class="size-4 shrink-0" />
                        </Link>
                    </div>
                </template>
            </Column>

            <template #empty>
                <div class="px-6 py-10 text-center text-sm text-surface-500">
                    {{ t('treatment_list.empty_filtered') }}
                </div>
            </template>
        </DataTableWrapper>
    </div>
</template>
