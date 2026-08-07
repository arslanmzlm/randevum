<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { IconStack2 } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import StockLevelTag from '@/components/products/StockLevelTag.vue';
import StockMovementReasonTag from '@/components/products/StockMovementReasonTag.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { useTableFilters } from '@/composables/useTableFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as productsIndex } from '@/routes/products';
import { index as movementsIndex } from '@/routes/products/movements';
import { show as treatmentShow } from '@/routes/treatments';
import type { StockMovementIndexProps } from '@/types/stockMovement';
import { parseDateString } from '@/utils/datetime';
import { STOCK_MOVEMENT_REASONS } from '@/utils/stockMovementReason';

defineOptions({ layout: AppLayout });

const props = defineProps<StockMovementIndexProps>();

const { t } = useI18n();
const { can } = useCan();
const { formatDateTime } = useDateTime();

const canViewTreatments = computed(() => can('treatments.viewAny'));

const { state, loading, first, onPage } = useTableFilters<{
    reason: string[];
    start_date: Date | null;
    end_date: Date | null;
}>({
    url: movementsIndex(props.product.id).url,
    only: ['movements', 'query'],
    currentPage: props.movements.meta.current_page,
    perPage: props.query.per_page,
    filters: {
        reason: {
            type: 'array',
            value: props.query.filter.reason
                ? props.query.filter.reason.split(',')
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

const hasActiveFilters = computed(
    () =>
        state.reason.length > 0 ||
        state.start_date !== null ||
        state.end_date !== null,
);

// Big empty state only when the product genuinely has no movements (not a filtered miss).
const showEmptyState = computed(
    () => props.movements.meta.total === 0 && !hasActiveFilters.value,
);

const reasonOptions = computed(() =>
    STOCK_MOVEMENT_REASONS.map((reason) => ({
        label: t(`stock_movement.reason.${reason}`),
        value: reason,
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

function signedQuantity(quantity: number): string {
    return quantity > 0 ? `+${quantity}` : String(quantity);
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('stock_movement.title')" />

        <PageHeader
            :title="t('stock_movement.title')"
            :description="t('stock_movement.subtitle')"
            :breadcrumbs="[
                { label: t('nav.products'), href: productsIndex().url },
                { label: product.name },
                { label: t('stock_movement.title') },
            ]"
        >
            <template #actions>
                <span class="text-sm text-surface-500">
                    {{ t('stock_movement.current_stock') }}
                </span>
                <StockLevelTag
                    :stock="product.current_stock"
                    :unit="product.unit"
                />
            </template>
        </PageHeader>

        <EmptyState
            v-if="showEmptyState"
            :icon="IconStack2"
            :message="t('stock_movement.empty')"
        />

        <DataTableWrapper
            v-else
            :value="movements.data"
            :total-records="movements.meta.total"
            :rows="state.per_page"
            :first="first"
            :loading="loading"
            @page="onPage"
        >
            <template #toolbar>
                <MultiSelect
                    v-model="state.reason"
                    :options="reasonOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('stock_movement.filter_reason')"
                    :max-selected-labels="1"
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
                    :placeholder="t('stock_movement.filter_date_range')"
                    class="w-full sm:w-64"
                    :pt="{ panel: { class: 'daterange-panel-centered' } }"
                />
            </template>

            <Column
                field="created_at"
                :header="t('stock_movement.columns.datetime')"
                class="w-44"
            >
                <template #body="{ data }">
                    <span class="text-surface-700">
                        {{ formatDateTime(data.created_at) }}
                    </span>
                </template>
            </Column>

            <Column :header="t('stock_movement.columns.reason')" class="w-40">
                <template #body="{ data }">
                    <StockMovementReasonTag :reason="data.reason" />
                </template>
            </Column>

            <Column
                :header="t('stock_movement.columns.quantity')"
                class="w-28 text-right"
            >
                <template #body="{ data }">
                    <span
                        class="font-medium"
                        :class="
                            data.quantity > 0
                                ? 'text-green-600'
                                : 'text-red-600'
                        "
                    >
                        {{ signedQuantity(data.quantity) }}
                    </span>
                </template>
            </Column>

            <Column
                :header="t('stock_movement.columns.balance_after')"
                class="w-32 text-right"
            >
                <template #body="{ data }">
                    <span class="text-surface-700">
                        {{ data.balance_after }} {{ product.unit }}
                    </span>
                </template>
            </Column>

            <Column :header="t('stock_movement.columns.source')" class="w-52">
                <template #body="{ data }">
                    <Link
                        v-if="data.treatment_id && canViewTreatments"
                        :href="treatmentShow(data.treatment_id).url"
                        class="truncate font-medium text-primary-600 transition-colors hover:text-primary-700 hover:underline"
                    >
                        {{
                            data.patient_name ||
                            t('stock_movement.treatment_link')
                        }}
                    </Link>
                    <span
                        v-else-if="data.treatment_id"
                        class="text-surface-700"
                    >
                        {{
                            data.patient_name ||
                            t('stock_movement.treatment_link')
                        }}
                    </span>
                    <span v-else class="text-surface-400">—</span>
                </template>
            </Column>

            <Column :header="t('stock_movement.columns.user')" class="w-40">
                <template #body="{ data }">
                    <span v-if="data.created_by_name" class="text-surface-700">
                        {{ data.created_by_name }}
                    </span>
                    <span v-else class="text-surface-400">—</span>
                </template>
            </Column>

            <Column :header="t('stock_movement.columns.note')">
                <template #body="{ data }">
                    <span
                        v-if="data.note"
                        v-tooltip.top="data.note"
                        class="line-clamp-2 max-w-md text-sm text-surface-600"
                    >
                        {{ data.note }}
                    </span>
                    <span v-else class="text-surface-400">—</span>
                </template>
            </Column>

            <template #empty>
                <div class="px-6 py-10 text-center text-sm text-surface-500">
                    {{ t('stock_movement.empty_filtered') }}
                </div>
            </template>
        </DataTableWrapper>
    </div>
</template>
