<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { IconFolders, IconSearch } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import CaseStatusTag from '@/components/CaseStatusTag.vue';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import PatientNameLink from '@/components/patients/PatientNameLink.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { useTableFilters } from '@/composables/useTableFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, show } from '@/routes/cases';
import type { CaseIndexProps } from '@/types/case';
import { MVP_CASE_STATUSES } from '@/utils/caseStatus';
import { shouldFilterSelect } from '@/utils/selectFilter';

defineOptions({ layout: AppLayout });

const props = defineProps<CaseIndexProps>();

const { t } = useI18n();
const { can } = useCan();
const { formatDate, formatDateOnly } = useDateTime();

const canViewAll = computed(() => can('cases.viewAll'));

const { state, loading, first, sortField, sortOrder, onPage, onSort } =
    useTableFilters<{
        status: string[];
        doctor_id: number | null;
    }>({
        url: index().url,
        only: ['cases', 'query'],
        currentPage: props.cases.meta.current_page,
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
        },
    });

const hasActiveFilters = computed(
    () => !!state.search || state.status.length > 0 || state.doctor_id !== null,
);

// Big empty state only when the clinic genuinely has no cases (not a filtered miss).
const showEmptyState = computed(
    () => props.cases.meta.total === 0 && !hasActiveFilters.value,
);

const statusOptions = computed(() =>
    MVP_CASE_STATUSES.map((status) => ({
        label: t(`case.status.${status}`),
        value: status,
    })),
);
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('case_list.title')" />

        <PageHeader
            :title="t('case_list.title')"
            :description="t('case_list.subtitle')"
            :breadcrumbs="[{ label: t('nav.cases') }]"
        />

        <EmptyState
            v-if="showEmptyState"
            :icon="IconFolders"
            :message="t('case_list.empty')"
        />

        <DataTableWrapper
            v-else
            :value="cases.data"
            :total-records="cases.meta.total"
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
                        :placeholder="t('case_list.search_placeholder')"
                        class="w-full sm:w-72"
                    />
                </IconField>
                <MultiSelect
                    v-model="state.status"
                    :options="statusOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('case_list.filter_status')"
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
                    :placeholder="t('case_list.filter_doctor')"
                    show-clear
                    :filter="shouldFilterSelect(doctors.length)"
                    :filter-placeholder="t('common.search')"
                    class="w-full sm:w-52"
                />
            </template>

            <Column
                field="title"
                :header="t('case_list.columns.title')"
                sortable
            >
                <template #body="{ data }">
                    <Link
                        :href="show(data.id).url"
                        class="font-medium text-primary-600 transition-colors hover:text-primary-700 hover:underline"
                    >
                        {{ data.title }}
                    </Link>
                </template>
            </Column>

            <Column :header="t('case_list.columns.patient')">
                <template #body="{ data }">
                    <PatientNameLink :patient="data.patient" subtle />
                </template>
            </Column>

            <Column v-if="canViewAll" :header="t('case_list.columns.doctor')">
                <template #body="{ data }">
                    <span class="text-surface-700">
                        {{ data.doctor.display_name }}
                    </span>
                </template>
            </Column>

            <Column
                field="status"
                :header="t('case_list.columns.status')"
                class="w-36"
            >
                <template #body="{ data }">
                    <CaseStatusTag :status="data.status" />
                </template>
            </Column>

            <Column
                :header="t('case_list.columns.treatments')"
                class="w-28 text-center"
            >
                <template #body="{ data }">
                    <span class="text-surface-700">
                        {{ data.treatments_count }}
                    </span>
                </template>
            </Column>

            <Column
                field="opened_at"
                :header="t('case_list.columns.opened_at')"
                sortable
                class="w-40"
            >
                <template #body="{ data }">
                    <span class="text-surface-700">
                        {{ formatDate(data.opened_at) }}
                    </span>
                </template>
            </Column>

            <Column :header="t('case_list.columns.follow_up')" class="w-36">
                <template #body="{ data }">
                    <span v-if="data.follow_up_date" class="text-surface-700">
                        {{ formatDateOnly(data.follow_up_date) }}
                    </span>
                    <span v-else class="text-surface-400">—</span>
                </template>
            </Column>

            <template #empty>
                <div class="px-6 py-10 text-center text-sm text-surface-500">
                    {{ t('case_list.empty_filtered') }}
                </div>
            </template>
        </DataTableWrapper>
    </div>
</template>
