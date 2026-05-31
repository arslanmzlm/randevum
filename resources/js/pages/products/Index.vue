<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { FilterMatchMode } from '@primevue/core/api';
import {
    IconPackage,
    IconPlus,
    IconSearch,
    IconStack2,
    IconTrash,
} from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { create, destroy, edit } from '@/routes/products';
import { update as updateStock } from '@/routes/products/stock';
import type {
    Product,
    ProductIndexProps,
    ProductStockFormData,
} from '@/types/product';

defineOptions({ layout: AppLayout });

const props = defineProps<ProductIndexProps>();

const { t, locale } = useI18n();
const confirm = useConfirm();

const priceFormatter = computed(
    () =>
        new Intl.NumberFormat(locale.value, {
            style: 'currency',
            currency: props.currency,
        }),
);

function formatPrice(value: string): string {
    return priceFormatter.value.format(Number(value));
}

function subtitle(product: Product): string {
    return [product.brand, product.category].filter(Boolean).join(' · ');
}

// Client-side filters (catalog stays small per clinic — no server-side pagination needed):
// free-text global search + an active/passive status filter (null = all).
const filters = ref({
    global: {
        value: null as string | null,
        matchMode: FilterMatchMode.CONTAINS,
    },
    is_active: {
        value: null as boolean | null,
        matchMode: FilterMatchMode.EQUALS,
    },
});

const statusOptions = computed(() => [
    { label: t('product.active'), value: true },
    { label: t('product.passive'), value: false },
]);

// Stock-adjust modal — the same PATCH .../stock operation as the edit page, reachable from the list.
const stockDialogVisible = ref(false);
const stockTarget = ref<Product | null>(null);
const stockForm = useForm<ProductStockFormData>({ current_stock: 0 });

function openStockDialog(product: Product): void {
    stockTarget.value = product;
    stockForm.clearErrors();
    stockForm.current_stock = product.current_stock;
    stockDialogVisible.value = true;
}

function submitStock(): void {
    if (!stockTarget.value) {
        return;
    }

    stockForm.patch(updateStock(stockTarget.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            stockDialogVisible.value = false;
        },
    });
}

