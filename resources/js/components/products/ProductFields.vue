<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    useCrudContext,
    useCrudForm,
    useCrudIsEdit,
} from '@/components/crud/crudFormContext';
import FormField from '@/components/FormField.vue';
import SettingRow from '@/components/SettingRow.vue';
import type {
    ProductCreateFormData,
    ProductSuggestions,
} from '@/types/product';

const { t } = useI18n();

const form = useCrudForm<ProductCreateFormData>();
const context = useCrudContext<ProductSuggestions & { currency: string }>();
const isEdit = useCrudIsEdit();

// Free-text AutoComplete (no forceSelection): suggest the clinic's existing values, but a typed
// new value is kept and submitted as-is.
const brandSuggestions = ref<string[]>([]);
const categorySuggestions = ref<string[]>([]);

function filterList(source: string[], query: string): string[] {
    const q = query.toLowerCase();

    return source.filter((value) => value.toLowerCase().includes(q));
}
</script>

<template>
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
                    :currency="context.currency"
                    :min="0"
                    :max-fraction-digits="2"
                    fluid
                />
            </FormField>

            <!-- Opening stock only: an existing product's stock is adjusted from the list. -->
            <FormField
                v-if="!isEdit"
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
                            context.brands,
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
                            context.categories,
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
            <Textarea v-model="form.description" rows="2" auto-resize fluid />
        </FormField>

        <SettingRow
            :label="t('product.fields.is_active')"
            :description="t('product.hints.is_active')"
        >
            <ToggleSwitch v-model="form.is_active" />
        </SettingRow>
    </div>
</template>
