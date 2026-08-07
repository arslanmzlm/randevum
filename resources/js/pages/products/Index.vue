<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    IconHistory,
    IconPackage,
    IconPlus,
    IconSearch,
    IconStack2,
    IconTrash,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import CrudDialog from '@/components/crud/CrudDialog.vue';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import AdjustStockDialog from '@/components/products/AdjustStockDialog.vue';
import StockLevelTag from '@/components/products/StockLevelTag.vue';
import { useCan } from '@/composables/useCan';
import { useCrudDialog } from '@/composables/useCrudDialog';
import { useMoney } from '@/composables/useMoney';
import { useTableFilters } from '@/composables/useTableFilters';
import { productResource } from '@/crud/product';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, index } from '@/routes/products';
import { index as movementsIndex } from '@/routes/products/movements';
import type { Product, ProductIndexProps } from '@/types/product';
import { productColumns } from './columns';

defineOptions({ layout: AppLayout });

const props = defineProps<ProductIndexProps>();

const { t } = useI18n();
const { can } = useCan();
const { formatMoney } = useMoney();

// One control per ability, mirroring what the server enforces on each route.
const canCreate = computed(() => can('products.create'));
const canUpdate = computed(() => can('products.update'));
const canDelete = computed(() => can('products.delete'));
const canManageStock = computed(() => can('products.manageStock'));
// Reading the movement history is open to anyone who can see products; only the manual
// adjustment stays behind manageStock (mirrors ProductPolicy::viewMovements).
const canViewMovements = computed(
    () => canManageStock.value || can('products.viewAny'),
);
const showActions = computed(() => canUpdate.value || canDelete.value);

const { visible, item, openCreate, openEdit, confirmDelete } =
    useCrudDialog<Product>({
        lang: productResource.lang,
        destroy,
        editing: () => props.editing,
        canCreate: () => canCreate.value,
    });

const { state, loading, first, sortField, sortOrder, onPage, onSort } =
    useTableFilters<{ is_active: boolean | null }>({
        url: index().url,
        only: ['products', 'query'],
        currentPage: props.products.meta.current_page,
        search: props.query.filter.search,
        sort: props.query.sort,
        perPage: props.query.per_page,
        filters: {
            is_active: {
                type: 'boolean',
                value: props.query.filter.is_active,
            },
        },
    });

const hasActiveFilters = computed(
    () => !!state.search || state.is_active !== null,
);

// Big empty state only when the clinic genuinely has no products (not a filtered miss).
const showEmptyState = computed(
    () => props.products.meta.total === 0 && !hasActiveFilters.value,
);

function subtitle(product: Product): string {
    return [product.brand, product.category].filter(Boolean).join(' · ');
}

const statusOptions = computed(() => [
    { label: t('product.active'), value: true },
    { label: t('product.passive'), value: false },
]);

const columns = computed(() =>
    productColumns(t).filter((col) => !col.requiresManage || showActions.value),
);

// Stock-adjust modal — the same PATCH .../stock operation as the edit page, reachable from the list.
const stockDialogVisible = ref(false);
const stockTarget = ref<Product | null>(null);

function openStockDialog(product: Product): void {
    stockTarget.value = product;
    stockDialogVisible.value = true;
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('product.title')" />

        <PageHeader
            :title="t('product.title')"
            :description="t('product.subtitle')"
            :breadcrumbs="[{ label: t('nav.products') }]"
        >
            <template #actions>
                <Button
                    v-if="canCreate"
                    type="button"
                    :label="t('product.add')"
                    @click="openCreate"
                >
                    <template #icon>
                        <IconPlus />
                    </template>
                </Button>
            </template>
        </PageHeader>

        <CrudDialog
            v-if="canCreate || canUpdate"
            v-model:visible="visible"
            :resource="productResource"
            :item="item"
            :context="{ brands, categories }"
        />

        <EmptyState
            v-if="showEmptyState"
            :icon="IconPackage"
            :message="t('product.empty')"
        />

        <DataTableWrapper
            v-else
            :value="products.data"
            :total-records="products.meta.total"
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
                        :placeholder="t('product.search_placeholder')"
                        class="w-full sm:w-72"
                    />
                </IconField>
                <Select
                    v-model="state.is_active"
                    :options="statusOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('product.filter_status')"
                    show-clear
                    class="w-full sm:w-44"
                />
            </template>

            <Column
                v-for="col in columns"
                :key="col.key"
                :field="col.field"
                :header="col.header"
                :sortable="col.sortable"
                :class="col.class"
            >
                <template #body="{ data }">
                    <div
                        v-if="col.key === 'name'"
                        class="flex min-w-0 flex-col"
                    >
                        <span class="truncate font-medium text-surface-900">
                            {{ data.name }}
                        </span>
                        <span
                            v-if="subtitle(data)"
                            class="truncate text-xs text-surface-500"
                        >
                            {{ subtitle(data) }}
                        </span>
                    </div>

                    <span
                        v-else-if="col.key === 'price'"
                        class="font-medium text-surface-700"
                    >
                        {{ formatMoney(data.price) }}
                    </span>

                    <div
                        v-else-if="col.key === 'current_stock'"
                        class="flex items-center gap-2"
                    >
                        <StockLevelTag
                            :stock="data.current_stock"
                            :unit="data.unit"
                        />
                        <Button
                            v-if="canManageStock"
                            type="button"
                            severity="secondary"
                            outlined
                            size="small"
                            :aria-label="t('product.update_stock')"
                            v-tooltip.top="t('product.update_stock')"
                            @click="openStockDialog(data)"
                        >
                            <IconStack2 />
                        </Button>
                        <ButtonLink
                            v-if="canViewMovements"
                            :href="movementsIndex(data.id).url"
                            severity="secondary"
                            outlined
                            size="small"
                            :aria-label="t('stock_movement.open')"
                            v-tooltip.top="t('stock_movement.open')"
                        >
                            <template #icon>
                                <IconHistory />
                            </template>
                        </ButtonLink>
                    </div>

                    <Tag
                        v-else-if="col.key === 'is_active'"
                        :severity="data.is_active ? 'success' : 'secondary'"
                        :value="
                            data.is_active
                                ? t('product.active')
                                : t('product.passive')
                        "
                    />

                    <div
                        v-else-if="col.key === 'actions'"
                        class="flex items-center justify-end gap-1"
                    >
                        <Button
                            v-if="canUpdate"
                            type="button"
                            :label="t('product.edit')"
                            severity="secondary"
                            outlined
                            size="small"
                            @click="openEdit(data)"
                        />
                        <Button
                            v-if="canDelete"
                            type="button"
                            severity="danger"
                            text
                            size="small"
                            :aria-label="t('product.remove')"
                            @click="confirmDelete(data, data.name)"
                        >
                            <IconTrash />
                        </Button>
                    </div>
                </template>
            </Column>

            <template #empty>
                <div class="px-6 py-10 text-center text-sm text-surface-500">
                    {{ t('product.empty_filtered') }}
                </div>
            </template>
        </DataTableWrapper>

        <AdjustStockDialog
            v-model:visible="stockDialogVisible"
            :product="stockTarget"
        />
    </div>
</template>