function removeProduct(product: Product): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('product.remove_confirm', { name: product.name }),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('common.delete'), severity: 'danger' },
        accept: () =>
            router.delete(destroy(product.id).url, { preserveScroll: true }),
    });
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
                <ButtonLink
                    v-if="canManage"
                    :href="create().url"
                    :label="t('product.add')"
                >
                    <template #icon>
                        <IconPlus class="size-4" />
                    </template>
                </ButtonLink>
            </template>
        </PageHeader>

        <section
            class="rounded-xl border border-surface-200 bg-surface-0 p-2 sm:p-3"
        >
            <template v-if="products.length">
                <div
                    class="flex flex-col gap-2 p-2 sm:flex-row sm:items-center"
                >
                    <IconField>
                        <InputIcon>
                            <IconSearch class="size-4 text-surface-400" />
                        </InputIcon>
                        <InputText
                            v-model="filters.global.value"
                            :placeholder="t('product.search_placeholder')"
                            class="w-full sm:w-72"
                        />
                    </IconField>
                    <Select
                        v-model="filters.is_active.value"
                        :options="statusOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('product.filter_status')"
                        show-clear
                        class="w-full sm:w-44"
                    />
                </div>

                <DataTable
                    v-model:filters="filters"
                    :value="products"
                    data-key="id"
                    removable-sort
                    paginator
                    :rows="10"
                    :rows-per-page-options="[10, 25, 50]"
                    :global-filter-fields="['name', 'brand', 'category', 'sku']"
                    class="text-sm"
                >
                    <Column
                        field="name"
                        :header="t('product.columns.name')"
                        sortable
                    >
                        <template #body="{ data }">
                            <div class="flex min-w-0 flex-col">
                                <span
                                    class="truncate font-medium text-surface-900"
                                >
                                    {{ data.name }}
                                </span>
                                <span
                                    v-if="subtitle(data)"
                                    class="truncate text-xs text-surface-500"
                                >
                                    {{ subtitle(data) }}
                                </span>
                            </div>
                        </template>
                    </Column>

                    <Column
                        field="price"
                        :header="t('product.columns.price')"
                        sortable
                        class="w-40"
                    >
                        <template #body="{ data }">
                            <span class="font-medium text-surface-700">
                                {{ formatPrice(data.price) }}
                            </span>
                        </template>
                    </Column>

                    <Column
                        field="current_stock"
                        :header="t('product.columns.stock')"
                        sortable
                        class="w-44"
                    >
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <Tag
                                    v-if="data.current_stock < 0"
                                    severity="danger"
                                    :value="`${data.current_stock} ${data.unit}`"
                                />
                                <span v-else class="text-surface-700">
                                    {{ data.current_stock }} {{ data.unit }}
                                </span>
                                <Button
                                    v-if="canManage"
                                    type="button"
                                    severity="secondary"
                                    outlined
                                    size="small"
                                    :aria-label="t('product.update_stock')"
                                    v-tooltip.top="t('product.update_stock')"
                                    @click="openStockDialog(data)"
                                >
                                    <IconStack2 class="size-4" />
                                </Button>
                            </div>
                        </template>
                    </Column>

                    <Column
                        field="is_active"
                        :header="t('product.columns.status')"
                        sortable
                        class="w-32"
                    >
                        <template #body="{ data }">
                            <Tag
                                :severity="
                                    data.is_active ? 'success' : 'secondary'
                                "
                                :value="
                                    data.is_active
                                        ? t('product.active')
                                        : t('product.passive')
                                "
                            />
                        </template>
                    </Column>

                    <Column
                        v-if="canManage"
                        :header="t('product.columns.actions')"
                        class="w-32"
                    >
                        <template #body="{ data }">
                            <div class="flex items-center justify-end gap-1">
                                <ButtonLink
                                    :href="edit(data.id).url"
                                    :label="t('product.edit')"
                                    severity="secondary"
                                    outlined
                                    size="small"
                                />
                                <Button
                                    type="button"
                                    severity="danger"
                                    text
                                    size="small"
                                    :aria-label="t('product.remove')"
                                    @click="removeProduct(data)"
                                >
                                    <IconTrash class="size-4" />
                                </Button>
                            </div>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="px-6 py-10 text-center text-sm text-surface-500"
                        >
                            {{ t('product.empty_filtered') }}
                        </div>
                    </template>
                </DataTable>
            </template>

            <div
                v-else
                class="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center"
            >
                <IconPackage class="size-10 text-surface-300" />
                <p class="text-sm text-surface-500">{{ t('product.empty') }}</p>
            </div>
        </section>

        <Dialog
            v-model:visible="stockDialogVisible"
            modal
            :draggable="false"
            :header="t('product.update_stock')"
            class="w-full max-w-sm"
        >
            <p v-if="stockTarget" class="mb-4 text-sm text-surface-500">
                {{ stockTarget.name }}
            </p>

            <form novalidate @submit.prevent="submitStock">
                <FormField
                    :label="t('product.fields.current_stock')"
                    :error="stockForm.errors.current_stock"
                    :hint="t('product.hints.stock')"
                >
                    <InputNumber
                        v-model="stockForm.current_stock"
                        :use-grouping="false"
                        show-buttons
                        fluid
                    />
                </FormField>

                <div class="mt-6 flex justify-end gap-2">
                    <Button
                        type="button"
                        severity="secondary"
                        text
                        :label="t('common.cancel')"
                        @click="stockDialogVisible = false"
                    />
                    <Button
                        type="submit"
                        :label="t('product.stock_save')"
                        :loading="stockForm.processing"
                    />
                </div>
            </form>
        </Dialog>
    </div>
</template>
