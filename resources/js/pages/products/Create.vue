<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconArrowLeft, IconPackage } from '@tabler/icons-vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, store } from '@/routes/products';
import type {
    ProductCreateFormData,
    ProductCreateProps,
} from '@/types/product';

defineOptions({ layout: AppLayout });

const props = defineProps<ProductCreateProps>();

const { t } = useI18n();

// Free-text AutoComplete (no forceSelection): suggest the clinic's existing values, but a typed
// new value is kept and submitted as-is.
const brandSuggestions = ref<string[]>([]);
const categorySuggestions = ref<string[]>([]);

function filterList(source: string[], query: string): string[] {
    const q = query.toLowerCase();

    return source.filter((value) => value.toLowerCase().includes(q));
}

const form = useForm<ProductCreateFormData>({
    name: '',
    description: '',
    brand: '',
    category: '',
    sku: '',
    unit: 'adet',
    price: null,
    current_stock: null,
    is_active: true,
});

function submit(): void {
    form.post(store().url);
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('product.create_title')" />

        <PageHeader
            :title="t('product.create_title')"
            :description="t('product.create_subtitle')"
            :breadcrumbs="[
                { label: t('nav.products'), href: index().url },
                { label: t('product.create_title') },
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

        <form novalidate class="flex flex-col gap-6" @submit.prevent="submit">
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
                            :label="t('product.fields.current_stock')"
                            :error="form.errors.current_stock"
                            :hint="t('product.hints.current_stock')"
                        >
                            <InputNumber
                                v-model="form.current_stock"
                                :use-grouping="false"
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
                            <span class="text-sm font-medium text-surface-900">
                                {{ t('product.fields.is_active') }}
                            </span>
                            <span class="text-xs text-surface-500">
                                {{ t('product.hints.is_active') }}
                            </span>
                        </div>
                        <ToggleSwitch v-model="form.is_active" />
                    </div>
                </div>
            </section>

            <div class="flex justify-end">
                <Button
                    type="submit"
                    :label="t('product.create_submit')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </div>
</template>
