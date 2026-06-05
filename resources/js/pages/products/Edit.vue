<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconArrowLeft, IconPackage, IconStack2 } from '@tabler/icons-vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, update } from '@/routes/products';
import { update as updateStock } from '@/routes/products/stock';
import type {
    ProductEditProps,
    ProductFormData,
    ProductStockFormData,
} from '@/types/product';

defineOptions({ layout: AppLayout });

const props = defineProps<ProductEditProps>();

const { t } = useI18n();

const form = useForm<ProductFormData>({
    name: props.product.name,
    description: props.product.description ?? '',
    brand: props.product.brand ?? '',
    category: props.product.category ?? '',
    sku: props.product.sku ?? '',
    unit: props.product.unit,
    price: Number(props.product.price),
    is_active: props.product.is_active,
});

const stockForm = useForm<ProductStockFormData>({
    current_stock: props.product.current_stock,
});

// Free-text AutoComplete (no forceSelection): suggest existing values, keep a typed new one.
const brandSuggestions = ref<string[]>([]);
const categorySuggestions = ref<string[]>([]);

function filterList(source: string[], query: string): string[] {
    const q = query.toLowerCase();

    return source.filter((value) => value.toLowerCase().includes(q));
}

function submit(): void {
    form.put(update(props.product.id).url, { preserveScroll: true });
}

function submitStock(): void {
    stockForm.patch(updateStock(props.product.id).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('product.edit_title')" />

        <PageHeader
            :title="product.name || t('product.edit_title')"
            :description="t('product.edit_subtitle')"
            :breadcrumbs="[
                { label: t('nav.products'), href: index().url },
                { label: product.name || t('product.edit_title') },
            ]"
        >
            <template #actions>
                <ButtonLink
                    :href="index().url"
                    :label="t('product.back')"
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
            <form
                novalidate
                class="flex flex-col gap-6 lg:col-span-2"
                @submit.prevent="submit"
            >
                <section
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                >
                    <header class="mb-6 flex items-center gap-2">
                        <IconPackage class="size-5 text-surface-500" />
                        <h2 class="text-lg font-semibold text-surface-900">
                            {{ t('product.sections.info') }}
                        </h2>
                    </header>

                    <div class="flex flex-col gap-5">
                        <FormField
                            :label="t('product.fields.name')"
                            :error="form.errors.name"
                            required
                        >
                            <InputText v-model="form.name" fluid />
                        </FormField>

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <FormField
                                :label="t('product.fields.price')"
                                :error="form.errors.price"
                                required
                            >
                                <InputNumber
                                    v-model="form.price"
                                    mode="currency"
                                    :currency="currency"
                                    :min="0"
                                    :max-fraction-digits="2"
                                    fluid
                                />
                            </FormField>

                            <FormField
                                :label="t('product.fields.brand')"
                                :error="form.errors.brand"
                            >
                                <AutoComplete
                                    v-model="form.brand"
                                    :suggestions="brandSuggestions"
                                    dropdown
                                    fluid
                                    @complete="
                                        brandSuggestions = filterList(
                                            props.brands,
                                            $event.query,
                                        )
                                    "
                                />
                            </FormField>

                            <FormField
                                :label="t('product.fields.category')"
                                :error="form.errors.category"
                            >
                                <AutoComplete
                                    v-model="form.category"
                                    :suggestions="categorySuggestions"
                                    dropdown
                                    fluid
                                    @complete="
                                        categorySuggestions = filterList(
                                            props.categories,
                                            $event.query,
                                        )
                                    "
                                />
                            </FormField>

                            <FormField
                                :label="t('product.fields.sku')"
                                :error="form.errors.sku"
                            >
                                <InputText v-model="form.sku" fluid />
                            </FormField>

                            <FormField
                                :label="t('product.fields.unit')"
                                :error="form.errors.unit"
                                required
                            >
                                <InputText v-model="form.unit" fluid />
                            </FormField>
                        </div>

                        <FormField
                            :label="t('product.fields.description')"
                            :error="form.errors.description"
                        >
                            <Textarea
                                v-model="form.description"
                                rows="3"
                                auto-resize
                                fluid
                            />
                        </FormField>

                        <div
                            class="flex items-center justify-between gap-4 rounded-lg border border-surface-200 p-4"
                        >
                            <div class="flex min-w-0 flex-col gap-1">
                                <span
                                    class="text-sm font-medium text-surface-900"
                                >
                                    {{ t('product.fields.is_active') }}
                                </span>
                                <span class="text-xs text-surface-500">
                                    {{ t('product.hints.is_active') }}
                                </span>
                            </div>
                            <ToggleSwitch v-model="form.is_active" />
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <Button
                            type="submit"
                            :label="t('product.save')"
                            :loading="form.processing"
                        />
                    </div>
                </section>
            </form>

            <!-- Quick stock adjust — its own PATCH endpoint, independent of the catalog form.
                 Reaching this page already requires products.update (owner/manager), the same
                 roles that hold products.manageStock. -->
            <form
                novalidate
                class="flex flex-col"
                @submit.prevent="submitStock"
            >
                <section
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                >
                    <header class="mb-2 flex items-center gap-2">
                        <IconStack2 class="size-5 text-surface-500" />
                        <h2 class="text-lg font-semibold text-surface-900">
                            {{ t('product.sections.stock') }}
                        </h2>
                    </header>
                    <p class="mb-6 text-sm text-surface-500">
                        {{ t('product.hints.stock') }}
                    </p>

                    <div class="flex flex-col gap-5">
                        <FormField
                            :label="t('product.fields.current_stock')"
                            :error="stockForm.errors.current_stock"
                        >
                            <InputNumber
                                v-model="stockForm.current_stock"
                                :use-grouping="false"
                                show-buttons
                                fluid
                            />
                        </FormField>

                        <Button
                            type="submit"
                            severity="secondary"
                            :label="t('product.stock_save')"
                            :loading="stockForm.processing"
                        />
                    </div>
                </section>
            </form>
        </div>
    </div>
</template>
